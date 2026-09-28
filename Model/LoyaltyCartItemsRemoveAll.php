<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartItemsRemoveApiInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;

class LoyaltyCartItemsRemoveAll implements LoyaltyCartItemsRemoveApiInterface
{
    public function __construct(private CartRemoval $removal)
    {
    }

    public function removeAllProduct(int $customerId): LoyaltyCartResponseInterface
    {
        return $this->removal->remove($customerId);
    }
}
