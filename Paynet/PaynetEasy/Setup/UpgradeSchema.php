<?php

namespace Paynet\PaynetEasy\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UpgradeSchemaInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        // Gateway ids do not fit into int: widen the column of tables created by older versions
        $tableName = $setup->getTable('payneteasy_payments');
        if (version_compare($context->getVersion(), '1.0.1', '<') && $setup->getConnection()->isTableExists($tableName)) {
            $setup->getConnection()->modifyColumn($tableName, 'paynet_order_id', [
                'type' => Table::TYPE_BIGINT,
                'unsigned' => true,
                'nullable' => false,
                'comment' => 'PaynetOrderId',
            ]);
        }

        $setup->endSetup();
    }
}
