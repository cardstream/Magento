<?php
namespace OPN\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Customer\Model\Session as CustomerSession;
use OPN\Payment\Helper\Data as PaymentHelper;

class Redirect extends Action
{
    protected $orderFactory;
    protected $customerSession;
    protected $helper;

    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        CustomerSession $customerSession,
        PaymentHelper $helper
    ) {
        parent::__construct($context);
        $this->orderFactory    = $orderFactory;
        $this->customerSession = $customerSession;
        $this->helper          = $helper;
    }

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->messageManager->addErrorMessage(__('Please log in to continue.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('customer/account/login');
        }

        $orderId = $this->getRequest()->getParam('order_id');
        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Order id missing.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl('/');
        }

        $order = $this->orderFactory->create()->loadByIncrementId($orderId);
        if (!$order->getId()) {
            $this->messageManager->addErrorMessage(__('Order not found.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setUrl('/');
        }

        if ((int)$order->getCustomerId() !== (int)$this->customerSession->getCustomerId()) {
            $this->messageManager->addErrorMessage(__('You do not have permission to access this order.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('customer/account');
        }

        $settings    = $this->helper->getSettings();
        $redirectUrl = $this->helper->getSuccessUrl($order->getId());

        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress() ?: $billing;
        $bStreet  = $billing  ? $billing->getStreet()  : [];
        $sStreet  = $shipping ? $shipping->getStreet() : [];

        $orderInfo = [
            'order_id'           => $order->getIncrementId(),
            'total'              => $order->getGrandTotal(),
            'currency'           => $order->getOrderCurrencyCode(),
            'email'              => $order->getCustomerEmail() ?: '',
            'telephone'          => $billing ? $billing->getTelephone() : '',
            'payment_firstname'  => $billing ? $billing->getFirstname() : '',
            'payment_lastname'   => $billing ? $billing->getLastname()  : '',
            'payment_address_1'  => is_array($bStreet) ? ($bStreet[0] ?? '') : $bStreet,
            'payment_address_2'  => is_array($bStreet) ? ($bStreet[1] ?? '') : '',
            'payment_city'       => $billing ? $billing->getCity()       : '',
            'payment_town'       => $billing ? $billing->getCity()       : '',
            'payment_county'     => $billing ? $billing->getRegion()     : '',
            'payment_postcode'   => $billing ? $billing->getPostcode()   : '',
            'payment_country'    => $billing ? $billing->getCountryId()  : '',
            'shipping_firstname' => $shipping ? $shipping->getFirstname() : '',
            'shipping_lastname'  => $shipping ? $shipping->getLastname()  : '',
            'shipping_address_1' => is_array($sStreet) ? ($sStreet[0] ?? '') : $sStreet,
            'shipping_address_2' => is_array($sStreet) ? ($sStreet[1] ?? '') : '',
            'shipping_city'      => $shipping ? $shipping->getCity()       : '',
            'shipping_town'      => $shipping ? $shipping->getCity()       : '',
            'shipping_county'    => $shipping ? $shipping->getRegion()     : '',
            'shipping_postcode'  => $shipping ? $shipping->getPostcode()   : '',
            'shipping_country'   => $shipping ? $shipping->getCountryId()  : '',
        ];

        $request    = $this->helper->prepareRequest($orderInfo, $settings, $redirectUrl);
        $gatewayUrl = rtrim($settings['gateway_url'], '/') . '/hosted/';
        $html       = $this->helper->renderForm($gatewayUrl, $request);

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($html);
        return $result;
    }
}
