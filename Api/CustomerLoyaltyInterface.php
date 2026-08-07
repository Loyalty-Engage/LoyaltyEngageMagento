<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api;

use LoyaltyEngage\LoyaltyShop\Api\Data\CustomerLoyaltyUpdateResponseInterface;

interface CustomerLoyaltyInterface
{
    /**
     * Update customer loyalty data by email.
     *
     * @param string $email
     * @param string|null $leCurrentTier
     * @param int|null $lePoints
     * @param int|null $leAvailableCoins
     * @param string|null $leNextTier
     * @param int|null $lePointsToNextTier
     * @param int|null $leReservedCoins
     * @param int|null $leExpiringPoints30d
     * @param string|null $storeCode
     * @return CustomerLoyaltyUpdateResponseInterface
     */
    public function updateCustomerLoyaltyData(
        string $email,
        ?string $leCurrentTier = null,
        ?int $lePoints = null,
        ?int $leAvailableCoins = null,
        ?string $leNextTier = null,
        ?int $lePointsToNextTier = null,
        ?int $leReservedCoins = null,
        ?int $leExpiringPoints30d = null,
        ?string $storeCode = null
    ): CustomerLoyaltyUpdateResponseInterface;
}
