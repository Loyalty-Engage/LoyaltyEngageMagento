<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemRemoveApiInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

class LoyaltyCartItemRemove implements LoyaltyCartItemRemoveApiInterface
{
    public function __construct(private CartRemoval $removal)
    {
    }

    public function removeProduct(string $sku, int $customerId, int $quantity): LoyaltyCartResponseInterface
    {
        return $this->removal->remove($customerId, $sku, $quantity);
    }
}
