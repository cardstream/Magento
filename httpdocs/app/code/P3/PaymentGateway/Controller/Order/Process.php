<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   P3
 * @package    PaymentGateway
 * @copyright  Copyright (c) 2017 P3
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace P3\PaymentGateway\Controller\Order;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\OrderFactory;
use P3\PaymentGateway\Model\Gateway;
use Psr\Log\LoggerInterface;

class Process implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface {
    /**
     * @var Gateway
     */
    private $gateway;

    /**
     * @var OrderFactory
     */
    private $orderFactory;

    /**
     * @var RedirectFactory
     */
    private $resultRedirectFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var Session
     */
    private $checkoutSession;
    /**
     * @var ManagerInterface
     */
    private $messageManager;

    public function __construct(
		Gateway $model,
        RedirectFactory $resultRedirectFactory,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        Session $checkoutSession,
        ManagerInterface $messageManager
	) {
		$this->gateway = $model;
        $this->orderFactory = $orderFactory;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
    }

	public function isPaymentSubmission() {
		return (
			// Make sure we have something to submit for payment
			$_SERVER['REQUEST_METHOD'] == 'GET' && $this->checkoutSession->hasQuote() === false && $this->checkoutSession->getLastRealOrder()
		) || (
			// Check when we have to go through 3DS
			$_SERVER['REQUEST_METHOD'] == 'POST' &&
			isset($_POST['MD']) && (
				isset($_POST['PaRes']) ||
				isset($_POST['PaReq'])
			)
		) || (
            $_SERVER['REQUEST_METHOD'] == 'POST' &&
            isset($_POST['cardNumber'], $_POST['cardExpiryMonth'], $_POST['cardExpiryYear'], $_POST['cardCVV'], $_POST['step'])
        );
	}

