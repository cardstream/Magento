<?php

namespace P3\PaymentGateway\Model\Method;

use BadMethodCallException;
use Exception;
use InvalidArgumentException;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Checkout\Model\Session;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use P3\PaymentGateway\Model\Source\Integration;
use P3\SDK\AmountHelper;
use P3\SDK\Gateway;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;

class P3Method extends AbstractMethod {
    const VERIFY_ERROR = 'The signature provided in the response does not match. This response might be fraudulent';
    const PROCESS_ERROR = 'Sorry, we are unable to process this order (reason: %s). Please correct any faults and try again.';
    const SERVICE_ERROR = 'SERVICE ERROR - CONTACT ADMIN';
    const INVALID_REQUEST = 'INVALID REQUEST';

    protected $_countryFactory;
    protected $_checkoutSession;

    protected $_code = 'P3_PaymentGateway';
    protected $_isGateway              = true;
    protected $_canCapture             = true;
    protected $_canUseInternal         = true;
    protected $_canUseCheckout         = true;
    protected $_canCapturePartial      = true;
    protected $_isInitializeNeeded     = true;
    protected $_canReviewPayment       = true;
    protected $_canRefund              = true;
    protected $_canVoid                = false;

    /**
     * @var Gateway
     */
    protected $gateway;

    public $integrationType, $responsive, $countryCode, $currencyCode;

    /**
     * @var UrlInterface
     */
    public static $_urlBuilder;

    /**
     * @var OrderFactory
     */
    private $orderFactory;
    /**
     * @var BuilderInterface
     */
    private $transactionBuilder;
    /**
     * @var CustomerSession
     */
    private $customerSession;

    public function __construct(
        UrlInterface $urlBuilder,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        CustomerSession $customerSession,
        BuilderInterface $transactionBuilder,
        array $data = []
    ) {
        self::$_urlBuilder = $urlBuilder;

        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->integrationType = $this->getConfigData('integration_type');
        $this->responsive = $this->getConfigData('form_responsive') ? 'Y' : 'N';
        $this->countryCode = $this->getConfigData('country_code');
        $this->currencyCode = $this->getConfigData('currency_code');

        // Tell our template to load the integration type we need
        setcookie($this->_code . "_IntegrationMethod", $this->integrationType);

        $this->gateway = new Gateway(
            $this->getConfigData('merchant_id'),
            $this->getConfigData('merchant_shared_key'),
            $this->getConfigData('merchant_gateway_url')
        );

        $this->orderFactory = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->transactionBuilder = $transactionBuilder;
        $this->customerSession = $customerSession;
    }

    public function getOrderPlaceRedirectUrl(): string
    {
        $routeParams = [
            '_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on',
        ];

        $debug = $this->getConfigData('debug');

        if ($debug) {
            $routeParams['_query']['XDEBUG_SESSION_START'] = 'PHPSTORM';
        }

        return self::$_urlBuilder->getUrl('paymentgateway/order/process', $routeParams);
    }

    ##################################################

    public function processHostedRequest(): string
    {
        $req = array_merge(
            $this->captureOrder(),
            [
                'redirectURL'       => $this->getOrderPlaceRedirectUrl(),
                'callbackURL'       => $this->getOrderPlaceRedirectUrl(),
                'formResponsive'    => $this->responsive,
            ]
        );

        return $this->gateway->hostedRequest($req, $this->integrationType === Integration::TYPE_HOSTED_EMBEDDED, $this->integrationType === Integration::TYPE_HOSTED_MODAL);
    }

