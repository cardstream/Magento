<?php
namespace OPN\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\ResultFactory;
use OPN\Payment\Helper\Data as PaymentHelper;

class EmbeddedData extends Action
{
    protected $checkoutSession;
    protected $orderFactory;
    protected $helper;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        PaymentHelper $helper
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory    = $orderFactory;
        $this->helper          = $helper;
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $orderId = $this->checkoutSession->getLastOrderId();
            if (empty($orderId)) {
                return $result->setData(['success' => false, 'error' => 'No pending order found.']);
            }

            $order = $this->orderFactory->create()->load($orderId);
            if (!$order->getId()) {
                return $result->setData(['success' => false, 'error' => 'Order not found.']);
            }

            $payment = $order->getPayment();
            if ($payment->getMethodInstance()->getCode() !== 'qidigitalpayment') {
                return $result->setData(['success' => false, 'error' => 'Invalid payment method.']);
            }

            $settings = $this->helper->getSettings();

            // `emb=1` tells Controller/Redirect/Index.php that the gateway's return will land
            // INSIDE the embedded iframe, so it must break out to the top window rather than
            // 302. Query string only: the gateway signs its POST body, and this is not in it.
            $redirectUrl = $this->helper->getRedirectUrl($orderId, 'id', 'emb=1');

            $billing  = $order->getBillingAddress();
            $shipping = $order->getShippingAddress() ?: $billing;
            $bStreet  = $billing->getStreet();
            $sStreet  = $shipping->getStreet();

            $orderInfo = [
                'order_id'           => $order->getIncrementId(),
                'total'              => $order->getGrandTotal(),
                'currency'           => $order->getOrderCurrencyCode(),
                'email'              => $billing->getEmail() ?: '',
                'telephone'          => $billing->getTelephone() ?: '',
                'payment_firstname'  => ucfirst($billing->getFirstname() ?: ''),
                'payment_lastname'   => ucfirst($billing->getLastname()  ?: ''),
                'payment_address_1'  => is_array($bStreet) ? ($bStreet[0] ?? '') : $bStreet,
                'payment_address_2'  => is_array($bStreet) ? ($bStreet[1] ?? '') : '',
                'payment_city'       => $billing->getCity() ?: '',
                'payment_town'       => $billing->getCity() ?: '',
                'payment_region'     => $billing->getRegion() ?: '',
                'payment_county'     => $billing->getRegion() ?: '',
                'payment_postcode'   => $billing->getPostcode() ?: '',
                'payment_country'    => $billing->getCountryId() ?: '',
                'shipping_firstname' => ucfirst($shipping->getFirstname() ?: ''),
                'shipping_lastname'  => ucfirst($shipping->getLastname()  ?: ''),
                'shipping_address_1' => is_array($sStreet) ? ($sStreet[0] ?? '') : $sStreet,
                'shipping_address_2' => is_array($sStreet) ? ($sStreet[1] ?? '') : '',
                'shipping_city'      => $shipping->getCity() ?: '',
                'shipping_town'      => $shipping->getCity() ?: '',
                'shipping_region'    => $shipping->getRegion() ?: '',
                'shipping_county'    => $shipping->getRegion() ?: '',
                'shipping_postcode'  => $shipping->getPostcode() ?: '',
                'shipping_country'   => $shipping->getCountryId() ?: '',
            ];

            $request = $this->helper->prepareRequest($orderInfo, $settings, $redirectUrl);

            $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                  ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                  ->save();

            // Embedded = the ordinary Hosted Payment Form (Cardstream, 31/08/2026), POSTed by the
            // checkout JS into an iframe on the page. Same resolver as the Hosted redirect, so
            // the Custom Hosted Form URL override cannot drift between the two.
            return $result->setData([
                'success'     => true,
                'gateway_url' => $this->helper->getHostedFormUrl($settings, 'hosted/'),
                'request'     => $request,
            ]);

        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
