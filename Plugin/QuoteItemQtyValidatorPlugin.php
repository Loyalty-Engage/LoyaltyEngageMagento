<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

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
     * QuoteItemQtyValidatorPlugin construct
     *
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Enforce quantity = 1 for loyalty products (before validation)
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

        if (!$quoteItem) {
            return;
        }

        if (!$this->isConfirmedLoyaltyProduct($quoteItem)) {
            return;
        }

        $currentQty = $quoteItem->getQty();

        if ($currentQty != 1) {
            $quoteItem->setQty(1);

            $this->loyaltyLogger->debug(
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
