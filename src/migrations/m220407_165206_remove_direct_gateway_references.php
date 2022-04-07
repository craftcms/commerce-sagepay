<?php

namespace craft\commerce\sagepay\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * m220407_165206_remove_direct_gateway_references migration.
 */
class m220407_165206_remove_direct_gateway_references extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $directGatewayIds = (new Query())
            ->select(['id'])
            ->where(['type' => 'craft\\commerce\\sagepay\\gateways\\Direct'])
            ->from(['{{%commerce_gateways}}'])
            ->column();

        if (empty($directGatewayIds)) {
            return true;
        }

        // Remove related gateway data
        $this->update('{{%commerce_orders}}', ['gatewayId' => null], ['gatewayId' => $directGatewayIds]);
        $this->update('{{%commerce_transactions}}', ['gatewayId' => null], ['gatewayId' => $directGatewayIds]);

        // Remove gateways
        $this->delete('{{%commerce_gateways}}', ['id' => $directGatewayIds]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m220407_165206_remove_direct_gateway_references cannot be reverted.\n";
        return false;
    }
}
