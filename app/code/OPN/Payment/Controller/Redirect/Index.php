<?php
namespace OPN\Payment\Controller\Redirect;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order;
use OPN\Payment\Helper\Data as PaymentHelper;

class Index extends Action implements CsrfAwareActionInterface
{

    protected $_resultFactory;
    protected $_request;
    protected $_helper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        ResultFactory $resultFactory,
        PaymentHelper $helper
    )
    {
        $this->_resultFactory = $resultFactory;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_request = $this->_objectManager->get('Magento\Framework\App\RequestInterface');
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

    public function execute(){

        $order_id = (int)$this->_request->getParam('id');
        $payment_id = preg_replace('/[^0-9a-f]/i', '', (string)$this->_request->getParam('payment_id'));

        // Set by Controller/Payment/EmbeddedData.php: the gateway's return is arriving inside
        // the Embedded integration's iframe, so the final redirect must target the top window.
        $embedded = $this->_request->getParam('emb') === '1';

        if (empty($order_id) && !empty($payment_id)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();
            $like = '"payment_id":"' . $payment_id . '"';

            // getTableName() applies the install's table prefix. The raw
            // 'FROM sales_order_payment' this replaced broke outright on any store
            // configured with one. $payment_id is already restricted to hex above.
            //
            // Select parent_id, NOT entity_id: entity_id is the payment row's own key,
            // whereas parent_id is the sales_order it belongs to — and the value is used
            // below as an order ID. The two coincide on a clean install because both
            // auto-increments advance in step, which is why this went unnoticed; they
            // diverge as soon as the two tables' row counts differ, at which point a
            // legitimate callback resolves to the wrong order or none at all.
            $select = $connection->select()
                ->from($resource->getTableName('sales_order_payment'), 'parent_id')
                ->where('additional_information LIKE ?', '%' . $like . '%')
                ->order('entity_id DESC')
                ->limit(1);

            $order_id = (int)$connection->fetchOne($select);
        }

        list($order, $payment, $error) = $this->validateOrder($order_id);

        $settings = $error ? [] : $this->_helper->getSettings();
        $response = $this->_request->getParams();

        if (!$error) {
            // Intermediate 3DS / alternative-payment callbacks (device fingerprint,
            // ACS challenge result, PayPal/checkout return) carry NO gateway
            // signature: their authoritative result is fetched server-to-server from
            // the gateway below (via direct/), so the browser cannot forge it.
            $additionalInfo = $payment->getAdditionalInformation();
            $method = $additionalInfo['method'] ?? 'card';
            $isContinuation =
                (!empty($response['checkoutRef']) && $method == 'paypal') ||
                !empty($response['threeDSMethodData']) ||
                !empty($response['threeDSResponse']) ||
                (!empty($response['cres']) && !empty($response['threeDSAcsResponse']));

            // F-NEW-04: continuation callbacks skip HMAC (server fetches result directly),
            // but order_id comes from the URL and is otherwise unverified — check ownership.
            if ($isContinuation) {
                $customerSession = $this->_objectManager->get(\Magento\Customer\Model\Session::class);
                $customerId = (int)$customerSession->getCustomerId();
                if ($customerId) {
                    if ((int)$order->getCustomerId() !== $customerId) {
                        $error = (string)__('Order access denied.');
                    }
                } else {
                    $checkoutSession = $this->_objectManager->get(\Magento\Checkout\Model\Session::class);
                    $lastOrderId = (int)$checkoutSession->getLastOrderId();
                    if (!$lastOrderId || $lastOrderId !== (int)$order_id) {
                        // Session may be lost during cross-domain 3DS redirect (SameSite cookie).
                        // Fall back to payment_id: it is 128-bit CSPRNG stored server-side,
                        // so knowing it proves the user completed the original checkout.
                        $storedPaymentId = $payment->getAdditionalInformation('payment_id') ?? '';
                        if (empty($payment_id) || empty($storedPaymentId) || $payment_id !== $storedPaymentId) {
                            $error = (string)__('Order access denied.');
                        }
                    }
                }
            }

            if (!$isContinuation) {
                // 🔒 Security: this route is public, unauthenticated and CSRF-exempt —
                // the gateway redirects the customer's BROWSER back here, so the data
                // is otherwise fully attacker-controlled (e.g. responseCode=0 would
                // forge a "payment successful" result for any order). Verify the HMAC
                // signature against the gateway's signing key before trusting it.
                //
                // The gateway only signs the fields it POSTs back. The URL path/query
                // params we inject into redirectURL (id, payment_id, svcd, acs, ...)
                // are NOT part of that signature, so verify against the POST body only
                // — otherwise those extra params poison the HMAC and every legitimate
                // response is rejected.
                $signed = $this->_request->getPost()->toArray();
                if (empty($signed['signature']) || !$this->_helper->verifySignature($signed, $settings['signature_key'])) {
                    $error = (string) __('Invalid or missing gateway response signature.');
                }
            }
        }

        // This controller is the gateway->browser return path for the hosted, modal and
        // embedded integration types. It carried no logging at all, so with Debug enabled
        // an entire embedded-mode payment produced nothing in open_payment_network.log and
        // there was no way to diagnose a failed one after the fact.
        // $settings is [] when validateOrder() already failed, so guard rather than
        // concatenating onto an empty value (which produced a "redirect_redirect" tag).
        $logType = !empty($settings['integration_type'])
            ? $settings['integration_type'] . '_redirect'
            : 'redirect';
        $this->_helper->log(
            "Payment #" . $order_id,
            $logType,
            $this->_request->getParams(),
            'Gateway callback received'
        );

        if ($error) {
            $this->_helper->log("Payment #" . $order_id, $logType, ['error' => $error], 'Callback rejected');
        }

        if (!$error) {

            $saveCard = isset($response['svcd']) && $response['svcd'] === 'Y' && $order->getCustomerId();
            $cres = !empty($response['cres']) && !empty($response['threeDSAcsResponse']) ? $response['cres'] : null;

            unset($response['id'],$response['payment_id'], $response['language'], $response['svcd'], $response['acs'], $response['threeDSAcsResponse'], $response['emb']);

            $additionalInfo = $payment->getAdditionalInformation();
            $method = $additionalInfo['method'] ?? 'card';
            
            if(!empty($cres))
                $response = ['threeDSResponse' => ['cres' => $cres] ];

            if ((!empty($response['checkoutRef']) && $method == 'paypal') || 
                !empty($response['threeDSMethodData']) || 
                !empty($response['threeDSResponse'])) {

                if(!empty($response['checkoutRef'])){
                    $request = [
                        'checkoutRef' => $response['checkoutRef'],
                        'checkoutResponse' => $response,
                    ];

                } else {
                    $request = [
                        'merchantID'      => $settings['gateway_id'],
                        'action'          => strtoupper($settings['purchase_type'] ?? 'SALE'),
                        'threeDSRef'      => $additionalInfo['redirect_3d_auth']['ref'] ?? '',
                        'threeDSResponse' => !empty($response['threeDSResponse']) ? $response['threeDSResponse'] : $response,
                    ];

                    $request['signature'] = $this->_helper->createSignature($request, $settings['signature_key']) . '|' . implode(",", array_keys($request));
                }

                $this->_helper->log("Payment #" . $order_id, $logType, $request, 'Continuation request');
                $response = $this->_helper->httpRequest('POST', $settings['gateway_url'] . 'direct/', $request);
                $this->_helper->log("Payment #" . $order_id, $logType, $response, 'Continuation response');
            }

            list($success, $responseError, $status, $redirectUrl) = $this->_helper->validateResponse($response, $settings['signature_key'], 'R');

            $this->_helper->log(
                "Payment #" . $order_id,
                $logType,
                ['status' => $status, 'success' => $success, 'error' => $responseError],
                'Classified'
            );

            // If gateway returned an error and payment is NOT success, set error
            if ($responseError && $status !== 'success') {
                $error = $responseError;
            }

            // ✅ FIX: Ensure no leftover error is shown on success pages
            if ($status === 'success') {
                $error = false;
            }

            $this->_helper->saveTransaction($payment, $settings['integration_type'], $settings['purchase_type'] ?? 'sale', $status, $response);

            if($status == 'pending'){
                echo $this->_helper->threeDsAuthForm($response['threeDSURL'],$response['threeDSRequest']);
                exit;
            }
            
            if($success && $saveCard && $status == 'success') {
                $this->_helper->saveCard($order->getCustomerId(), $response);
            }

            if($status == 'success') {
    $this->_helper->sendInvoice($order);

    // ✅ Ensure Apple Pay billing/shipping addresses are saved in customer address book
    try {
        $quote = $this->_objectManager->create(\Magento\Quote\Model\Quote::class)->load($order->getQuoteId());

        if ($quote && $quote->getCustomerId()) {

            // Billing Address
            if ($quote->getBillingAddress()) {
                $quote->getBillingAddress()->setSaveInAddressBook(1);
            }

            // Shipping Address (if not virtual)
            if (!$quote->isVirtual() && $quote->getShippingAddress()) {
                $quote->getShippingAddress()->setSaveInAddressBook(1);
            }

            $quote->save();
        }
    } catch (\Exception $e) {
        // do not block checkout flow if address book save fails
    }
}

            

            if($status == 'cancelled') {
                $order->setState(Order::STATE_CANCELED)->setStatus(Order::STATE_CANCELED);
                $order->save();
            }
        }

        // Wallet payments (Apple Pay / Google Pay) don't have a "cart" for the customer
        // to fix and resubmit — the product-page/mini-cart Buy Now quote isn't their real
        // cart, and the wallet sheet is already closed. Only restore-quote-and-go-to-cart
        // for the card/direct flow, which this recovery was originally built for.
        $walletMethod = in_array($method ?? '', ['applepay', 'googlepay'], true);

        if ($error && !$walletMethod) {
            if ($order && $order->getId()) {
                $quote = $this->_objectManager->create(\Magento\Quote\Model\Quote::class)->load($order->getQuoteId());
                if ($quote->getId()) {
                    $quote->setIsActive(true);
                    $quote->setReservedOrderId(null);
                    $quote->save();

                    $checkoutSession = $this->_objectManager->get(\Magento\Checkout\Model\Session::class);
                    $checkoutSession->replaceQuote($quote);
                    $checkoutSession->setQuoteIdForRestore($quote->getId());
                }
            }
        }

        // Stamp the order ID in the checkout session so the guest-order IDOR check
        // in Controller/Order/Index passes for the legitimate post-payment redirect.
        if ($order && $order->getId()) {
            $this->_objectManager->get(\Magento\Checkout\Model\Session::class)
                ->setLastOrderId($order->getId());
        }

        if ($error) {
            if ($walletMethod && $order && $order->getId()) {
                $this->messageManager->addErrorMessage($error);

                // Show the customer what happened on the order status page instead of
                // dumping them on an unrelated (and likely empty) cart page.
                $targetUrl = $this->_url->getUrl('opn/order/index/id/' . $order_id, ['_query' => ['msg' => $error]]);
            } else {
                // Controller/Payment/Basket shows the reason from a top-window request. Added
                // here it is lost in Embedded mode: this response is a frame navigation the
                // gateway started, so the browser drops Magento's SameSite=Strict message cookie.
                $this->_objectManager->get(\Magento\Checkout\Model\Session::class)
                    ->setData(\OPN\Payment\Controller\Payment\Basket::SESSION_ERROR_KEY, $error);
                $targetUrl = $this->_url->getUrl('opn/payment/basket');
            }
        } else {
            $targetUrl = $this->_url->getUrl('opn/order/index/id/' . $order_id);
        }

        if ($embedded) {
            // This response is being rendered INSIDE the embedded iframe. A 302 would load the
            // order-status / cart page in that frame, on top of the finished checkout; hand
            // the browser a page whose only job is to move the TOP window there instead.
            $raw = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $raw->setContents(
                '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
                . htmlspecialchars((string)__('Redirecting'), ENT_QUOTES, 'UTF-8') . '</title></head><body>'
                . '<script>window.top.location.replace(' . json_encode($targetUrl, PaymentHelper::JS_JSON_FLAGS) . ');</script>'
                . '<noscript><a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '" target="_top">'
                . htmlspecialchars((string)__('Continue'), ENT_QUOTES, 'UTF-8') . '</a></noscript>'
                . '</body></html>'
            );
            return $raw;
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setUrl($targetUrl);
        return $redirect;
    }

    private function validateOrder($orderId){
        
        $error = false;
        $_order = false;
        $_payment = null;
        $order = !empty($orderId) ? $this->_objectManager->get('Magento\Sales\Model\Order')->load($orderId) : null;
        
        if($order){
            $_payment = $order->getPayment();
            $payment_code = $_payment ? $_payment->getMethodInstance()->getCode() : null;
            
            if($payment_code == "qidigitalpayment"){
               $_order = $order;
            } else {
                $error = "Invalid order payment request";
            }
        } else {
            $error = "Invalid Order";
        }
        
        return [$_order,$_payment,$error];
    }
}