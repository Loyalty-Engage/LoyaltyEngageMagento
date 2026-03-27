<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class CartItemHelper implements ArgumentInterface
{
    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Check if the item quantity should be locked (loyalty product)
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    public function isQtyLocked($item): bool
    {
        $isLocked = $this->isLoyaltyProduct($item);

        if ($isLocked && $this->loyaltyLogger->isDebugEnabled()) {
            $this->loyaltyLogger->debug(
                LoyaltyLogger::COMPONENT_VIEWMODEL,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf('Loyalty product detected in cart: %s (SKU: %s)', $item->getName(), $item->getSku())
            );
        }

        return $isLocked;
    }

    /**
     * Check if a quote item is a loyalty product
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProduct($item): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }

        // Method 2: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $item->getOptionByCode('additional_options');
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
