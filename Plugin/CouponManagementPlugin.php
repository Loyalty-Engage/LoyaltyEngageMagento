<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\CouponManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Model\CouponClaim;

class CouponManagementPlugin
{
    public function __construct(private CartRepositoryInterface $quotes, private CouponClaim $claim)
    {
    }

    public function afterSet(CouponManagement $subject, $result, $cartId, $couponCode)
    {
        if ($result) {
            $this->claim->queue($this->quotes->getActive($cartId));
        }
        return $result;
    }
}
