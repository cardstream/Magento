<?php

namespace P3\PaymentGateway\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @throws \Zend_Db_Exception
     */

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (!$setup->getConnection()->isTableExists($setup->getTable('payment_gateway_wallets'))) {
            $table = $setup->getConnection()
                ->newTable($setup->getTable('payment_gateway_wallets'))
                ->addColumn(
                    'merchant_id',
                    Table::TYPE_TEXT,
                    null,
                    ['nullable' => false],
                    'Merchant ID'
                )
                ->addColumn(
                    'customer_email',
                    Table::TYPE_TEXT,
                    100,
                    ['nullable' => false],
                    'Customer email'
                )
                ->addColumn(
                    'wallet_id',
                    Table::TYPE_TEXT,
                    100,
                    ['nullable' => false],
                    'Wallet Id'
                )
                ->setComment('Payment Gateway Wallets Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');

            $setup->getConnection()->createTable($table);
        }

        $setup->endSetup();
    }
}
