<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

interface LoyaltyCartItemsRemoveApiInterface
{
    /**
     * RemoveProduct function
     *
     * @param int $customerId
     * @return LoyaltyCartResponseInterface
     */
    public function removeAllProduct(int $customerId): LoyaltyCartResponseInterface;
}
