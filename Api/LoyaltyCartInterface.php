<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

interface LoyaltyCartInterface
{
    /**
     * Add a product to the cart using loyalty points.
     *
     * @param int $customerId
     * @param string $sku
     * @return LoyaltyCartResponseInterface
     */
    public function addProduct(int $customerId, string $sku);
}
