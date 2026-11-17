<?php
namespace OPN\Payment\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Sales\Model\OrderFactory;

class OpnRedirect implements ResolverInterface
{
    protected $scopeConfig;
    protected $urlBuilder;
    protected $orderFactory;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder,
        OrderFactory $orderFactory
    ) {
        $this->scopeConfig  = $scopeConfig;
        $this->urlBuilder   = $urlBuilder;
        $this->orderFactory = $orderFactory;
    }

    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('You must be logged in to access this resource.'));
        }

        $orderId = $args['order_id'] ?? null;
        if (!$orderId) {
            throw new GraphQlInputException(__('Order ID is required.'));
        }

        $order = $this->orderFactory->create()->loadByIncrementId($orderId);
        if (!$order->getId()) {
            throw new GraphQlInputException(__('Order not found.'));
        }

        if ((int)$order->getCustomerId() !== (int)$context->getUserId()) {
            throw new GraphQlAuthorizationException(__('You do not have permission to access this order.'));
        }

        $redirectUrl = $this->urlBuilder->getUrl('opn/payment/redirect', ['order_id' => $orderId]);

        return ['redirect_url' => $redirectUrl];
    }
}
