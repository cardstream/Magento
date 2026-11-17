<?php
namespace OPN\Payment\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class SortOrder implements ArrayInterface
{
    public function toOptionArray()
    {
        $options = [];
        for ($i = 1; $i <= 100; $i++) {
            $options[] = ['value' => $i, 'label' => (string)$i];
        }
        return $options;
    }
}
