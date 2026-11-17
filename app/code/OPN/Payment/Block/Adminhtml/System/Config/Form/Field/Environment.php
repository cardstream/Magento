<?php

namespace OPN\Payment\Block\Adminhtml\System\Config\Form\Field;

class Environment implements \Magento\Framework\Data\OptionSourceInterface{

    public function toOptionArray()
    {
        return [
            ['value' => 'TEST', 'label' => __('Test')],
            ['value' => 'PROD', 'label' => __('Production')],
        ];
    }
}
