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

        $startTime = microtime(true);
        
        try {
            $this->logger->info("[LoyaltyShop] Queue Consumer - Starting batch processing", [
                'consumers' => self::CONSUMERS,
                'max_messages_per_consumer' => self::MAX_MESSAGES_PER_CONSUMER,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Process each consumer
            foreach (self::CONSUMERS as $consumerName) {
                $this->processConsumer($consumerName);
            }

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info("[LoyaltyShop] Queue Consumer - All processing completed", [
                'total_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->error("[LoyaltyShop] Queue Consumer Error: " . $e->getMessage(), [
                'exception' => $e->getTraceAsString(),
                'processing_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
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
            $this->logger->info("[LoyaltyShop] Processing consumer: " . $consumerName);
            
            $consumer = $this->consumerFactory->get($consumerName);
            
            // Process messages in smaller batches to respect rate limits
            $batchSize = 5;
            
            for ($i = 0; $i < ceil(self::MAX_MESSAGES_PER_CONSUMER / $batchSize); $i++) {
                $batchStart = microtime(true);
                
                // Process batch
                $consumer->process($batchSize);
                
                $batchTime = round((microtime(true) - $batchStart) * 1000, 2);
                
                $this->logger->debug("[LoyaltyShop] Batch processed", [
                    'consumer' => $consumerName,
                    'batch_number' => $i + 1,
                    'processing_time_ms' => $batchTime
                ]);
                
                // Add delay between batches to respect API rate limits
                if ($i < ceil(self::MAX_MESSAGES_PER_CONSUMER / $batchSize) - 1) {
                    sleep(self::RATE_LIMIT_DELAY);
                }
            }

            $this->logger->info("[LoyaltyShop] Consumer completed: " . $consumerName);

        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Consumer error for " . $consumerName . ": " . $e->getMessage());
        }
    }
}
