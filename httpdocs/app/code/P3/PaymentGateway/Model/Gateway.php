<?php
/** @noinspection PhpComposerExtensionStubsInspection */

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
namespace P3\PaymentGateway\Model;

use DomainException;
use InvalidArgumentException;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;

class Gateway extends AbstractMethod {
	const _MODULE = 'P3_PaymentGateway';
	const DIRECT_URL = 'https://gateway.cardstream.com/direct/';
	const HOSTED_URL = 'https://gateway.cardstream.com/hosted/';
	const VERIFY_ERROR = 'The signature provided in the response does not match. This response might be fraudulent';
	const PROCESS_ERROR = 'Sorry, we are unable to process this order (reason: %s). Please correct any faults and try again.';
	const SERVICE_ERROR = 'SERVICE ERROR - CONTACT ADMIN';
	const PAYMENT_ERROR = '[AUTOMATED CANCELLATION BY PAYMENT GATEWAY - REASON: FAILED PAYMENT]';
	const INVALID_REQUEST = 'INVALID REQUEST';
	const INSECURE_ERROR = 'The %s module cannot be used under an insecure host and has been hidden for user protection';

    const TYPE_HOSTED_V1 = 'hosted';
    const TYPE_HOSTED_EMBEDDED = 'iframe';
    const TYPE_HOSTED_MODAL = 'hosted_modal';
    const TYPE_DIRECT_V1 = 'direct';
    const TYPE_HOSTED_THREEDS_TWO = 'hosted_3DSV2';
    const TYPE_DIRECT_THREEDS_TWO = 'direct_3DSV2';

    /**
     * Gateway Hosted API Endpoint
     */
    const API_ENDPOINT_HOSTED = 'https://gateway.cardstream.com/hosted/';

    /**
     * Gateway Hosted API Endpoint
     */
    const API_ENDPOINT_HOSTED_MODAL = 'https://gateway.cardstream.com/hosted/modal/';

    /**
     * Gateway Direct API Endpoint
     */
    const API_ENDPOINT_DIRECT = 'https://gateway.Cardstream.com/direct/';

    /**
     * 3DSV2 Test Endpoint
     */
    const API_ENDPOINT_THREEDS_TWO_HOSTED = 'https://test.3ds-pit.com/hosted/';
    const API_ENDPOINT_THREEDS_TWO_DIRECT = 'https://test.3ds-pit.com/direct/';


	protected $_code = self::_MODULE;

	protected $_countryFactory;
	protected $_checkoutSession;

	protected $_isGateway              = true;
	protected $_canCapture             = true;
	protected $_canUseInternal         = true;
	protected $_canUseCheckout         = true;
	protected $_canCapturePartial      = true;
	protected $_isInitializeNeeded     = true;
	protected $_canReviewPayment       = true;
	protected $_canUseForMultishipping = true;
	protected $_canSendNewEmailFlag    = false;
	protected $_canSaveCc              = false;
	protected $_canRefund              = false;
	protected $_canVoid                = false;

	public $merchantId, $secret, $debug, $redirectURL, $gatewayURL;

	public $integrationType, $responsive, $countryCode, $currencyCode, $session;

	/**
     * @var UrlInterface
     */
    public static $_urlBuilder;

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
		$this->merchantId = $this->getConfigData('merchant_id');
		$this->secret = $this->getConfigData('merchant_shared_key');
		$this->integrationType = $this->getConfigData('integration_type');
		$this->responsive = $this->getConfigData('form_responsive') ? 'Y' : 'N';
		$this->countryCode = $this->getConfigData('country_code');
		$this->currencyCode = $this->getConfigData('currency_code');
		$this->debug = (boolean)$this->getConfigData('debug');

//		if (!$this->isSecure() && $this->integrationType == 'direct') {
//			$this->_canUseCheckout = false;
//			$error = sprintf(self::INSECURE_ERROR, self::_MODULE);
//			$this->log($error, true);
//		}
		// Tell our template to load the integration type we need
		setcookie(self::_MODULE . "_IntegrationMethod", $this->integrationType);

		$this->gatewayURL = $this->getConfigData('merchant_gateway_url');

