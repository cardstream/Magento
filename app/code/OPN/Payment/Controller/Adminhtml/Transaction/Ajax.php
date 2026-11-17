<?php
namespace OPN\Payment\Controller\Adminhtml\Transaction;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use OPN\Payment\Helper\Data as PaymentHelper;

class Ajax extends Action
{
    const ADMIN_RESOURCE = 'OPN_Payment::transaction_actions';
    protected $resultJsonFactory;
    protected $orderRepository;
    protected $creditmemoFactory;
    protected $creditmemoService;
    protected $_helper;

    public function __construct(
        Action\Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoService $creditmemoService,
        PaymentHelper $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->_helper = $helper;
    }

    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        $action = $this->getRequest()->getParam('action');
        $amount = round((float)urldecode($this->getRequest()->getParam('amount') ?? 0), 2);

        $resp = ['success' => false, 'msg' => __('Invalid request'), 'text' => ''];

        try {
            $orderModel = $this->orderRepository->get($orderId);
            $payment = $orderModel->getPayment();

            if (!$payment) {
                $resp['msg'] = __('Order has no payment method.');
                return $this->resultJsonFactory->create()->setData($resp);
            }

            $paymentInfo = $payment->getAdditionalInformation();
            $paymentInfo['order_id'] = $orderId;

            if (empty($paymentInfo['transaction_id'])) {
                throw new \Exception('Missing transaction_id in payment info.');
            }

            $transactionData = $this->_helper->getTransaction($paymentInfo, false);
            
            $total_amount = $action === 'cancel' ? ($transactionData['total'] ?? 0) :
                            ($action === 'refund' ? ($transactionData['refund_pending'] ?? 0) :
                            ($transactionData['capture_pending'] ?? 0));

            if ($action === 'cancel') {
                $amount = $total_amount;
            }

            if (!in_array($action, ['refund', 'capture', 'cancel']) || empty($orderId)) {
                $resp['msg'] = __('Invalid request');
            } elseif (isset($transactionData['allow_' . $action]) && empty($transactionData['allow_' . $action])) {
                $resp['msg'] = __('Action not allowed.');
            } elseif (empty($amount) || $amount <= 0) {
                $resp['msg'] = __('Invalid amount.');
            } elseif ($amount > $total_amount) {
                $resp['msg'] = __('Amount should be less than or equal to %1', $total_amount);
            } else {
                [$resp['success'], $resp['msg'], $totals, $status] = $this->_helper->processAction($payment, $transactionData, $action, $amount);

                if ($resp['success']) {
                    $note = (string)$this->describeOutcome($orderModel, $action, $amount, $totals);

                    // Record exactly what the gateway was asked to do. A pre-settlement "refund"
                    // is a reduced CAPTURE or a CANCEL rather than a REFUND_SALE, and the
                    // merchant must be able to see that on the order later.
                    $orderModel->addCommentToStatusHistory($note);
                    if (!empty($status)) {
                        $orderModel->setState($status)->setStatus($status);
                    }
                    $orderModel->save();

                    $resp['msg']      = $note;
                    $resp['captured'] = $orderModel->formatPriceTxt($totals['captured']);
                    $resp['refunded'] = $orderModel->formatPriceTxt($totals['refunded']);
                    $resp['text']     = $action === 'refund' ? $resp['refunded'] : $resp['captured'];
                }
            }

        } catch (\Exception $e) {
            $resp['msg'] = __('Error: ') . $e->getMessage();
        }

        return $this->resultJsonFactory->create()->setData($resp);
    }

    /**
     * Human-readable outcome for the admin message and the order's status history.
     */
    private function describeOutcome($order, $action, $amount, array $totals)
    {
        $f = function ($v) use ($order) { return $order->formatPriceTxt((float)$v); };
        $net = $totals['captured'] - $totals['refunded'];

        if ($action === 'capture') {
            return __('Captured %1. Total captured: %2.', $f($amount), $f($totals['captured']));
        }
        if ($action === 'cancel') {
            return __('Transaction cancelled at the gateway. No funds will be taken.');
        }
        switch ($totals['gateway_action']) {
            case 'CANCEL':
                return __('Refund of %1: the transaction had not yet settled, so it was cancelled at the gateway (CANCEL). No funds will be taken.', $f($amount));
            case 'CAPTURE':
                return __('Refund of %1: the transaction had not yet settled, so the amount to be captured was reduced to %2 (CAPTURE). Total refunded: %3.', $f($amount), $f($net), $f($totals['refunded']));
            default:
                return __('Refunded %1 (REFUND_SALE). Total refunded: %2.', $f($amount), $f($totals['refunded']));
        }
    }
}
