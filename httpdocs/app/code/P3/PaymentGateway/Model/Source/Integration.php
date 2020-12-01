<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   P3
 * @package    PaymentGateway
 * @copyright  Copyright (c) 2017 P3
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace P3\PaymentGateway\Model\Source;

class Integration implements \Magento\Framework\Option\ArrayInterface
{
	/**
	 * @return array
	 */
	public function toOptionArray()
	{

		return [
			['value' => 'hosted', 'label' => __('Hosted')],
			['value' => 'hosted_modal', 'label' => __('Hosted (Modal)')],
			['value' => 'iframe', 'label' => __('Hosted (Embedded)')],
			['value' => 'direct', 'label' => __('Direct')],
			['value' =>	'hosted_3DSV2', 'label' => __('Hosted 3DSV2')],
			['value' => 'direct_3DSV2', 'label' => __('Direct 3DSV2')],
		 ];
	}
}




