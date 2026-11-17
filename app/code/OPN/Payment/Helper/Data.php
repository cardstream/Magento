<?php

namespace OPN\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use OPN\Payment\Model\Payment\PaymentCards;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\Currency;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    /**
     * json_encode() flags for any value emitted INSIDE a <script> block.
     *
     * Without JSON_HEX_TAG a customer-controlled value containing "</script>" closes the
     * element early and everything after it is parsed as markup — and the gateway request
     * assembled by prepareRequest() carries customerName, customerAddress, customerEmail
     * and customerPhone straight from the shopper. The remaining flags cover the same
     * class of break-out in attribute and entity contexts.
     *
     * These only escape characters WITHIN string values; structural JSON is untouched, so
     * the decoded value the SDK forwards to the gateway is byte-for-byte unchanged. That
     * matters for applePayCheckoutOptions in particular, which must reach the gateway with
     * real double quotes (see prepareRequest()).
     */
    const JS_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /**
     * Gateway transaction states in which a SALE has NOT yet been settled (Gateway
     * Integration Guide 3.05, appendix A-13.2): `approved` = authorised, awaiting capture;
     * `captured` = captured, awaiting the settlement batch; `received` = not yet authorised.
     * Until settlement a refund is a CANCEL or a reduced CAPTURE; after it, REFUND_SALE.
     */
    const UNSETTLED_STATES = ['received', 'approved', 'captured'];

    protected $gateway_key = 'qidigitalpayment';
    protected $version = '3.1.11';
    protected $checkoutSession;
    protected $paymentCards;
    protected $_resources;
    public $currency;
    protected $storeManager;
    protected $currencyModel;

    /**
     * Dedicated Monolog channel writing to var/log/open_payment_network.log.
     * Bound to the OpnPaymentLogger virtual type in etc/di.xml. Nullable so a stale
     * DI cache degrades to the framework logger instead of raising a fatal error.
     *
     * @var LoggerInterface|null
     */
    protected $opnLogger;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        PaymentCards $paymentCards,
        ResourceConnection $resources,
        StoreManagerInterface $storeManager,
        Currency $currencyModel,
        ?LoggerInterface $opnLogger = null
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->paymentCards = $paymentCards;
        $this->_resources = $resources;
        $this->storeManager = $storeManager;
        $this->currencyModel = $currencyModel;
        $this->opnLogger = $opnLogger;
    }

    public function getSettings()
    {
        $gateway_key = $this->gateway_key;
        $config = [];

        $fields = [
            'general_settings' => ['debug','methods'],
            'gateway_settings' => ['gateway_url','queue_gateway_url','gateway_id','signature_key','country_code','integration_type','3d_secure','purchase_type','capture_delay','save_card_enabled'],
            'googlepay_settings' => ['google_environment','google_merchant_id','google_merchant_name','google_gateway_identifier', 'google_allowed_cards','google_enable_product','google_enable_cart'],
            'applepay_settings' => ['apple_merchant_name', 'apple_allowed_cards','apple_enable_product','apple_enable_cart'],
        ];

        $settings = [
            'title' => trim($this->scopeConfig->getValue("payment/{$gateway_key}/title", ScopeInterface::SCOPE_STORE) ?? ''),
        ];

        foreach($fields as $group => $keys){
            foreach ($keys as $key) {
                $val = $this->scopeConfig->getValue("payment/{$gateway_key}/{$group}/{$key}", ScopeInterface::SCOPE_STORE);
                $val = !empty($val) ? (is_array($val) ? $val : trim($val)) : null;
                if( in_array($key,['debug', '3d_secure','status']) )
                    $val = $val == '1' ? 'Y' : 'N';
                if( in_array($key,['methods','google_allowed_cards','apple_allowed_cards']) )
                    $val = json_decode($val ?? '',true) ?? [];    

                $settings[$key] = $val;
            }
        }

        if (!empty($settings['gateway_url'])) {
            $settings['gateway_url'] = rtrim($settings['gateway_url'], '/') . '/';
            if (strpos($settings['gateway_url'], '://') === false) {
                $settings['gateway_url'] = 'https://' . $settings['gateway_url'];
            }
        }

        if( empty($settings['methods']) )
            $settings['methods'] = ['card'];

        // Apple shows this on the sheet's total line and requires it as `displayName` for
        // merchant validation. Left blank in admin, use the name the merchant did give.
        if( empty($settings['apple_merchant_name']) )
            $settings['apple_merchant_name'] = $settings['google_merchant_name']
                ?: trim((string)$this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE));

        $rawAdditional = $this->scopeConfig->getValue("payment/{$gateway_key}/additional_settings/additional_fields", ScopeInterface::SCOPE_STORE);
     
        $settings['additional_fields'] = [];
        $unserialized = is_string($rawAdditional) ? json_decode($rawAdditional, true) : [];

        if (is_array($unserialized)) {
            foreach ($unserialized as $key => $item) {
                if (is_array($item) && isset($item['name']) && isset($item['value'])) {
                    $settings['additional_fields'][] = [
                        'name' => $item['name'],
                        'value' => $item['value']
                    ];
                }
            }
        }

        $magentoVersion = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Framework\App\ProductMetadataInterface::class)
            ->getVersion();
        $settings['module_version'] = 'Magento ' . $magentoVersion . ' / OPN ' . $this->version;

        return $settings;
    }

    public function getConfig($key){
        return $this->scopeConfig->getValue($key, ScopeInterface::SCOPE_STORE);
    }

    public function getTransaction($row, $frmt = false){

        $data = (array)$row;

        if( empty($data['captured_total']) ) $data['captured_total'] = 0;
        if( empty($data['refunded_total']) ) $data['refunded_total'] = 0;
        if( empty($data['total']) )          $data['total'] = 0;
        if( empty($data['capture_delay']) )  $data['capture_delay'] = 0;
        if( empty($data['auth_type']) )      $data['auth_type'] = 'sale';
        
        if( $frmt ){
            $data['captured_total_frmt'] = $this->currency->format($data['captured_total']);
            $data['refunded_total_frmt'] = $this->currency->format($data['refunded_total']);
        }

        $success_statuses = ['success','failed'];

        $total = (float)$data['total'];
        $delay = trim($data['capture_delay']);
        if( $delay == '0' ) $delay = '';

        $row['captureDelay'] = $delay;

        $captured_total = ($data['status'] ?? '') == 'success' &&  empty($delay) && $data['auth_type'] == 'sale' ? $total : (float)$data['captured_total'];

        $capture_pending = $total - $captured_total;
        if( $capture_pending < 0 ) $capture_pending = 0;

        $allow_capture = $capture_pending > 0 && $capture_pending <= $total && $total > 0 && (!empty($delay) || $data['auth_type'] != 'sale') && in_array($data['status'], $success_statuses);

        $data['allow_capture'] = $allow_capture;
        $data['capture_pending'] = $capture_pending;

        $refund_pending = $captured_total - (float)$data['refunded_total'];
        if( $refund_pending < 0 ) $refund_pending = 0;

        $allow_refund = $captured_total > 0 && $total > 0 && $refund_pending > 0 && in_array($data['status'], $success_statuses);
        $data['allow_refund'] = $allow_refund;
        $data['refund_pending'] = $refund_pending;

        $allow_cancel = $data['auth_type'] != 'sale' && ($data['status'] ?? '') != 'cancelled' && $captured_total == 0;
        $data['allow_cancel'] = $allow_cancel;

        if( $frmt ){
            $fields = [
                'method' => 'Payment&nbsp;Method',
                'action'        => 'Action',
                'integration_type' => 'Integration Type',
                'action'        => 'Action',
                'payment_id'    => 'Payment ID',
                'reference'     => 'Reference',
                'transaction_id'=> 'Transaction ID',
                'acquirer_transaction_id' => 'Acquirer Transaction ID',
                '3dauth_trans_id'=> '3DAuth Transaction ID',
                'request_id'    => 'Request ID',
                'capture_delay' => "Capture Delay",
                'status'        => 'Status',
                'total'         => 'Total',
                'message'       => 'Message',
            ];

            $_data = [];
            foreach($fields as $key => $value){
                if( !empty($data[$key]) ){
                    $val = $data[$key];     
                    if( $key == 'status' || $key == 'integration_type')
                        $val = ucfirst($val); 
                    if( $key == 'capture_delay')
                        $val = $val." days";  
                    if( $key == 'total')
                        $val = $this->currency->format($val, false);

                    $_data[$value] = $val;
                }
            }

            $data['formatted'] = $_data;
        }

        return $data;
    }

    public function saveTransaction($model, $integration_type, $auth_type, $status, $data) {

        $info = [];
       
        $fields = [
            'paymentMethod' => 'method',
            'action'        => 'action',
            'transactionID' => 'transaction_id',
            'xref'          => 'reference',
            'acquirerTransactionID' => 'acquirer_transaction_id',
            'threeDSXID'    => '3dauth_trans_id',
            'requestID'     => 'request_id',
            'cardNumberMask'=> 'card_number',
            'cardType'      => 'card_type',
            'responseMessage'=> 'message',
        ];
        
        $total = round(floatval($data['amount'] ?? 0)/100 ,2);
        $delay = ($data['captureDelay'] ?? '');
        $captured_total = $status == 'success' && (empty($delay) || $delay == '0') && $auth_type == 'sale' ? $total : 0;
   
        foreach($fields as $key => $value){
            if( !empty($data[$key]) )
                $info[$value] = $data[$key];
        }

        $info = $this->merge($info,[
            'integration_type' => $integration_type, 
            'auth_type' => $auth_type, 
            'status' => $status,
            'total' => $total,
            'capture_status' => $captured_total > 0 ? 'Y' : 'N',
            'captured_total' => $captured_total,
            'capture_delay' => $delay,
            'refund_status' => 'N',
            'refunded_total' => 0,
            'response' => $data
        ]);

        $info['redirect_checkout'] = null;

        // Any terminal outcome clears the in-flight redirect state. Only 'success' used
        // to do this, so a declined or cancelled 3-D Secure payment kept a stale
        // redirect_3d_auth['url'] — the module-wide "this payment is mid-3DS" signal —
        // and Controller/Order/Threedsecure.php would push the shopper straight back
        // into a challenge the issuer had already refused.
        if( $status != 'pending' ){
            $info['redirect_checkout'] = $info['redirect_3d_auth'] = [];
        }

        if( $status == 'pending' ){

            if( !empty($data['checkoutURL']) ){
                $info['redirect_checkout']  = [
                    'action'  => ($data['paymentMethod'] ?? '') != 'paypal' && !empty($data['checkoutRequest']) ? 'POST' : 'GET',
                    'url'     => $data['checkoutURL'],
                    'request' => $data['checkoutRequest'] ?? [],
                ];
            }

            if( !empty($data['threeDSURL']) ){
                $info['redirect_3d_auth']  = [
                    'url'     => $data['threeDSURL'],
                    'request' => $data['threeDSRequest'],
                    'ref'     => $data['threeDSRef'] ?? null
                ];
            }
        }

        foreach ($info as $key => $value) {
            $model->setAdditionalInformation($key,$value);
        }

        $model->save();
    }
    

    public function sendInvoice($order){
        try{

            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->save();

            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                
            if ( !empty($order) && $order->canInvoice()) {

                $invoiceService = $objectManager->create('\Magento\Sales\Model\Service\InvoiceService');
                $invoiceSender  = $objectManager->create('\Magento\Sales\Model\Order\Email\Sender\InvoiceSender');

                $invoice = $invoiceService->prepareInvoice($order);
                $invoice->register();
                $invoice->save();
                
                $invoiceOrder = $invoice->getOrder();

                $transactionSave = $objectManager->create('\Magento\Framework\DB\Transaction')->addObject($invoice)->addObject($invoiceOrder);

                $transactionSave->save();
                $invoiceSender->send($invoice);
                
                $order->addCommentToStatusHistory(__('Notified customer about invoice creation #%1.', $invoice->getId()))->setIsCustomerNotified(true)->save();
            }

            // Stock deduction is handled automatically by Magento when invoice->register()
            // fires the sales_order_invoice_register event. No manual decrement needed.

        } catch(\Exception $e){
            $this->log('Invoice creation failed', 'error', [
                'order_id' => $order->getId(),
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin Capture / Refund / Cancel against the gateway's Direct API.
     *
     * Cardstream's rules (Gateway Integration Guide 3.05, sections 1.9.4 to 1.9.7):
     *  - REFUND_SALE "can only be performed on transactions that have been successfully
     *    settled. Up until that point, a CANCEL or partial CAPTURE can be used, to refund or
     *    partially refund the original SALE transaction."
     *  - CAPTURE's `amount` is the amount to capture (the new total, capped at the original
     *    authorisation), so a second partial capture must send the cumulative figure.
     *  - CANCEL voids the whole transaction and takes no amount.
     *
     * The refund branch used to apply these the wrong way round: REFUND_SALE while the
     * transaction was still approved/captured, CAPTURE/CANCEL once settled. That is what
     * produced both "Cannot REFUND this SALE transaction" and "Cannot CAPTURE this
     * transaction" in the admin (reported 21/09/2026).
     *
     * Bookkeeping: `captured_total` is what the sale captured; `refunded_total` is the
     * cumulative amount returned to the shopper by ANY mechanism (reduced capture, void or
     * refund). `captured_total - refunded_total` is therefore always what the shopper ends
     * up paying, and is the figure the gateway is told to capture when a pre-settlement
     * refund lowers it.
     *
     * Returns [$success, $error, $totals, $orderState] where $totals is
     * ['captured' => float, 'refunded' => float, 'gateway_action' => string].
     */
    public function processAction($payment, $order, $action, $amount) {

        $success = false;
        $error = null;
        $status = null;
        $update = [];

        $order_id = (int)$order['order_id'];
        $xref = $order['reference'];
        $total = (float)$order['total'];
        $captured = (float)($order['captured_total'] ?? 0);
        $refunded = (float)($order['refunded_total'] ?? 0);
        $net = $captured - $refunded;            // what the shopper currently pays
        $amount = (float)$amount;

        $settings = $this->getSettings();

        $gatewayAction = strtoupper($action);
        $requestAmount = null;                   // major units; null = omit the field

        if ($action == 'refund') {
            $state = $this->queryTransactionState($xref, $settings);

            if (!in_array($state, self::UNSETTLED_STATES, true)) {
                // Settled (or state unknown, in which case the gateway is the judge):
                // REFUND_SALE, full or partial, repeatable while anything is un-refunded.
                $gatewayAction = 'REFUND_SALE';
                $requestAmount = $amount;
            } elseif ($amount >= $net) {
                // Not yet settled and nothing would be left to take: void it.
                $gatewayAction = 'CANCEL';
            } else {
                // Not yet settled, partial: lower the amount the settlement will take.
                $gatewayAction = 'CAPTURE';
                $requestAmount = $net - $amount;
            }
        } elseif ($action == 'capture') {
            // Cumulative: CAPTURE replaces the amount to capture rather than adding to it.
            // This used to send no amount at all, which captured the FULL authorisation while
            // the module recorded only the partial figure the admin typed.
            $gatewayAction = 'CAPTURE';
            $requestAmount = $net + $amount;
        } elseif ($action == 'cancel') {
            $gatewayAction = 'CANCEL';
        }

        $request = [
            'merchantID' => $settings['gateway_id'],
            'xref'       => $xref,
            'action'     => $gatewayAction,
        ];
        if ($requestAmount !== null) {
            $request['amount'] = (int)round($requestAmount * 100);
        }
        $request['signature'] = $this->createSignature($request, $settings['signature_key']);

        $this->log("Order #".$order_id, $action, $request, 'Request');
        $response = $this->httpRequest('POST', $settings['gateway_url'].'direct/', $request);
        $this->log("Order #".$order_id, $action, $response, 'Response');

        if (isset($response['responseCode']) && (string)$response['responseCode'] === '0') {
            $success = true;

            if ($action == 'refund') {
                // A void returns everything still outstanding; the other two return $amount.
                $refunded += ($gatewayAction == 'CANCEL') ? $net : $amount;
                $update['refunded_total'] = $refunded;
                $update['refund_status']  = $refunded >= $captured ? 1 : 0;
                // 'refunded' is not a Magento order state; Closed is Magento's "fully refunded".
                $status = $refunded >= $captured
                    ? \Magento\Sales\Model\Order::STATE_CLOSED
                    : \Magento\Sales\Model\Order::STATE_PROCESSING;
            }
            elseif ($action == 'capture') {
                $captured += $amount;
                $update['captured_total'] = $captured;
                $update['capture_status'] = $captured >= $total ? 1 : 0;
                $status = \Magento\Sales\Model\Order::STATE_PROCESSING;

                if ($captured >= $total) {
                    $update['status'] = 'success';
                }
            }
            elseif ($action == 'cancel') {
                $update['status'] = 'cancelled';
                $status = \Magento\Sales\Model\Order::STATE_CANCELED;
            }

            foreach ($update as $key => $value) {
                $payment->setAdditionalInformation($key, $value);
            }

        } else {
            $error = $response['responseMessage'] ?? "Unable to process this action, try again";
        }

        $totals = ['captured' => $captured, 'refunded' => $refunded, 'gateway_action' => $gatewayAction];

        return [$success, $error, $totals, $status];
    }

    /**
     * The gateway's current `state` for a transaction (lower-case), or '' if the QUERY fails.
     */
    private function queryTransactionState($xref, array $settings): string
    {
        $query = [
            'merchantID' => $settings['gateway_id'],
            'xref'       => $xref,
            'action'     => 'QUERY',
        ];
        $query['signature'] = $this->createSignature($query, $settings['signature_key']);

        $this->log("Query xref ".$xref, 'query', $query, 'Request');
        $response = $this->httpRequest('POST', $settings['gateway_url'].'direct/', $query);
        $this->log("Query xref ".$xref, 'query', $response, 'Response');

        if (isset($response['responseCode']) && (string)$response['responseCode'] === '0') {
            return strtolower(trim((string)($response['state'] ?? '')));
        }
        return '';
    }

    public function prepareRequest($order, $settings, $redirect_url, $params = [], $type = null ){
        $order_id = $order['order_id'];

        $signature_key = $settings['signature_key'];
        $integration_type = $settings['integration_type'] ?? 'direct';
   
        $request = [
            'merchantID' => $settings['gateway_id'],
        ];
  
        if( !empty($params) )
            $request = $this->merge($request,$params);

        $total = $order['total'] ?? 0;

        $paymentMethod = $request['paymentMethod'] ?? '';
        $needs3ds = $settings['3d_secure'] != 'N' && $type === 'D'
            && (empty($paymentMethod) || $paymentMethod === 'googlepay');
        if( $needs3ds ){
            $request['threeDSRedirectURL'] = $this->getThreeDSCallbackUrl($order_id);
            $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $clientIp = $forwardedFor ? trim(explode(',', $forwardedFor)[0]) : ($_SERVER['REMOTE_ADDR'] ?? '');
            if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
            }
            $request['deviceIpAddress'] = $clientIp;
            $request['threeDSOptions'] = [
                'paymentAccountAge' => date("Ymd"),
                'paymentAccountAgeIndicator' => '05',
            ];
        }

        // applePayCheckoutOptions tells the gateway which merchant domain to use for Apple Pay
        // verification. It is only meaningful when the gateway's payment form is rendered
        // INSIDE AN IFRAME on our domain — i.e. the 'modal' and 'embedded' integrations.
        //
        // It must NOT be sent for:
        //   - 'hosted': a full-page redirect, where the gateway owns the top-level document
        //     and derives the domain itself. Sending it there produced responseCode 66546
        //     ("invalid checkout options").
        //   - Direct API calls ($type === 'D'): this block sits above the hosted-form guard
        //     below, so it previously leaked an Apple-Pay-only field into every Direct
        //     request — including plain card and Google Pay payments — whenever the
        //     integration happened to be hosted/modal/embedded.
        //
        // The value must contain real double quotes. htmlentities() here once encoded them
        // as &quot;, which was then signed AND posted, so the signature matched and the
        // gateway rejected the payload rather than the request. json_encode() only.
        $isIframeHostedForm = $type !== 'D' && in_array($integration_type, ['modal', 'embedded'], true);

        if( $isIframeHostedForm && in_array('applepay', $settings['methods'] ?? [], true) ) {
            $storeBaseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            $apDomain = parse_url($storeBaseUrl, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');
            $request['applePayCheckoutOptions'] = json_encode(['domainName' => $apDomain]);
        }

        if( $integration_type != 'direct' || $type == 'D' ){
            
            // Get currency code - FIXED
            $currencyCode = $this->getCurrencyNumericCode($order['currency'] ?? null);
            
            $countryCode = $this->getCountryNumericCode($settings['country_code']);
            
            // Prepare customer address
            $customerAddress = trim($order['payment_address_1'] ?? '');
            if (!empty($order['payment_address_2'])) {
                $customerAddress .= ', ' . trim($order['payment_address_2']);
            }
            
            // Prepare town/city - FIXED
            $customerTown = trim($order['payment_city'] ?? '');
            if (empty($customerTown) && !empty($order['payment_town'])) {
                $customerTown = trim($order['payment_town']);
            }
            
            // Prepare county/region - FIXED
            $customerCounty = trim($order['payment_region'] ?? '');
            if (empty($customerCounty) && !empty($order['payment_county'])) {
                $customerCounty = trim($order['payment_county']);
            }

            $request =  $this->merge($request,[
                'action' => strtoupper($settings['purchase_type'] ?? 'SALE'),
                'type' => '1',
                'countryCode' => $countryCode, // Numeric country code
                'currencyCode' => $currencyCode, // Numeric currency code - FIXED
                'amount' => number_format((float)$total*100,0,'.',''),
                'orderRef' => ($type == 'D' ? 'Payment' : 'Order').' #'.$order_id,
                'transactionUnique' => trim($order_id)."-".bin2hex(random_bytes(8)),
                
                'customerName' => trim(trim($order['payment_firstname'])." ".trim($order['payment_lastname'])),
                'customerEmail' => trim(strtolower($order['email'])),
                'customerAddress' => $customerAddress,
                'customerTown' => $customerTown, 
                'customerCounty' => $customerCounty, 
                'customerPostCode' => trim($order['payment_postcode'] ?? ''),
                'customerCountryCode' => $this->getCountryAlpha2Code($order['payment_country'] ?? $settings['country_code']),
            ]);

            if( !empty($order['telephone']) )
                $request['customerPhone'] = trim($order['telephone']);

            if( $type != 'D' )
                $request['redirectURL'] = $redirect_url;

            if( $settings['purchase_type'] == 'sale' && trim($settings['capture_delay'] ?? '') != '')
                $request['captureDelay'] = $settings['capture_delay'];

            $method = explode(".",$request['paymentMethod'] ?? '')[0] ?? '';
            if( in_array($method,['paypal','ppro','nomupay','nuapay']) )
                $request['checkoutRedirectURL'] = $redirect_url;

            foreach($settings['additional_fields'] ?? [] as $field){
                if( !empty($field['name']) && !empty($field['value']) ){
                    $request[trim($field['name'])] = trim($field['value']);
                }
            }

            if( !empty($settings['module_version']))
                $request['moduleVersion'] = $settings['module_version'];
            
            $request['signature'] = $this->createSignature($request,$signature_key).'|'.implode(",",array_keys($request));
        } 

        return $request;
    }

    /**
     * ISO 3166-1: alpha-2 => numeric. Complete list.
     *
     * Values are written WITHOUT leading zeros — a PHP integer literal starting with 0 is
     * octal, so 090 would silently become 72 and 092 would be a parse error.
     *
     * Magento's own directory_country table stores only iso2/iso3 codes, so there is no
     * framework source for the numeric form; this table is the module's source of truth
     * and getCountryAlpha2Code() reverses it rather than keeping a second copy.
     */
    private const COUNTRY_ISO_NUMERIC = [
        'AD' => 20,  'AE' => 784, 'AF' => 4,   'AG' => 28,  'AI' => 660, 'AL' => 8,   'AM' => 51,
        'AO' => 24,  'AQ' => 10,  'AR' => 32,  'AS' => 16,  'AT' => 40,  'AU' => 36,  'AW' => 533,
        'AX' => 248, 'AZ' => 31,
        'BA' => 70,  'BB' => 52,  'BD' => 50,  'BE' => 56,  'BF' => 854, 'BG' => 100, 'BH' => 48,
        'BI' => 108, 'BJ' => 204, 'BL' => 652, 'BM' => 60,  'BN' => 96,  'BO' => 68,  'BQ' => 535,
        'BR' => 76,  'BS' => 44,  'BT' => 64,  'BV' => 74,  'BW' => 72,  'BY' => 112, 'BZ' => 84,
        'CA' => 124, 'CC' => 166, 'CD' => 180, 'CF' => 140, 'CG' => 178, 'CH' => 756, 'CI' => 384,
        'CK' => 184, 'CL' => 152, 'CM' => 120, 'CN' => 156, 'CO' => 170, 'CR' => 188, 'CU' => 192,
        'CV' => 132, 'CW' => 531, 'CX' => 162, 'CY' => 196, 'CZ' => 203,
        'DE' => 276, 'DJ' => 262, 'DK' => 208, 'DM' => 212, 'DO' => 214, 'DZ' => 12,
        'EC' => 218, 'EE' => 233, 'EG' => 818, 'EH' => 732, 'ER' => 232, 'ES' => 724, 'ET' => 231,
        'FI' => 246, 'FJ' => 242, 'FK' => 238, 'FM' => 583, 'FO' => 234, 'FR' => 250,
        'GA' => 266, 'GB' => 826, 'GD' => 308, 'GE' => 268, 'GF' => 254, 'GG' => 831, 'GH' => 288,
        'GI' => 292, 'GL' => 304, 'GM' => 270, 'GN' => 324, 'GP' => 312, 'GQ' => 226, 'GR' => 300,
        'GS' => 239, 'GT' => 320, 'GU' => 316, 'GW' => 624, 'GY' => 328,
        'HK' => 344, 'HM' => 334, 'HN' => 340, 'HR' => 191, 'HT' => 332, 'HU' => 348,
        'ID' => 360, 'IE' => 372, 'IL' => 376, 'IM' => 833, 'IN' => 356, 'IO' => 86,  'IQ' => 368,
        'IR' => 364, 'IS' => 352, 'IT' => 380,
        'JE' => 832, 'JM' => 388, 'JO' => 400, 'JP' => 392,
        'KE' => 404, 'KG' => 417, 'KH' => 116, 'KI' => 296, 'KM' => 174, 'KN' => 659, 'KP' => 408,
        'KR' => 410, 'KW' => 414, 'KY' => 136, 'KZ' => 398,
        'LA' => 418, 'LB' => 422, 'LC' => 662, 'LI' => 438, 'LK' => 144, 'LR' => 430, 'LS' => 426,
        'LT' => 440, 'LU' => 442, 'LV' => 428, 'LY' => 434,
        'MA' => 504, 'MC' => 492, 'MD' => 498, 'ME' => 499, 'MF' => 663, 'MG' => 450, 'MH' => 584,
        'MK' => 807, 'ML' => 466, 'MM' => 104, 'MN' => 496, 'MO' => 446, 'MP' => 580, 'MQ' => 474,
        'MR' => 478, 'MS' => 500, 'MT' => 470, 'MU' => 480, 'MV' => 462, 'MW' => 454, 'MX' => 484,
        'MY' => 458, 'MZ' => 508,
        'NA' => 516, 'NC' => 540, 'NE' => 562, 'NF' => 574, 'NG' => 566, 'NI' => 558, 'NL' => 528,
        'NO' => 578, 'NP' => 524, 'NR' => 520, 'NU' => 570, 'NZ' => 554,
        'OM' => 512,
        'PA' => 591, 'PE' => 604, 'PF' => 258, 'PG' => 598, 'PH' => 608, 'PK' => 586, 'PL' => 616,
        'PM' => 666, 'PN' => 612, 'PR' => 630, 'PS' => 275, 'PT' => 620, 'PW' => 585, 'PY' => 600,
        'QA' => 634,
        'RE' => 638, 'RO' => 642, 'RS' => 688, 'RU' => 643, 'RW' => 646,
        'SA' => 682, 'SB' => 90,  'SC' => 690, 'SD' => 729, 'SE' => 752, 'SG' => 702, 'SH' => 654,
        'SI' => 705, 'SJ' => 744, 'SK' => 703, 'SL' => 694, 'SM' => 674, 'SN' => 686, 'SO' => 706,
        'SR' => 740, 'SS' => 728, 'ST' => 678, 'SV' => 222, 'SX' => 534, 'SY' => 760, 'SZ' => 748,
        'TC' => 796, 'TD' => 148, 'TF' => 260, 'TG' => 768, 'TH' => 764, 'TJ' => 762, 'TK' => 772,
        'TL' => 626, 'TM' => 795, 'TN' => 788, 'TO' => 776, 'TR' => 792, 'TT' => 780, 'TV' => 798,
        'TW' => 158, 'TZ' => 834,
        'UA' => 804, 'UG' => 800, 'UM' => 581, 'US' => 840, 'UY' => 858, 'UZ' => 860,
        'VA' => 336, 'VC' => 670, 'VE' => 862, 'VG' => 92,  'VI' => 850, 'VN' => 704, 'VU' => 548,
        'WF' => 876, 'WS' => 882,
        'YE' => 887, 'YT' => 175,
        'ZA' => 710, 'ZM' => 894, 'ZW' => 716,
    ];

    /**
     * ISO 4217: alpha-3 => numeric, for every currency currently in circulation.
     * Same no-leading-zeros rule as COUNTRY_ISO_NUMERIC above.
     */
    private const CURRENCY_ISO_NUMERIC = [
        'AED' => 784, 'AFN' => 971, 'ALL' => 8,   'AMD' => 51,  'ANG' => 532, 'AOA' => 973,
        'ARS' => 32,  'AUD' => 36,  'AWG' => 533, 'AZN' => 944,
        'BAM' => 977, 'BBD' => 52,  'BDT' => 50,  'BGN' => 975, 'BHD' => 48,  'BIF' => 108,
        'BMD' => 60,  'BND' => 96,  'BOB' => 68,  'BRL' => 986, 'BSD' => 44,  'BTN' => 64,
        'BWP' => 72,  'BYN' => 933, 'BZD' => 84,
        'CAD' => 124, 'CDF' => 976, 'CHF' => 756, 'CLP' => 152, 'CNY' => 156, 'COP' => 170,
        'CRC' => 188, 'CUP' => 192, 'CVE' => 132, 'CZK' => 203,
        'DJF' => 262, 'DKK' => 208, 'DOP' => 214, 'DZD' => 12,
        'EGP' => 818, 'ERN' => 232, 'ETB' => 230, 'EUR' => 978,
        'FJD' => 242, 'FKP' => 238,
        'GBP' => 826, 'GEL' => 981, 'GHS' => 936, 'GIP' => 292, 'GMD' => 270, 'GNF' => 324,
        'GTQ' => 320, 'GYD' => 328,
        'HKD' => 344, 'HNL' => 340, 'HTG' => 332, 'HUF' => 348,
        'IDR' => 360, 'ILS' => 376, 'INR' => 356, 'IQD' => 368, 'IRR' => 364, 'ISK' => 352,
        'JMD' => 388, 'JOD' => 400, 'JPY' => 392,
        'KES' => 404, 'KGS' => 417, 'KHR' => 116, 'KMF' => 174, 'KPW' => 408, 'KRW' => 410,
        'KWD' => 414, 'KYD' => 136, 'KZT' => 398,
        'LAK' => 418, 'LBP' => 422, 'LKR' => 144, 'LRD' => 430, 'LSL' => 426, 'LYD' => 434,
        'MAD' => 504, 'MDL' => 498, 'MGA' => 969, 'MKD' => 807, 'MMK' => 104, 'MNT' => 496,
        'MOP' => 446, 'MRU' => 929, 'MUR' => 480, 'MVR' => 462, 'MWK' => 454, 'MXN' => 484,
        'MYR' => 458, 'MZN' => 943,
        'NAD' => 516, 'NGN' => 566, 'NIO' => 558, 'NOK' => 578, 'NPR' => 524, 'NZD' => 554,
        'OMR' => 512,
        'PAB' => 590, 'PEN' => 604, 'PGK' => 598, 'PHP' => 608, 'PKR' => 586, 'PLN' => 985,
        'PYG' => 600,
        'QAR' => 634,
        'RON' => 946, 'RSD' => 941, 'RUB' => 643, 'RWF' => 646,
        'SAR' => 682, 'SBD' => 90,  'SCR' => 690, 'SDG' => 938, 'SEK' => 752, 'SGD' => 702,
        'SHP' => 654, 'SLE' => 925, 'SOS' => 706, 'SRD' => 968, 'SSP' => 728, 'STN' => 930,
        'SVC' => 222, 'SYP' => 760, 'SZL' => 748,
        'THB' => 764, 'TJS' => 972, 'TMT' => 934, 'TND' => 788, 'TOP' => 776, 'TRY' => 949,
        'TTD' => 780, 'TWD' => 901, 'TZS' => 834,
        'UAH' => 980, 'UGX' => 800, 'USD' => 840, 'UYU' => 858, 'UZS' => 860,
        'VED' => 926, 'VES' => 928, 'VND' => 704, 'VUV' => 548,
        'WST' => 882,
        'XAF' => 950, 'XCD' => 951, 'XOF' => 952, 'XPF' => 953,
        'YER' => 886,
        'ZAR' => 710, 'ZMW' => 967, 'ZWG' => 924,
    ];

    /**
     * The merchant's own country, as alpha-2.
     *
     * This is what Apple Pay's `countryCode` and Google Pay's `transactionInfo.countryCode`
     * are specified to carry: the country the MERCHANT trades from, never the shopper's.
     * The wallet entry points used to read the customer's default billing address or the
     * store default instead, which is why the gateway saw the shopper's country.
     *
     * The shopper's country is still sent separately, as `customerCountryCode`.
     */
    public function getMerchantCountryCode(): string
    {
        $configured = $this->scopeConfig->getValue(
            "payment/{$this->gateway_key}/gateway_settings/country_code",
            ScopeInterface::SCOPE_STORE
        );

        if (empty($configured)) {
            $configured = $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE);
        }

        return $this->getCountryAlpha2Code($configured);
    }

    public function getCurrencyNumericCode($currencyCode = null)
    {
        if (!$currencyCode) {
            $currencyCode = $this->storeManager->getStore()->getCurrentCurrency()->getCode();
        }

        $currencyCode = strtoupper(trim((string)$currencyCode));

        if (isset(self::CURRENCY_ISO_NUMERIC[$currencyCode])) {
            return self::CURRENCY_ISO_NUMERIC[$currencyCode];
        }

        // Already numeric and recognised — pass through.
        if (ctype_digit($currencyCode) && in_array((int)$currencyCode, self::CURRENCY_ISO_NUMERIC, true)) {
            return (int)$currencyCode;
        }

        // Deliberately fails loudly. This used to `?? 826`, which silently told the gateway
        // "GBP" for any currency outside a 23-entry list while the shopper was charged in
        // their own currency — a wrong-currency capture is never the safe default.
        throw new \Exception((string)__(
            'OPN Payment: currency "%1" has no known ISO-4217 numeric code, so the payment cannot be sent to the gateway.',
            $currencyCode
        ));
    }

    /**
     * Get numeric country code
     */
    public function getCountryNumericCode($countryCode = null)
    {
        if (!$countryCode) {
            throw new \Exception((string)__(
                'OPN Payment: No Country Code configured. Please set a valid Country Code under Stores > Configuration > Sales > Payment Methods > Open Payment Network > Gateway Settings.'
            ));
        }

        $countryCode = strtoupper(trim((string)$countryCode));

        if (isset(self::COUNTRY_ISO_NUMERIC[$countryCode])) {
            return self::COUNTRY_ISO_NUMERIC[$countryCode];
        }

        if (ctype_digit($countryCode) && in_array((int)$countryCode, self::COUNTRY_ISO_NUMERIC, true)) {
            return (int)$countryCode;
        }

        throw new \Exception((string)__(
            'OPN Payment: Country code "%1" is not recognised. Please set a valid Country Code under Stores > Configuration > Sales > Payment Methods > Open Payment Network > Gateway Settings.',
            $countryCode
        ));
    }

    /**
     * Get alpha-2 country code
     */
    public function getCountryAlpha2Code($countryCode = null)
    {
        if (!$countryCode) {
            $countryCode = $this->scopeConfig->getValue(
                'general/country/default',
                ScopeInterface::SCOPE_STORE
            );
        }

        $countryCode = strtoupper(trim((string)$countryCode));

        // Already alpha-2 — return as-is even if unknown, so a country Magento accepts but
        // this table has not caught up with still reaches the gateway rather than becoming GB.
        if (strlen($countryCode) === 2) {
            return $countryCode;
        }

        if (ctype_digit($countryCode)) {
            $alpha2 = array_search((int)$countryCode, self::COUNTRY_ISO_NUMERIC, true);
            if ($alpha2 !== false) {
                return $alpha2;
            }
        }

        return 'GB'; // Default to GB
    }

    // validate response
    public function validateResponse(array $response, $key, $type = 'R'){

        $success = false;
        $error = false;
        $status = 'failed';
        $redirect = null;

        $data = [];

        $fields = [
            'action'            => 'Action',
            'transactionID'     => 'Transaction ID',
            'xref'              => 'Reference ID',
            'transactionUnique' => 'Payment Id',
            '3DAuth_Trans_ID'   => '3DAuth Transaction',
            'cardNumberMask'    => 'Card Number',
            'cardType'          => 'Card Type',
            'cardIssuer'        => 'Card Issuer',
            'responseMessage'   => 'Message',
        ];

        foreach ($fields as $keyField => $label) {
            if (!empty($response[$keyField])) {
                $data[] = $label . ': ' . $response[$keyField];
            }
        }

        $error = isset($response['responseMessage']) ? $response['responseMessage'] : false;

        if (isset($response['responseCode']) && (int)$response['responseCode'] === 0) {
            $success = true;
            $status = 'success';

        } elseif (isset($response['responseCode']) && (int)$response['responseCode'] === 65576) {
            $status = 'cancelled';

        } elseif ( isset($response['responseCode']) && ($response['responseCode'] == 0x1010A || (int)$response['responseCode'] == 65802) && !empty($response['threeDSURL']) && !empty($response['threeDSRequest']) ){
            $success = true;
            $status = 'pending';
            $error = false;

        } elseif ( isset($response['responseCode']) && (int)$response['responseCode'] == 65826 && !empty($response['checkoutURL']) ){
            $success = true;
            $status = 'pending';
            $error = false;
        }

        return [$success, $error, $status, $redirect]; 
    }

    public function threeDsAuthForm($url, $post)
    {
    
        $url = htmlentities($url);
        $target = "opn_threeds_acs";
        
        $fields = '';

        foreach( (array)$post as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $n => $v) {
                    $v = htmlspecialchars(urldecode($v), ENT_QUOTES, 'UTF-8');
                    $fields .= '<input type="hidden" name="'.$name.'['.$n.']" value="'.$v.'" />'."\n";
                }
            } else {
                $value = htmlspecialchars(urldecode($value), ENT_QUOTES, 'UTF-8');
                $fields .= '<input type="hidden" name="'.$name.'" value="'.$value.'" />'."\n";
            }
        }

        $html = '
            <form id="silentPostOpn" action="' . $url . '" method="post">
                ' . $fields . '
                <noscript><input type="submit" value="Continue" /></noscript>
            </form>
            <script>
                setTimeout(function() {
                    document.getElementById("silentPostOpn").submit();
                }, 0);
            </script>
        ';

        return $html;
    }


    //verify signature
    public function verifySignature(array $data, $key){
        $signature = $data['signature'] ?? null;
        unset($data['signature']);

        return is_string($signature) && hash_equals($this->createSignature($data, $key), $signature);
    }

    //create signature  
    public function createSignature(array $data, $key) {  
        ksort($data);  
        
        $ret = http_build_query($data, '', '&');  
      
        $ret = str_replace(array('%0D%0A', '%0A%0D', '%0D'), '%0A', $ret);  
      
        return hash('SHA512', $ret . $key);  
    }

    // get browser info
    public function getBrowserInfo(array $extra = []) {

        $info = [
            'deviceChannel' => 'browser',
            'deviceIdentity' => (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
            'deviceTimeZone' => '0',
            'deviceCapabilities' => 'javascript',
            'deviceScreenResolution' => '1x1x1',
            'deviceAcceptContent' => (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
            'deviceAcceptEncoding' => (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? htmlentities($_SERVER['HTTP_ACCEPT_ENCODING']) : null),
            'deviceAcceptLanguage' => (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : 'en'),
            'deviceAcceptCharset' => (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? htmlentities($_SERVER['HTTP_ACCEPT_CHARSET']) : null),
        ];

        if( !empty($extra) )
            $info = $this->merge($info,$extra);

        return $info;
    }

    // http curl request
    public function httpRequest($method, $apiUrl, $data=[], $headers = []){
        
        
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $data = http_build_query($data, '', '&');

        if( $method != 'GET' ){
            if( $method == 'POST' ) 
                curl_setopt($ch, CURLOPT_POST, 1);
            else 
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST,$method);  
            
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
        }
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        if ($result === false) {
            $this->log('cURL error', 'httpRequest', ['error' => curl_error($ch)], 'Error');
        }
        curl_close($ch);

        $response = [];
        if( !empty($result) ){
            if( strpos($result,'{') === 0 )
                $response = json_decode($result,true);
            else
                parse_str(urldecode($result), $response);
        }

        return $response;
    }

    private function merge($params1, $params2){
        foreach($params2 as $k => $v){
            $params1[$k] = $v;
        }

        return $params1;
    }

    public function log($text, $type, $data, $title = null) {

        if ( empty($this->scopeConfig->getValue("payment/".$this->gateway_key."/general_settings/debug", ScopeInterface::SCOPE_STORE)) ) {
            return;
        }

        $message = $text.' - ('.strtoupper((string)$type).') '.(!empty($title) ? '- '.$title : '').":\n- "
            . trim((string)json_encode($data, JSON_PRETTY_PRINT));

        // $this->_logger (var/log/system.log) is the fallback when DI has not injected the
        // dedicated channel — never let a logging problem break a payment.
        $logger = $this->opnLogger ?: $this->_logger;

        try {
            $logger->info($message);
        } catch (\Throwable $e) {
            // Deliberately swallowed: debug logging must never abort a transaction.
        }
    }

    public function getRedirectUrl($id,$key = 'id' ,$extra = ''){
        $urlBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface');
        $url = $urlBuilder->getUrl("opn/redirect/index/$key/".$id);
        if( !empty($extra) )
            $url = $url."?".$extra;

        return $url;
    }

    // 3DS challenge/method steps run inside an iframe and must land on a page that
    // does nothing but postMessage() the ACS result to the parent window — never a
    // page that finalises the order itself (the browser owns the 3DS state machine;
    // Controller/Order/Continue3ds.php is the only place that talks to the gateway).
    /**
     * True while an order is still waiting on a gateway-hosted form (Hosted, Modal or
     * Embedded) that the shopper has not completed: the hand-off put it in pending_payment
     * and no gateway result has been recorded against it. Only such an order may have its
     * basket handed back. The order itself is left alone, exactly as if the tab was closed.
     */
    public function isAbandonedHostedOrder($order): bool
    {
        $payment = $order && $order->getId() ? $order->getPayment() : null;

        return $payment
            && $payment->getMethod() === $this->gateway_key
            && $order->getState() === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT
            && !$payment->getAdditionalInformation('status');
    }

    public function getThreeDSCallbackUrl($paymentId){
        $urlBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface');
        return $urlBuilder->getUrl("opn/order/threedscallback/payment_id/".$paymentId);
    }

    // Stamps every session field Magento's native checkout/onepage/success page requires
    // (\Magento\Checkout\Model\Session\SuccessValidator checks LastSuccessQuoteId,
    // LastQuoteId and LastOrderId — all three, not just LastOrderId) and returns its URL,
    // so the caller can redirect straight there — matching how PayPal/Braintree/Stripe
    // integrations complete an order in this same codebase, and how C11 fixed the 3DS
    // continuation flow. Routing a completed order through opn/order/index first was found
    // to lose these session fields between the two separate HTTP requests for reasons never
    // fully root-caused; going directly avoids that hop entirely. $order->getQuoteId() is
    // used for the quote fields, not the ambient checkout-session quote, since product-page/
    // cart-express orders use a standalone quote that was never the customer's session quote.
    public function redirectToCheckoutSuccess($order){
        $checkoutSession = \Magento\Framework\App\ObjectManager::getInstance()->get(\Magento\Checkout\Model\Session::class);
        $checkoutSession->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus())
            ->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId());

        $urlBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface');
        return $urlBuilder->getUrl('checkout/onepage/success');
    }

    public function getSuccessUrl($id, $key = 'id', $extra = ''){
        $urlBuilder = \Magento\Framework\App\ObjectManager::getInstance()->get('Magento\Framework\UrlInterface');
        $url = $urlBuilder->getUrl("opn/order/index/$key/".$id);

        if (!empty($extra)) {
            $url = $url . (strpos($url, '?') !== false ? '&' : '?') . $extra;
        }

        return $url;
    }

    public function renderForm($url, $request = [], $action = 'POST', $btnTxt = 'Pay Now', $target = ''){

        ob_start();

        if( $action == 'GET'){
            $url = $url.(strpos($url,'?') !== false ? '&' : '?').http_build_query($request);
        ?>
        <script>
            window.location.href = <?= json_encode($url, self::JS_JSON_FLAGS) ?>;
        </script>
        <?php
        } else {
        $eUrl    = htmlspecialchars($url,    ENT_QUOTES, 'UTF-8');
        $eAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $eBtnTxt = htmlspecialchars($btnTxt, ENT_QUOTES, 'UTF-8');
    ?>
        <form id="form" method="<?php echo $eAction;?>" novalidate="novalidate" lang="en" action="<?php echo $eUrl;?>"<?php if( $target !== '' ){ ?> target="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8');?>"<?php } ?>>
            <?php foreach ($request as $key => $value) {
                $eKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
                ?>
                <?php if( is_array($value) ){
                    foreach($value as $_k => $_v){
                        $e_k = htmlspecialchars($_k, ENT_QUOTES, 'UTF-8');
                        if( is_array($_v) ){
                            foreach($_v as $_k2 => $_v2){
                                $e_k2 = htmlspecialchars($_k2, ENT_QUOTES, 'UTF-8');
                                $e_v2 = htmlspecialchars($_v2, ENT_QUOTES, 'UTF-8');
                            ?>
                                    <input type="hidden" name="<?php echo $eKey;?>[<?php echo $e_k;?>][<?php echo $e_k2;?>]" value="<?php echo $e_v2;?>">
                            <?php
                            }
                        } else {
                            $e_v = htmlspecialchars($_v, ENT_QUOTES, 'UTF-8');
                ?>
                    <input type="hidden" name="<?php echo $eKey;?>[<?php echo $e_k;?>]" value="<?php echo $e_v;?>">
                <?php
                        }
                    }
                } else {
                    $eValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                ?>
                    <input type="hidden" name="<?php echo $eKey;?>" value="<?php echo $eValue;?>">
                <?php } ?>
            <?php } ?>
            <input type="submit" value="<?php echo $eBtnTxt;?>" id="button-confirm" class="btn btn-primary btn-pay-now action primary" />
        </form>
    <?php
        }
        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

    /**
     * The gateway page a hosted-form request is POSTed to. The Custom Hosted Form URL
     * (queue_gateway_url) is a per-merchant override honoured as a COMPLETE URL with no
     * suffix appended (Cardstream, 31/08/2026); otherwise $path under the gateway base.
     * One resolver for both the Hosted redirect (paymentform/) and Embedded (hosted/) so the
     * two cannot drift apart again.
     */
    public function getHostedFormUrl(array $settings, string $path): string
    {
        $custom = trim((string)($settings['queue_gateway_url'] ?? ''));
        return $custom !== '' ? $custom : rtrim((string)$settings['gateway_url'], '/') . '/' . ltrim($path, '/');
    }

    public function renderHTML($type, $order_id, $settings, $request, $btnTxt = "Pay now"){
        $integration_type = $settings['integration_type'];
        $purchase_type = $settings['purchase_type'];
 
        $this->log("Order #".$order_id, $integration_type.'_'.$purchase_type, $request, 'Form');
        ob_start();
    ?>
        <?php if( $integration_type == 'modal' ){ ?>
            <div id="dv_confirm" ></div>

            <script type="text/javascript">
                function loadPayment(){

                    var data = {
                        id: 'payment-form',
                        url: <?= json_encode($settings['gateway_url'] . 'hosted/modal/', self::JS_JSON_FLAGS) ?>,
                        modal: true,
                        data: <?php echo json_encode($request, self::JS_JSON_FLAGS);?>,
                        submit: {
                            type: 'button',
                            label: '<?php echo $btnTxt;?>',
                            attrs: { class: 'btn btn-primary btn-pay-now action primary' },
                        }
                    };

                    try{
                        var form = new window.hostedForms.classes.Form('dv_confirm', data);

                    } catch(e) {
                        alert("<?php __("Unable to process payment");?>");
                    }
                }
            </script>
             <script  type="text/javascript" onload="loadPayment()" src="<?php echo $settings['gateway_url'];?>sdk/web/v1/js/hostedforms.min.js"></script>

        <?php } else if( $integration_type == 'embedded' ){
            // Embedded = the ordinary Hosted Payment Form POSTed into an iframe on OUR page.
            // The hostedForms SDK has no inline mode: with modal:false it merely auto-submits a
            // plain form, which navigates the whole browser to the gateway (reported
            // 21/09/2026). allow="payment *" lets Apple Pay run inside the cross-origin frame.
            $frameName = 'opn-embedded-frame';
        ?>
            <iframe name="<?php echo $frameName;?>" title="<?php echo htmlspecialchars((string)__('Secure payment form'), ENT_QUOTES, 'UTF-8');?>" allow="payment *" style="width:100%;min-height:760px;border:0;"></iframe>
            <div style="display:none;">
                <?php echo $this->renderForm($this->getHostedFormUrl($settings, 'hosted/'), $request, 'POST', $btnTxt, $frameName); ?>
            </div>
            <script type="text/javascript">
                document.querySelector('form[target="<?php echo $frameName;?>"]').submit();
            </script>

        <?php } else {
            echo $this->renderForm($this->getHostedFormUrl($settings, 'paymentform/'), $request, 'POST', $btnTxt);
        }
        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

    public function saveCard($customerId, $response){

        // Every exit below is silent to the shopper on purpose (a failed vault save must
        // never break a successful payment), so log WHY nothing was stored — "ticked Save
        // Card, no card offered next time" is otherwise undiagnosable (reported 31/08/2026).
        $missing = [];
        foreach (['walletID','cardID','cardToken','cardType','cardNumberMask'] as $field) {
            if (empty($response[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($response['walletStoreResponseCode'])) {
            $missing[] = 'walletStoreResponseCode='.$response['walletStoreResponseCode'];
        }

        if (!empty($customerId) && empty($missing)) {

            try {
                return $this->paymentCards->save(
                    $customerId,
                    $response['walletID'].'|'.$response['cardID'].'|'.$response['cardToken'],
                    $response['cardType'],
                    $response['cardNumberMask'],
                    $response['cardExpiryYear'],
                    $response['cardExpiryMonth'],
                );
            } catch (\Exception $e) {
                $this->log('Save Card', 'save_card', ['error' => $e->getMessage()], 'Vault save failed');
                return false;
            }
        }

        $this->log('Save Card', 'save_card', [
            'customer_id' => (int)$customerId,
            'missing_or_refused' => $missing,
        ], 'Card NOT stored — gateway response lacks card-on-file fields');

        return false;
    }
    
    public function getPaymentOptions($method){

        if( $method == 'ppro' ){
            return [
                ['code'=>'affinbank', 'name'=>'Affin bank'],
                ['code'=>'seveneleven', 'name'=>'7-Eleven'],
                ['code'=>'alipay', 'name'=>'AliPay'],
                ['code'=>'ambank', 'name'=>'AmBank'],
                ['code'=>'argencard', 'name'=>'Argencard'],
                ['code'=>'astropaycard', 'name'=>'AstroPay Card'],
                ['code'=>'astropaydirect', 'name'=>'AstroPay Direct'],
                ['code'=>'aura', 'name'=>'Aura'],
                ['code'=>'baloto', 'name'=>'Baloto'],
                ['code'=>'banamex', 'name'=>'Banamex'],
                ['code'=>'bancodobrasil', 'name'=>'Banco do Brasil'],
                ['code'=>'bancodechile', 'name'=>'Banco de Chile'],
                ['code'=>'bancodeoccidente', 'name'=>'Banco de Occidente'],
                ['code'=>'bancomer', 'name'=>'Bancomer'],
                ['code'=>'bankislam', 'name'=>'Bank Islam'],
                ['code'=>'bcmc', 'name'=>'Bancontact'],
                ['code'=>'bitpay', 'name'=>'Bitpay'],
                ['code'=>'blik', 'name'=>'Blik (direct only)'],
                ['code'=>'boleto', 'name'=>'Boleto Bancario'],
                ['code'=>'bradesco', 'name'=>'Bradesco'],
                ['code'=>'cabal', 'name'=>'Cabal'],
                ['code'=>'cartaomercadolivre', 'name'=>'Cartao Mercado Livre'],
                ['code'=>'carulla', 'name'=>'Carulla'],
                ['code'=>'ccauth', 'name'=>'Credit/Debit Card'],
                ['code'=>'ccweb', 'name'=>'Credit/Debit Card'],
                ['code'=>'cencosud', 'name'=>'Cencosud'],
                ['code'=>'cimbclicks', 'name'=>'CIMB Clicks'],
                ['code'=>'cmr', 'name'=>'CMR'],
                ['code'=>'davivienda', 'name'=>'Davivienda'],
                ['code'=>'directpay', 'name'=>'Sofortüberweisung (Direct Pay)'],
                ['code'=>'dragonpay', 'name'=>'Dragonpay'],
                ['code'=>'easypay', 'name'=>'EasyPay'],
                ['code'=>'efecty', 'name'=>'Efecty'],
                ['code'=>'elo', 'name'=>'Elo'],
                ['code'=>'empresedeenergia', 'name'=>'Emprese de Energia'],
                ['code'=>'enets', 'name'=>'eNETS'],
                ['code'=>'entercash', 'name'=>'Entercash'],
                ['code'=>'eps', 'name'=>'EPS'],
                ['code'=>'estonianbanks', 'name'=>'Estonian Banks'],
                ['code'=>'giropay', 'name'=>'Giropay'],
                ['code'=>'hipercard', 'name'=>'Hipercard'],
                ['code'=>'hongleongbank', 'name'=>'Hong Leong Bank'],
                ['code'=>'ideal', 'name'=>'iDEAL'],
                ['code'=>'instanttransfer', 'name'=>'Instant Transfer'],
                ['code'=>'int_payout', 'name'=>'International Pay-Outs'],
                ['code'=>'itau', 'name'=>'Itau'],
                ['code'=>'klarna', 'name'=>'Klarna (direct only)'],
                ['code'=>'klarnapns', 'name'=>'Klarna Debit Risk (Pay Now Standalone) (direct only)'],
                ['code'=>'latvianbanks', 'name'=>'Latvian Banks'],
                ['code'=>'lithuanianbanks', 'name'=>'Lithuanian Banks'],
                ['code'=>'magna', 'name'=>'Magna'],
                ['code'=>'maxima', 'name'=>'Maxima'],
                ['code'=>'maybanktwou', 'name'=>'Maybank2u'],
                ['code'=>'multibanco', 'name'=>'Multibanco'],
                ['code'=>'mybank', 'name'=>'MyBank'],
                ['code'=>'myclearfpx', 'name'=>'MyClear FPX'],
                ['code'=>'naranja', 'name'=>'Naranja'],
                ['code'=>'narvesen', 'name'=>'Narvesen'],
                ['code'=>'nativa', 'name'=>'Nativa'],
                ['code'=>'oxxo', 'name'=>'OXXO'],
                ['code'=>'p24', 'name'=>'Przelewy24'],
                ['code'=>'p24payout', 'name'=>'Przelewy24 Payout'],
                ['code'=>'pagofacil', 'name'=>'Pago Facil'],
                ['code'=>'paypost', 'name'=>'PayPost'],
                ['code'=>'paysafecard', 'name'=>'Paysafe Card'],
                ['code'=>'paysbuy', 'name'=>'Paysbuy'],
                ['code'=>'paysera', 'name'=>'Paysera'],
                ['code'=>'payu', 'name'=>'PayU'],
                ['code'=>'perlas', 'name'=>'Perlas Terminals'],
                ['code'=>'poli', 'name'=>'OLI'],
                ['code'=>'presto', 'name'=>'Presto'],
                ['code'=>'pse', 'name'=>'PSE'],
                ['code'=>'pugglepay', 'name'=>'Pugglepay'],
                ['code'=>'qiwi', 'name'=>'QIWI'],
                ['code'=>'qiwipayout', 'name'=>'QIWI Payout'],
                ['code'=>'rapipago', 'name'=>'Rapipago'],
                ['code'=>'redpagos', 'name'=>'Redpagos'],
                ['code'=>'rhbbank', 'name'=>'RHB Bank'],
                ['code'=>'safetypay', 'name'=>'SafetyPay'],
                ['code'=>'santander', 'name'=>'Santander'],
                ['code'=>'sepadirectdebit', 'name'=>'SEPA DirectDebit'],
                ['code'=>'sepapayout', 'name'=>'SEPA Payout'],
                ['code'=>'singpost', 'name'=>'SingPost'],
                ['code'=>'skrill', 'name'=>'Skrill'],
                ['code'=>'surtimax', 'name'=>'Surtimax'],
                ['code'=>'tarjetashopping', 'name'=>'Tarjeta Shopping'],
                ['code'=>'trustly', 'name'=>'Trustly'],
                ['code'=>'trustpay', 'name'=>'TrustPay'],
                ['code'=>'twint', 'name'=>'Twint'],
                ['code'=>'unionpay', 'name'=>'UnionPay'],
                ['code'=>'verkkopankki', 'name'=>'Verkkopankki – Finish Online Banking'],
                ['code'=>'webpay', 'name'=>'Webpay'],
                ['code'=>'yellowpay', 'name'=>'Yellow Pay'],
            ];
        }

        if( $method == 'nomupay' ){
            return [
                ['code'=>'seven_eleven', 'name'=>'7-Eleven'],
                ['code'=>'boost', 'name'=>'Boost'],
                ['code'=>'duitnow', 'name'=>'DuitNow Online'],
                ['code'=>'duitnow_qr', 'name'=>'DuitNow QR'],
                ['code'=>'ecpay', 'name'=>'ECPay'],
                ['code'=>'gcash', 'name'=>'GCash'],
                ['code'=>'grabpay', 'name'=>'GrabPay'],
                ['code'=>'linepay', 'name'=>'LINE Pay'],
                ['code'=>'paymaya', 'name'=>'Maya'],
                ['code'=>'shopeepay', 'name'=>'ShopeePay'],
                ['code'=>'touch_n_go', 'name'=>'TouchAndGo'],
                ['code'=>'upop_securepay', 'name'=>'Union Pay'],
            ];
        }

        return [];
    }
}
