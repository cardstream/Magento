<?php

namespace Cardstream\PaymentGateway\Model\Method;

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
use Cardstream\PaymentGateway\Model\Source\Integration;
use Cardstream\SDK\AmountHelper;
use Cardstream\SDK\Gateway;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;

class CardstreamMethod extends AbstractMethod {
    
    const VERIFY_ERROR      = 'The signature provided in the response does not match. This response might be fraudulent';
    const PROCESS_ERROR     = 'Sorry, we are unable to process this order (reason: %s). Please correct any faults and try again.';
    const SERVICE_ERROR     = 'SERVICE ERROR - CONTACT ADMIN';
    const INVALID_REQUEST   = 'INVALID REQUEST';

    protected $_countryFactory;
    protected $_checkoutSession;

    protected $_code                   = 'Cardstream_PaymentGateway';
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

    public $integrationType, $responsive, $countryCode;

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
        OrderFactory $orderFactory,
        Session $checkoutSession,
        CustomerSession $customerSession,
        BuilderInterface $transactionBuilder,
        InvoiceSender $invoiceSender,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        self::$_urlBuilder = $urlBuilder;
        $this->invoiceSender = $invoiceSender;

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
        $this->redirectToCheckoutOnPayFail = ($this->getConfigData('redirect_to_checkout_on_failed_payment') ? true : false);
        $this->sendCustomerInvoice = ($this->getConfigData('send_customer_sale_invoice') ? true : false);

        // Tell our template to load the integration type we need
        setcookie($this->_code . "_IntegrationMethod", $this->integrationType, [
            'expires' => time() + 500,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => false,
            'samesite' => 'None'
        ]);

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

        return self::$_urlBuilder->getUrl('paymentgateway/order/process', $routeParams);
    }

    ##################################################

    public function processHostedRequest($returnRequest = false): string|array
    {
        $req = array_merge(
            $this->captureOrder(),
            [
                'redirectURL'       => $this->getOrderPlaceRedirectUrl(),
                'callbackURL'       => $this->getOrderPlaceRedirectUrl(),
                'formResponsive'    => $this->responsive,
            ]
        );

        if ($returnRequest) {
            $req['gatewayURL'] = $this->getConfigData('merchant_gateway_url') . '/hosted/';
            $req['signature'] = $this->gateway->sign($req, $this->getConfigData('merchant_shared_key'));
            return $req;
        } else {
            return $this->gateway->hostedRequest($req, false, $this->integrationType === Integration::TYPE_HOSTED_MODAL);
        }
    }

    public function processDirectRequest(): array
    {
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
            return $response;
        }

        throw new InvalidArgumentException('Something went wrong with processing direct request, please check $_POST data');
    }

    public function processResponse(array $response)
    {
        if ($this->gateway->verifyResponse($response)) {

            if ((int)$response['responseCode'] === 65802) {
                return $this->onThreeDSRequired($response);
            } else if ((int)$response['responseCode'] === 0) {
                // Create the wallet entry Module side.
                $this->createWallet($response);
                return $this->onSuccessfulTransaction($response);
            } else if ((int)$response['responseCode'] !== 0) {
                return $this->onFailedTransaction($response);
            }
        
        }
    }

    public function onThreeDSRequired($res) {

        setcookie('threeDSRef', $res['threeDSRef'], [
            'expires' => time() + 500,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => false,
            'samesite' => 'None'
        ]);

        $_SESSION['threeDSRef'] = $res['threeDSRef'];

        $result = ['responseCode'=> 65802, 'threeDSRequest' => $res['threeDSRequest'], 'threeDSURL' => $res['threeDSURL']];

        return $result;
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

                if ($this->sendCustomerInvoice) {
                    $this->invoiceSender->send($invoice);
                    $order->addCommentToStatusHistory(
                        __('Notified customer about invoice creation #%1.', $invoice->getId())
                       )->setIsCustomerNotified(true)->save();
                }
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

            $data['lastOrderID'] = $order->getIncrementId();
        }

        return $data;
    }

    public function onFailedTransaction($data) {

        if (isset($data['orderRef'], $data['responseCode'])) {
            $status = $this->getConfigData('unsuccessful_status');

            $orderId = str_replace('#', '', $data['orderRef']);
            $order = $this->orderFactory->create();
            $order->loadByIncrementId($orderId);

            $orderMessage = "Payment Unsuccessful <br/><br/>" .
                "Message: " . $data['responseMessage'] . "<br/>";

            if (isset($data['xref'])) {
                $orderMessage .= "xref: " . $data['xref'] . "<br/>";
            }

            $data['lastOrderID'] = $order->getIncrementId();
            
            $order->addStatusToHistory($status, $orderMessage, 0);
            $order->save();
        }

        $this->_checkoutSession->setLastSuccessQuoteId($order->getId());
        $this->_checkoutSession->setLastQuoteId($order->getId());
        $this->_checkoutSession->setLastOrderId($order->getId());
        $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());

        return $data;
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
        $amount = AmountHelper::calculateAmountByCurrency($order->getTotalDue(), $order->getOrderCurrencyCode());
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
            'countryCode'       => $this->countryCode,
            'currencyCode'      => $order->getOrderCurrencyCode(),
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

        if (!is_null($billingAddress->getTelephone())) {
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
            if (count($wallets) > 0) {
                //Add walletID to request.
                $req['walletID'] = $wallets[0]['wallet_id'];
            } 
            $req['walletEnabled'] = 'Y';
            $req['walletRequired'] = 'Y';
        }

        // Save the orderID as the lastOrderID to a cookie 
        // so the customer can be tracked when they return from HPF or ACS.
        setcookie('lastOrderID', $orderId, [
            'expires' => time() + 500,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => false,
            'samesite' => 'None'
        ]);

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
