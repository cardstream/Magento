<?php

namespace OPN\Payment\Block\Shortcut;

use Magento\Catalog\Block\ShortcutInterface;
use OPN\Payment\Helper\Data as PaymentHelper;

class Buttons extends \Magento\Framework\View\Element\Template implements ShortcutInterface
{
    protected $_helper;

    public $settings = [];

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        PaymentHelper $helper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_helper = $helper;
        $this->settings = $this->_helper->getSettings();
    }

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('OPN_Payment::shortcut/buttons.phtml');
    }

    public function getAlias()
    {
        return 'opn_express_shortcut';
    }

    public function checkMethod($method)
    {
        return in_array($method, $this->settings['methods'])
            && !empty($this->settings[str_replace('pay#', '', $method . '#') . '_enable_cart']);
    }

    public function isDirectMode(): bool
    {
        return ($this->settings['integration_type'] ?? '') === 'direct';
    }

    public function getExpressConfig(): array
    {
        $s   = $this->settings;
        $om  = \Magento\Framework\App\ObjectManager::getInstance();
        $url = $om->get('Magento\Framework\UrlInterface');

        // Merchant's country, not the store default and not the shopper's — see
        // Helper\Data::getMerchantCountryCode().
        $merchantCountry = $this->_helper->getMerchantCountryCode();

        $cfg = [
            'currency'      => $om->get('Magento\Store\Model\StoreManagerInterface')->getStore()->getCurrentCurrencyCode(),
            'country_code'  => $merchantCountry,
            'show_applepay' => $this->checkMethod('applepay'),
            'show_googlepay'=> $this->checkMethod('googlepay'),
            'urls' => [
                'get_totals'     => $url->getUrl('opn/cart/express', ['action' => 'get_totals']),
                'get_shippings'  => $url->getUrl('opn/cart/express', ['action' => 'get_shippings']),
                'place_order'    => $url->getUrl('opn/cart/express', ['action' => 'place_order']),
                'verify_merchant'=> $url->getUrl('opn/order/process', ['action' => 'verify_merchant']),
            ],
            'googlepay' => [
                'countryCode'         => $merchantCountry,
                'allowed_cards'       => $s['google_allowed_cards'] ?? ['VISA', 'MASTERCARD'],
                'gateway_provider'    => $s['google_gateway_identifier'] ?? '',
                'merchant_id'         => $s['gateway_id'] ?? '',
                'google_environment'  => $s['google_environment'] ?? 'TEST',
                'google_merchant_name'=> $s['google_merchant_name'] ?? '',
                'google_merchant_id'  => $s['google_merchant_id'] ?? '',
            ],
            'applepay' => [
                'request' => [
                    'countryCode'           => $merchantCountry,
                    'currencyCode'          => $om->get('Magento\Store\Model\StoreManagerInterface')->getStore()->getCurrentCurrencyCode(),
                    'merchantCapabilities'  => ['supports3DS'],
                    'supportedNetworks'     => array_map('strtolower', $s['apple_allowed_cards'] ?? ['visa', 'masterCard']),
                    'total'                 => ['label' => $s['apple_merchant_name'] ?? '', 'amount' => '0.00'],
                ],
            ],
        ];

        return $cfg;
    }
}
