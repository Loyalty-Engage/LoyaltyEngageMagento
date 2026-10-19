<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\CartItemRemoveProcessor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;

class MinimumOrderSubmitPlugin
{
    public function __construct(private CartItemRemoveProcessor $processor, private CartRepositoryInterface $quotes)
    {
    }

    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        if (!$this->processor->requiresMinimumCheck($quote)) {
            return;
        }
        $before = count($quote->getAllVisibleItems());
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        if (count($quote->getAllVisibleItems()) < $before) {
            // Persist the removal/outbox before rejecting checkout, including an emptied quote.
            $this->quotes->save($quote);
            throw new LocalizedException(__('Loyalty rewards were removed because the minimum order value is no longer met. Please review your cart.'));
        }
    }
}
