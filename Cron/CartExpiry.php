<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Quote\Model\QuoteRepository;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\FilterBuilder;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartExpiry
{
    private const HTTP_OK = 200;
    private const LOYALTY_EXPIRY_HOURS = 24;

    /**
     * Execute Cron For Loyalty Product Expiry (24 hours)
     * ONLY removes loyalty products, leaves regular products untouched
     *
     * CartExpiry Construct
     *
     * @param QuoteRepository $quoteRepository
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected QuoteRepository $quoteRepository,
        protected LoyaltyengageCart $loyaltyengageCart,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected FilterBuilder $filterBuilder,
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * Execute cron for removing expired loyalty products from cart
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }
        $fromTime = new \DateTime('now', new \DateTimezone('UTC'));
        $fromTime->sub(
            \DateInterval::createFromDateString(
                self::LOYALTY_EXPIRY_HOURS . " hours"
            )
        );
        $fromDate = $fromTime->format('Y-m-d H:i:s');

        $this->loyaltyHelper->log(
            'info',
            'CartExpiry',
            'execute',
            sprintf(
                '[CartExpiry] Starting cleanup for quotes older than %s (%d hours)',
                $fromDate,
                self::LOYALTY_EXPIRY_HOURS
            )
        );

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
                        $this->loyaltyHelper->log(
                            'warning',
                            'CartExpiry',
                            'execute',
                            '[CartExpiry] No email for quote ID ' . $quote->getId()
                        );
                        continue;
                    }

                    $quoteFullObject = $this->quoteRepository->get($quote->getId());
                    $items = $quoteFullObject->getAllItems();

                    $loyaltyItemsInQuote = [];
                    $regularItemsCount = 0;

                    foreach ($items as $item) {
                        if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
                            $loyaltyItemsInQuote[] = $item;
                        } else {
                            $regularItemsCount++;
                        }
                    }

                    if (empty($loyaltyItemsInQuote)) {
                        $this->loyaltyHelper->log(
                            'debug',
                            'CartExpiry',
                            'execute',
                            sprintf(
                                '[CartExpiry] Quote %d has no loyalty products',
                                $quote->getId()
                            )
                        );
                        continue;
                    }

                    $response = $this->loyaltyengageCart->removeAllItem($email);

                    if ($response !== self::HTTP_OK) {
                        $this->loyaltyHelper->log(
                            'warning',
                            'CartExpiry',
                            'execute',
                            sprintf(
                                '[CartExpiry] External removal failed for %s (Quote ID: %d). Response: %d',
                                $email,
                                $quote->getId(),
                                $response
                            )
                        );
                    }

                    $removedCount = 0;

                    foreach ($loyaltyItemsInQuote as $loyaltyItem) {
                        $quoteFullObject->removeItem($loyaltyItem->getId());
                        $removedCount++;
                    }

                    $this->quoteRepository->save($quoteFullObject);

                    $loyaltyItemsRemoved += $removedCount;
                    $processedQuotes++;

                    $this->loyaltyHelper->log(
                        'info',
                        'CartExpiry',
                        'execute',
                        sprintf(
                            '[CartExpiry] Quote %d processed: %d removed, %d remain',
                            $quote->getId(),
                            $removedCount,
                            $regularItemsCount
                        )
                    );

                } catch (\Exception $e) {
                    $this->loyaltyHelper->log(
                        'error',
                        'CartExpiry',
                        'execute',
                        sprintf(
                            '[CartExpiry] Error quote %d: %s',
                            $quote->getId(),
                            $e->getMessage()
                        )
                    );
                }
            }
        }

        $this->loyaltyHelper->log(
            'debug',
            'CartExpiry',
            'execute',
            sprintf(
                '[CartExpiry] Done: %d quotes, %d removed, %d total',
                $processedQuotes,
                $loyaltyItemsRemoved,
                $searchResults->getTotalCount()
            )
        );
    }
}
