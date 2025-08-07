<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Quote\Model\QuoteRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartExpiry
{
    private const HTTP_OK = 200;
    private const LOYALTY_EXPIRY_HOURS = 24; // 24 hours for loyalty products

    /**
     * CartExpiry Construct
     *
     * @param LoggerInterface $logger
     * @param QuoteRepository $quoteRepository
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected QuoteRepository $quoteRepository,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected FilterBuilder $filterBuilder,
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * Execute Cron For Loyalty Product Expiry (24 hours)
     * ONLY removes loyalty products, leaves regular products untouched
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $loggerStatus = $this->loyaltyengageCart->getLoggerStatus();
        
        // Calculate 24-hour expiry time
        $fromTime = new \DateTime('now', new \DateTimezone('UTC'));
        $fromTime->sub(\DateInterval::createFromDateString(self::LOYALTY_EXPIRY_HOURS . " hours"));
        $fromDate = $fromTime->format('Y-m-d H:i:s');

        if ($loggerStatus) {
            $this->logger->info(
                sprintf(
                    '[CartExpiry] Starting loyalty product cleanup for quotes older than %s (%d hours)',
                    $fromDate,
                    self::LOYALTY_EXPIRY_HOURS
                )
            );
        }

        // Find active quotes older than 24 hours
        $this->searchCriteriaBuilder->addFilter('created_at', $fromDate, 'lteq');
        $this->searchCriteriaBuilder->addFilter('customer_email', null, 'neq');
        $this->searchCriteriaBuilder->addFilter('is_active', 1, 'eq');
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $searchResults = $this->quoteRepository->getList($searchCriteria);

        $processedQuotes = 0;
        $loyaltyItemsRemoved = 0;

        if ($searchResults->getTotalCount() > 0) {
            foreach ($searchResults->getItems() as $quote) {
                try {
                    $email = $quote->getCustomerEmail();
                    if (!$email) {
                        if ($loggerStatus) {
                            $this->logger->warning('[CartExpiry] No email found for quote ID ' . $quote->getId());
                        }
                        continue;
                    }

                    $quoteFullObject = $this->quoteRepository->get($quote->getId());
                    $items = $quoteFullObject->getAllItems();
                    $loyaltyItemsInQuote = [];
                    $regularItemsCount = 0;

                    // Identify loyalty vs regular products
                    foreach ($items as $item) {
                        if ($this->isLoyaltyProduct($item)) {
                            $loyaltyItemsInQuote[] = $item;
                        } else {
                            $regularItemsCount++;
                        }
                    }

                    // Skip if no loyalty products found
                    if (empty($loyaltyItemsInQuote)) {
                        if ($loggerStatus) {
                            $this->logger->info(
                                sprintf(
                                    '[CartExpiry] Quote ID %d has no loyalty products to remove',
                                    $quote->getId()
                                )
                            );
                        }
                        continue;
                    }

                    // Remove loyalty products from external system first
                    $response = $this->loyaltyengageCart->removeAllItem($email);

                    if ($response !== self::HTTP_OK) {
                        if ($loggerStatus) {
                            $this->logger->warning(
                                sprintf(
                                    '[CartExpiry] Could not remove loyalty products from external system for %s (Quote ID: %d). Response: %d',
                                    $email,
                                    $quote->getId(),
                                    $response
                                )
                            );
                        }
                        // Continue anyway to clean up local cart
                    }

                    // Remove ONLY loyalty products from the quote
                    $removedCount = 0;
                    foreach ($loyaltyItemsInQuote as $loyaltyItem) {
                        $quoteFullObject->removeItem($loyaltyItem->getId());
                        $removedCount++;
                        
                        if ($loggerStatus) {
                            $this->logger->info(
                                sprintf(
                                    '[CartExpiry] Removed loyalty product: %s (SKU: %s) from quote ID %d',
                                    $loyaltyItem->getName(),
                                    $loyaltyItem->getSku(),
                                    $quote->getId()
                                )
                            );
                        }
                    }

                    // Save the quote (regular products remain)
                    $this->quoteRepository->save($quoteFullObject);

                    $loyaltyItemsRemoved += $removedCount;
                    $processedQuotes++;

                    if ($loggerStatus) {
                        $this->logger->info(
                            sprintf(
                                '[CartExpiry] Quote ID %d processed: Removed %d loyalty products, %d regular products remain',
                                $quote->getId(),
                                $removedCount,
                                $regularItemsCount
                            )
                        );
                    }

                } catch (\Exception $e) {
                    if ($loggerStatus) {
                        $this->logger->error(
                            sprintf(
                                '[CartExpiry] Error processing quote ID %d: %s',
                                $quote->getId(),
                                $e->getMessage()
                            )
                        );
                    }
                }
            }
        }

        // Final summary log
        if ($loggerStatus) {
            $this->logger->info(
                sprintf(
                    '[CartExpiry] Cleanup completed: %d quotes processed, %d loyalty products removed, %d total quotes found',
                    $processedQuotes,
                    $loyaltyItemsRemoved,
                    $searchResults->getTotalCount()
                )
            );
        }
    }

    /**
     * Check if quote item is a loyalty product
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProduct($item): bool
    {
        // Method 1: Check for loyalty_locked_qty option (most reliable)
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
            $value = @unserialize($additionalOptions->getValue());
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
}
