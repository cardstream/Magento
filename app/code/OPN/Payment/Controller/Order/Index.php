<?php
namespace OPN\Payment\Controller\Order;

use OPN\Payment\Helper\Data as PaymentHelper;

class Index extends \Magento\Framework\App\Action\Action
{

	protected $_pageFactory;
	protected $_objectManager;
	protected $_request;
    protected $_helper;
    protected $customerFactory;
    protected $customerSession;
    protected $cart;
    protected $checkoutSession;
    protected $resultFactory;

	public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        PaymentHelper $helper,
    ){

        $this->_pageFactory = $pageFactory;

        $this->customerFactory = $customerFactory;
        $this->customerSession = $customerSession;
        $this->cart = $cart;
        $this->checkoutSession = $checkoutSession;
        $this->resultFactory = $resultFactory;

	    $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_request =   $this->_objectManager->get('Magento\Framework\App\RequestInterface');    
        $this->_helper = $helper;
        
        return parent::__construct($context);
    }

    public function execute(){

        $orderId = (int)$this->_request->getParam('id');
        $_error = ($this->_request->getParam('msg') ?? null);

        list($order, $payment, $error) = $this->validateOrder($orderId);

        if( !$error ){
            $customerId = $order->getCustomerId();
            if( $customerId && $this->customerSession->isLoggedIn() ){
                if( $this->customerSession->getCustomerId() != $customerId ){
                    return $this->_redirect('customer/account/');
                }
            }
            // N02: For guest orders, only allow access if this is the session's last order.
            // Prevents unauthenticated enumeration of all guest orders by integer ID.
            if( !$customerId ){
                $lastOrderId = $this->checkoutSession->getLastOrderId();
                if( !$lastOrderId || (int)$lastOrderId !== $orderId ){
                    return $this->_redirect('noroute');
                }
            }
        }
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');

        $data = [];
        $data['orderId'] = $orderId;
        $data['order']   = $order;
        $data['payment'] = $payment;
        $data['error']   = $error ?: $_error;
        $data['baseUrl'] = $storeManager->getStore()->getBaseUrl();
        $data['helper']  = $this->_helper;

        $resultLayout = $this->_pageFactory->create();

        $block = $resultLayout->getLayout()->getBlock('opn_order');
        $block->setOrderData($data);

        // Initialised up front: the reads below sit outside the try, and an exception
        // raised before getInfo() used to leave $info undefined.
        $info = [];

        try{
            $info = $block->getInfo();

            $session = $objectManager->get(\Magento\Checkout\Model\Type\Onepage::class)->getCheckout();

            if( ($info['status'] ?? '') == 'success' ){
                // Stamp these first and unconditionally. Magento's own checkout/onepage/success
                // page (\Magento\Checkout\Model\Session\SuccessValidator) requires BOTH
                // getLastOrderId() AND getLastQuoteId() to equal the order's own quote_id —
                // not just any quote — or it silently redirects to cart. For product-page/
                // cart-express orders the order's quote is a separate standalone quote, never
                // the customer's ambient checkout-session quote, so LastQuoteId must come from
                // $order->getQuoteId() directly, not from $session->getQuote().
                $session->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus())
                    ->setLastQuoteId($order->getQuoteId())
                    ->setLastSuccessQuoteId($order->getQuoteId());

                // Best-effort: mark whatever unrelated quote this ambient checkout session
                // happens to be holding as inactive. A failure here must never block the
                // stamps above, or the customer silently bounces to an unrelated cart page.
                try {
                    $quote = $session->getQuote();
                    if ($quote && $quote->getId()) {
                        $quote->setIsActive(false)->save();
                    }
                } catch (\Exception $e) {
                }
            } else {
                try {
                    $session->clearQuote();
                } catch (\Exception $e) {
                }
            }

        } catch(\Exception $e){
        }
            
        if( ($info['status'] ?? '') == 'success' ){
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT)->setPath('checkout/onepage/success');
        }

        // A payment still pending 3-D Secure belongs back in the challenge, not on a status
        // page that would present the gateway's "3DS authentication required" as a failure.
        // No redirect loop is possible: Threedsecure.php only sends an order here when
        // redirect_3d_auth['url'] is EMPTY, which is the exact inverse of this condition.
        // Its guest-ownership check is the same one already applied above, so anything
        // reaching this line will also pass there.
        if( ($info['status'] ?? '') == 'pending' && !empty($info['redirect_3d_auth']['url']) ){
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT)
                ->setPath('opn/order/threedsecure', ['id' => $orderId]);
        }

        return $resultLayout;
	}

	private function validateOrder($orderId){
        
        $error = false;
        $_order = false;
        $_payment = null;
        $order = !empty($orderId) ? $this->_objectManager->get('Magento\Sales\Model\Order')->load($orderId) : null;
        
        if( $order ){

            $_payment = $order->getPayment();
            $payment_code = null;
            if( $_payment )
                $payment_code = $_payment->getMethodInstance()->getCode();
            
            if( $payment_code == "qidigitalpayment" ){
                $_order = $order;

            } else
                $error = "Invalid order payment request"; 

        } else 
            $error = "Invalid Order";

        return [$_order,$_payment, $error];
    }
}