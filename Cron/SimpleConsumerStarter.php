<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class SimpleConsumerStarter
{
    private const CONSUMER_NAME = 'loyaltyshop_free_product_purchase_event_consumer';
    private const MAX_MESSAGES = 10;
    private const RATE_LIMIT_DELAY = 1; // 1 second delay between batches to respect API rate limits

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
                'consumer' => self::CONSUMER_NAME,
                'max_messages' => self::MAX_MESSAGES,
                'rate_limit_delay' => self::RATE_LIMIT_DELAY,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Create and run the consumer with rate limiting
            $consumer = $this->consumerFactory->get(self::CONSUMER_NAME);
            
            // Process messages in smaller batches to respect rate limits
            $batchSize = 5; // Process 5 messages at a time
            $totalProcessed = 0;
            
            for ($i = 0; $i < ceil(self::MAX_MESSAGES / $batchSize); $i++) {
                $batchStart = microtime(true);
                
                // Process batch
                $consumer->process($batchSize);
                $totalProcessed += $batchSize;
                
                $batchTime = round((microtime(true) - $batchStart) * 1000, 2);
                
                $this->logger->info("[LoyaltyShop] Queue Consumer - Batch processed", [
                    'batch_number' => $i + 1,
                    'batch_size' => $batchSize,
                    'processing_time_ms' => $batchTime,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                
                // Add delay between batches to respect API rate limits (200/min = ~3.3/sec)
                if ($i < ceil(self::MAX_MESSAGES / $batchSize) - 1) {
                    sleep(self::RATE_LIMIT_DELAY);
                }
            }

            $totalTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info("[LoyaltyShop] Queue Consumer - Processing completed", [
                'consumer' => self::CONSUMER_NAME,
                'total_processed' => $totalProcessed,
                'total_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->error("[LoyaltyShop] Queue Consumer Error: " . $e->getMessage(), [
                'consumer' => self::CONSUMER_NAME,
                'exception' => $e->getTraceAsString(),
                'processing_time_ms' => $totalTime,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
