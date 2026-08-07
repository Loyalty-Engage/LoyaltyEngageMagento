<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\CartItemRemoveProcessor;
use Magento\Quote\Model\Quote;

class QuoteRemoveItemPlugin
{
    public function __construct(
        private CartItemRemoveProcessor $cartItemRemoveProcessor
    ) {
    }

    public function aroundRemoveItem(Quote $subject, callable $proceed, $itemId): Quote
    {
        $item = $subject->getItemById($itemId);
        $result = $proceed($itemId);

        if ($item) {
            $this->cartItemRemoveProcessor->process($subject, $item);
        }

        return $result;
    }
}
