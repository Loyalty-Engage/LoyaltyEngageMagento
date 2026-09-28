<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class RepairLoyaltyFreeShippingRule implements DataPatchInterface
{
    private const RULE_NAMES = [
        'Loyalty Free Shipping - Brons Tier',
        'loyalty_free_shipping_brons',
    ];

    public function __construct(private ResourceConnection $resourceConnection)
    {
    }

    public function apply(): self
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('salesrule');

        $connection->update(
            $table,
            [
                'simple_action' => 'by_percent',
                'discount_amount' => 0,
                'simple_free_shipping' => 2,
                'apply_to_shipping' => 0,
                'is_active' => 0,
            ],
            [
                'name IN (?)' => self::RULE_NAMES,
                'simple_action = ?' => 'free_shipping',
            ]
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreateLoyaltyFreeShippingRule::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
