<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Item as QuoteItem;
use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class QuoteItemPriceProtectionPlugin
{
    /**
     * @var EnterpriseDetection
     */
    private $enterpriseDetection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @param EnterpriseDetection $enterpriseDetection
     * @param LoggerInterface $logger
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        EnterpriseDetection $enterpriseDetection,
        LoggerInterface $logger,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->enterpriseDetection = $enterpriseDetection;
        $this->logger = $logger;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Protect regular product prices from being modified
     *
     * @param QuoteItem $subject
     * @param callable $proceed
     * @param float $price
     * @return QuoteItem
     */
    public function aroundSetCustomPrice(QuoteItem $subject, callable $proceed, $price)
    {
        // Only allow custom price setting for confirmed loyalty products
        if ($this->isConfirmedLoyaltyProduct($subject)) {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Allowing custom price %.2f for confirmed loyalty product: %s',
                $price,
                $subject->getName()
            ));
            return $proceed($price);
        }

        // Block custom price setting for regular products
        $this->logger->warning(sprintf(
            '[LOYALTY-PRICE-PROTECTION] BLOCKED custom price %.2f for regular product: %s (SKU: %s)',
            $price,
            $subject->getName(),
            $subject->getSku()
        ));
        
        return $subject;
    }

    /**
     * Protect regular product original custom prices from being modified
     *
     * @param QuoteItem $subject
     * @param callable $proceed
     * @param float $price
     * @return QuoteItem
     */
    public function aroundSetOriginalCustomPrice(QuoteItem $subject, callable $proceed, $price)
    {
        // Only allow original custom price setting for confirmed loyalty products
        if ($this->isConfirmedLoyaltyProduct($subject)) {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Allowing original custom price %.2f for confirmed loyalty product: %s',
                $price,
                $subject->getName()
            ));
            return $proceed($price);
        }

        // Block original custom price setting for regular products
        $this->logger->warning(sprintf(
            '[LOYALTY-PRICE-PROTECTION] BLOCKED original custom price %.2f for regular product: %s (SKU: %s)',
            $price,
            $subject->getName(),
            $subject->getSku()
        ));
        
        return $subject;
    }

    /**
     * Strict loyalty product detection - only confirmed loyalty products
     *
     * @param QuoteItem $subject
     * @return bool
     */
    private function isConfirmedLoyaltyProduct(QuoteItem $subject): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $subject->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Confirmed loyalty product via option: %s',
                $subject->getName()
            ));
            return true;
        }

        // Method 2: Check item data directly (secondary check)
        $loyaltyData = $subject->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            $this->logger->info(sprintf(
                '[LOYALTY-PRICE-PROTECTION] Confirmed loyalty product via data: %s',
                $subject->getName()
            ));
            return true;
        }

        // All other cases are treated as regular products
        $this->logger->info(sprintf(
            '[LOYALTY-PRICE-PROTECTION] Confirmed regular product: %s (SKU: %s)',
            $subject->getName(),
            $subject->getSku()
        ));
        
        return false;
    }
}
