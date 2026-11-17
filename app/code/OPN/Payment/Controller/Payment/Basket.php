<?php
namespace OPN\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use OPN\Payment\Helper\Data as PaymentHelper;

/**
 * The one way back to the basket after a card payment that did not complete.
 *
 * Always reached as an ordinary navigation of the TOP window, which is the point:
 *  - a failure reported inside the Embedded iframe cannot set Magento's SameSite=Strict
 *    message cookie (that frame navigation was started by the gateway), so the shopper
 *    landed on the basket with no explanation;
 *  - the Embedded form's "Cancel" link only hid the form, leaving a checkout page whose
 *    quote had already become an order ("No such entity with cartId" on the next attempt);
 *  - pressing Back on the Hosted form re-ran the hand-off page, which submitted the shopper
 *    straight back to the gateway.
 * Controller/Redirect/Index and Controller/Order/Continue3ds park the reason in the session
 * and send the shopper here; the cancel link and the hand-off page arrive with nothing parked.
 */
class Basket extends Action
{
    public const SESSION_ERROR_KEY = 'opn_basket_error';

    protected $checkoutSession;
    protected $cookieManager;
    protected $cookieMetadataFactory;
    protected $helper;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        PaymentHelper $helper
    ) {
        parent::__construct($context);
        $this->checkoutSession       = $checkoutSession;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->helper                = $helper;
    }

    public function execute()
    {
        $error = $this->checkoutSession->getData(self::SESSION_ERROR_KEY, true);

        if ($error) {
            // Whoever parked the reason has already restored the basket.
            $this->messageManager->addErrorMessage($error);
        } elseif ($this->helper->isAbandonedHostedOrder($this->checkoutSession->getLastRealOrder())
            && $this->checkoutSession->restoreQuote()
        ) {
            $this->messageManager->addNoticeMessage(__('Order payment cancelled.'));
        }

        // The mini-cart was emptied client-side when the order was placed, and the basket has
        // just been restored server-side. This cookie is Magento's own cue (customer-data.js)
        // to reload every section. The value must not parse as a JSON number or boolean:
        // customer-data.js tests it with _.isEmpty(), which is true for both.
        try {
            $this->cookieManager->setPublicCookie(
                'section_data_clean',
                'yes',
                $this->cookieMetadataFactory->createPublicCookieMetadata()->setPath('/')
            );
        } catch (\Exception $e) {
            // Cosmetic only; never let it cost the shopper the redirect below.
        }

        return $this->resultRedirectFactory->create()->setPath('checkout/cart');
    }
}