	public function execute() {
        $isPaymentSubmission = $this->isPaymentSubmission();

        $redirectURL = $this->gateway->getOrderPlaceRedirectUrl();

        $options = [
            'pageUrl' => $redirectURL.'?',
            'redirectURL' => $redirectURL,
            'merchantID' => $this->gateway->merchantId,
            'secret' => $this->gateway->secret,
            'integrationType' => $this->gateway->integrationType,
            'responsive' => $this->gateway->responsive,
        ];

        try {
            if ($isPaymentSubmission) {
                $isContinuationResponse = isset($_POST['MD']) && (
                        isset($_POST['PaRes']) ||
                        isset($_POST['PaReq'])
                    );

                $orderData = !$isContinuationResponse ? $this->captureOrder() : null;

                $res = Gateway::processRequest($orderData, $options);

                if ('html' === $res['type']) {
                    echo $res['response'];
                    exit;
                }
            }

            $status = $this->gateway->getConfigData('successful_status');

            $onSuccessfulTransaction = function ($data) use ($status) {
                $orderId = str_replace('#', '', $data['orderRef']);
                $order = $this->orderFactory->create();
                $order->loadByIncrementId($orderId);

                //'Payment successful - ammending order details';
                // Save payment information
                $amount = intval($data['amountReceived']) / 100;
                // Set order status
                if ($order->getStatus() != $status) {
                    $order->setBaseTotalPaid($amount)->setTotalPaid($amount);

                    // Create invoice if we can
                    $order->setStatus($status);

                    $ordermessage = "P3 Payment<br/><br/>" .
                        ($data['responseCode'] == "0" ? "Payment Successful" : "Payment Unsuccessful") . "<br/><br/>" .
                        "Amount Received: " . (isset($data['amountReceived']) ? floatval($data['amountReceived']) / 100 : "None") . "<br/><br/>" .
                        "Message: " . $data['responseMessage'] . "<br/>" .
                        "xref: " . $data['xref'] . "<br/>" .
                        "CV2 Check: " . (isset($data['cv2Check']) ? $data['cv2Check'] : 'Unknown') . "<br/>" .
                        "addressCheck: " . (isset($data['addressCheck']) ? $data['addressCheck'] : 'Unknown') . "<br/>" .
                        "postcodeCheck: " . (isset($data['postcodeCheck']) ? $data['postcodeCheck'] : 'Unknown') . "<br/>";

                    if (isset($data['threeDSEnrolled'])) {
                        switch ($data['threeDSEnrolled']) {
                            case "Y":
                                $enrolledtext = "Enrolled.";
                                break;
                            case "N":
                                $enrolledtext = "Not Enrolled.";
                                break;
                            case "U";
                                $enrolledtext = "Unable To Verify.";
                                break;
                            case "E":
                                $enrolledtext = "Error Verifying Enrolment.";
                                break;
                            default:
                                $enrolledtext = "Integration unable to determine enrolment status.";
                                break;
                        }
                        $ordermessage .= "<br />3D Secure enrolled check outcome: \"$enrolledtext\"";
                    }

                    if (isset($data['threeDSAuthenticated'])) {
                        switch ($data['threeDSAuthenticated']) {
                            case "Y":
                                $authenticatedtext = "Authentication Successful";
                                break;
                            case "N":
                                $authenticatedtext = "Not Authenticated";
                                break;
                            case "U";
                                $authenticatedtext = "Unable To Authenticate";
                                break;
                            case "A":
                                $authenticatedtext = "Attempted Authentication";
                                break;
                            case "E":
                                $authenticatedtext = "Error Checking Authentication";
                                break;
                            default:
                                $authenticatedtext = "Integration unable to determine authentication status.";
                                break;
                        }
                        $ordermessage .= "<br />3D Secure authenticated check outcome: \"$authenticatedtext\"";
                    }

                    $order->addStatusToHistory($status, $ordermessage, 0);
                    $order->save();

                    $this->checkoutSession->setLastSuccessQuoteId($order->getId());
                    $this->checkoutSession->setLastQuoteId($order->getId());
                    $this->checkoutSession->setLastOrderId($order->getId());
                    $this->checkoutSession->setLastRealOrderId($order->getIncrementId());

                    $result = $this->resultRedirectFactory->create();
                    $result->setPath('checkout/onepage/success');

                    return $result;
                }
            };

            $data = isset($res['response']) ? $res['response'] : $_POST;


            return Gateway::processResponse($data, $onSuccessfulTransaction, $options);
        } catch (\Exception $exception) {
            $result = $this->resultRedirectFactory->create();
            $result->setPath('checkout/cart');

            $this->logger->error($exception->getMessage(), $exception->getTrace());
            $this->messageManager->addErrorMessage(__('Something went wrong with the payment, we were not able to process it, please contact support.'));

            return $result;
        }
	}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Custom function created to store the order details
     * (e.g. billing address, order reference, etc) so that
     * we can simply add it to the redirection in a hosted
     * form or to a direct request easily.
     *
     * Beforehand, we used to store this information in the
     * session along with any other data which made it much
     * harder for us to find what the actual data was. We
     * were regenerating the information as much as we could
     * and used array_merge to try and make sure we had all
     * the information available to ourself. This made it
     * much harder to track how and where the information was
     * being handled.
     *
     * This time we generate the order information into our
     * own array block inside the session known as _MODULE.
     * Making sure to seperate this information and the form
     * submitted by the user. We can then merge the data
     * effortlessly
     *
     * @return array
     **/
    public function captureOrder() {
        // Prevent any email getting sent
        $order = $this->checkoutSession->getLastRealOrder();
        $order->setEmailSent(0);
        $status = $this->gateway->getConfigData('order_status');
        $order->setStatus($status);
        $order->save();
        $orderId = $order->getIncrementId();
        $amount = (int) round($order->getBaseTotalDue(), 2) * 100;
        $ref = '#' . $orderId;
        $billingAddress = $order->getBillingAddress();

        // Create a formatted address
        $address  = ($billingAddress->getStreetLine(1) ? $billingAddress->getStreetLine(1) . ",\n" : '');
        $address .= ($billingAddress->getStreetLine(2) ? $billingAddress->getStreetLine(2) . ",\n" : '');
        $address .= ($billingAddress->getCity() ? $billingAddress->getCity() . ",\n" : '');
        $address .= ($billingAddress->getRegion() ? $billingAddress->getRegion() . ",\n" : '');
        $address .= ($billingAddress->getCountryId() ? $billingAddress->getCountryId() : '');

        $req = [
            'merchantID'        =>  $this->gateway->getConfigData('merchant_id'),
            'amount'            => $amount,
            'transactionUnique' => uniqid(),
            'orderRef'          => $ref,
            'countryCode'       => $this->gateway->getConfigData('country_code'),
            'currencyCode'      => $order->getBaseCurrency()->getCode(),
            'customerName'      => $billingAddress->getName(),
            'customerAddress'   => $address,
            'customerPostCode'  => $billingAddress->getPostcode(),
            'customerEmail'     => $billingAddress->getEmail(),
            'remoteAddress'     => $_SERVER['REMOTE_ADDR']
        ];

        $req['action'] = 'SALE';
        $req['type'] = 1;

        $req['debug'] = 1;

        if(!is_null($billingAddress->getTelephone())) {
            $req["customerPhone"] = $billingAddress->getTelephone();
        }

        if (!is_null($billingAddress->getPostcode())) {
            // PostCode's are optional.
            $req['customerPostCode'] = $billingAddress->getPostcode();
        }

        return $req;
    }
}
