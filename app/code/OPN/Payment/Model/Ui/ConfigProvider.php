<?php

namespace OPN\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Customer\Model\Session;
use \Magento\Customer\Helper\Session\CurrentCustomerAddress;
use OPN\Payment\Helper\Data as PaymentHelper;
use OPN\Payment\Model\Payment\PaymentCards;

class ConfigProvider implements ConfigProviderInterface
{
    const CODE = 'qidigitalpayment';

    protected $helper;
    protected $scopeConfig;
    protected $storeConfig;
    protected $customerSession;
    protected $customerAddress;
    protected $paymentCards;

    public function __construct(
        PaymentHelper $helper,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeConfig,
        Session $customerSession,
        CurrentCustomerAddress $customerAddress,
        PaymentCards $paymentCards
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->storeConfig = $storeConfig;
        $this->customerSession = $customerSession;
        $this->customerAddress = $customerAddress;
        $this->paymentCards = $paymentCards;
    }

    public function getConfig()
    {

        $currentCurrency = $this->storeConfig->getStore()->getCurrentCurrencyCode();

        // The MERCHANT's country. Apple Pay's `countryCode` and Google Pay's
        // `transactionInfo.countryCode` both mean where the merchant trades — this used to
        // read the shopper's default billing address, which is what put the customer's
        // country in the gateway request instead of the configured Country Code.
        $countryCode = $this->helper->getMerchantCountryCode();

        $settings = $this->helper->getSettings();
        $customerId = (int)($this->customerSession->getCustomer()->getId() ?? 0);
        $saved_cards = !empty($customerId) ? $this->paymentCards->getAll($customerId) : [];
        $has_cards = !empty($saved_cards);
        
        $methods = $settings['methods'] ?? [];
        if( $settings['integration_type'] != 'direct' ){
            // Card (and the APMs) live on the gateway's own form in these modes, but Apple Pay
            // and Google Pay do not need Hosted Fields: their tokens are ALWAYS processed
            // through the Direct API (DataAssignObserver), exactly as the cart and product page
            // already do in every mode. Forcing ['card'] here is what hid both wallet buttons
            // from the checkout in Modal / Hosted / Embedded (reported 21/09/2026).
            $methods = array_merge(['card'], array_values(array_intersect($methods, ['applepay', 'googlepay'])));
        }
        
        $methods_config = [];
        $payment_options = [];

        if( in_array('ppro',$methods) )
            $payment_options['ppro'] = $this->helper->getPaymentOptions('ppro');
        
        if( in_array('nomupay',$methods) )
            $payment_options['nomupay'] = $this->helper->getPaymentOptions('nomupay');

        if( in_array('googlepay',$methods) ){
            $methods_config['googlepay'] = [
                'countryCode' => $countryCode,
                'allowed_cards' => $settings['google_allowed_cards'] ?? [],
                'gateway_provider' => $settings['google_gateway_identifier'] ?? '', 
                'merchant_id' => $settings['gateway_id'] ?? '',
                'google_environment' => $settings['google_environment'] ?? 'TEST',
                'google_merchant_name' => $settings['google_merchant_name'] ?? '',
                'google_merchant_id' => $settings['google_merchant_id'] ?? '',
            ];
        }

        if( in_array('applepay',$methods) ){

            $total = 0;
            $methods_config['applepay'] = [
                'countryCode' => $countryCode,
                'currencyCode' => $currentCurrency,
                "merchantCapabilities" => [ "supports3DS" ],
                // A guest can reach the payment step with the billing form open and empty;
                // Magento then refuses to place the order and its field-level validation
                // never surfaces as an error the wallet code can see (confirmed 31/08/2026).
                // The sheet collects the card's billing address so opn.js can fill the
                // quote's billing address before placeOrder().
                "requiredBillingContactFields" => [ "postalAddress", "name" ],
                "supportedNetworks" => array_map('strtolower',$settings['apple_allowed_cards']),
                "total" => [
                    "label" => $settings['apple_merchant_name'] ?? '',
                    "amount" => $total,
                ]
            ];
        }

        $gateway_url = $settings['gateway_url'];
        if( in_array($settings['integration_type'] ?? '',['hosted','embedded']) && !empty($settings['queue_gateway_url']) )
            $gateway_url = trim(trim($settings['queue_gateway_url']),'/').'/';

        return [
            'payment' => [
                self::CODE => [
                    'title' => $settings['title'] ?? '',
                    'gateway_url' => $gateway_url,
                    'merchant_id' => $settings['gateway_id'] ?? '',
                    'integration_type' => $settings['integration_type'] ?? '',
                    'save_card_enabled' =>  !empty($customerId) && !empty($settings['save_card_enabled']) ? true : false,
                    'methods' => $methods,
                    'has_cards' => $has_cards,
                    'saved_cards' => $saved_cards,
                    'currency' => $currentCurrency,
                    'methods_config' => $methods_config,
                    'verify_url' => \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface')->getUrl("opn/order/process/action/verify_merchant"),
                    'embedded_data_url' => \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface')->getUrl("opn/payment/embeddeddata"),
                    'basket_url' => \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface')->getUrl("opn/payment/basket"),
                    'payment_options' => $payment_options,
                ]
            ]
        ];
    }

}
