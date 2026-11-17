<?php

namespace OPN\Payment\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Config\Block\System\Config\Form\Field;

class Debug extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $value = $element->getValue();

        $html = '<div id="' . $element->getHtmlId() . '">';
    
        $html .= '<input type="hidden" name="'.$element->getName().'" value="0"/><label><input type="checkbox" name="'.$element->getName().'" value="1" '.($value == '1' ? 'checked="checked"' : '').'></label>';

        $html .= '</div>';

        return $html;
    }
}
