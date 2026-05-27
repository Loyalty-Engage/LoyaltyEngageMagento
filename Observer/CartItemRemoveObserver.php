<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Observer: CartItemRemoveObserver
 *
 * Handles two scenarios when a cart item is removed:
 *
 * 1. If the removed item IS a loyalty product:
 *    → Publish a remove event to the queue (async via FreeProductRemoveConsumer).
 *
 * 2. If the removed item is a REGULAR product:
 *    → Recalculate the cart subtotal (incl. tax, excl. loyalty products).
 *    → If the subtotal drops below the configured minimum order value,
 *      remove all loyalty products from the cart and publish remove events to the queue.
 */
class CartItemRemoveObserver implements ObserverInterface
{
    public function __construct(
        private LoyaltyHelper $loyaltyHelper,
        private PublisherInterface $publisher,
        private CustomerSession $customerSession,
        private MessageManager $messageManager
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        /** @var QuoteItem $item */
        $item = $observer->getEvent()->getQuoteItem();
        if (!$item) {
            return;
        }

        $quote = $item->getQuote();
        if (!$quote) {
            return;
        }

        $customer = $this->customerSession->getCustomer();
        $email = $customer ? $customer->getEmail() : null;

        if (!$email) {
            return;
        }

        // Scenario 1: The removed item is a loyalty product → publish remove event to queue
        if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
            $this->publishRemoveEvent($email, $item);
            return;
        }

        // Scenario 2: A regular product was removed → check if minimum order value is still met
        if (!$this->loyaltyHelper->isMinimumOrderValueEnabled()) {
            return;
        }

        // Calculate subtotal AFTER the current item is removed (excl. loyalty products, incl. tax)
        $subtotalAfterRemoval = $this->calculateSubtotalAfterRemoval($quote, $item);
        $minimumOrderValue = $this->loyaltyHelper->getMinimumOrderValueForLoyalty();

        if ($subtotalAfterRemoval < $minimumOrderValue) {
            $this->removeLoyaltyProductsFromCart($quote, $email, $minimumOrderValue, $subtotalAfterRemoval);
        }
    }

    /**
     * Publish a remove event to the queue for a loyalty product
     */
    private function publishRemoveEvent(string $email, QuoteItem $item): void
    {
        $sku = $item->getSku();
        $qty = (int) $item->getQty();

        $payload = [
            'email'    => $email,
            'sku'      => $sku,
            'quantity' => $qty
        ];

        try {
            $this->publisher->publish(
                'loyaltyshop.free_product_remove_event',
                json_encode($payload)
            );

            $this->loyaltyHelper->log(
                'info',
                'CartItemRemoveObserver',
                'RemoveFromCart',
                'Loyalty product remove event published to queue.',
                [
                    'email'    => $email,
                    'sku'      => $sku,
                    'quantity' => $qty
                ]
            );
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'CartItemRemoveObserver',
                'RemoveFromCartError',
                'Failed to publish loyalty product remove event to queue.',
                [
                    'sku'   => $sku,
                    'error' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Calculate cart subtotal (incl. tax) excluding loyalty products,
     * also excluding the item that is currently being removed.
     */
    private function calculateSubtotalAfterRemoval($quote, QuoteItem $removedItem): float
    {
        $subtotal = 0.0;

        foreach ($quote->getAllVisibleItems() as $item) {
            // Skip the item being removed
            if ($item->getId() === $removedItem->getId()) {
                continue;
            }

            // Skip loyalty products
            if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
                continue;
            }

            $subtotal += (float) ($item->getRowTotalInclTax() ?? $item->getRowTotal());
        }

        return $subtotal;
    }

    /**
     * Remove all loyalty products from the cart and publish remove events to the queue.
     * Also shows a message to the customer.
     */
    private function removeLoyaltyProductsFromCart($quote, string $email, float $minimum, float $current): void
    {
        $loyaltyItemsRemoved = 0;

        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$this->loyaltyHelper->isLoyaltyProduct($item)) {
                continue;
            }

            $sku = $item->getSku();
            $qty = (int) $item->getQty();

            // Remove from Magento quote
            $quote->removeItem($item->getId());

            // Publish remove event to queue
            $payload = [
                'email'    => $email,
                'sku'      => $sku,
                'quantity' => $qty
            ];

            try {
                $this->publisher->publish(
                    'loyaltyshop.free_product_remove_event',
                    json_encode($payload)
                );

                $this->loyaltyHelper->log(
                    'info',
                    'CartItemRemoveObserver',
                    'AutoRemovedLoyaltyProduct',
                    'Loyalty product auto-removed and remove event published to queue.',
                    [
                        'email'   => $email,
                        'sku'     => $sku,
                        'minimum' => $minimum,
                        'current' => $current
                    ]
                );
            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'CartItemRemoveObserver',
                    'AutoRemoveQueueError',
                    'Failed to publish auto-removed loyalty product remove event to queue.',
                    [
                        'sku'   => $sku,
                        'error' => $e->getMessage()
                    ]
                );
            }

            $loyaltyItemsRemoved++;
        }

        if ($loyaltyItemsRemoved > 0) {
            $quote->collectTotals()->save();

            $message = $this->loyaltyHelper->getFormattedLoyaltyProductRemovedMessage($minimum, $current);
            $this->messageManager->addWarningMessage($message);

            $this->loyaltyHelper->log(
                'info',
                'CartItemRemoveObserver',
                'AutoRemovedLoyaltyProducts',
                sprintf('Removed %d loyalty product(s) from cart due to minimum order value not met.', $loyaltyItemsRemoved),
                [
                    'minimum' => $minimum,
                    'current' => $current
                ]
            );
        }
    }
}
