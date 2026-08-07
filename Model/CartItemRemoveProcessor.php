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
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $email = $this->resolveCustomerEmail($quote);

        if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
            if ($email) {
                $this->publishRemoveEvent($email, $item);
            }
            return;
        }

        if ($quote->getData(self::AUTO_REMOVE_FLAG) || !$email || !$this->loyaltyHelper->isMinimumOrderValueEnabled()) {
            return;
        }

        $subtotalAfterRemoval = $this->calculateSubtotal($quote);
        $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty();

        if ($subtotalAfterRemoval >= $minimumOrderValue) {
            return;
        }

        $this->removeLoyaltyProductsFromCart($quote, $minimumOrderValue, $subtotalAfterRemoval);
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

        foreach ($quote->getAllVisibleItems() as $item) {
            if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
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
                if (!$this->loyaltyHelper->isLoyaltyProduct($item)) {
                    continue;
                }

                $quote->removeItem($item->getId());
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
            $quote->collectTotals();
            $this->messageManager->addWarningMessage(
                $this->loyaltyHelper->getFormattedLoyaltyProductRemovedMessage($minimum, $current)
            );
        }
    }

    private function publishRemoveEvent(string $email, QuoteItem $item): void
    {
        $payload = [
            'email' => $email,
            'sku' => $item->getSku(),
            'quantity' => (int) $item->getQty(),
        ];

        try {
            $this->publisher->publish('loyaltyshop.free_product_remove_event', json_encode($payload));

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
