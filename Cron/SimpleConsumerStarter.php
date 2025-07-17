<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\ConsumerFactory;

class SimpleConsumerStarter
{
    private const CONSUMER_NAME = 'loyaltyshop_free_product_purchase_event_consumer';
    private const MAX_MESSAGES = 10;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param ConsumerFactory $consumerFactory
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ConsumerFactory $consumerFactory
    ) {
    }

    /**
     * Execute cron job to process queue messages
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->logger->info("[LoyaltyShop] Simple Consumer Starter - Checking for messages", [
                'consumer' => self::CONSUMER_NAME,
                'max_messages' => self::MAX_MESSAGES,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Create and run the consumer directly
            $consumer = $this->consumerFactory->get(self::CONSUMER_NAME);
            $consumer->process(self::MAX_MESSAGES);

            $this->logger->info("[LoyaltyShop] Simple Consumer Starter - Processing completed", [
                'consumer' => self::CONSUMER_NAME,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Simple Consumer Starter Error: " . $e->getMessage(), [
                'consumer' => self::CONSUMER_NAME,
                'exception' => $e->getTraceAsString(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
