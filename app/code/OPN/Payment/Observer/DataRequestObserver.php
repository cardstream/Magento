<?php

namespace OPN\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface; 
use OPN\Payment\Helper\Data as PaymentHelper;

class DataRequestObserver extends AbstractDataAssignObserver
{

    protected $_orderFactory;
    protected $_checkoutSession;
    protected $_request;
    protected $_helper;
    
    public function __construct(
        OrderFactory $orderFactory,
        CheckoutSession $checkoutSession,
        RequestInterface $request,
        PaymentHelper $helper
    ) {
        $this->_orderFactory = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->_request = $request;
        $this->_helper = $helper;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer){

        $request = $observer->getData('request');

        // A shopper who walks away from the gateway's form (browser Back, or just returning to
        // the store) found an empty basket, because the order already exists. Hand the basket
        // back the first time they reach the cart or checkout again. Never over a basket they
        // have since started: restoreQuote() replaces the session's quote.
        if ($request->getModuleName() == 'checkout'
            && $request->getActionName() == 'index'
            && in_array($request->getControllerName(), ['cart', 'index'], true)
        ) {
            try {
                if ($this->_helper->isAbandonedHostedOrder($this->_checkoutSession->getLastRealOrder())
                    && !$this->_checkoutSession->getQuote()->hasItems()
                ) {
                    $this->_checkoutSession->restoreQuote();
                }
            } catch (\Exception $e) {
                // Basket recovery must never take the cart or checkout page down.
            }
            return 1;
        }

        if($request->getModuleName() == "checkout" && $request->getActionName() == "success"){

            $orderId = $this->_checkoutSession->getLastOrderId();
            if( empty($orderId) )
                return 1;

            $order = $this->_orderFactory->create()->load($orderId);
            
            $payment = $order->getPayment();
            $payment_code = $payment->getMethodInstance()->getCode();

            if( $payment_code == 'qidigitalpayment' ){

                $settings = $this->_helper->getSettings();
                $integration_type = $settings['integration_type'] ?? '';

                $additionalInfo = $payment->getAdditionalInformation();  

                // Check if payment is already completed - ADD THIS CHECK
                $paymentStatus = $additionalInfo['status'] ?? '';
                if ($paymentStatus === 'success') {
                    // Payment is already successful, don't trigger hosted flow again
                    $this->_helper->sendInvoice($order);
                    return 1;
                }

                // Check if we're coming back from 3DS authentication - ADD THIS CHECK
                if ($this->_request->getParam('acs') || $this->_request->getParam('threeDSAcsResponse')) {
                    // We're in the middle of 3DS flow, let the redirect controller handle it
                    return 1;
                }

                if(  $integration_type == 'direct' && !empty($additionalInfo['redirect_checkout']['url']) ){
                    $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
                    $order->save();

                    $form = $this->_helper->renderForm($additionalInfo['redirect_checkout']['url'],$additionalInfo['redirect_checkout']['request'],$additionalInfo['redirect_checkout']['action']);

                    $this->renderHtml($form);

                } else if( !empty($additionalInfo['redirect_3d_auth']['url']) ){
                    // Applies regardless of integration_type: any payment (card/direct or
                    // wallet) that came back pending 3DS must complete the ACS challenge —
                    // via the shared browser-driven 3DS flow in Controller/Order/Threedsecure.php —
                    // before any hosted/modal/embedded form logic starts a second, unrelated
                    // payment attempt.
                    $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
                    $order->save();

                    $threeDsUrl = \Magento\Framework\App\ObjectManager::getInstance()
                        ->get('Magento\Framework\UrlInterface')
                        ->getUrl('opn/order/threedsecure', ['id' => $orderId]);
                    header('Location: ' . $threeDsUrl);
                    exit;

                } else if( $integration_type == 'direct' ){

                    $this->_helper->sendInvoice($order);

                } else if( $integration_type == 'embedded' ){

                    // Embedded form is displayed by JS in the checkout page via afterPlaceOrder().
                    // Mark order as pending and return — JS fetches the signed request via
                    // Controller/Payment/EmbeddedData and renders the hostedForms iFrame.
                    $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
                    $order->save();
                    return 1;

                } else {

                    $billing = $order->getBillingAddress();
                    $shipping = $order->getShippingAddress() ?: $billing;

                    $total = (float)$order ->getGrandTotal();

                    // FIXED: Properly handle street addresses
                    $payment_street = $billing->getStreet();
                    $shipping_street = $shipping->getStreet();

                    $orderInfo = [
                        // The order NUMBER, as Embedded has always sent. This only feeds the
                        // gateway's orderRef / transactionUnique, which the shopper sees on the
                        // payment page and the merchant searches by in the MMS; the entity id
                        // ("Order #88" for order 000000097) matched neither.
                        'order_id' => $order->getIncrementId(),
                        'total' => $total,
                        'currency' => $order->getOrderCurrencyCode(), // Add currency
                        
                        // Billing Address - FIXED
                        'payment_firstname' => ucfirst($billing->getFirstname() ?: ''),
                        'payment_lastname' => ucfirst($billing->getLastname() ?: ''),
                        'payment_address_1' => is_array($payment_street) ? ($payment_street[0] ?? '') : $payment_street,
                        'payment_address_2' => is_array($payment_street) ? ($payment_street[1] ?? '') : '',
                        'payment_city' => $billing->getCity() ?: '',
                        'payment_town' => $billing->getCity() ?: '',
                        'payment_region' => $billing->getRegion() ?: '',
                        'payment_county' => $billing->getRegion() ?: '',
                        'payment_postcode' => $billing->getPostcode() ?: '',
                        'payment_country' => $billing->getCountryId() ?: '',
                        
                        // Shipping Address - FIXED
                        'shipping_firstname' => ucfirst($shipping->getFirstname() ?: ''),
                        'shipping_lastname' => ucfirst($shipping->getLastname() ?: ''),
                        'shipping_address_1' => is_array($shipping_street) ? ($shipping_street[0] ?? '') : $shipping_street,
                        'shipping_address_2' => is_array($shipping_street) ? ($shipping_street[1] ?? '') : '',
                        'shipping_city' => $shipping->getCity() ?: '',
                        'shipping_town' => $shipping->getCity() ?: '',
                        'shipping_region' => $shipping->getRegion() ?: '',
                        'shipping_county' => $shipping->getRegion() ?: '',
                        'shipping_postcode' => $shipping->getPostcode() ?: '',
                        'shipping_country' => $shipping->getCountryId() ?: '',
                        
                        'email' => $billing->getEmail() ?: '',
                        'telephone' => $billing->getTelephone() ?: '',
                    ];

                    $this->handlePayment($orderId, $order, $orderInfo , $settings );
                }
            }
        }
    }

