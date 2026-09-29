<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Service;

/** A documented HTTP 400 refusal, not an ambiguous transport failure. */
class ApiRejectionException extends ApiException
{
    private const MESSAGES = [
        'EMPTY_REQUEST_BODY' => 'The redemption request is empty. Please contact the shop.',
        'INVALID_REQUEST_BODY' => 'The redemption request is invalid. Please contact the shop.',
        'INVALID_IDENTIFIER' => 'Your loyalty profile could not be identified. Please contact the shop.',
        'INVALID_SKU' => 'This reward has an invalid product code. Please contact the shop.',
        'SKU_NOT_FOUND' => 'This reward could not be found in the loyalty shop.',
        'QUANTITY_INVALID' => 'The requested reward quantity is invalid.',
        'PRODUCT_NOT_AVAILABLE_IN_LOYALTY_SHOP' => 'This reward is not available in the loyalty shop.',
        'DISCOUNT_CODE_TYPE_PRODUCT_NOT_PURCHASABLE' => 'This discount reward cannot be redeemed through this action.',
        'LOYALTY_TIER_INSUFFICIENT' => 'Your loyalty tier is not high enough to redeem this reward.',
        'AVAILABLE_COINS_INSUFFICIENT' => 'You do not have enough available coins to redeem this reward.',
        'PRODUCTS_IN_CART_LIMIT_REACHED' => 'You have reached the maximum number of loyalty rewards in your cart.',
        'MAXIMUM_COIN_SPEND_LIMIT_EXCEEDED' => 'This reward would exceed your coin spending limit.',
    ];

    public function __construct(private string $reason)
    {
        if (!self::isKnownReason($reason)) {
            throw new \InvalidArgumentException('Unknown Loyalty Engage rejection reason.');
        }
        parent::__construct('Loyalty Engage rejected the request: ' . $reason, 400);
    }

    public static function isKnownReason(string $reason): bool
    {
        return isset(self::MESSAGES[$reason]);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCustomerMessage(): string
    {
        return (string) __(self::MESSAGES[$this->reason]);
    }
}
