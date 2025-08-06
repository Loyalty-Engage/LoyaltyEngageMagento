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

    /**
     * CartExpiry Construct
     *
     * @param LoggerInterface $logger
     * @param QuoteRepository $quoteRepository
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param LoyaltyengageCart $loyaltyengageCart
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
     * Execute Cron For Empty Cart
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            $expiryTime = $this->loyaltyengageCart->getexpiryTime();
            $fromTime = new \DateTime('now', new \DateTimezone('UTC'));
            $expiryMinutes = $expiryTime * 60;

            $fromTime->sub(\DateInterval::createFromDateString("$expiryMinutes minutes"));
            $fromDate = $fromTime->format('Y-m-d H:i:s');

            $loggerStatus = $this->loyaltyengageCart->getLoggerStatus();

            $this->searchCriteriaBuilder->addFilter('created_at', $fromDate, 'lteq');
            $this->searchCriteriaBuilder->addFilter('customer_email', NULL, 'neq');
            $this->searchCriteriaBuilder->addFilter('is_active', 1, 'eq');
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $searchResults = $this->quoteRepository->getList($searchCriteria);

            if ($searchResults->getTotalCount() > 0) {
                foreach ($searchResults->getItems() as $quote) {
                    try {
                        $email = $quote->getCustomerEmail();
                        if (!$email) {
                            if ($loggerStatus) {
                                $this->logger->error('Not found quote email for quote ID ' . $quote->getId());
                            }
                            continue;
                        }

                        $response = $this->loyaltyengageCart->removeAllItem($email);

                        if ($response !== self::HTTP_OK) {
                            if ($loggerStatus) {
                                $this->logger->error(
                                    'Products could not be removed for email ' . $email . '. User is not eligible.'
                                );
                            }
                            continue;
                        }

                        $quoteFullObject = $this->quoteRepository->get($quote->getId());

                        $items = $quoteFullObject->getAllItems();
                        foreach ($items as $item) {
                            $quoteFullObject->removeItem($item->getId());
                        }
                        $this->quoteRepository->save($quoteFullObject);

                        if ($loggerStatus) {
                            $this->logger->info('Quote ID ' . $quote->getId() . ' processed successfully.');
                        }
                    } catch (\Exception $e) {
                        if ($loggerStatus) {
                            $this->logger->error('Error processing quote ID ' . $quote->getId() . ': ' . $e->getMessage());
                        }
                    }
                }
            } else {
                if ($loggerStatus) {
                    $this->logger->info('No expired quotes found to process.');
                }
            }
        }
    }
}
