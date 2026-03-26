<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

class QuoteItemQtyValidatorPlugin
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
     * QuoteItemQtyValidatorPlugin construct
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
     * Enforce quantity = 1 for loyalty products (before validation)
     * STRICT APPROACH: Always enforce qty = 1 for confirmed loyalty products
     *
     * @param QuantityValidator $subject
     * @param Observer $observer
     * @return void
     */
    public function beforeValidate(QuantityValidator $subject, Observer $observer)
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $quoteItem = $observer->getEvent()->getItem();
        
        // Early exit if no quote item
        if (!$quoteItem) {
            return;
        }

        // ONLY process if this is a 100% confirmed loyalty product
        if (!$this->isConfirmedLoyaltyProduct($quoteItem)) {
            return; // Leave regular products completely untouched
        }

        $currentQty = $quoteItem->getQty();
        
        // ALWAYS enforce quantity = 1 for loyalty products
        if ($currentQty != 1) {
            $quoteItem->setQty(1);

            // Log the enforcement action
            $this->logger->info(sprintf(
                '[LOYALTY-CART] Quantity enforcement: Reset qty for loyalty product %s from %s to 1',
                $quoteItem->getSku(),
                $currentQty
            ));

            $this->loyaltyLogger->info(
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_VALIDATION,
                sprintf(
                    'Loyalty product quantity enforced: %s (SKU: %s) - Changed from %s to 1',
                    $quoteItem->getName(),
                    $quoteItem->getSku(),
                    $currentQty
                )
            );
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
            $value = $this->safeUnserialize($additionalOptions->getValue());
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
        }

        // Not a confirmed loyalty product
        return false;
    }

    /**
     * Safely unserialize data with JSON fallback
     * Prevents PHP object injection vulnerabilities
     *
     * @param string|null $data
     * @return array|null
     */
    private function safeUnserialize(?string $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        $jsonResult = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonResult)) {
            return $jsonResult;
        }

        try {
            $result = @unserialize($data, ['allowed_classes' => false]);
            return is_array($result) ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
