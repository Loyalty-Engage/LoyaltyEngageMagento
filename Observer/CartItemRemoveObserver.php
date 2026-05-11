<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote\Item as QuoteItem;

/**
 * Observer: CartItemRemoveObserver
 *
 * Handles two scenarios when a cart item is removed:
 *
 * 1. If the removed item IS a loyalty product:
 *    → Send a remove event to the LoyaltyEngage API immediately.
 *
 * 2. If the removed item is a REGULAR product:
 *    → Recalculate the cart subtotal (incl. tax, excl. loyalty products).
 *    → If the subtotal drops below the configured minimum order value,
 *      remove all loyalty products from the cart and notify the API.
 */
class CartItemRemoveObserver implements ObserverInterface
{
    public function __construct(
        private LoyaltyHelper $loyaltyHelper,
        private LoyaltyengageCart $loyaltyengageCart,
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

        $hashedEmail = $this->loyaltyHelper->hashEmail($email);

        // Scenario 1: The removed item is a loyalty product → notify API
        if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
            $this->sendRemoveEventToApi($hashedEmail, $item);
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
            $this->removeLoyaltyProductsFromCart($quote, $hashedEmail, $minimumOrderValue, $subtotalAfterRemoval);
        }
    }

    /**
     * Send remove event to LoyaltyEngage API for a loyalty product
     */
    private function sendRemoveEventToApi(string $hashedEmail, QuoteItem $item): void
    {
        $sku = $item->getSku();
        $qty = (int) $item->getQty();

        try {
            $this->loyaltyengageCart->removeItem($hashedEmail, $sku, $qty);

            $this->loyaltyHelper->log(
                'info',
                'CartItemRemoveObserver',
                'RemoveFromCart',
                'Loyalty product remove event sent to API.',
                [
                    'hashed_email' => substr($hashedEmail, 0, 8) . '...',
                    'sku'          => $sku,
                    'quantity'     => $qty
                ]
            );
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'CartItemRemoveObserver',
                'RemoveFromCartError',
                'Failed to send loyalty product remove event to API.',
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
     * Remove all loyalty products from the cart and notify the LoyaltyEngage API.
     * Also shows a message to the customer.
     */
    private function removeLoyaltyProductsFromCart($quote, string $hashedEmail, float $minimum, float $current): void
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

            // Notify LoyaltyEngage API
            try {
                $this->loyaltyengageCart->removeItem($hashedEmail, $sku, $qty);

                $this->loyaltyHelper->log(
                    'info',
                    'CartItemRemoveObserver',
                    'AutoRemovedLoyaltyProduct',
                    'Loyalty product auto-removed because cart dropped below minimum order value.',
                    [
                        'sku'     => $sku,
                        'minimum' => $minimum,
                        'current' => $current
                    ]
                );
            } catch (\Exception $e) {
                $this->loyaltyHelper->log(
                    'error',
                    'CartItemRemoveObserver',
                    'AutoRemoveApiError',
                    'Failed to notify API of auto-removed loyalty product.',
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

            $message = $this->loyaltyHelper->getFormattedMinimumOrderValueMessage($minimum, $current);
            $this->messageManager->addWarningMessage(
                __('Your loyalty product(s) have been removed because your cart total dropped below the minimum required amount. %1', $message)
            );

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
