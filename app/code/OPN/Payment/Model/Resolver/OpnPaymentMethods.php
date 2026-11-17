<?php
declare(strict_types=1);

namespace OPN\Payment\Model\Resolver;

use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class OpnPaymentMethods implements ResolverInterface
{
    const XML_PATH_GROUP = 'payment/qidigitalpayment/';
    const XML_ACTIVE = self::XML_PATH_GROUP . 'active';
    const XML_TITLE = self::XML_PATH_GROUP . 'title';

    protected ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $isActive = $this->scopeConfig->isSetFlag(self::XML_ACTIVE, ScopeInterface::SCOPE_STORE);
        $title = $this->scopeConfig->getValue(self::XML_TITLE, ScopeInterface::SCOPE_STORE) ?: 'OPN Payment';

        return [
            [
                'code' => 'opn_payment',
                'title' => $title,
                'is_active' => (bool)$isActive
            ]
        ];
    }
}