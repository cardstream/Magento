<?php

namespace OPN\Payment\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use OPN\Payment\Block\Shortcut\Buttons;

/**
 * Adds Apple Pay / Google Pay express shortcut buttons to the cart page and the mini-cart.
 *
 * The product page already renders its own Apple Pay / Google Pay buttons (see
 * catalog_product_view.xml), so this observer skips the `is_catalog_product` context
 * to avoid rendering the buttons twice there.
 */
class AddExpressShortcutObserver implements ObserverInterface
{
    public function execute(EventObserver $observer)
    {
        if ($observer->getEvent()->getIsCatalogProduct()) {
            return;
        }

        /** @var \Magento\Catalog\Block\ShortcutButtons $shortcutButtons */
        $shortcutButtons = $observer->getEvent()->getContainer();

        $shortcut = $shortcutButtons->getLayout()->createBlock(Buttons::class);

        $shortcutButtons->addShortcut($shortcut);
    }
}
