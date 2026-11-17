<?php

namespace OPN\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use OPN\Payment\Helper\Data as PaymentHelper;

class PaymentMethod extends AbstractMethod
{
    protected $_code = 'qidigitalpayment';

    protected $_isOffline = false;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canAuthorize = true;

    protected $curlClient;
    protected $_paymentHelper;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        Curl $curlClient,
        PaymentHelper $paymentHelper,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            null,
            null,
            $data
        );
        $this->curlClient = $curlClient;
        $this->_paymentHelper = $paymentHelper;
    }

    /**
     * Gate order authorization on a confirmed gateway response, regardless of the
     * order-placement channel (storefront checkout, REST/GraphQL API, admin, headless).
     *
     * AbstractMethod::authorize() is an inherited no-op that returns $this, so without
     * this override Magento treats authorization as trivially successful and moves the
     * order straight to "processing" even though no payment was ever collected from the
     * gateway. See docs/payment-team/STATE.md CRITIQUE #2.
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        }

        $settings = $this->_paymentHelper->getSettings();
        $integrationType = $settings['integration_type'] ?? 'hosted';

        if ($integrationType === 'direct') {
            // Direct/Inline: the gateway call already happened synchronously in
            // DataAssignObserver (payment_method_assign_data), which throws and blocks
            // order placement on failure. Re-check the stored result here as a second
            // gate so this method can never report success without a confirmed response.
            $status = $payment->getAdditionalInformation('status');

            if (!in_array($status, ['success', 'pending'], true)) {
                throw new LocalizedException(
                    __('Unable to authorize payment: the gateway did not confirm this transaction.')
                );
            }

            $payment->setIsTransactionPending($status === 'pending');
            $payment->setIsTransactionClosed(false);

            return $this;
        }

        // Hosted/Redirect/Popup: no gateway call has happened yet — the customer's
        // browser still has to round-trip to the gateway's hosted page (see
        // DataRequestObserver) and the result only arrives through the signed
        // Controller\Redirect\Index callback. Mark the authorization as pending so
        // Magento keeps the order out of "processing" until that callback confirms
        // success, closing the loophole where any channel that skips the browser
        // redirect (REST API, GraphQL, admin, abandoned redirects) would otherwise
        // leave the order accepted with zero payment collected.
        $payment->setIsTransactionPending(true);
        $payment->setIsTransactionClosed(false);

        return $this;
    }
}
