<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Api\Data;

interface LoyaltyCartItemInterface
{
    /**
     * Set the product SKU.
     *
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku);

    /**
     * Get the product SKU.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Set the product quantity.
     *
     * @param int $quantity
     * @return $this
     */
    public function setQuantity(int $quantity);

    /**
     * Get the product quantity.
     *
     * @return int
     */
    public function getQuantity(): int;
}
