<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class SimpleConsumerStarter
{
    /**
     * List of consumers to process
     */
    private const CONSUMERS = [
        'loyaltyshop_free_product_purchase_event_consumer',
        'loyaltyshop_free_product_remove_event_consumer',
    ];

    private const MAX_MESSAGES_PER_CONSUMER = 10;
    private const RATE_LIMIT_DELAY = 1; // 1 second delay between batches

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param ConsumerFactory $consumerFactory
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ConsumerFactory $consumerFactory,
        protected LoyaltyHelper $loyaltyHelper,
    ) {
    }

    /**
     * Execute cron job to process queue messages with rate limiting
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        try {
            foreach (self::CONSUMERS as $consumerName) {
                $this->processConsumer($consumerName);
            }
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Queue Consumer Error: " . $e->getMessage());
        }
    }

    /**
     * Process a single consumer with rate limiting
     *
     * @param string $consumerName
     * @return void
     */
    private function processConsumer(string $consumerName): void
    {
        try {
            $consumer = $this->consumerFactory->get($consumerName);

            // Process messages in smaller batches to respect rate limits
            $batchSize = 5;
            $batches = (int) ceil(self::MAX_MESSAGES_PER_CONSUMER / $batchSize);

            for ($i = 0; $i < $batches; $i++) {
                $consumer->process($batchSize);

                // Add delay between batches to respect API rate limits
                if ($i < $batches - 1) {
                    sleep(self::RATE_LIMIT_DELAY);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Consumer error for " . $consumerName . ": " . $e->getMessage());
        }
    }
}
