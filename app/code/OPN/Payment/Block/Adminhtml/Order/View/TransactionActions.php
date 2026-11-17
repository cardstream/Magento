<?php

namespace OPN\Payment\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Magento\Framework\Pricing\PriceCurrencyInterface;

use OPN\Payment\Helper\Data as PaymentHelper;

class TransactionActions extends Template
{
    private Registry $registry;
    protected $_helper;
    protected $_currency;

    public function __construct(
        Context $context,
        Registry $registry,
        PriceCurrencyInterface $priceCurrency,
        PaymentHelper $helper,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->_currency = $priceCurrency;
        $this->_helper = $helper;
        $helper->currency = $priceCurrency;
       
        parent::__construct($context, $data);
    }

    public function getOrder(): ?Order
    {
        return $this->registry->registry('current_order');
    }

    public function getPaymentDetails()
    {
        $order = $this->getOrder();
        if (!$order || ($order && !$order->getPayment()) )
            return false;

        $payment = $order->getPayment();
        $paymentInfo = $payment->getAdditionalInformation();

       $paymentInfo['action_url'] = $this->getUrl(
            'opn_payment/transaction/ajax',
            ['order_id' => $order ? $order->getId() : 0]
        );


        return $this->_helper->getTransaction($paymentInfo, true);

    }
}
