<?php
namespace OPN\Payment\Controller\Cart;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteManagement;
use Magento\Quote\Model\Quote\Address\RateRequestFactory;
use Magento\Quote\Model\Quote\Address\RateFactory;
use Magento\Quote\Model\Quote\Address\RateCollectorInterfaceFactory;
use OPN\Payment\Helper\Data as PaymentHelper;

class Express extends \Magento\Framework\App\Action\Action
{
    protected $resultJsonFactory;
    protected $checkoutSession;
    protected $quoteManagement;
    protected $rateRequestFactory;
    protected $addressRateFactory;
    protected $rateCollector;
    protected $helper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        QuoteManagement $quoteManagement,
        RateRequestFactory $rateRequestFactory,
        RateFactory $addressRateFactory,
        RateCollectorInterfaceFactory $rateCollector,
        PaymentHelper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory   = $resultJsonFactory;
        $this->checkoutSession     = $checkoutSession;
        $this->quoteManagement     = $quoteManagement;
        $this->rateRequestFactory  = $rateRequestFactory;
        $this->addressRateFactory  = $addressRateFactory;
        $this->rateCollector       = $rateCollector;
        $this->helper              = $helper;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $action = $this->getRequest()->getParam('action', 'get_totals');
        $params = array_merge(
            $this->getRequest()->getParams(),
            (array)$this->getRequest()->getPostValue()
        );

        $resp = ['success' => false, 'data' => [], 'message' => null];

        try {
            $settings = $this->helper->getSettings();

            // Wallet express checkout (Apple Pay / Google Pay) works in every
            // integration mode: the wallet token is captured client-side and
            // processed via the gateway Direct API by DataAssignObserver — there
            // is no hosted page to redirect to, exactly like the product page.

            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId() || $quote->getItemsCount() == 0) {
                throw new \Exception((string)__('Your cart is empty.'));
            }

            switch ($action) {
                case 'get_totals':
                    $resp['data'] = $this->getTotals($quote);
                    break;
                case 'get_shippings':
                    [$rates, $selectedId] = $this->getShippings($quote, $params);
                    $resp['data']       = $rates;
                    $resp['selectedId'] = $selectedId;
                    break;
                case 'place_order':
                    $resp = array_merge($resp, $this->placeExpressOrder($quote, $params, $settings));
                    break;
                default:
                    throw new \Exception((string)__('Unknown action.'));
            }

