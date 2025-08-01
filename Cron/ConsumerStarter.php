<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class ConsumerStarter
{
    private const CONSUMER_NAME = 'loyaltyshop_free_product_purchase_event_consumer';
    private const MAX_MESSAGES = 50;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param DirectoryList $directoryList
     * @param LoyaltyengageCart $loyaltyengageCart
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected DirectoryList $directoryList,
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * Execute cron job to ensure consumer is running
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            try {
                // Check if consumer is already running
                if ($this->isConsumerRunning()) {
                    return; // Consumer is already running, no action needed
                }

                // Start the consumer
                $this->startConsumer();

            } catch (\Exception $e) {
                $this->logger->error("[LoyaltyShop] Consumer Starter Error: " . $e->getMessage(), [
                    'exception' => $e->getTraceAsString(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Check if consumer is already running
     *
     * @return bool
     */
    private function isConsumerRunning(): bool
    {
        // Check if process is running using ps command
        $command = "ps aux | grep '" . self::CONSUMER_NAME . "' | grep -v grep | wc -l";
        $output = (int) shell_exec($command);

        return $output > 0;
    }

    /**
     * Start the queue consumer
     *
     * @return void
     */
    private function startConsumer(): void
    {
        try {
            $this->logger->info("[LoyaltyShop] Starting queue consumer", [
                'consumer' => self::CONSUMER_NAME,
                'max_messages' => self::MAX_MESSAGES,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Get Magento root directory
            $magentoRoot = $this->directoryList->getRoot();

            // Build and execute the command to start consumer in background
            $command = sprintf(
                'cd %s && nohup php bin/magento queue:consumers:start %s --max-messages=%d > /dev/null 2>&1 &',
                $magentoRoot,
                self::CONSUMER_NAME,
                self::MAX_MESSAGES
            );

            shell_exec($command);

            $this->logger->info("[LoyaltyShop] Queue consumer started successfully", [
                'consumer' => self::CONSUMER_NAME,
                'command' => $command,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Failed to start queue consumer", [
                'consumer' => self::CONSUMER_NAME,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
