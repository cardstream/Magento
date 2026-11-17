<?php

namespace OPN\Payment\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Config\Block\System\Config\Form\Field;

class CardTypes extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $html = '';
        $raw    = $element->getValue();
        $values = is_array($raw) ? $raw : (json_decode($raw ?: '[]', true) ?: []);

        $options = [
            'AMEX' => __('Amex'),
            'DISCOVER' => __('Discover'),
            'JCB' => __('JCB'),
            'MASTERCARD' => __('Mastercard'),
            'VISA' => __('Visa')
        ];

        $html .= '<input type="hidden" name="' . $element->getName() . '" value=""/>';

        foreach ($options as $value => $label) {
            $checked = in_array($value, $values) ? 'checked="checked"' : '';
            $html .= '<label><input type="checkbox" name="' . $element->getName() . '[]"
                     value="' . $value . '" ' . $checked . '/> ' . $label . '</label><br>';
        }

        return $html;
    }
}
