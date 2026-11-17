<?php

namespace OPN\Payment\Block\Catalog\Product;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session;
use \Magento\Customer\Helper\Session\CurrentCustomerAddress;
use OPN\Payment\Helper\Data as PaymentHelper;

class Buttons extends \Magento\Framework\View\Element\Template{
    
    protected $_helper;
    protected $storeConfig;
    protected $customerSession;
    protected $customerAddress;

    public $settings = [];

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        StoreManagerInterface $storeConfig,
        Session $customerSession,
        CurrentCustomerAddress $customerAddress,
        PaymentHelper $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_helper = $helper;
        $this->storeConfig = $storeConfig;
        $this->customerSession = $customerSession;
        $this->customerAddress = $customerAddress;
        $this->settings = $this->_helper->getSettings();
    }

    /**
     * The MERCHANT's country, for the Apple Pay sheet's `countryCode` and Google Pay's
     * `transactionInfo.countryCode`. Both specs define this as where the merchant trades,
     * not where the shopper lives.
     *
     * This used to return the logged-in customer's default billing country, so the gateway
     * received the shopper's country and ignored the configured Country Code entirely.
     */
    public function getCountryCode(){
        return $this->_helper->getMerchantCountryCode();
    }

    public function isLogged(){
        return (int)($this->customerSession->getCustomer()->getId() ?? 0) ? true : false;
    }
    
    public function getCurrency(){
        return $this->storeConfig->getStore()->getCurrentCurrencyCode();
    }

    public function checkMethod($method){
        return in_array($method,$this->settings['methods']) && !empty($this->settings[str_replace('pay#','',$method.'#').'_enable_product']);
    }

}
