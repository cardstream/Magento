<?php

namespace P3\PaymentGateway\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class Integration implements ArrayInterface
{
    const TYPE_HOSTED = 'hosted';
    const TYPE_HOSTED_EMBEDDED = 'iframe';
    const TYPE_HOSTED_MODAL = 'hosted_modal';
    const TYPE_DIRECT = 'direct';

	/**
	 * @return array
	 */
	public function toOptionArray(): array
    {
		return [
			['value' => self::TYPE_HOSTED, 'label' => __('Hosted')],
			['value' => self::TYPE_HOSTED_MODAL, 'label' => __('Hosted (Modal)')],
			['value' => self::TYPE_HOSTED_EMBEDDED, 'label' => __('Hosted (Embedded)')],
			['value' => self::TYPE_DIRECT, 'label' => __('Direct 3D-Secure')],
		 ];
	}
}




