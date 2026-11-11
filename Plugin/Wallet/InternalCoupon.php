<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Utility;
use Magento\Quote\Model\Quote\Address;

class InternalCoupon
{
    public function aroundCanProcessRule(Utility $subject, callable $proceed, Rule $rule, Address $address): bool
    {
        // Coin purchases fund tender, never a second sales-rule discount.
        if ((int) $rule->getCouponType() !== Rule::COUPON_TYPE_NO_COUPON
            && preg_match('/\ALE-WALLET-[a-f0-9]{64}\z/i', (string) $address->getQuote()->getCouponCode())) {
            return false;
        }
        return $proceed($rule, $address);
    }
}
