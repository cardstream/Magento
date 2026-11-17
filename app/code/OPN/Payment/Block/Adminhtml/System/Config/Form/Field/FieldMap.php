<?php

namespace OPN\Payment\Block\Adminhtml\System\Config\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class FieldMap extends AbstractFieldArray
{
    protected function _prepareToRender()
    {
        $this->addColumn('name', ['label' => __('Field Name'), 'class' => 'required-entry']);
        $this->addColumn('value', ['label' => __('Field Value'), 'class' => 'required-entry']);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Field');
    }

    protected function _prepareArrayRow(DataObject $row)
    {
        $row->setData(
            'option_extra_attrs',
            []
        );
    }
}
