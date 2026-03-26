<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\ShellInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

/**
 * Cron job to ensure queue consumers are running
 * 
 * Note: This class uses Magento's ShellInterface for secure command execution
 * instead of PHP's shell_exec() to prevent command injection vulnerabilities.
 */
class ConsumerStarter
{
    private const CONSUMER_NAME = 'loyaltyshop_free_product_purchase_event_consumer';
    private const MAX_MESSAGES = 50;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param DirectoryList $directoryList
     * @param LoyaltyHelper $loyaltyHelper
     * @param ShellInterface $shell
     * @param ConsumerConfigInterface|null $consumerConfig
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected DirectoryList $directoryList,
        protected LoyaltyHelper $loyaltyHelper,
        protected ShellInterface $shell,
        protected ?ConsumerConfigInterface $consumerConfig = null
    ) {
    }

    /**
     * Execute cron job to ensure consumer is running
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

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

    /**
     * Check if consumer is already running using Magento's shell
     *
     * @return bool
     */
    private function isConsumerRunning(): bool
    {
        try {
            // Use pgrep for safer process checking (no shell injection risk)
            // Escape the consumer name to prevent any injection
            $consumerName = escapeshellarg(self::CONSUMER_NAME);
            $output = $this->shell->execute('pgrep -f %s 2>/dev/null || echo ""', [$consumerName]);
            
            // If output contains process IDs, consumer is running
            return !empty(trim($output));
        } catch (\Exception $e) {
            // If we can't check, assume it's not running
            $this->logger->debug("[LoyaltyShop] Could not check consumer status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Start the queue consumer using Magento's shell
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
            $phpBinary = PHP_BINARY ?: 'php';

            // Build command with proper escaping
            // Using nohup to run in background, redirecting output to prevent blocking
            $command = sprintf(
                'cd %s && nohup %s bin/magento queue:consumers:start %s --max-messages=%d > /dev/null 2>&1 &',
                escapeshellarg($magentoRoot),
                escapeshellcmd($phpBinary),
                escapeshellarg(self::CONSUMER_NAME),
                (int) self::MAX_MESSAGES
            );

            // Execute using shell interface
            $this->shell->execute($command);

            $this->logger->info("[LoyaltyShop] Queue consumer started successfully", [
                'consumer' => self::CONSUMER_NAME,
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
