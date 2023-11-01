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
 * @category   Cardstream
 * @package    PaymentGateway
 * @copyright  Copyright (c) 2017 Cardstream
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace Cardstream\PaymentGateway\Controller\Order;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Cardstream\PaymentGateway\Model\Method\CardstreamMethod;
use Cardstream\PaymentGateway\Model\Source\Integration;
use Psr\Log\LoggerInterface;

use Magento\Quote\Model\QuoteFactory;

class Process extends Action implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface {
    /**
     * @var CardstreamMethod
     */
    private $gateway;

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var Session
     */
    private $checkoutSession;

    public function __construct(
        Context $context,
        CardstreamMethod $model,
        LoggerInterface $logger,
        Session $checkoutSession
    ) {
        parent::__construct($context);

        $this->gateway = $model;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute() {
        try {
            // Make sure we have something to submit for payment
            if ($_SERVER['REQUEST_METHOD'] == 'GET'
                && $this->checkoutSession->hasQuote() === false
                && $this->checkoutSession->getLastRealOrder()
                && in_array($this->gateway->integrationType, [
                    Integration::TYPE_HOSTED,
                    Integration::TYPE_HOSTED_MODAL,
                    Integration::TYPE_HOSTED_EMBEDDED
                ])
            ) {
                echo $this->gateway->processHostedRequest();
                exit;
            }

            if ($this->gateway->integrationType === Integration::TYPE_DIRECT) {
                $response = $this->gateway->processDirectRequest();
            }

            $data = $response ?? $_POST;

            $this->gateway->processResponse($data);

            $responseMessage = $data['responseMessage'];

            // If the payment was successfull redirect to success page.
            if ($data['responseCode'] == 0) {
                $this->messageManager->addSuccessMessage(__('Payment complete'));
                return $this->redirect('checkout/onepage/success');
            } else {
                // If the payment was not sucessfull then either redirect back
                // to the cart if module setting 'redirect to checkout on pay' 
                // is true and restore the cart/session or redirect to failure page.
                if ($this->gateway->redirectToCheckoutOnPayFail) {
                
                    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                    $order = $objectManager->create('\Magento\Sales\Model\Order')->loadByIncrementId($_COOKIE['lastOrderID']);
                    $quoteFactory = $objectManager->create('\Magento\Quote\Model\QuoteFactory');
                    $quote = $quoteFactory->create()->loadByIdWithoutStore($order->getQuoteId());

                    if ($quote->getId()) {
                        $quote->setIsActive(1)->setReservedOrderId(null)->save();
                        $this->checkoutSession->replaceQuote($quote);
                        $this->messageManager->addErrorMessage("Payment Failed - $responseMessage.");
                        return $this->redirect('checkout/cart', $data);
                    }

                } else {
                    // Redirect to failure page with error.                
                    $this->messageManager->addErrorMessage("Payment Failed - $responseMessage.");
                    
                    if (isset($data)) {
                        $this->gateway->onFailedTransaction($data);
                    }
                    return $this->redirect('checkout/onepage/failure', $data);                
                }
            }

        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage(), $exception->getTrace());
            $this->messageManager->addErrorMessage(__('Something went wrong with the payment, we were not able to process it, please contact support.'));

            if (isset($data)) {
                $this->gateway->onFailedTransaction($data);
            }
            
            return $this->redirect('checkout/cart');
        }

        // If the response can't be handled redirect to cart page with error.
        $this->messageManager->addErrorMessage(__('Something went wrong with the payment, we were not able to process it, please contact support.'));
        return $this->redirect('checkout/cart');

    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    protected function redirect($path, $data = null) {
        if ((isset($_SERVER['HTTP_X_REQUESTED_WITH'])) && ($_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')) {
            
            if ((isset($data['responseCode']) && $data['responseCode'] != 0)) {
                
                $result = $this->resultFactory->create('raw');
                $contents = <<<SCRIPT
<script>window.top.location.href = "{$this->_url->getUrl($path)}";</script>
SCRIPT;

                $result->setContents($contents);

            } else {
                /** @var Json $result */
                $result = $this->resultFactory->create('json');
                $result->setData(['success' => 'true', 'path' => $path]);
            }

        } elseif ($this->gateway->integrationType === Integration::TYPE_HOSTED_EMBEDDED) {
            /** @var Raw $result */
            $result = $this->resultFactory->create('raw');
            $contents = <<<SCRIPT
<script>window.top.location.href = "{$this->_url->getUrl($path)}";</script>
SCRIPT;
            $result->setContents($contents);
        } else {
            /** @var Redirect $result */
            $result = $this->resultFactory->create('redirect');
            $result->setPath($path);
        }

        return $result;
    }
}
