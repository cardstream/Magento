<?php

namespace OPN\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use OPN\Payment\Helper\Data as PaymentHelper;
use OPN\Payment\Model\Payment\PaymentCards;

class DataAssignObserver extends AbstractDataAssignObserver
{
    protected $_checkoutSession;
    protected $resultFactory;
    protected $_request;
    protected $_helper;
    protected $paymentCards;

    public function __construct(
        CheckoutSession $checkoutSession,
        ResultFactory $resultFactory,
        RequestInterface $request,
        PaymentHelper $helper,
        PaymentCards $paymentCards
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->resultFactory = $resultFactory;
        $this->_request = $request;
        $this->_helper = $helper;
        $this->paymentCards = $paymentCards;
    }

    /**
     * Process Apple Pay token that comes through Magento's Web API
     */
    private function processApplePayToken($token)
    {
        // Debug: Log what we're receiving
        $this->_helper->log("Apple Pay Token Processing", "token_input", [
            'token_type'   => gettype($token),
            'token_length' => is_string($token) ? strlen($token) : 0,
            'is_base64'    => (is_string($token) && base64_decode($token, true) !== false),
        ], 'Debug');

        // If token is base64 encoded string from frontend
        if (is_string($token) && base64_decode($token, true) !== false) {
            $decoded = base64_decode($token);
            $tokenData = json_decode($decoded, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($tokenData)) {
                $this->_helper->log("Apple Pay Base64 Decoded", "decoded_structure", [
                    'keys' => array_keys($tokenData),
                ], 'Debug');
                
                // Try different possible structures
                
                // Structure 1: {token: {paymentData: {...}}}
                if (isset($tokenData['token']) && is_array($tokenData['token'])) {
                    if (isset($tokenData['token']['paymentData']) && is_array($tokenData['token']['paymentData'])) {
                        $paymentDataJson = json_encode($tokenData['token']['paymentData'], JSON_UNESCAPED_SLASHES);
                        $this->_helper->log("Apple Pay Structure 1", "structure_1_success", [
                            'payment_data_length' => strlen($paymentDataJson),
                        ], 'Debug');
                        return $paymentDataJson;
                    }
                }
                
                // Structure 2: Direct paymentData at root
                if (isset($tokenData['paymentData']) && is_array($tokenData['paymentData'])) {
                    $paymentDataJson = json_encode($tokenData['paymentData'], JSON_UNESCAPED_SLASHES);
                    $this->_helper->log("Apple Pay Structure 2", "structure_2_success", [
                        'payment_data_length' => strlen($paymentDataJson),
                    ], 'Debug');
                    return $paymentDataJson;
                }
                
                // Structure 3: The token itself might be the paymentData
                if (isset($tokenData['data']) || isset($tokenData['header']) || isset($tokenData['version'])) {
                    $paymentDataJson = json_encode($tokenData, JSON_UNESCAPED_SLASHES);
                    $this->_helper->log("Apple Pay Structure 3", "structure_3_success", [
                        'payment_data_length' => strlen($paymentDataJson),
                    ], 'Debug');
                    return $paymentDataJson;
                }
            }
        }
        
        // If token is already a JSON string (not base64)
        if (is_string($token)) {
            $tokenData = json_decode($token, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tokenData)) {
                $this->_helper->log("Apple Pay JSON String", "json_string", [
                    'keys' => array_keys($tokenData),
                ], 'Debug');
                
                // Check if it's already paymentData
                if (isset($tokenData['data']) || isset($tokenData['header']) || isset($tokenData['version'])) {
                    $this->_helper->log("Apple Pay JSON is PaymentData", "json_is_payment_data", [
                        'token_length' => strlen($token),
                    ], 'Debug');
                    return $token;
                }
                
                // Try to extract paymentData from structure
                if (isset($tokenData['paymentData']) && is_array($tokenData['paymentData'])) {
                    return json_encode($tokenData['paymentData'], JSON_UNESCAPED_SLASHES);
                }
            }
        }
        
