<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class UpgradeEventDelivery implements DataPatchInterface
{
    public function __construct(private ResourceConnection $resource, private ConfigureCronConsumers $consumers)
    {
    }

    public function apply(): self
    {
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate($this->resource->getTableName('core_config_data'), [
            'scope' => 'default', 'scope_id' => 0, 'path' => 'loyalty/export/tracking_started_at',
            'value' => gmdate('Y-m-d H:i:s'),
        ], ['path']);
        // Exact generator signature only; do not claim merchant-created rules by prefix alone.
        $db->update($this->resource->getTableName('salesrule'), ['loyaltyengage_managed' => 1], [
            'description = ?' => 'Auto-generated from LoyaltyEngage',
            'simple_action IN (?)' => ['cart_fixed', 'by_percent'], 'use_auto_generation = ?' => 1,
        ]);
        $this->consumers->apply();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [ConfigureCronConsumers::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
