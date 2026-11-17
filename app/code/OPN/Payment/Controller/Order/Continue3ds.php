<?php
namespace OPN\Payment\Controller\Order;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use OPN\Payment\Helper\Data as PaymentHelper;

/**
 * The only place that talks to the gateway during a 3DS challenge. The browser
 * (view/frontend/web/js/threeds.js) drives the state machine: it renders each
 * hidden/visible iframe, relays whatever the ACS posts back via
 * Controller/Order/Threedscallback.php + postMessage, then calls this endpoint
 * with { id, threeDSRef, threeDSResponse }. Returns JSON:
 *   { status: 'success',   redirect: <order status url> }
 *   { status: 'challenge', threeDSURL, threeDSRequest, threeDSRef }  — another round required
 *   { status: 'declined',  message, redirect }  — always carries somewhere to recover to
 */
class Continue3ds extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $_objectManager;
    protected $_helper;
    protected $customerSession;
    protected $checkoutSession;
    protected $resultJsonFactory;

    public function __construct(
        Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        JsonFactory $resultJsonFactory,
        PaymentHelper $helper
    ) {
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->resultJsonFactory = $resultJsonFactory;

        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_helper = $helper;

        parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $orderId = (int)$this->getRequest()->getParam('id');
        $submittedRef = trim((string)$this->getRequest()->getParam('threeDSRef'));
        // Sent by threeds.js as a JSON string (the ACS payload's shape is unknown up
        // front), not native PHP bracket-notation form fields.
        $relayedRaw = $this->getRequest()->getParam('threeDSResponse');
        if (is_array($relayedRaw)) {
            $relayed = $relayedRaw;
        } else {
            $decoded = json_decode((string)$relayedRaw, true);
            $relayed = is_array($decoded) ? $decoded : [];
        }

        list($order, $payment, $error) = $this->validateOrder($orderId);

        if (!$error) {
            $customerId = $order->getCustomerId();
            if ($customerId && $this->customerSession->isLoggedIn()) {
                if ($this->customerSession->getCustomerId() != $customerId) {
                    $error = 'Order access denied.';
                }
            } elseif (!$customerId) {
                $lastOrderId = $this->checkoutSession->getLastOrderId();
                if (!$lastOrderId || (int)$lastOrderId !== $orderId) {
                    $error = 'Order access denied.';
                }
            }
        }

        if ($error) {
            // Deliberately no order: this branch covers "Invalid Order" AND
            // "Order access denied", and declineResponse() restores the order's quote
            // into the current checkout session. Handing it an order the caller has just
            // failed the ownership check on would leak that basket to them. A plain
            // redirect back to their own cart is all this case warrants.
            return $this->declineResponse($result, (string)__($error), null);
        }

        $additionalInfo = $payment->getAdditionalInformation();

        // Idempotency: a genuine ACS callback and the client-side 10s timeout fallback
        // can both fire a continuation for the same challenge. If this order already
        // completed, never re-process — just report the outcome we already reached.
        if (($additionalInfo['status'] ?? '') === 'success') {
            return $result->setData([
                'status' => 'success',
                'redirect' => $this->_helper->redirectToCheckoutSuccess($order),
            ]);
        }

        $expected = $additionalInfo['redirect_3d_auth'] ?? [];
        if (empty($submittedRef) || empty($expected['ref']) || $submittedRef !== $expected['ref']) {
            // Stale or mismatched continuation (e.g. a duplicate/late timeout fallback
            // after a real callback already advanced the challenge) — report the
            // currently expected step instead of forwarding an untrusted ref.
            if (!empty($expected['url'])) {
                return $result->setData([
                    'status' => 'challenge',
                    'threeDSURL' => $expected['url'],
                    'threeDSRequest' => $expected['request'] ?? [],
                    'threeDSRef' => $expected['ref'] ?? '',
                ]);
            }
            return $this->declineResponse($result, (string)__('Your session has expired. Please try again.'), $order);
        }

        $settings = $this->_helper->getSettings();

        // Carry forward the integration the FIRST leg recorded (a wallet in Modal mode is
        // 'direct'), not the configured mode. Re-saving the configured value here is what
        // relabelled a wallet order "Modal" again once 3-D Secure completed.
        $integrationUsed = $additionalInfo['integration_type'] ?? $settings['integration_type'];

        $cres = !empty($relayed['cres']) && !empty($relayed['threeDSAcsResponse']) ? $relayed['cres'] : null;
        $threeDSResponse = !empty($cres) ? ['cres' => $cres] : $relayed;

        $request = [
            'merchantID' => $settings['gateway_id'],
            'action' => strtoupper($settings['purchase_type'] ?? 'SALE'),
            'threeDSRef' => $submittedRef,
            'threeDSResponse' => $threeDSResponse,
        ];
        $request['signature'] = $this->_helper->createSignature($request, $settings['signature_key']) . '|' . implode(",", array_keys($request));

        $this->_helper->log("Payment #".$orderId, $integrationUsed.'_3ds_continue', $request, 'Request');
        $response = $this->_helper->httpRequest('POST', $settings['gateway_url'] . 'direct/', $request);
        $this->_helper->log("Payment #".$orderId, $integrationUsed.'_3ds_continue', $response, 'Response');

        list($success, $responseError, $status, $redirectUrl) = $this->_helper->validateResponse($response, $settings['signature_key'], 'R');

        $this->_helper->saveTransaction($payment, $integrationUsed, $settings['purchase_type'] ?? 'sale', $status, $response);

        if ($status === 'pending') {
            return $result->setData([
                'status' => 'challenge',
                'threeDSURL' => $response['threeDSURL'] ?? '',
                'threeDSRequest' => $response['threeDSRequest'] ?? [],
                'threeDSRef' => $response['threeDSRef'] ?? '',
            ]);
        }

        if ($status === 'success') {
            // walletID / cardID / cardToken only ever arrive on the response that approves
            // the transaction, so for a 3-D Secure payment this is the ONLY place a card
            // can be stored. DataAssignObserver's own saveCard() call is reachable solely
            // when the gateway approves outright on the first response — which never
            // happens on a 3DS-enabled account, so Save Card silently did nothing there
            // (reported 27/08/2026). The intent is carried on the payment by
            // DataAssignObserver; saveCard() itself re-validates the response fields.
            if (!empty($additionalInfo['save_card']) && $order->getCustomerId()) {
                $this->_helper->saveCard((int)$order->getCustomerId(), $response);
            }

            // sendInvoice() transitions the order to STATE_PROCESSING and saves it — call it
            // before redirectToCheckoutSuccess() so the stamped LastOrderStatus reflects the
            // real post-payment status rather than the stale 'pending_payment' it was set to
            // before this challenge.
            $this->_helper->sendInvoice($order);

            // Same address-book save as Controller/Redirect/Index.php's success path.
            try {
                $quote = $this->_objectManager->create(\Magento\Quote\Model\Quote::class)->load($order->getQuoteId());
                if ($quote && $quote->getCustomerId()) {
                    if ($quote->getBillingAddress()) {
                        $quote->getBillingAddress()->setSaveInAddressBook(1);
                    }
                    if (!$quote->isVirtual() && $quote->getShippingAddress()) {
                        $quote->getShippingAddress()->setSaveInAddressBook(1);
                    }
                    $quote->save();
                }
            } catch (\Exception $e) {
                // do not block checkout flow if address book save fails
            }

            return $result->setData([
                'status' => 'success',
                'redirect' => $this->_helper->redirectToCheckoutSuccess($order),
            ]);
        }

        if ($status === 'cancelled') {
            $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
            $order->save();
        }

        return $this->declineResponse(
            $result,
            (string)($responseError ?: __('Payment could not be completed.')),
            $order
        );
    }

    /**
     * Build a declined result.
     *
     * Every rejection reached from here used to return a bare message, which threeds.js
     * painted onto the 3DS host page before stopping — that is how a refused challenge
     * left the shopper stranded on "3DS DECLINED" at opn/order/threedsecure with no way
     * forward (reported 27/08/2026). A decline must always name somewhere to recover to,
     * matching what Controller/Redirect/Index.php already does for the hosted
     * integrations: basket restored, reason shown, back to the cart to try again.
     */
    private function declineResponse($result, $message, $order = null)
    {
        $hasOrder = $order && $order->getId();

        $method = 'card';
        if ($hasOrder && $order->getPayment()) {
            $method = $order->getPayment()->getAdditionalInformation('method') ?: 'card';
        }

        if ($hasOrder && in_array($method, ['applepay', 'googlepay'], true)) {
            // Express wallet quotes are not the shopper's cart and the wallet sheet has
            // already closed, so there is nothing useful to restore — the order status
            // page explains what happened. Same rule as Controller/Redirect/Index.php.
            $redirectUrl = $this->_url->getUrl('opn/order/index', ['id' => $order->getId()]);
        } else {
            if ($hasOrder) {
                $this->restoreQuote($order);
            }
            // Controller/Payment/Basket shows the reason and refreshes the mini-cart.
            $this->checkoutSession->setData(\OPN\Payment\Controller\Payment\Basket::SESSION_ERROR_KEY, $message);
            $redirectUrl = $this->_url->getUrl('opn/payment/basket');
        }

        return $result->setData([
            'status'   => 'declined',
            'message'  => $message,
            'redirect' => $redirectUrl,
        ]);
    }

    /**
     * Reactivate the order's quote and put it back in the checkout session so the
     * shopper's basket is intact when they land on the cart page. Byte-for-byte the
     * same recovery Controller/Redirect/Index.php performs on a hosted-form failure.
     */
    private function restoreQuote($order)
    {
        try {
            $quote = $this->_objectManager->create(\Magento\Quote\Model\Quote::class)->load($order->getQuoteId());
            if ($quote->getId()) {
                $quote->setIsActive(true);
                $quote->setReservedOrderId(null);
                $quote->save();

                $this->checkoutSession->replaceQuote($quote);
                $this->checkoutSession->setQuoteIdForRestore($quote->getId());
            }
        } catch (\Exception $e) {
            // Never let basket recovery turn a declined payment into a hard error —
            // the shopper still needs the redirect below.
            $this->_helper->log(
                "Payment #" . $order->getId(),
                '3ds_continue',
                ['error' => $e->getMessage()],
                'Quote restore failed'
            );
        }
    }

    private function validateOrder($orderId)
    {
        $error = false;
        $_order = false;
        $_payment = null;
        $order = !empty($orderId) ? $this->_objectManager->get('Magento\Sales\Model\Order')->load($orderId) : null;

        if ($order && $order->getId()) {
            $_payment = $order->getPayment();
            $payment_code = $_payment ? $_payment->getMethodInstance()->getCode() : null;

            if ($payment_code == 'qidigitalpayment') {
                $_order = $order;
            } else {
                $error = 'Invalid order payment request';
            }
        } else {
            $error = 'Invalid Order';
        }

        return [$_order, $_payment, $error];
    }
}