		if (
			// Make sure we're given an valid URL
			!empty($this->gatewayURL) &&
			preg_match('/(http[s]?:\/\/[a-z0-9\.]+(?:\/[a-z]+\/?)+)/i', $this->gatewayURL) != false
		) {
			// Prevent insecure requests
			$this->gatewayURL = str_ireplace('http://', 'https://', $this->gatewayURL);
			// Always append end slash
			if (preg_match('/\/$/', $this->gatewayURL) == false) {
				$this->gatewayURL .= '/';
			}

			// Prevent direct requests using hosted
			if ($this->integrationType == 'hosted' && preg_match('/(\/direct\/)$/i', $this->gatewayURL) != false) {
				$this->gatewayURL = self::HOSTED_URL;
			}
		} else {
			if ($this->integrationType == 'direct') {
				$this->gatewayURL = self::DIRECT_URL;
			} else {
				$this->gatewayURL = self::HOSTED_URL;
			}
		}

	}

	public function log($message, $error = false) {
		if ($this->debug) {
			$this->_logger->addDebug($message);
		}
		if ($error) {
			$this->_logger->addError($message);
		}
	}

	public static function getOrderPlaceRedirectUrl() {
        
        return  self::$_urlBuilder->getUrl('paymentgateway/order/process', ['_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on']);
    }
    
    public static function get3DSV2Data() {
        include('hardwareinfo.html');
        $information = array(
                'threeDSRedirectURL'    =>  self::getOrderPlaceRedirectUrl(),
                'threeDSVersion'    => 2,
                'browserAcceptHeader'      => (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                'browserIPAddress'         => (isset($_SERVER['REMOTE_ADDR']) ? htmlentities($_SERVER['REMOTE_ADDR']) : null),
                'browserJavaEnabled'    => $_COOKIE['java'],
                'browserLanguage'		   => $_COOKIE['language'],
                'browserScreenColorDepth'  => $_COOKIE['screen_depth'],
                'browserScreenHeight'      => $_COOKIE['screen_height'],
                'browserScreenWidth'       => $_COOKIE['screen_width'],
                'browserTimeZone'          => $_COOKIE['timezone'],
                'deviceIdentity'           => $_COOKIE['identity'],
                'deviceChannel'				=> 'browser',
                'deviceTimeZone'			=> '0',
                'deviceCapabilities'		=> '',
                'deviceScreenResolution'	=> '1x1x1',
                'deviceAcceptContent'		=> (isset($_SERVER['HTTP_ACCEPT']) ? htmlentities($_SERVER['HTTP_ACCEPT']) : null),
                'deviceAcceptEncoding'		=> (isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? htmlentities($_SERVER['HTTP_ACCEPT_ENCODING']) : null),
                'deviceAcceptLanguage'		=> (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? htmlentities($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null),
                'deviceAcceptCharset'		=> (isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? htmlentities($_SERVER['HTTP_ACCEPT_CHARSET']) : null),

                'browserUserAgent'		   => (isset($_SERVER['HTTP_USER_AGENT']) ? htmlentities($_SERVER['HTTP_USER_AGENT']) : null),
        );
        return $information;
    }

    public static function render3DSv2Form($response) {
        setcookie("threeDSRef", $response['threeDSRef'], time()+60*60*24*30);

        $continuation = array(
            'threeDSRef'    => $response['threeDSRef'],
        );

        $continuation[] = $response['threeDSRequest'];
        

        $inputFields = '<input type="text" name="threeDSRef" value="' . $_COOKIE['threeDSRef']. '" />';

        foreach($response['threeDSRequest'] as $field => $value) {
            $inputFields .= '<input type="text" name="'. $field .'" value="' . $value. '" />';
        }


        return "<form name='form' action='" . $response['threeDSURL'] ."' method='post'>$inputFields</form>";
    }

	##################################################

	public static function processRequest($orderData, $options = []) {

        switch ($options['integrationType']) {
            case self::TYPE_HOSTED_V1:
                $req = array_merge($orderData, [
                    'redirectURL'       => $options['redirectURL'],
                    'callbackURL'       => $options['redirectURL'],
                    'formResponsive'    => $options['responsive'],
                ]);

                $req['signature'] = self::createSignature($req, $options['secret']);

                return [
                    'type' => 'html',
                    'response' => self::hostedRedirectForm($req, $options),
                ];
            case self::TYPE_HOSTED_MODAL:
                $req = array_merge($orderData, [
                    'redirectURL'       => $options['redirectURL'],
                    'callbackURL'       => $options['redirectURL'],
                    'formResponsive'    => $options['responsive'],
                ]);

                $req['signature'] = self::createSignature($req, $options['secret']);

                return [
                    'type' => 'html',
                    'response' => self::hostedRedirectForm($req, array_merge($options, ['gatewayURL' => self::API_ENDPOINT_HOSTED_MODAL])),
                ];
            case self::TYPE_HOSTED_EMBEDDED:
                $req = array_merge($orderData, [
                    'redirectURL'       => $options['redirectURL'],
                    'callbackURL'       => $options['redirectURL'],
                    'formResponsive'    => $options['responsive'],
                ]);

                $req['signature'] = self::createSignature($req, $options['secret']);

                return [
                    'type' => 'html',
                    'response' => self::embeddedForm($req, $options),
                ];

            case self::TYPE_HOSTED_THREEDS_TWO:
                $options['gatewayURL'] = self::API_ENDPOINT_THREEDS_TWO_HOSTED;
                
                $req = array_merge($orderData, [
                    'redirectURL'       => $options['redirectURL'],
                    'callbackURL'       => $options['redirectURL'],
                    'formResponsive'    => $options['responsive'],
                ]);

                $req = array_merge($req,self::get3DSV2Data());

                $req['signature'] = self::createSignature($req, $options['secret']);

                return [
                    'type' => 'html',
                    'response' => self::hostedRedirectForm($req, $options),
                ];

            case self::TYPE_DIRECT_V1:
                $step = isset($_REQUEST['step']) ? (int)$_REQUEST['step'] : 1;

                switch ($step) {
                    case 1:
                        return [
                            'type' => 'html',
                            'response' => self::buildFormForInitialRequest($orderData, $options),
                        ];
                    case 2:
                        $parameters = array_merge($orderData, array(
                            'type'               => 1,
                            'cardNumber'         => $_POST['cardNumber'],
                            'cardExpiryMonth'    => $_POST['cardExpiryMonth'],
                            'cardExpiryYear'     => $_POST['cardExpiryYear'],
                            'cardCVV'            => $_POST['cardCVV'],
                        ));

                        $errors = self::validateCardDetails($parameters);
                        if (count($errors) > 0) {
                            return [
                                'type' => 'html',
                                'response' => self::buildFormForInitialRequest($parameters, $options, $errors),
                            ];
                        }

                        $parameters['signature'] = self::createSignature($parameters, $options['secret']);

                        $response = self::post($parameters, []);

                        if ($response['responseCode'] == 65802) {
                            return [
                                'type' => 'html',
                                'response' => self::buildFormForContinuationRequest($response, $options),
                            ];
                        }

                        return [
                            'type' => 'payment_response_not_eligible_for_3ds',
                            'response' => $response,
                        ];
                    case 3:
                        $req = [
                            'merchantID'     => $options['merchantID'],
                            'action'         => 'SALE',
                            'xref'           => $_REQUEST['xref'],
                            'threeDSMD'      => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
                            'threeDSPaRes'   => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
                            'threeDSPaReq'   => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null),
                        ];

                        $req['signature'] = self::createSignature($req, $options['secret']);

                        $response = self::post($req);

                        return [
                            'type' => 'payment_response_with_3ds',
                            'response' => $response,
                        ];
                    default:
                        throw new DomainException(sprintf(
                            'Integration %s do not have such step: %s',
                            $options['integrationType'],
                            $step
                        ));
                }
            case self::TYPE_DIRECT_THREEDS_TWO:
                $step = isset($_REQUEST['step']) ? (int)$_REQUEST['step'] : 1;
                $options['gatewayURL'] = self::API_ENDPOINT_THREEDS_TWO_DIRECT;
                switch ($step) {
                    case 1:
                        return [
                            'type' => 'html',
                            'response' => self::buildFormForInitialRequest($orderData, array_merge($options, ['with_browser_info' => true])),
                        ];
                    case 2:
                        $parameters = array_merge($orderData, [
                            'type'               => 1,
                            'cardNumber'         => $_POST['cardNumber'],
                            'cardExpiryMonth'    => $_POST['cardExpiryMonth'],
                            'cardExpiryYear'     => $_POST['cardExpiryYear'],
                            'cardCVV'            => $_POST['cardCVV'],
                        ]);

                        $errors = self::validateCardDetails($parameters);
                        $parameters = array_merge($parameters,self::get3DSV2Data());
                        if (count($errors) > 0) {
                            return [
                                'type' => 'html',
                                'response' => self::buildFormForInitialRequest($parameters, array_merge($options, ['with_browser_info' => true]), $errors),
                            ];
                        }

                        $parameters['signature'] = self::createSignature($parameters, $options['secret']);

                        $response = self::post($parameters, $options);

                        if ($response['responseCode'] == 65802) {
                            setcookie("threeDSRef", $response['threeDSRef']);

                            $continuation = array(
                                'threeDSRef'    => $response['threeDSRef'],
                            );

                            $continuation[] = $response['threeDSRequest'];
                            

                            $inputFields = '<input type="text" name="threeDSRef" value="' . $_COOKIE['threeDSRef']. '" />';

                            foreach($response['threeDSRequest'] as $field => $value) {
                                $inputFields .= '<input type="text" name="'. $field .'" value="' . $value. '" />';
                            }


                            die("<form name='form' action='" . $response['threeDSURL'] ."' method='post'>$inputFields</form>");

                        }

                        return [
                            'type' => 'payment_response_not_eligible_for_3ds',
                            'response' => $response,
                        ];
                    case 3:
                        $req = [
                            'merchantID'     => $options['merchantID'],
                            'xref'           => $_REQUEST['xref'],
                            'action'         => 'SALE',
                            'threeDSMD'      => (isset($_REQUEST['MD']) ? $_REQUEST['MD'] : null),
                            'threeDSPaRes'   => (isset($_REQUEST['PaRes']) ? $_REQUEST['PaRes'] : null),
                            'threeDSPaReq'   => (isset($_REQUEST['PaReq']) ? $_REQUEST['PaReq'] : null),
                        ];

                        $req['signature'] = self::createSignature($req, $options['secret']);

                        $response = self::post($req);

                        return [
                            'type' => 'payment_response_with_3ds',
                            'response' => $response,
                        ];
                    default:
                        throw new DomainException(sprintf(
                            'Integration %s do not have such step: %s',
                            $options['integrationType'],
                            $step
                        ));
                }
            default:
                throw new InvalidArgumentException(sprintf(
                    'Not Implemented Integration: %s', $options['integrationType']
                ));
        }
    }

    public static function processResponse(array $data, callable $onSuccessfulTransaction, $options) {
        // This is a valid response to save payment details
        $sig = isset($data['signature']) ? $data['signature'] : null;
        //die(var_dump($data));

        //die(var_dump($data));
        unset($data['signature']);
        if (isset($sig)){
            if ($sig === self::createSignature($data, $options['secret'])) {
                // Process a successful transaction
                if ($data['responseCode'] == 0) {
                    return $onSuccessfulTransaction($data);
                } else {
                    throw new DomainException(sprintf(self::PROCESS_ERROR, htmlentities($data['responseMessage'])));
                }
            } else {
                
                // Never trust the data when the signature cannot be verified
                throw new DomainException(self::VERIFY_ERROR, 66343);
            }
        }
        if (!isset($data['responseCode'])) {
            if(isset($data['threeDSMethodData']) || isset($data['cres'])) {
                //die(var_dump($_COOKIE));
                $threeDSRef = $_COOKIE['threeDSRef'];
                $req = array(
                    'merchantID' =>	$options['merchantID'],
                    'threeDSRef' 	=> $threeDSRef,
                    'action'	=>	'SALE',
                    'threeDSResponse'	=> $data,
                );

                $req['signature'] = self::createSignature($req, $options['secret']);
                $options['gatewayURL'] = self::API_ENDPOINT_THREEDS_TWO_DIRECT;
                $response = self::post($req,$options);
                
                if ((isset($response['responseCode'])) && $response['responseCode'] == 0){
                    unset($_COOKIE['threeDSRef']);
                    self::processResponse($response,$onSuccessfulTransaction,$options);
                }
                
                die(self::render3dsv2Form($response));
                //self::processResponse(self::post($req,$options));
            }
        }
    }

    /**
     * Send request to Gateway using HTTP Hosted API.
     *
     * The method will send a request to the Gateway using the HTTP Hosted API.
     *
     * The method returns the HTML fragment that needs including in order to
     * send the request.
     *
     * @param	array	    $parameters	request data
     * @param	array|null	$options	options (or null)
     * @return	string				    request HTML form.
     *
     * @throws	InvalidArgumentException	invalid request data
     */
    protected static function hostedRedirectForm(array $parameters, array $options = null) {
        $gatewayUrl = isset($options['gatewayURL']) && !empty($options['gatewayURL']) ? $options['gatewayURL'] : self::API_ENDPOINT_HOSTED;
        $gatewayName = isset($options['gatewayName']) ? $options['gatewayName'] : 'Payment Network';
        $requestData = '';
        foreach ($parameters as $key => $value) {
            $requestData .= '<input type="hidden" name="' . $key . '" value="' . htmlentities($value) . '" />';
        }

        return <<<FORM
<form action="$gatewayUrl" method="post" id="payment_network_payment_form">
 <label>Processing ...</label>
 <input type="submit" class="button alt" style="display: none;" value="Pay securely via $gatewayName" />
 $requestData
</form>
<script type="text/javascript">
    window.onload = function () {
        document.getElementById('payment_network_payment_form').submit();
    };
</script>
FORM;
    }

    /**
     * Send request to Gateway using HTTP Hosted API.
     *
     * The method will send a request to the Gateway using the HTTP Hosted API.
     *
     * The method returns the HTML fragment that needs including in order to
     * send the request.
     *
     * @param   array $parameters   request data
     * @param   array|null $options options (or null)
     * @return  string              request HTML form.
     */
    protected static function embeddedForm(array $parameters, array $options = null)
    {
        $gatewayUrl = isset($options['gatewayURL']) && !empty($options['gatewayURL']) ? $options['gatewayURL'] : self::API_ENDPOINT_HOSTED;
        $requestData = '';
        foreach ($parameters as $key => $value) {
            $requestData .= '<input type="hidden" name="' . $key . '" value="' . htmlentities($value) . '" />';
        }

        return <<<FORM
<iframe id="paymentgatewayframe" name="paymentgatewayframe" frameBorder="0" seamless='seamless' style="width:699px; height:1100px;margin: 0 auto;display:block;"></iframe>
<form id="paymentgatewaymoduleform" action="$gatewayUrl" method="post" target="paymentgatewayframe">
 $requestData
</form>
<script>
	// detects if jquery is loaded and adjusts the form for mobile devices
	document.body.addEventListener('load', function() {
		if( /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ) {
			const frame = document.querySelector('#paymentgatewayframe');
			frame.style.height = '1280px';
			frame.style.width = '50%';
		}
	});
	document.getElementById('paymentgatewaymoduleform').submit();
</script>

FORM;
    }

    /**
     * Direct form step 1, used to provide data for 3-D Secure v2
     *
     * @param array $parameters
     * @param array $options
     * @param array $errors
     *
     * @return string
     * @noinspection DuplicatedCode
     */
    public static function buildFormForInitialRequest(array $parameters, array $options = [], array $errors = [])
    {
        $gatewayName = isset($options['gateway_name']) ? $options['gateway_name'] : 'Payment Network';
        $gateway = isset($options['gateway_prefix']) ? $options['gateway_prefix'] : 'payment_network';
        $action = isset($options['action']) ? $options['action'] : '//'.$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '&step=2';
        $cancelOrderUrl = isset($options['cancel_order_url']) ? $options['cancel_order_url'] : '';
        $includeDeviceData = isset($options['with_browser_info']) ? $options['with_browser_info'] : false;

        $browserInfo = '';
        $scriptData = '';

        if ($includeDeviceData) {
            $threeDS2Data = self::get3DSV2Data();

            foreach ($threeDS2Data as $key => $value) {
                $browserInfo .= '<input type="hidden" name="' . $key . '" value="' . htmlentities($value) . '" />';
            }
        }

        $hasError = function ($key, $errors) {
            return array_search($key, $errors) !== false ? 'style="border: 1px solid red"' : '';
        };

        return <<<FORM
<form action="$action" method="post" id="{$gateway}_payment_form">
    <label class="card-label label-cardNumber">Card Number</label>
    <input type='text' class='card-input field-cardNumber' name='cardNumber' value='{$parameters['cardNumber']}' {$hasError('cardNumber', $errors)} required='required'/>

    <label class="card-label label-cardExpiryMonth">Card Expiry Month</label>
    <input type='text' class='card-input field-cardExpiryMonth' name='cardExpiryMonth' value='{$parameters['cardExpiryMonth']}' {$hasError('cardExpiryMonth', $errors)} required='required' placeholder='MM' maxlength='2'/>

    <label class="card-label label-cardExpiryYear">Card Expiry Year</label>
    <input type='text' class='card-input field-cardExpiryYear' name='cardExpiryYear' value='{$parameters['cardExpiryYear']}' {$hasError('cardExpiryYear', $errors)} required='required' placeholder='YY' maxlength='4'/>

    <label class="card-label label-cardCVV">CVV</label>
    <input type='text' class='card-input field-cardCVV' name='cardCVV' value='{$parameters['cardCVV']}' {$hasError('cardCVV', $errors)} required='required'/>
    <br/>
    {$browserInfo}
    <p>
        <input type="submit" class="button alt" value="Pay securely via $gatewayName" />
    </p>
    <a class="button cancel" href="$cancelOrderUrl">Cancel order</a>
</form>
{$scriptData}
FORM;
    }

    /**
     * Direct form step 2
     *
     * @param $parameters
     * @param null $options
     * @return string
     */
    protected static function buildFormForContinuationRequest($parameters, $options = null)
    {
        if (isset($options['pageUrl'])) {
            $pageUrl = $options['pageUrl'];
        } else {
            // Send details to 3D Secure ACS and the return here to repeat request
            $pageUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https://' : 'http://';
            if ($_SERVER['SERVER_PORT'] != '80') {
                $pageUrl .= $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
            } else {
                $pageUrl .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
            }
        }

        $queryParams = $_GET;
        $queryParams['step'] = 3;
        $queryParams['xref'] = $parameters['xref'];

        $pageUrl .= http_build_query($queryParams);

        if (isset($parameters['threeDSURL'], $parameters['threeDSRequest'])) {
            $action = htmlentities($parameters['threeDSURL']);
            $threeDSPaReq = htmlentities($parameters['threeDSRequest']['PaReq']);
            $threeDSMD = htmlentities($parameters['threeDSRequest']['MD']);
        } else {
            $action = htmlentities($parameters['threeDSACSURL']);
            $threeDSPaReq = htmlentities($parameters['threeDSPaReq']);
            $threeDSMD = htmlentities($parameters['threeDSMD']);
        }

        return <<<FORM
<p>Your transaction requires 3D Secure Authentication</p>
<form action="$action" method="post">
    <input type="hidden" name="MD" value="$threeDSMD">
    <input type="hidden" name="PaReq" value="$threeDSPaReq">
    <input type="hidden" name="TermUrl" value="$pageUrl">
    <input type="submit" value="Continue">
</form>
FORM;
    }

    /**
     * @param $parameters
     * @return array
     */
    protected static function validateCardDetails($parameters) {
        $required = ['cardNumber', 'cardCVV', 'cardExpiryMonth', 'cardExpiryYear'];
        $errors = [];

        // Check that the required fields are present:
        foreach ($required as $i => $field) {
            if (
                (isset($parameters[$field]) && empty($parameters[$field])) ||
                !isset($parameters[$field])
            ) {
                array_push($errors, $field);
            }
        }

        // Check year is a numeric and has either two or four digits
        if (
            array_search('cardExpiryYear', $errors) === false &&
            ($year = $parameters['cardExpiryYear']) &&
            is_numeric($year) &&
            (strlen($year) == 2 || strlen($year) == 4)
        ) {
            if (strlen($year) == 4) {
                $parameters['cardExpiryYear'] = substr($year, 2);
            }
        } else {
            array_push($errors, 'cardExpiryYear');
        }

        // Check month is a numeric and has two digits
        if (!(
            array_search('cardExpiryMonth', $errors) === false &&
            ($month = $parameters['cardExpiryMonth']) &&
            is_numeric($month) && strlen($month) == 2
        )) {
            array_push($errors, 'cardExpiryMonth');
        }

        return $errors;
    }

    /**
     * Sign requests with a SHA512 hash
     * @param array $data Request data
     *
     * @param $key
     * @return string|null
     */
    protected static function createSignature(array $data, $key) {
        if (!$key || !is_string($key) || $key === '' || !$data || !is_array($data)) {
            return null;
        }

        ksort($data);

        // Create the URL encoded signature string
        $ret = http_build_query($data, '', '&');

        // Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
        $ret = preg_replace('/%0D%0A|%0A%0D|%0A|%0D/i', '%0A', $ret);
        // Hash the signature string and the key together
        return hash('SHA512', $ret . $key);
    }

    public static function post($parameters, $options = null) {
        $gatewayUrl = isset($options['gatewayURL']) && !empty($options['gatewayURL']) ? $options['gatewayURL'] : self::API_ENDPOINT_DIRECT;
        $ch = curl_init($gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        parse_str(curl_exec($ch), $response);
        curl_close($ch);

        return $response;
    }
}