    public function processDirectRequest(): array
    {
        // v1
        if (isset($_REQUEST['MD'], $_REQUEST['PaRes'])) {
            $req = array(
                'action'	   => 'SALE',
                'merchantID'   => $this->getConfigData('merchant_id'),
                'xref'         => $_COOKIE['xref'],
                'threeDSMD'    => $_REQUEST['MD'],
                'threeDSPaRes' => $_REQUEST['PaRes'],
                'threeDSPaReq' => ($_REQUEST['PaReq'] ?? null),
            );

            return $this->gateway->directRequest($req);
        }

        // v2
        if (isset($_POST['threeDSMethodData']) || isset($_POST['cres'])) {
            $req = array(
                'merchantID' => $this->getConfigData('merchant_id'),
                'action' => 'SALE',
                // The following field must be passed to continue the 3DS request
                'threeDSRef' => $_COOKIE['threeDSRef'],
                'threeDSResponse' => $_POST,
            );

            return $this->gateway->directRequest($req);
        }

        if ($_SERVER['REQUEST_METHOD'] == 'POST'
            && isset($_POST['cardNumber'], $_POST['cardExpiryMonth'], $_POST['cardExpiryYear'], $_POST['cardCVV'], $_POST['browserInfo'])
        ) {
            $args = array_merge(
                $this->captureOrder(),
                [
                    'deviceAcceptContent'		=> (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                    'deviceAcceptEncoding'		=> (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? htmlentities($_SERVER['HTTP_ACCEPT_ENCODING']) : null),
                    'deviceAcceptLanguage'		=> (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
                    'deviceAcceptCharset'		=> (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? htmlentities($_SERVER['HTTP_ACCEPT_CHARSET']) : null),
                ],
                $_POST['browserInfo'],
                [
                    'cardNumber'           => str_replace(' ', '', $_POST['cardNumber']),
                    'cardExpiryMonth'      => $_POST['cardExpiryMonth'],
                    'cardExpiryYear'       => $_POST['cardExpiryYear'],
                    'cardCVV'              => $_POST['cardCVV'],
                    'threeDSRedirectURL'   => $this->getOrderPlaceRedirectUrl(),
                ]
            );

            $response = $this->gateway->directRequest($args);
            setcookie('xref', $response['xref'], time()+315);

            return $response;
        }

        throw new InvalidArgumentException('Something went wrong with processing direct request, please check $_POST data');
    }

    public function processResponse(array $data)
    {
        $this->createWallet($data);

        $this->gateway->verifyResponse($data, [$this, 'onThreeDSRequired'], [$this, 'onSuccessfulTransaction']);
    }

    public function onThreeDSRequired($threeDSVersion, $res) {
        setcookie('threeDSRef', $res['threeDSRef'], time()+315);

        // check for version
        echo Gateway::silentPost($res['threeDSURL'], $res['threeDSRequest']);

        if ($threeDSVersion >= 200) {
            // Silently POST the 3DS request to the ACS in the IFRAME

            // Remember the threeDSRef as need it when the ACS responds
            $_SESSION['threeDSRef'] = $res['threeDSRef'];
        }

        exit();
    }

    /**
     * @throws LocalizedException
     * @throws Exception
     * @noinspection PhpUndefinedMethodInspection
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    public function onSuccessfulTransaction($data)
    {
        $status = $this->getConfigData('successful_status');

        $orderId = str_replace('#', '', $data['orderRef']);
        $order = $this->orderFactory->create();
        $order->loadByIncrementId($orderId);

        //'Payment successful - amending order details';
        // Save payment information
        $amount = number_format($data['amountReceived'] / pow(10, $data['currencyExponent']), $data['currencyExponent'], '.', '');
        // Set order status
        if ($order->getStatus() != $status) {
            $orderMessage = ($data['responseCode'] == "0" ? "Payment Successful" : "Payment Unsuccessful") . "<br/><br/>" .
                "Amount Received: " . $amount . "<br/><br/>" .
                "Message: " . $data['responseMessage'] . "<br/>" .
                "xref: " . $data['xref'] . "<br/>" .
                "CV2 Check: " . ($data['cv2Check'] ?? 'Unknown') . "<br/>" .
                "addressCheck: " . ($data['addressCheck'] ?? 'Unknown') . "<br/>" .
                "postcodeCheck: " . ($data['postcodeCheck'] ?? 'Unknown') . "<br/>";

            if (isset($data['threeDSEnrolled'])) {
                switch ($data['threeDSEnrolled']) {
                    case "Y":
                        $enrolledText = "Enrolled.";
                        break;
                    case "N":
                        $enrolledText = "Not Enrolled.";
                        break;
                    case "U";
                        $enrolledText = "Unable To Verify.";
                        break;
                    case "E":
                        $enrolledText = "Error Verifying Enrolment.";
                        break;
                    default:
                        $enrolledText = "Integration unable to determine enrolment status.";
                        break;
                }

                $orderMessage .= "<br />3D Secure enrolled check outcome: \"$enrolledText\"";
            }

            if (isset($data['threeDSAuthenticated'])) {
                switch ($data['threeDSAuthenticated']) {
                    case "Y":
                        $authenticatedText = "Authentication Successful";
                        break;
                    case "N":
                        $authenticatedText = "Not Authenticated";
                        break;
                    case "U";
                        $authenticatedText = "Unable To Authenticate";
                        break;
                    case "A":
                        $authenticatedText = "Attempted Authentication";
                        break;
                    case "E":
                        $authenticatedText = "Error Checking Authentication";
                        break;
                    default:
                        $authenticatedText = "Integration unable to determine authentication status.";
                        break;
                }

                $orderMessage .= "<br />3D Secure authenticated check outcome: \"$authenticatedText\"";
            }

            $order->addStatusToHistory($status, $orderMessage, 0);

            $invoice = $order->prepareInvoice();

            if ($invoice->getTotalQty()) {
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->setTransactionId($data['xref']);
                $invoice->save();
            }

            $order->setBaseTotalPaid($amount)->setTotalPaid($amount);
            $order->setStatus($status);

            $payment = $order->getPayment();
            $payment->setLastTransId($data['xref']);
            $payment->setTransactionId($data['xref']);
            $payment->setAdditionalInformation([Payment\Transaction::RAW_DETAILS => $data]);
            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getTotalPaid()
            );

            $message = __('The authorized amount is %1.', $formattedPrice);
            //get the object of builder class
            $trans = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($data['xref'])
                ->setAdditionalInformation(
                    [Payment\Transaction::RAW_DETAILS => (array) $data]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build(Payment\Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );

            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();
            $transaction->save();
        }

        $this->_checkoutSession->setLastSuccessQuoteId($order->getId());
        $this->_checkoutSession->setLastQuoteId($order->getId());
        $this->_checkoutSession->setLastOrderId($order->getId());
        $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
    }

    public function onFailedTransaction($data = null) {
        if (isset($data['orderRef'], $data['responseCode'])) {
            $status = $this->getConfigData('unsuccessful_status');

            $orderId = str_replace('#', '', $data['orderRef']);
            $order = $this->orderFactory->create();
            $order->loadByIncrementId($orderId);

            $orderMessage = ($data['responseCode'] == "0" ? "Payment Successful" : "Payment Unsuccessful") . "<br/><br/>" .
                "Message: " . $data['responseMessage'] . "<br/>" .
                "xref: " . $data['xref'] . "<br/>";

            if ($order->getStatus() != $status) {
                $order->addStatusToHistory($status, $orderMessage, 0);
            }
            $order->save();
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    protected function captureOrder(): array
    {
        // Prevent any email getting sent
        $order = $this->_checkoutSession->getLastRealOrder();
        $order->setEmailSent(0);
        $status = $this->getConfigData('order_status');
        $order->setStatus($status);
        $order->save();
        $orderId = $order->getIncrementId();
        $amount = AmountHelper::calculateAmountByCurrency($order->getBaseTotalDue(), $order->getBaseCurrency()->getCode());
        $ref = '#' . $orderId;
        $billingAddress = $order->getBillingAddress();

        // Create a formatted address
        $address  = ($billingAddress->getStreetLine(1) ? $billingAddress->getStreetLine(1) . ",\n" : '');
        $address .= ($billingAddress->getStreetLine(2) ? $billingAddress->getStreetLine(2) . ",\n" : '');
        $address .= ($billingAddress->getCity() ? $billingAddress->getCity() . ",\n" : '');
        $address .= ($billingAddress->getRegion() ? $billingAddress->getRegion() . ",\n" : '');
        $address .= ($billingAddress->getCountryId() ? $billingAddress->getCountryId() : '');

        $merchantId = $this->getConfigData('merchant_id');

        $req = [
            'merchantID'        => $merchantId,
            'amount'            => $amount,
            'transactionUnique' => uniqid(),
            'orderRef'          => $ref,
            'countryCode'       => $billingAddress->getCountryId(),
            'currencyCode'      => $order->getBaseCurrency()->getCode(),
            'customerName'      => $billingAddress->getName(),
            'customerAddress'   => $address,
            'customerEmail'     => $billingAddress->getEmail(),
            'customerPHPSESSID' => $_COOKIE['PHPSESSID'],
        ];

        if (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $req['remoteAddress']   = $_SERVER['REMOTE_ADDR'];
        }

        $req['action'] = 'SALE';
        $req['type'] = 1;

        if(!is_null($billingAddress->getTelephone())) {
            $req["customerPhone"] = $billingAddress->getTelephone();
        }

        if (!is_null($billingAddress->getPostcode())) {
            // PostCode's are optional.
            $req['customerPostCode'] = $billingAddress->getPostcode();
        }

        /**
         * Wallets
         */
        if (
            $this->getConfigData('customer_wallets_enabled')
            && $this->integrationType === Integration::TYPE_HOSTED_MODAL
            && $this->customerSession->isLoggedIn()
        ) {
            //Try and find the users walletID in the wallets table.
            $connection = $this->getConnection();
            $where = $connection->select()
                ->from($connection->getTableName('payment_gateway_wallets'), ['wallet_id'])
                ->where('merchant_id = ?', $merchantId)
                ->where('customer_email = ?', $this->customerSession->getCustomer()->getEmail());

            $wallets = $connection->fetchAll($where);

            //If the customer wallet record exists.
            if (count($wallets) > 0)
            {
                //Add walletID to request.
                $req['walletID'] = $wallets[0]['wallet_id'];
            } else {
                //Create a new wallet.
                $req['walletStore'] = 'Y';
            }
            $req['walletEnabled'] = 'Y';
            $req['walletRequired'] = 'Y';
        }

        return $req;
    }

    /**
     * Wallet creation.
     *
     * A wallet will always be created if a walletID is returned. Even if payment fails.
     */
    protected function createWallet($response) {
        $merchantId = $this->getConfigData('merchant_id');
        $customerEmail = $this->customerSession->getCustomer()->getEmail();

        //when the wallets is enabled, the user is logged in and there is a wallet ID in the response.
        if ($this->getConfigData('customer_wallets_enabled')
            && $this->integrationType === Integration::TYPE_HOSTED_MODAL
            && $this->customerSession->isLoggedIn()
            && isset($response['walletID'], $merchantId, $customerEmail)) {

            $wallets = $this->getConnection()->fetchAll(
                $this->getConnection()->select()
                    ->from($this->getConnection()->getTableName('payment_gateway_wallets'), ['wallet_id'])
                    ->where('merchant_id = ?', $merchantId)
                    ->where('customer_email = ?', $customerEmail)
                    ->where('wallet_id = ?', $response['walletID'])
            );

            //If the customer wallet record does not exists.
            if (count($wallets) == 0) {
                //Add walletID to request.
                $this->getConnection()->insertArray(
                    'payment_gateway_wallets',
                    ['merchant_id', 'customer_email', 'wallet_id'],
                    [
                        [$merchantId, $customerEmail, $response['walletID']]
                    ]
                );
            }
        }
    }

    /**
     * @return AdapterInterface
     */
    protected function getConnection(): AdapterInterface
    {
        $objectManager = ObjectManager::getInstance(); // Instance of object manager
        /** @var ResourceConnection $resource */
        $resource = $objectManager->get(ResourceConnection::class);

        return $resource->getConnection();
    }

    public function canRefund()
    {
        return parent::canRefund();
    }


    /**
     * Refund capture
     *
     * @param DataObject|InfoInterface|Payment $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order = $payment->getOrder();

        if (!$this->canRefund() || $amount > $order->getTotalPaid()) {
            throw new LocalizedException(__('The refund action is not available.'));
        }

        try {
            $amount = AmountHelper::calculateAmountByCurrency($amount, $order->getBaseCurrencyCode());

            $data = $this->gateway->refundRequest($payment->getParentTransactionId(), $amount);

            $order->addStatusToHistory(Order::STATE_CLOSED, $data['message'], 0);
            $payment->setTransactionId($data['response']['xref'])->setIsTransactionClosed(true);
        } catch (Exception $exception) {
            throw new BadMethodCallException(
                __($exception->getMessage())
            );
        }
    }
}
