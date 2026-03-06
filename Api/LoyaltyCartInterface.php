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

    /**
     * Buy a discount code product using loyalty coins and apply the discount to the cart.
     *
     * @param int $customerId
     * @param string $sku SKU of the discount code product in LoyaltyEngage
     * @return LoyaltyCartResponseInterface
     */
    public function buyDiscountCodeProduct(int $customerId, string $sku): LoyaltyCartResponseInterface;

    /**
     * Add multiple products to the cart using loyalty points.
     *
     * @param int $customerId
     * @param string[] $skus
     * @return LoyaltyCartResponseInterface
     */
    public function addMultipleProducts(int $customerId, array $skus): LoyaltyCartResponseInterface;
}
