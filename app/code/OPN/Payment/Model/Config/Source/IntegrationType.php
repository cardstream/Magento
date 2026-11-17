<?php
namespace OPN\Payment\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\Data\OptionSourceInterface;

class IntegrationType implements ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'direct', 'label' => __('Hosted Fields (Inline)')],
            ['value' => 'modal', 'label' => __('Hosted Modal (Popup)')],
            ['value' => 'hosted', 'label' => __('Hosted (Secure Redirect)')],
            ['value' => 'embedded', 'label' => __('Embedded')],
        ];
    }
}