    public function handlePayment($order_id, $order, $data, $settings ){

        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
        $order->save();

        $redirect_url = $this->_helper->getRedirectUrl($order_id);
                
        $request = $this->_helper->prepareRequest($data, $settings, $redirect_url);

        $form = $this->_helper->renderHTML('H', $order_id, $settings, $request);

        // Hosted leaves the store, so this page can be reached again with the Back button.
        // (The Modal lives ON this page; reloading it must simply reopen the modal.)
        $returnUrl = ($settings['integration_type'] ?? '') === 'hosted'
            ? \Magento\Framework\App\ObjectManager::getInstance()
                ->get('Magento\Framework\UrlInterface')
                ->getUrl('opn/payment/basket')
            : null;

        $this->renderHtml($form, $returnUrl, (int)$order_id);
    }

    public function renderHtml($form, $returnUrl = null, $orderId = 0){
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?php echo __('Initializing payment, please wait');?>...</title>
        <meta name="viewport" content="width=device-width, initial-scale=1"/>
        <script type="text/javascript">
            (function(){
                <?php if ($returnUrl) { ?>
                // Running a second time for the same order means the shopper pressed Back on the
                // gateway's page. Submitting again would bounce them straight back to it, so
                // leave through the store, which puts the basket back (Controller/Payment/Basket).
                var back = <?= json_encode($returnUrl, PaymentHelper::JS_JSON_FLAGS) ?>;
                var seen = 'opn_handoff_<?= (int)$orderId ?>';
                window.addEventListener('pageshow', function(e){ if (e.persisted) { window.location.replace(back); } });
                try {
                    if (sessionStorage.getItem(seen)) { window.location.replace(back); return; }
                    sessionStorage.setItem(seen, '1');
                } catch (e) {}
                <?php } ?>
                var timer = setInterval(function(){
                    var btn = document.querySelector('.btn-pay-now');
                    if(btn && !btn.classList.contains('clkd')){
                        btn.classList.add('clkd');
                        btn.click();
                        clearInterval(timer);
                    }
                }, 100);
            })();
        </script>
    </head>
    <body>
        <div style="text-align: center;">
            <h4><?php echo __('Initializing payment, please wait');?>...</h4>
            <div style="display: none;">
                <?php echo $form; ?>
            </div>
        </div>
    </body>
    </html>  
    <?php       
        exit;
    }

}