<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\CartItemRemoveProcessor;
use Magento\Quote\Model\Quote;

class MinimumOrderTotalsPlugin
{
    private const CHECKING = 'loyaltyshop_minimum_check_in_progress';

    public function __construct(private CartItemRemoveProcessor $processor)
    {
    }

    public function aroundCollectTotals(Quote $subject, callable $proceed): Quote
    {
        if ($subject->getData(self::CHECKING)) {
            return $proceed();
        }
        $subject->setData(self::CHECKING, true);
        try {
            $result = $proceed();
            if ($this->processor->enforceMinimum($subject)) {
                // One additional pass updates totals after removing rewards; nested checks are skipped.
                $subject->setTotalsCollectedFlag(false)->collectTotals();
            }
            return $result;
        } finally {
            $subject->unsetData(self::CHECKING);
        }
    }
}
