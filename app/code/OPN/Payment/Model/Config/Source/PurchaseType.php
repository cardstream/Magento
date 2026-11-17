<?php
namespace OPN\Payment\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class PurchaseType implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'sale', 'label' => __('Capture')],
            ['value' => 'preauth', 'label' => __('Pre-authorization')],
        ];
    }
}
