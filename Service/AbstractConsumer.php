<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Service;

use LoyaltyEngage\LoyaltyShop\Helper\Data;

/**
 * Base abstract consumer for all LoyaltyShop queue processors
 */
abstract class AbstractConsumer
{
    /**
     * @var Data
     */
    protected Data $helper;

    /**
     * Constructor
     *
     * @param Data $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Data $helper,
    ) {
        $this->helper = $helper;
    }

    /**
     * Main entry point for queue processing
     *
     * @param string $payloadJson
     * @return void
     */
    public function process(string $payloadJson): void
    {
        if (empty($payloadJson)) {
            $this->logInfo('Empty payload received');
            return;
        }

        if (!$this->helper->isLoyaltyEngageEnabled()) {
            return;
        }

        $payload = json_decode($payloadJson, true);

        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        $startTime = microtime(true);

        try {
            $this->logInfo('Processing started', ['payload' => $payload]);
            $this->execute($payload);
            $this->logInfo('Processing success', [
                'processing_time_ms' => $this->getProcessingTime($startTime)
            ]);
        } catch (\Exception $e) {
            $this->logError('Processing failed', [
                'error_message' => $e->getMessage(),
                'payload' => $payload,
                'processing_time_ms' => $this->getProcessingTime($startTime)
            ]);
            throw $e;
        }
    }

    /**
     * Child classes must implement this
     *
     * @param array $payload
     * @return void
     */
    abstract protected function execute(array $payload): void;

    /**
     * Log info message
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logInfo(string $message, array $context = []): void
    {
        if ($this->helper->isLoggerEnabled()) {
            $this->helper->log('debug', 'Abstract_Consumer', 'Info', $message, $context);
        }
    }

    /**
     * Log error message
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function logError(string $message, array $context = []): void
    {
        if ($this->helper->isLoggerEnabled()) {
            $this->helper->log('error', 'Abstract_Consumer', 'Error', $message, $context);
        }
    }

    /**
     * Get processing time in milliseconds
     *
     * @param float $startTime
     * @return float
     */
    protected function getProcessingTime(float $startTime): float
    {
        return round((microtime(true) - $startTime) * 1000, 2);
    }
}