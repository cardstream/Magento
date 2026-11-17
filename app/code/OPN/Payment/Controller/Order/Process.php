<?php
namespace OPN\Payment\Controller\Order;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Quote\Model\Quote\Address\RateRequestFactory;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Quote\Model\Quote\Address\RateCollectorInterfaceFactory;
use Magento\Bundle\Api\ProductOptionRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use OPN\Payment\Helper\Data as PaymentHelper;

class Process extends \Magento\Framework\App\Action\Action
{
    protected $scopeConfig;
    protected $storeManager;
    protected $resultJsonFactory;
    protected $taxCalculation;
    protected $objectManager;
    protected $customerRepository;
    protected $quoteFactory;
    protected $quoteManagement;
    protected $customerSession;
    protected $shipping;
    protected $addressRateFactory;
    protected $rateCollector;
    protected $optionRepository;
    protected $orderFactory;
    protected $helper;
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        JsonFactory $resultJsonFactory,
        TaxCalculation $taxCalculation,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        CustomerRepositoryInterface $customerRepository,
        Session $customerSession,
        RateRequestFactory $shipping,
        RateFactory $addressRateFactory,
        RateCollectorInterfaceFactory $rateCollector,
        ProductOptionRepositoryInterface $optionRepository,
        OrderFactory $orderFactory,
        PaymentHelper $helper,
    ) {
        parent::__construct($context);

        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->taxCalculation = $taxCalculation;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->customerRepository = $customerRepository;
        $this->customerSession = $customerSession;
        $this->shipping = $shipping;
        $this->addressRateFactory = $addressRateFactory;
        $this->rateCollector = $rateCollector;
        $this->optionRepository  = $optionRepository;
        $this->orderFactory = $orderFactory;
        $this->helper = $helper;

        $this->objectManager = ObjectManager::getInstance();
    }

    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        $store = $this->storeManager->getStore();
        $storeId = $store->getId();
        $websiteId = $store->getWebsiteId();

        $customerId = (int)$this->customerSession->getCustomer()->getId();
        $customer = !empty($customerId) ? $this->customerRepository->getById($customerId) : null;

        $params = $this->getRequest()->getParams();
        $action = $params['action'] ?? 'get_totals';

        $resp = [
            'success' => false,
            'data' => [],
            'message' => null,
        ];

        if( $action == 'verify_merchant'){

            $settings = $this->helper->getSettings();

            $validation_url = urldecode($params['validation_url'] ?? '');

            $host = parse_url($validation_url, PHP_URL_HOST);
            if (!$host || !preg_match('/\.apple\.com$/', $host)) {
                return $resultJson->setData(['error' => 'Invalid validation URL']);
            }

            $storeBaseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            $domain = parse_url($storeBaseUrl, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');

            $request = array(
                'merchantID' => $settings['gateway_id'],
                'process' => 'applepay.validateMerchant',
                'validationURL' => $validation_url,
                'displayName' => $settings['apple_merchant_name'],
                'domainName' => $domain,
            );
        
            $request['signature'] = $this->helper->createSignature($request, $settings['signature_key']);

            $this->helper->log(0, 'Merchant Verify - Applepay ('.$settings['gateway_url'].'hosted/'.')', $request, 'Request');

            $resp = $this->helper->httpRequest('POST', $settings['gateway_url'].'hosted/', $request);

            $this->helper->log(0, 'Merchant Verify - Applepay', $resp, 'Response');

            if( !empty($resp['merchantSessionIdentifier']) )
                $resp = ['session' => $resp];

            return $resultJson->setData($resp);
        }

        if( $action == 'get_totals')
            $params = ['product' => $params];   
    
        if( $action == 'get_shippings' )
            $resp['selectedId'] = null;
        
        $address = $params['address'] ?? [];

        if( empty($address['firstname']) && !empty($address['name']) ){
            $_name = explode(" ",trim($address['name']));
            $address['firstname'] = $_name[0]; unset($_name[0]);
            $address['lastname'] = implode(" ",$_name);
        }
        unset($address['name']);

        $guestCheckoutAllowed = $this->scopeConfig->isSetFlag(
            'checkout/options/guest_checkout',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $isGuest = empty($customer) || empty($customer->getId());

        try {

            if( $isGuest && !$guestCheckoutAllowed )
                throw new \Exception((string)__("Please login to purchase this item"));

            $taxInclude = $this->scopeConfig->getValue('tax/calculation/price_includes_tax', ScopeInterface::SCOPE_STORE, $storeId);
            $region_code = $address['region_id'] ?? '';

            $regionCollection = $this->objectManager->create(\Magento\Directory\Model\ResourceModel\Region\Collection::class)->addFieldToFilter('code', $region_code)->addFieldToFilter('country_id', $address['country_id'] ?? '');
            $regionId = (int)$regionCollection->getFirstItem()->getId();
            $address['region_id'] = $regionId ?: null;

            $product_id = (int)($params['product']['product_id'] ?? ($params['product']['product'] ?? '')); 
            $qty = (int)($params['product']['qty'] ?? 1);
            $selectedOptions = $params['product']['super_attribute'] ?? ($params['product']['bundle_option'] ?? []);

            $product = $this->objectManager->get(ProductRepositoryInterface::class)->getById($product_id, false, $storeId);
            $buyQty = 0;

            if( !$product->getId() )
                throw new \Exception((string)__("This item is not available to buy"));

            $type = $product->getTypeId();
            $price = 0;
            $tax = 0;
            $weight = 0;

            $t_address = new \Magento\Framework\DataObject();
            $t_address->setCountryId($address['country_id'] ?? '');
            $t_address->setRegionId($address['region_id'] ?? '');
            $t_address->setPostcode($address['postcode'] ?? '');

            if( $type == 'configurable' ){
                $associatedProduct = $this->objectManager->create('Magento\ConfigurableProduct\Model\Product\Type\Configurable')->getProductByAttributes($selectedOptions, $product);
                $price = !empty($associatedProduct) ? (float)$associatedProduct->getFinalPrice() : 0;
                $buyQty = $qty;
            } else if( $type == 'grouped' ){

                $super_group = $params['product']['super_group'] ?? [];
                foreach($super_group as $pid => $_qty){
                    $_product = $this->objectManager->get(ProductRepositoryInterface::class)->getById($pid, false, $storeId);
                    if( $_product->getId() ){
                        $_price = $_product->getPrice() ;
                        $price += round($_price * $_qty, 2);
                        $tax += round($this->getTax($t_address, $_product, $_price) * $_qty, 2);
                        $weight += round((float)$product->getWeight() * $_qty,3);
                        $buyQty += $_qty;
                    }
                }

            } else if( $type == 'bundle' ){

                $bundleQty = $params['product']['bundle_option_qty'] ?? [];

                $typeInstance = $product->getTypeInstance();
                $typeInstance->setStoreFilter($product->getStoreId(), $product);

                $optionCollection = $typeInstance->getOptionsCollection($product);
                $selectionCollection = $typeInstance->getSelectionsCollection($typeInstance->getOptionsIds($product), $product);

                $this->objectManager->get(\Magento\CatalogRule\Model\ResourceModel\Product\CollectionProcessor::class)->addPriceData($selectionCollection);
                $selectionCollection->addTierPriceData();

                $options = $optionCollection->appendSelections(
                    $selectionCollection,
                    false,
                    $product->getSkipSaleableCheck()
                );

                $mainPrice = $product->getPrice();
                $price = $mainPrice;
                foreach ($options as $option){

                    $opn_id = $option->getOptionId();
                    if( !empty($selectedOptions[$opn_id]) ){

                        foreach($option->getSelections() as $slc){
                            if( $slc->getSelectionId() == $selectedOptions[$opn_id] ){
                                $_qty = !empty($bundleQty[$opn_id]) ? (int)$bundleQty[$opn_id] : (($slc->getSelectionQty() * 1) ?: 1);
                                $optionPriceAmount = $product->getPriceInfo()->getPrice(\Magento\Bundle\Pricing\Price\BundleOptionPrice::PRICE_CODE)->getOptionSelectionAmount($slc);

                                $_price = (float)$optionPriceAmount->getValue();
                                $optionTotal = round($_price * $_qty, 2);
                                $price += $optionTotal;
                                $tax += $this->getTax($t_address, $slc, $optionTotal);
                            }
                        }
                    }
                }

                $buyQty += $qty;

            } else {
                $price = (float)$product->getPrice();
                $buyQty += $qty;

            }

            if( $price <=0 )
                throw new \Exception((string)__("You cannot purchase this product at this time"));

            $subtotal = round($price * ($action == 'get_shippings' ? 1 : $qty),2);

            if( !in_array($type, ['grouped','bundle']) )
                $tax = round( $this->getTax($t_address, $product, $price) * $qty, 2);
            
            if( $type == 'bundle')
                $tax = round($tax * $qty,2);

            if( $type != 'grouped' )
                $weight = round((float)$product->getWeight() * $qty,3);
            
            $total = $subtotal + $tax;

            if( $action == 'place_order' ){

                // Guest orders MUST carry a valid email before the quote is submitted.
                // Without this, an empty email reached Magento's order address validator and
                // came back as "Failed address validation: Email has a wrong format" — a raw
                // internal exception rendered inside the customer's Google Pay / Apple Pay
                // sheet. Cart/Express.php has always guarded this; the product page did not.
                $customerEmail = trim($address['email'] ?? ($params['email'] ?? ''));

                if( $isGuest && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL) ){
                    $this->helper->log(
                        'Express place_order rejected',
                        'missing_email',
                        ['method' => $params['method'] ?? '', 'supplied' => $customerEmail],
                        'Validation'
                    );
                    throw new \Exception((string)__('A valid email address is required to complete this purchase.'));
                }

                // Carried onto both quote addresses by the addData() calls further down.
                if( !empty($customerEmail) )
                    $address['email'] = $customerEmail;

                $quote_id = 0;
                $order_id = 0;
                $order = null;

                $walletMethod = trim($params['method'] ?? '');
                $isWallet = in_array($walletMethod, ['applepay', 'googlepay'], true);

                $buy_product_order = $this->customerSession->getMyOrder() ?? [];
                $os_key = hash('sha256', $product_id . json_encode($selectedOptions) . $storeId . $customerId);

                // This 20-minute session cache exists to stop a double-click on "Buy Now"
                // from stacking up duplicate orders for the same product.
                //
                // It must NEVER be consulted for a wallet payment. Apple Pay / Google Pay
                // tokens are single-use, and the gateway call happens inside
                // DataAssignObserver when the NEW quote's payment is imported — so reusing
                // an existing order skips the gateway entirely and throws away the token
                // the shopper just authorised. That is what made a second Google Pay
                // purchase of the same product within 20 minutes show the FIRST order's
                // confirmation page with no 3DS, no new order and no transaction
                // (reported 27/08/2026).
                if( !$isWallet && !empty($buy_product_order[$os_key]['time']) ){
                    if( (time() - $buy_product_order[$os_key]['time']) <= 60*20 ){
                        $quote_id = $buy_product_order[$os_key]['quote_id'] ?? 0;
                        // Parenthesised: (int)$x ?? 0 casts before the null-coalesce, so a
                        // missing key raised an "Undefined array key" warning instead.
                        $order_id = (int)($buy_product_order[$os_key]['order_id'] ?? 0);
                    }
                }

                $shipping = $params['shipping'] ?? [];

                if( empty($shipping['id']) )
                    throw new \Exception((string)__("Shipping not available for selected address"));

                $shipping_method = $shipping['id'];
                $payment_method = 'qidigitalpayment';

                // $shipping price is resolved server-side after rate collection (F-NEW-02 fix)
                $shipping = 0.0;

                if( !empty($order_id) ){
                    $candidate = $this->orderFactory->create()->load($order_id);

                    // Only an order that is still an unfinished payment attempt may be
                    // reused. A paid, invoiced, cancelled or missing one is a finished
                    // transaction and must not be handed back as if it were this purchase.
                    if( $candidate->getId() && $this->isAwaitingPayment($candidate) ){
                        $order = $candidate;
                    } else {
                        unset($buy_product_order[$os_key]);
                        $this->customerSession->setMyOrder($buy_product_order);
                        $order_id = 0;
                        // The cached quote belongs to that finished order — it is already
                        // converted and inactive, so it must not seed the new one either.
                        $quote_id = 0;
                    }
                }

                if( !empty($order) && $order->getId() ){
                    $buy_product_order[$os_key]['time'] = time();
                    $this->customerSession->setMyOrder($buy_product_order);

                } else {

                    // create quote
                    $quote = $this->quoteFactory->create();
                    if( !empty($quote_id) )
                        $quote->load($quote_id);
                    
                    $quote->setStore($store);

                    // Assign customer or set up guest quote
                    if( !$isGuest && $customer ){
                        $quote->assignCustomer($customer);
                    } else {
                        $quote->setCustomerIsGuest(true);
                        $quote->setCustomerEmail($customerEmail);
                        $firstname = $address['firstname'] ?? '';
                        $lastname  = $address['lastname'] ?? '';
                        if( empty($firstname) && !empty($address['name']) ){
                            $parts = explode(' ', trim($address['name']), 2);
                            $firstname = $parts[0];
                            $lastname  = $parts[1] ?? '';
                        }
                        $quote->setCustomerFirstname($firstname);
                        $quote->setCustomerLastname($lastname);
                    }

                    if( $type == 'grouped' ){
                        $super_group = $params['product']['super_group'] ?? [];
                        foreach($super_group as $pid => $_qty){
                            $_product = $this->objectManager->get(ProductRepositoryInterface::class)->getById($pid, false, $storeId);
                            $quote->addProduct($_product, intval($_qty));
                        } 
                    } else {
                        // The buy request must carry the shopper's choices (super_attribute,
                        // bundle_option, options). Given a bare qty, Quote::addProduct() *returns*
                        // "You need to choose options for your item." rather than throwing, the
                        // quote stays empty, and the wallet token went to the gateway with
                        // amount 0: "Invalid amount" on any configurable product (22/10/2026).
                        $added = $quote->addProduct($product, new \Magento\Framework\DataObject(['qty' => $qty] + $params['product']));
                        if( is_string($added) )
                            throw new \Exception($added);
                    }

                    // Set addresses
                    $quote->getBillingAddress()->addData($address);
                    $quote->getShippingAddress()->addData($address)
                        ->setCollectShippingRates(true)
                        ->collectShippingRates()
                        ->setShippingMethod($shipping_method)
                        ->setPaymentMethod($payment_method);

                    // Resolve shipping price server-side — never trust the client-supplied value (F-NEW-02)
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAddress->setCollectShippingRates(true)->collectShippingRates();
                    $shippingRate = $shippingAddress->getShippingRateByCode($shipping_method);
                    if (!$shippingRate) {
                        throw new \Exception((string)__("Selected shipping method is no longer available."));
                    }
                    $shipping = (float)$shippingRate->getPrice();
                    $total    = $subtotal + $tax + $shipping;
                    $shippingAddress->setShippingMethod($shipping_method);
                    $shippingAddress->setShippingAmount($shipping);
                    $shippingAddress->setBaseShippingAmount($shipping);
                    $shippingAddress->setTaxAmount($tax);
                    $shippingAddress->setBaseTaxAmount($tax);
        
                    // Set payment method
                    $quote->setPaymentMethod($payment_method); 
                    $quote->setInventoryProcessed(false);
                    $quote->getShippingAddress()->setPaymentMethod($payment_method);
                    $quote->getPayment()->setMethod($payment_method);

                    // Collect totals & save — let Magento own the grand total
                    $quote->setTotalsCollectedFlag(false)->collectTotals();

                    $quote->save();

                    // Wallet payments (Apple Pay / Google Pay) are processed via the Direct API
                    // inside DataAssignObserver. importData must be called before submit so the
                    // observer sees the token and wallet method on the correct quote payment.
                    if ($isWallet) {
                        $browserInfo = $params['browserInfo'] ?? [];
                        $quote->getPayment()->importData([
                            'method'          => $payment_method,
                            'additional_data' => [
                                'method'      => $walletMethod,
                                'token'       => $params['token'] ?? '',
                                'browserInfo' => is_array($browserInfo) ? json_encode($browserInfo) : ($browserInfo ?: '{}'),
                            ],
                        ]);
                    }

                    $order = $this->quoteManagement->submit($quote);
                    if( empty($order) )
                        throw new \Exception((string)__("You cannot purchase this product at this time"));

                    $buy_product_order[$os_key] = [
                        'time' => time(),
                        'quote_id' => $quote->getId(),
                        'order_id' => $order->getId(),
                    ];
                    
                    $this->customerSession->setMyOrder($buy_product_order);
                }

                if( $order && $order->getId() ){

                    // Stamp the order ID in the checkout session so the guest-order ownership
                    // checks in Controller/Order/Index and Controller/Order/Threedsecure pass —
                    // this "Buy Now" flow never otherwise touches the checkout session.
                    $this->objectManager->get(\Magento\Checkout\Model\Session::class)
                        ->setLastOrderId($order->getId());

                    if ($isWallet) {
                        // Wallet: Direct API already ran in DataAssignObserver — skip hosted redirect.
                        $resp['success'] = true;
                        $pending3ds = $order->getPayment()->getAdditionalInformation('redirect_3d_auth');
                        if (!empty($pending3ds['url'])) {
                            // Gateway requires 3-D Secure — send the browser to the ACS challenge
                            // instead of the order status page.
                            $resp['redirect'] = $this->_url->getUrl('opn/order/threedsecure', ['id' => $order->getId()]);
                            $resp['message'] = (string)__('Additional verification required.');
                        } else {
                            // Paid outright (no 3DS step) — send the browser directly to
                            // Magento's native success page rather than opn/order/index,
                            // which was found to lose required session state on the extra hop.
                            // A wallet order is always freshly placed above, never reused.
                            $resp['redirect'] = $this->helper->redirectToCheckoutSuccess($order);
                            $resp['message'] = (string)__('Payment processed successfully.');
                        }
                    } else {
                        // Hosted/modal/embedded: send customer to gateway payment form.
                        $redirect_url = $this->helper->getRedirectUrl($order->getId());
                        $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                              ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                        $order->save();
                        $resp['success'] = true;
                        $resp['redirect'] = $redirect_url;
                        $resp['message'] = (string)__('Redirecting to hosted payment page.');
                    }
                }

            } else if( $action == 'get_shippings' ){

                $baseSubtotal = $baseSubtotalInclTax = $subtotal;
                if( $taxInclude )
                    $baseSubtotalInclTax = $baseSubtotal + $tax;

                $request = $this->shipping->create();        
              
                $request->setDestCountryId($address['country_id'] ?? '');
                $request->setDestRegionId($address['region_id'] ?? '');
                $request->setDestRegionCode($region_code);
                $request->setDestStreet($address['street'] ?? []);
                $request->setDestCity($address['city'] ?? '');
                $request->setDestPostcode($address['postcode'] ?? '');

                $request->setPackageValue($baseSubtotal);
                $request->setPackageValueWithDiscount($baseSubtotal);
                $request->setPackageWeight($weight);
                $request->setPackageQty(1);
                $request->setPackagePhysicalValue($baseSubtotal);
                $request->setFreeMethodWeight(0);
        
                $request->setStoreId($storeId);
                $request->setWebsiteId($this->storeManager->getStore($storeId)->getWebsiteId());
                $request->setFreeShipping(0);

                $request->setBaseCurrency($this->storeManager->getStore()->getBaseCurrency());
                $request->setPackageCurrency($this->storeManager->getStore()->getCurrentCurrency());

                $request->setLimitCarrier(null);
                $request->setBaseSubtotalInclTax($baseSubtotalInclTax);
                $request->setBaseSubtotalWithDiscountInclTax($baseSubtotalInclTax);

                $result = $this->rateCollector->create()->collectRates($request)->getResult();
                $rates = [];

                if ($result) {
                
                    $shippingRates = $result->getAllRates();
                    foreach ($shippingRates as $shippingRate) {
                        
                        $rate = $this->addressRateFactory->create()->importShippingRate($shippingRate);
                        if( !empty($rate['code']) ){
                    
                            if( empty($resp['selectedId']) )
                                $resp['selectedId'] = $rate['code'];

                            $rates[$rate['code']] = [
                                'id' =>$rate['code'],
                                'label' => $rate['carrier_title'],
                                'description' => '' ,
                                'price' => number_format((float)$rate['price'], 2, '.', ''),
                            ];
                        }
                    }   
                }

                $resp['data'] = $rates;

                if( empty($rates) )
                    throw new \Exception((string)__("Shipping rates not available for selected address"));

             } else if( $action == 'get_totals' ){
                $resp['data'] = [
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $subtotal + $tax,
                ];
            }    

        } catch (\Exception $e) {
            $resp['message'] = $e->getMessage();
        }

        if( empty($resp['message']) )
            $resp['success'] = true;

        return $resultJson->setData($resp);
    }

    /**
     * True only while an order is still an unfinished payment attempt, i.e. it may
     * legitimately be reused by the "Buy Now" duplicate-click cache instead of placing
     * a second order. Anything paid, invoiced, cancelled or closed is a completed
     * transaction and must never be reused.
     */
    private function isAwaitingPayment(Order $order): bool
    {
        if (!in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true)) {
            return false;
        }

        $payment = $order->getPayment();
        if ($payment && (string)$payment->getAdditionalInformation('status') === 'success') {
            return false;
        }

        return (float)$order->getTotalInvoiced() <= 0;
    }

    private function getTax($address, $product, $price){
        
        $taxId = $product->getTaxClassId();
        $request = $this->taxCalculation->getRateRequest($address, $address, null, $this->storeManager->getStore());
        $request->setProductClassId($taxId);
        $rate = $this->taxCalculation->getRate($request);
        
        return round( $price * $rate / 100, 3);
    }

}