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
     * Claim a discount after adding product using customer ID.
     *
     * @param int $customerId
     * @param float $discount
     * @param string $sku
     * @return LoyaltyCartResponseInterface
     */
    public function claimDiscountAfterAddToLoyaltyCart(int $customerId, float $discount, string $sku): LoyaltyCartResponseInterface;

    /**
     * Add multiple products to the cart using loyalty points.
     *
     * @param int $customerId
     * @param string[] $skus
     * @return LoyaltyCartResponseInterface
     */
    public function addMultipleProducts(int $customerId, array $skus): LoyaltyCartResponseInterface;
}
