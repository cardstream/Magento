<?php
namespace P3\PaymentGateway\Setup;

use Magento\Framework\Setup\UninstallInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        if ($setup->getConnection()->isTableExists($setup->getTable('payment_gateway_wallets'))) {
            $installer->getConnection()->dropTable($installer->getTable('payment_gateway_wallets'));
        }

        $installer->endSetup();
    }
}
