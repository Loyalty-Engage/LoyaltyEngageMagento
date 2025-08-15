<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Psr\Log\LoggerInterface;

class CartUpdateObserver implements ObserverInterface
{
    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * CartUpdateObserver constructor
     *
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger,
        LoggerInterface $logger
    ) {
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
        $this->logger = $logger;
    }

    /**
     * Execute observer - enforce loyalty product quantities
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        // Get the cart/quote from the observer
        $cart = $observer->getEvent()->getCart();
        if (!$cart) {
            return;
        }

        $quote = $cart->getQuote();
        if (!$quote) {
            return;
        }

        $this->logger->info('[LOYALTY-CART] Cart update observer triggered - checking loyalty products');

        // Process all items in the cart
        $itemsUpdated = 0;
        foreach ($quote->getAllItems() as $item) {
            if ($this->isConfirmedLoyaltyProduct($item)) {
                $currentQty = $item->getQty();
                
                if ($currentQty != 1) {
                    $item->setQty(1);
                    $itemsUpdated++;

                    $this->logger->info(sprintf(
                        '[LOYALTY-CART] Cart update: Reset loyalty product %s (SKU: %s) from qty %s to 1',
                        $item->getName(),
                        $item->getSku(),
                        $currentQty
                    ));

                    $this->loyaltyLogger->info(
                        LoyaltyLogger::COMPONENT_OBSERVER,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf(
                            'Cart update - Loyalty product quantity enforced: %s (SKU: %s) - Changed from %s to 1',
                            $item->getName(),
                            $item->getSku(),
                            $currentQty
                        )
                    );
                }
            }
        }

        if ($itemsUpdated > 0) {
            $this->logger->info(sprintf(
                '[LOYALTY-CART] Cart update complete - %d loyalty product quantities enforced',
                $itemsUpdated
            ));
        }
    }

    /**
     * Reliable loyalty product detection - only confirmed loyalty products
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return bool
     */
    private function isConfirmedLoyaltyProduct($quoteItem): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $quoteItem->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }

        // Method 2: Check item data directly (secondary check)
        $loyaltyData = $quoteItem->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $quoteItem->getOptionByCode('additional_options');
        if ($additionalOptions) {
            try {
                $value = unserialize($additionalOptions->getValue());
                if (is_array($value)) {
                    foreach ($value as $option) {
                        if (
                            isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                            isset($option['value']) && $option['value'] === '1'
                        ) {
                            return true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently handle unserialize errors - not a loyalty product
            }
        }

        // Not a confirmed loyalty product
        return false;
    }
}
