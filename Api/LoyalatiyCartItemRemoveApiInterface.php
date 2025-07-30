<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

interface LoyalatiyCartItemRemoveApiInterface
{
    /**
     * RemoveProduct function
     *
     * @param string $sku
     * @param int $customerId
     * @param int $quantity
     * @return LoyaltyCartResponseInterface
     */
    public function removeProduct(string $sku, int $customerId, int $quantity): LoyaltyCartResponseInterface;
}
