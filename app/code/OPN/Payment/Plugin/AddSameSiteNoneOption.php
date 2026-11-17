<?php
namespace OPN\Payment\Plugin;

class AddSameSiteNoneOption
{
    public function afterToOptionArray(
        \Magento\Config\Model\Config\Source\Cookie\SameSite $subject,
        array $result
    ) {
        $hasNone = false;

        foreach ($result as $option) {
            if (strtolower($option['value']) === 'none') {
                $hasNone = true;
                break;
            }
        }

        if (!$hasNone) {
            $result[] = [
                'value' => 'None',
                'label' => __('None'),
            ];
        }

        return $result;
    }
}