        // Final fallback - if it's a string, return it as-is (might be already formatted)
        if (is_string($token)) {
            $this->_helper->log("Apple Pay Fallback - Return As-Is", "fallback", [
                'token_length' => strlen($token),
            ], 'Debug');
            return $token;
        }
        
        $this->_helper->log("Apple Pay Token Error - All Cases Failed", "all_cases_failed", [
            'final_token_type' => gettype($token),
            'token_length' => (is_string($token) ? strlen($token) : 0),
        ], 'Error');
        
        throw new \Magento\Framework\Validator\Exception(__("Invalid Apple Pay token format. Token structure could not be parsed."));
    }
    
    public function execute(Observer $observer)
    {
        $data = $this->readDataArgument($observer);
        $paymentModel = $this->readPaymentModelArgument($observer);
        $payment_code = $paymentModel->getMethodInstance()->getCode();

        $settings = $this->_helper->getSettings();   

        $additionalData = $data->getDataByKey('additional_data');
        $requestedMethod = is_array($additionalData) ? trim($additionalData['method'] ?? '') : '';
        $isWalletExpress = in_array($requestedMethod, ['applepay', 'googlepay'], true);

        // Card payments follow the configured integration mode (direct vs hosted).
        // Apple Pay / Google Pay wallet tokens, however, are ALWAYS processed via
        // the gateway Direct API regardless of mode — the token is captured
        // client-side and there is no hosted page to redirect it to. This keeps the
        // cart / mini-cart and main-checkout wallet flows consistent with the
        // product page (which already forces Direct processing for wallets).
        if( $payment_code == 'qidigitalpayment' && ($settings['integration_type'] == 'direct' || $isWalletExpress) ){

            // What this payment actually went through, for the order record and the log tags.
            // Wallets ALWAYS use the Direct API whatever the configured mode; recording the
            // configured mode instead is what showed "Integration Type: Modal" on a Google
            // Pay order (reported 21/09/2026).
            $integrationUsed = $isWalletExpress ? 'direct' : $settings['integration_type'];

            $method = trim($additionalData['method'] ?? 'card');
            $token = $additionalData['token'] ?? '';
            $browserInfo = json_decode(trim($additionalData['browserInfo'] ?? ''),true) ?? [];
            $option = trim($additionalData['payment_option'] ?? '');

            // Add debug logging for Apple Pay
            if ($method == 'applepay') {
                $this->_helper->log("Apple Pay Received", "initial_data", [
                    'method' => $method,
                    'token_type' => gettype($token),
                    'token_length' => is_string($token) ? strlen($token) : 'N/A',
                    'browserInfo_type' => gettype($browserInfo),
                ], 'Debug');
            }

            if( in_array($method,['ppro','nomupay']) && empty($option) )
                throw new \Magento\Framework\Validator\Exception(__("Please select APM method"));

            if( $method == 'ppro' || $method == 'nomupay')
                $method = $method.'.'.$option;

            // Use the payment model's own quote when available (product-page express checkout
            // creates a fresh quote not stored in the checkout session).
            $quote = (method_exists($paymentModel, 'getQuote') && $paymentModel->getQuote() && $paymentModel->getQuote()->getId())
                ? $paymentModel->getQuote()
                : $this->_checkoutSession->getQuote();

            //save card
            $saveCard =  $method == 'card' && (int)($additionalData['saveCard'] ?? '') && !$quote->getCustomerIsGuest();
            $cardId = $method == 'card' && (!empty($additionalData['cardId']) && $additionalData['cardId'] !== 'new') ? $additionalData['cardId'] : 0;
            if( !empty($cardId) ) $saveCard = false;

            // Persist the shopper's "Save Card" intent on the payment itself.
            //
            // walletID / cardID / cardToken only appear on the response that APPROVES the
            // transaction. When the gateway routes the payment through 3-D Secure, that
            // response arrives at Controller/Order/Continue3ds.php long after this observer
            // has returned — so without a durable flag the intent is lost and the card is
            // never stored. That is why ticking Save Card produced nothing on a 3DS-enabled
            // account (reported 27/08/2026).
            //
            // Written on every attempt, not only when true: a quote's payment row is reused
            // across retries, so a stale 1 from an earlier attempt would otherwise store a
            // card the shopper had since unticked.
            $paymentModel->setAdditionalInformation('save_card', $saveCard ? 1 : 0);

            $billing = $quote->getBillingAddress();
            $shipping = $quote->getShippingAddress();

            $total = (float)$quote ->getGrandTotal();
          
            $additionalInfo  = $paymentModel->getAdditionalInformation();
            if( isset($additionalInfo['payment_id']) )
                $paymentId = $additionalInfo['payment_id'];
            else {
                $paymentId = bin2hex(random_bytes(16));
                $paymentModel->setAdditionalInformation('payment_id',trim($paymentId));
                $paymentModel->save();
            }

            $urlBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface');    

            $order_id = $paymentId;
            $redirect_url =  $this->_helper->getRedirectUrl($order_id,'payment_id',$saveCard ? 'svcd=Y': '');

            $request = $settings['3d_secure'] != 'N' ? $this->_helper->getBrowserInfo($browserInfo) : [];

            if ($cardId) {
                $customerId = $quote->getCustomer()->getId();
                $card = $this->paymentCards->get($cardId,$customerId);
                
                if ($card) {
                    $request['walletId'] = $card['wallet_id'];
                    $request['cardToken'] = $card['card_token'];
                    $request['cardCVV'] = trim($additionalData['ccv'] ?? '');

                } else 
                    throw new \Magento\Framework\Validator\Exception(__("Saved card not found or invalid"));
                
            } else {
                if( in_array($method,['card','applepay','googlepay']) && empty($token) ) {
                    throw new \Magento\Framework\Validator\Exception(__("Unable to process your payment, refresh page and try again"));
                } else {
                    
                    if( $method != 'card' ) {
                        $request['paymentMethod'] = $method;
                    }

                    if( in_array($method,['card','applepay','googlepay']) ) {
    
                        // Handle Apple Pay token specifically
                        if( $method == 'applepay' ) {
                            try {
                                $request['paymentToken'] = $this->processApplePayToken($token);
                                
                                // Log the final token being sent to gateway
                                $this->_helper->log("Apple Pay Final Request Token", "gateway_token", [
                                    'token_length' => strlen($request['paymentToken']),
                                    'token_type'   => gettype($request['paymentToken']),
                                ], 'Debug');
                                
                            } catch (\Exception $e) {
                                $this->_helper->log("Apple Pay Processing Error", "exception", [
                                    'error'        => $e->getMessage(),
                                    'token_type'   => gettype($token),
                                    'token_length' => is_string($token) ? strlen($token) : 0,
                                ], 'Error');
                                throw $e;
                            }
                        } 
                        // Handle Google Pay token
                        else if( $method == 'googlepay' ) {
                            if( is_array($token) ) {
                                $request['paymentToken'] = json_encode($token, JSON_UNESCAPED_SLASHES);
                            } else {
                                $request['paymentToken'] = $token;
                            }
                        }
                        // Handle regular card token
                        else {
                            $request['paymentToken'] = is_array($token) 
                                ? json_encode($token, JSON_UNESCAPED_SLASHES)
                                : $token;
                        }
                    }
                }
            }

            // FIXED: Properly handle street addresses
            $payment_street = $billing->getStreet();
            $shipping_street = $shipping->getStreet();
            
            $shipping_method = $shipping->getShippingMethod() ?? '';
            $shipping_method = strpos($shipping_method,'_') !== false ? ucfirst(explode("_",$shipping_method)[1]) : '';

            $products = [];
            foreach($quote->getAllVisibleItems() as $item){
                $products[] = [
                    'sku' =>  $item->getSku(),
                    'name' => $item->getName(),
                    'qty' => $item->getQty(),
                    'price' => $item->getPrice(),
                ];
            }

            // FIXED: Proper order info with all required fields
            $orderInfo = [
                'order_id' => $order_id,
                'total' => $total,
                'currency' => $quote->getQuoteCurrencyCode(), // Add currency
                
                'session_id' => session_id(),
                'customer_id' => $quote->getCustomer()->getId(),
                // 'email' is not a real quote_address attribute (no quote_address.email column) —
                // it only persists reliably on the quote itself (quote.customer_email), which
                // Process.php/Cart/Express.php already set via setCustomerEmail() for guests.
                // $billing->getEmail() happens to work for logged-in customers because
                // assignCustomer() threads the registered email through, but is blank for guests.
                'email' => $billing->getEmail() ?: ($quote->getCustomerEmail() ?: ''),
                'telephone' => $billing->getTelephone() ?: '',
                
                // Billing Address - FIXED
                'payment_firstname' => ucfirst($billing->getFirstname() ?: ''),
                'payment_lastname' => ucfirst($billing->getLastname() ?: ''),
                'payment_address_1' => is_array($payment_street) ? ($payment_street[0] ?? '') : $payment_street,
                'payment_address_2' => is_array($payment_street) ? ($payment_street[1] ?? '') : '',
                'payment_company'   => $billing->getCompany() ?: '',
                'payment_city' => $billing->getCity() ?: '',
                'payment_town' => $billing->getCity() ?: '', // Alias for town
                'payment_region' => $billing->getRegion() ?: '',
                'payment_county' => $billing->getRegion() ?: '', // Alias for county
                'payment_postcode' => $billing->getPostcode() ?: '',
                'payment_country' => $billing->getCountryId() ?: '',
                
                // Shipping Address - FIXED
                'shipping_firstname' => ucfirst($shipping->getFirstname() ?: ''),
                'shipping_lastname' => ucfirst($shipping->getLastname() ?: ''),
                'shipping_address_1' => is_array($shipping_street) ? ($shipping_street[0] ?? '') : $shipping_street,
                'shipping_address_2' => is_array($shipping_street) ? ($shipping_street[1] ?? '') : '',
                'shipping_company'   => $shipping->getCompany() ?: '',
                'shipping_city' => $shipping->getCity() ?: '',
                'shipping_town' => $shipping->getCity() ?: '',
                'shipping_region' => $shipping->getRegion() ?: '',
                'shipping_county' => $shipping->getRegion() ?: '',
                'shipping_postcode' => $shipping->getPostcode() ?: '',
                'shipping_country' => $shipping->getCountryId() ?: '',
                'shipping_method' => $shipping_method,
                
                'products' => $products
            ];

            // Card-on-file instruction. This MUST be added before prepareRequest(), which
            // is where createSignature() runs: the module signs with Cardstream's partial
            // form (hash + '|' + the field names covered), so anything appended afterwards
            // sits outside the signed field list and cannot be relied on to reach the
            // gateway. Both fields used to be set on the line below this call, which is
            // one of the two reasons Save Card stored nothing (reported 27/08/2026).
            if( $saveCard ){
                $request['walletOwnerRef'] = 'C'.sprintf('%020d', $quote->getCustomer()->getId());
                $request['cardStore'] = 'Y';
            }

            $request = $this->_helper->prepareRequest($orderInfo, $settings, $redirect_url, $request, 'D');

            $logRequest = $request;
            if (isset($logRequest['paymentToken'])) {
                $logRequest['paymentToken'] = '[redacted]';
            }
            $this->_helper->log("Payment #".$order_id, $integrationUsed.'_'.$settings['purchase_type'], $logRequest, 'Request');

            $response = $this->_helper->httpRequest('POST', $settings['gateway_url'].'direct/', $request);

            $this->_helper->log("Payment #".$order_id, $integrationUsed.'_'.$settings['purchase_type'], $response, 'Response');

            [$success, $error, $status, $redirect] = $this->_helper->validateResponse($response, $settings['signature_key'], 'D');

            $this->_helper->saveTransaction($paymentModel, $integrationUsed, $settings['purchase_type'] ?? 'sale', $status, $response);
            
            if ( $success && $saveCard && $status == 'success' ) {
                $customerId = $quote->getCustomer()->getId();
                $this->_helper->saveCard($customerId, $response);
            }
        
            if( !$success )
                throw new \Magento\Framework\Validator\Exception(__($error ?: "Unable to process payment") );
        }
    }
}