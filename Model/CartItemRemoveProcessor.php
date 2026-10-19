<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;

class CartItemRemoveProcessor
{
    private const AUTO_REMOVE_FLAG = 'loyaltyshop_auto_remove_in_progress';

    public function __construct(
        private LoyaltyHelper $loyaltyHelper,
        private PublisherInterface $publisher,
        private MessageManager $messageManager
    ) {
    }

    public function process(Quote $quote, QuoteItem $item): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled((int) $quote->getStoreId())) {
            return;
        }

        $email = $this->resolveCustomerEmail($quote);

        if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
            if ($email) {
                $this->publishRemoveEvent($email, $item);
            }
            return;
        }

        if ($quote->getData(self::AUTO_REMOVE_FLAG) || !$email || !$this->requiresMinimumCheck($quote)) {
            return;
        }

        // The totals plugin checks the remaining paid subtotal after Magento recalculates it.
        $quote->setTotalsCollectedFlag(false)->collectTotals();
    }

    public function requiresMinimumCheck(Quote $quote): bool
    {
        $storeId = (int) $quote->getStoreId();
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled($storeId)
            || !$this->loyaltyHelper->isMinimumOrderValueEnabled($storeId)) {
            return false;
        }
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item->isDeleted() && $this->loyaltyHelper->isLoyaltyProduct($item, true)) {
                return true;
            }
        }
        return false;
    }

    /** Call only after totals collection; this method never recursively collects totals. */
    public function enforceMinimum(Quote $quote): bool
    {
        if ($quote->getData(self::AUTO_REMOVE_FLAG) || !$this->requiresMinimumCheck($quote)) {
            return false;
        }

        $subtotalAfterRemoval = $this->calculateSubtotal($quote);
        $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty((int) $quote->getStoreId());

        if ($subtotalAfterRemoval + 0.0001 >= $minimumOrderValue) {
            return false;
        }

        $this->removeLoyaltyProductsFromCart($quote, $minimumOrderValue, $subtotalAfterRemoval);
        return true;
    }

    private function resolveCustomerEmail(Quote $quote): ?string
    {
        if ($quote->getCustomerEmail()) {
            return (string) $quote->getCustomerEmail();
        }

        $customerId = (int) $quote->getCustomerId();
        if (!$customerId) {
            return null;
        }

        $customerData = $this->loyaltyHelper->getCustomerDataById($customerId);
        return $customerData['email'] ?? null;
    }

    private function calculateSubtotal(Quote $quote): float
    {
        $subtotal = 0.0;

        foreach ($quote->getAllItems() as $item) {
            $parent = $item->getParentItem();
            if ($item->isDeleted() || ($parent && $parent->isDeleted())
                || ($parent && !$item->isChildrenCalculated())
                || ($item->getHasChildren() && $item->isChildrenCalculated())
                || $this->loyaltyHelper->isLoyaltyProduct($item, true)
                || ($parent && $this->loyaltyHelper->isLoyaltyProduct($parent, true))) {
                continue;
            }

            $subtotal += (float) ($item->getRowTotalInclTax() ?? $item->getRowTotal());
        }

        return $subtotal;
    }

    private function removeLoyaltyProductsFromCart(Quote $quote, float $minimum, float $current): void
    {
        $loyaltyItemsRemoved = 0;
        $quote->setData(self::AUTO_REMOVE_FLAG, true);

        try {
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item->isDeleted() || !$this->loyaltyHelper->isLoyaltyProduct($item, true)) {
                    continue;
                }

                if ($item->getId()) {
                    $quote->removeItem($item->getId());
                } else {
                    $item->isDeleted(true);
                    foreach ($item->getOptions() as $option) {
                        $option->isDeleted(true);
                    }
                    foreach ($item->getChildren() ?: [] as $child) {
                        $child->isDeleted(true);
                        foreach ($child->getOptions() as $option) {
                            $option->isDeleted(true);
                        }
                    }
                }
                $loyaltyItemsRemoved++;

                $this->loyaltyHelper->log(
                    'info',
                    'CartItemRemoveProcessor',
                    'AutoRemovedLoyaltyProduct',
                    'Loyalty product auto-removed after cart subtotal dropped below threshold.',
                    [
                        'sku' => $item->getSku(),
                        'minimum' => $minimum,
                        'current' => $current,
                    ]
                );
            }
        } finally {
            $quote->unsetData(self::AUTO_REMOVE_FLAG);
        }

        if ($loyaltyItemsRemoved > 0) {
            $this->messageManager->addWarningMessage(
                $this->loyaltyHelper->getFormattedLoyaltyProductRemovedMessage($minimum, $current)
            );
        }
    }

    private function publishRemoveEvent(string $email, QuoteItem $item): void
    {
        $quote = $item->getQuote();
        if (!$item->getId()) {
            return;
        }
        $payload = [
            'email' => $email,
            'store_id' => (int) $quote->getStoreId(),
            'quote_id' => (int) $quote->getId(),
            'removal_id' => (int) $item->getId(),
            'sku' => $item->getSku(),
            'quantity' => (int) $item->getQty(),
        ];

        try {
            $removals = $quote->getData('loyalty_pending_removals') ?: [];
            $removals[$item->getId()] = $payload;
            $quote->setData('loyalty_pending_removals', $removals);

            $this->loyaltyHelper->log(
                'info',
                'CartItemRemoveProcessor',
                'RemoveFromCart',
                'Loyalty product remove event published to queue.',
                $payload
            );
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'CartItemRemoveProcessor',
                'RemoveFromCartError',
                'Failed to publish loyalty product remove event to queue.',
                [
                    'sku' => $item->getSku(),
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
