<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartItemInterface;

/**
 * Class LoyaltyCartItem
 *
 * Represents a single cart item with SKU and quantity.
 * Used for API requests that require product information.
 */
class LoyaltyCartItem implements LoyaltyCartItemInterface
{
    /**
     * @var string
     */
    protected $sku;

    /**
     * @var int
     */
    protected $quantity;

    /**
     * Set the product SKU.
     *
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku)
    {
        $this->sku = $sku;
        return $this;
    }

    /**
     * Get the product SKU.
     *
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * Set the product quantity.
     *
     * @param int $quantity
     * @return $this
     */
    public function setQuantity(int $quantity)
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Get the product quantity.
     *
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
