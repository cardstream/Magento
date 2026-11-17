<?php

namespace OPN\Payment\Block\Checkout;

use Magento\Checkout\Model\Session;
use OPN\Payment\Helper\Data as PaymentHelper;

class Success extends \OPN\Payment\Block\Order{
    
    protected $_helper;
    protected $objectManager;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        PaymentHelper $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        
        $this->_helper = $helper;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $orderId = (int)$checkoutSession->getLastOrderId();
        
        $storeManager = $this->objectManager->get('\Magento\Store\Model\StoreManagerInterface');

        list($order, $payment, $error) = $this->validateOrder($orderId);

        $data = [];
        $data['orderId'] = $orderId;
        $data['order']   = $order;
        $data['payment'] = $payment;
        $data['error']   = $error;
        $data['baseUrl'] = $storeManager->getStore()->getBaseUrl();
        $data['helper']  = $this->_helper;
        
        $this->setOrderData($data);
    }

    private function validateOrder($orderId){
        
        $error = false;
        $_order = false;
        $_payment = null;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $order = !empty($orderId) ? $this->objectManager->get('Magento\Sales\Model\Order')->load($orderId) : null;
        
        if( $order ){

            $_payment = $order->getPayment();
            $payment_code = null;
            if( $_payment )
                $payment_code = $_payment->getMethodInstance()->getCode();
            
            if( $payment_code == "qidigitalpayment" ){
               $_order = $order;

            } else
                $error = "Invalid order payment request"; 

        } else 
            $error = "Invalid Order";

        return [$_order,$_payment, $error];
    }
}