            $resp['success'] = true;

        } catch (\Magento\Framework\Validator\Exception $e) {
            $resp['message'] = $e->getMessage();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $resp['message'] = $e->getMessage();
        } catch (\Exception $e) {
            $resp['message'] = $e->getMessage();
        }

        return $result->setData($resp);
    }

    private function getTotals($quote)
    {
        $quote->setTotalsCollectedFlag(false)->collectTotals();

        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'label' => $item->getName(),
                'price' => number_format((float)$item->getRowTotalInclTax() ?: $item->getRowTotal(), 2, '.', ''),
                'type'  => 'LINE_ITEM',
            ];
        }

        $subtotal = (float)$quote->getSubtotal();
        $tax      = (float)$quote->getShippingAddress()->getTaxAmount();
        // cart-express.js adds the chosen shipping rate itself, so leave out any shipping the
        // cart page's estimate already put on the quote (the sheet showed £30 for a £25 order).
        $total    = (float)$quote->getGrandTotal() - (float)$quote->getShippingAddress()->getShippingInclTax();

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'tax'      => number_format($tax, 2, '.', ''),
            'total'    => number_format($total, 2, '.', ''),
            'items'    => $items,
            'currency' => $quote->getQuoteCurrencyCode(),
        ];
    }

    private function getShippings($quote, array $params)
    {
        $address = $this->parseAddress($params['address'] ?? []);

        // Temporarily apply address to collect rates without committing
        $shippingAddress = $quote->getShippingAddress();
        $prevData        = $shippingAddress->getData();

        $shippingAddress->addData($address);
        $shippingAddress->setCollectShippingRates(true)->collectShippingRates();

        $rates      = [];
        $selectedId = null;

        foreach ($shippingAddress->getAllShippingRates() as $rate) {
            $code = $rate->getCode();
            if ($code) {
                if (!$selectedId) {
                    $selectedId = $code;
                }
                $rates[$code] = [
                    'id'          => $code,
                    'label'       => $rate->getCarrierTitle() . ' — ' . $rate->getMethodTitle(),
                    'description' => '',
                    'price'       => number_format((float)$rate->getPrice(), 2, '.', ''),
                ];
            }
        }

        // Restore previous address state (don't persist a partial address)
        $shippingAddress->setData($prevData);

        if (empty($rates)) {
            throw new \Exception((string)__('No shipping methods available for the selected address.'));
        }

        return [$rates, $selectedId];
    }

    private function placeExpressOrder($quote, array $params, array $settings)
    {
        $walletMethod    = trim($params['method'] ?? '');
        $token           = $params['token'] ?? '';
        $browserInfo     = $params['browserInfo'] ?? [];
        $billingData     = $this->parseAddress($params['billing'] ?? []);
        $shippingData    = $this->parseAddress($params['shipping'] ?? $params['billing'] ?? []);
        $shippingMethod  = trim($params['shipping_method'] ?? '');
        $email           = trim($params['email'] ?? '');

        if (!in_array($walletMethod, ['applepay', 'googlepay'], true)) {
            throw new \Exception((string)__('Invalid payment method.'));
        }
        if (empty($token)) {
            throw new \Exception((string)__('Payment token is missing.'));
        }

        // Apply addresses and shipping method to the quote
        $quote->getBillingAddress()->addData($billingData);

        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->addData($shippingData);
        $shippingAddress->setCollectShippingRates(true)->collectShippingRates();
        if ($shippingMethod) {
            $shippingRate = $shippingAddress->getShippingRateByCode($shippingMethod);
            if (!$shippingRate) {
                throw new \Exception((string)__("Selected shipping method is no longer available."));
            }
            $shippingAddress->setShippingMethod($shippingMethod);
        }
        $shippingAddress->setPaymentMethod('qidigitalpayment');

        $quote->setTotalsCollectedFlag(false)->collectTotals();

        // Handle guest checkout — wallet provides all needed contact data
        if (!$quote->getCustomerId()) {
            if (empty($email)) {
                throw new \Exception((string)__('Email address is required for guest checkout.'));
            }
            $quote->setCheckoutMethod('guest');
            $quote->setCustomerEmail($email);
            $quote->setCustomerFirstname($billingData['firstname'] ?? '');
            $quote->setCustomerLastname($billingData['lastname'] ?? '');
            $quote->setCustomerIsGuest(true);
        }

        $quote->setPaymentMethod('qidigitalpayment');
        $quote->setInventoryProcessed(false);
        $quote->getPayment()->setQuote($quote);
        $quote->save();

        // Assign payment data. This fires payment_method_assign_data →
        // DataAssignObserver::execute() → gateway Direct API call → stores
        // status in additional_information. Throws on gateway failure.
        $quote->getPayment()->importData([
            'method'          => 'qidigitalpayment',
            'additional_data' => [
                'method'      => $walletMethod,
                'token'       => $token,
                'browserInfo' => is_array($browserInfo) ? json_encode($browserInfo) : $browserInfo,
            ],
        ]);

        // Submit quote → Order\Payment::place() → authorize() confirms the
        // status stored above and ensures the order cannot proceed without it.
        $order = $this->quoteManagement->submit($quote);

        if (!$order || !$order->getId()) {
            throw new \Exception((string)__('Unable to place order. Please try again.'));
        }

        $pending3ds = $order->getPayment()->getAdditionalInformation('redirect_3d_auth');

        if (!empty($pending3ds['url'])) {
            // Gateway requires 3-D Secure — send the browser to the ACS challenge instead of
            // the order status page. Threedsecure.php's guest-ownership check needs this.
            $this->checkoutSession->setLastOrderId($order->getId());
            $redirectUrl = $this->_url->getUrl('opn/order/threedsecure', ['id' => $order->getId()]);
        } else {
            // Already fully paid — send the browser directly to Magento's native success page
            // rather than opn/order/index, which was found to lose required session state
            // (LastQuoteId/LastSuccessQuoteId/LastRealOrderId) on that extra hop.
            $redirectUrl = $this->helper->redirectToCheckoutSuccess($order);
        }

        return [
            'order_id'           => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'redirect'           => $redirectUrl,
        ];
    }

    private function parseAddress(array $raw): array
    {
        $address = $raw;

        // Normalize name: wallet provides "name" as a single string
        if (empty($address['firstname']) && !empty($address['name'])) {
            $parts = explode(' ', trim($address['name']), 2);
            $address['firstname'] = $parts[0] ?? '';
            $address['lastname']  = $parts[1] ?? ($parts[0] ?? '');
        }
        if (empty($address['lastname'])) {
            $address['lastname'] = $address['firstname'] ?? 'Customer';
        }
        unset($address['name']);

        if (empty($address['telephone'])) {
            $address['telephone'] = '0000000000';
        }

        // Normalize street to array
        if (!empty($address['street']) && !is_array($address['street'])) {
            $address['street'] = [$address['street']];
        }

        // Resolve region_id from region code + country (wallet sends code, Magento needs ID)
        $regionCode = $address['region_id'] ?? '';
        $countryId  = $address['country_id'] ?? '';
        if ($regionCode && !is_numeric($regionCode) && $countryId) {
            $om         = \Magento\Framework\App\ObjectManager::getInstance();
            $collection = $om->create(\Magento\Directory\Model\ResourceModel\Region\Collection::class)
                ->addFieldToFilter('code', $regionCode)
                ->addFieldToFilter('country_id', $countryId);
            $regionId   = (int)$collection->getFirstItem()->getId();
            $address['region_id'] = $regionId ?: null;
            $address['region']    = $regionCode;
        }

        return $address;
    }
}
