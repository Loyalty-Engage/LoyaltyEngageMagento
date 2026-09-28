<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Controller\Discount;

class Claim extends \LoyaltyEngage\LoyaltyShop\Controller\CustomerAction
{
    protected function perform(int $customerId, string $sku): \LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface
    {
        return $this->cart->buyDiscountCodeProduct($customerId, $sku);
    }
}
