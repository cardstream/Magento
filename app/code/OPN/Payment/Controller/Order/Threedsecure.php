<?php
namespace OPN\Payment\Controller\Order;

use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use OPN\Payment\Helper\Data as PaymentHelper;

/**
 * Entry point for the browser-driven 3DS state machine (view/frontend/web/js/threeds.js),
 * used for both wallet (Apple Pay / Google Pay) and card/direct payments that the
 * gateway put on hold pending 3-D Secure authentication (responseCode 65802).
 * This action only renders the page and hands off the initial threeDSURL /
 * threeDSRequest / threeDSRef to the JS — it never talks to the gateway itself.
 * Controller/Order/Continue3ds.php is the only place that does that.
 */
class Threedsecure extends \Magento\Framework\App\Action\Action
{
    protected $_objectManager;
    protected $_request;
    protected $_helper;
    protected $customerSession;
    protected $checkoutSession;
    protected $assetRepo;
    protected $storeManager;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        AssetRepository $assetRepo,
        StoreManagerInterface $storeManager,
        PaymentHelper $helper
    ) {
        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->assetRepo = $assetRepo;
        $this->storeManager = $storeManager;

        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_request = $this->_objectManager->get('Magento\Framework\App\RequestInterface');
        $this->_helper = $helper;

        parent::__construct($context);
    }

    public function execute()
    {
        $orderId = (int)$this->_request->getParam('id');

        list($order, $payment, $error) = $this->validateOrder($orderId);

        if (!$error) {
            $customerId = $order->getCustomerId();
            if ($customerId && $this->customerSession->isLoggedIn()) {
                if ($this->customerSession->getCustomerId() != $customerId) {
                    return $this->_redirect('customer/account/');
                }
            }
            // Same guest-order ownership rule as Controller/Order/Index.php (N02):
            // only allow access if this is the session's last order.
            if (!$customerId) {
                $lastOrderId = $this->checkoutSession->getLastOrderId();
                if (!$lastOrderId || (int)$lastOrderId !== $orderId) {
                    return $this->_redirect('noroute');
                }
            }
        }

        if ($error) {
            return $this->_redirect('noroute');
        }

        $additionalInfo = $payment->getAdditionalInformation();
        $pending3ds = $additionalInfo['redirect_3d_auth'] ?? [];

        if (empty($pending3ds['url'])) {
            if (($additionalInfo['status'] ?? '') === 'success') {
                // Already completed (e.g. a second tab, or a reload after finishing) —
                // go straight to the real success page, same as Continue3ds.php.
                return $this->_redirect($this->_helper->redirectToCheckoutSuccess($order));
            }
            // Not successful, and nothing pending — fall back to the order status page,
            // which correctly shows whatever real outcome this order has.
            return $this->_redirect('opn/order/index/id/' . $orderId);
        }

        $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
              ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $order->save();

        $jsUrl = $this->assetRepo->getUrl('OPN_Payment::js/threeds.js');
        $continueUrl = $this->_objectManager->get('Magento\Framework\UrlInterface')->getUrl('opn/order/continue3ds');
        $origin = rtrim($this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB), '/');

        $config = json_encode([
            'orderId' => $orderId,
            'continueUrl' => $continueUrl,
            'origin' => $origin,
            'threeDSURL' => $pending3ds['url'],
            'threeDSRequest' => $pending3ds['request'] ?? [],
            'threeDSRef' => $pending3ds['ref'] ?? '',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $this->getResponse()->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $this->getResponse()->setBody(
            '<!DOCTYPE html><html><head><meta charset="utf-8">' .
            '<title>' . htmlspecialchars((string)__('Verifying payment'), ENT_QUOTES, 'UTF-8') . '&hellip;</title>' .
            '<meta name="viewport" content="width=device-width, initial-scale=1"/>' .
            '<style>body{font-family:sans-serif;text-align:center;padding-top:60px;color:#555;}' .
            '#opn3ds-error{color:#c62828;display:none;max-width:420px;margin:20px auto 0;}' .
            '#opn3ds-container{margin-top:20px;}</style>' .
            '</head><body>' .
            '<h4 id="opn3ds-status">' . htmlspecialchars((string)__('Verifying your payment'), ENT_QUOTES, 'UTF-8') . '&hellip;</h4>' .
            '<div id="opn3ds-error"></div>' .
            '<div id="opn3ds-container"></div>' .
            '<script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '"></script>' .
            '<script>' .
            'var opn3dsConfig = ' . $config . ';' .
            'var opn3ds = new window.OPN3DS({' .
            '    orderId: opn3dsConfig.orderId,' .
            '    continueUrl: opn3dsConfig.continueUrl,' .
            '    origin: opn3dsConfig.origin,' .
            '    container: document.getElementById("opn3ds-container"),' .
            '    onError: function (msg) {' .
            '        document.getElementById("opn3ds-status").style.display = "none";' .
            '        var el = document.getElementById("opn3ds-error");' .
            '        el.textContent = msg;' .
            '        el.style.display = "block";' .
            '    }' .
            '});' .
            'opn3ds.start(opn3dsConfig.threeDSURL, opn3dsConfig.threeDSRequest, opn3dsConfig.threeDSRef);' .
            '</script>' .
            '</body></html>'
        );

        return $this->getResponse();
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
