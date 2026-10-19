<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Historical patch identity retained for upgrades without changing deployment configuration.
 * Consumer scheduling belongs to the merchant's deployment.
 */
class ConfigureCronConsumers implements DataPatchInterface
{
    public function apply(): self
    {
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
