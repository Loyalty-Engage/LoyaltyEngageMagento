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

        $payload = json_decode($payloadJson, true);

        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        $storeId = isset($payload['store_id']) ? (int) $payload['store_id'] : null;

        if (!$this->helper->isLoyaltyEngageEnabled($storeId)) {
            return;
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

    public function deliver(array $payload): void
    {
        $required = match (static::TOPIC) {
            'loyaltyshop.purchase_event', 'loyaltyshop.return_event' => ['event', 'identifier', 'orderId', 'products'],
            'loyaltyshop.free_product_purchase_event' => ['email', 'orderId', 'products'],
            'loyaltyshop.free_product_remove_event' => ['email', 'sku', 'quantity'],
            'loyaltyshop.review_event' => ['review_id', 'customer_email'],
            'loyaltyshop.redeem_discount_event' => ['discount_code', 'identifier'],
            'loyaltyshop.claim_discount_event' => ['identifier', 'amount', 'currency'],
            default => [],
        };
        foreach ($required as $field) {
            if (empty($payload[$field])) {
                throw new \InvalidArgumentException('Invalid event: missing ' . $field);
            }
        }
        $storeId = (int) ($payload['store_id'] ?? 0);
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('An explicit store is required for delivery.');
        }
        if (!$this->helper->isLoyaltyEngageEnabled($storeId)) {
            throw new DeferredDeliveryException('Loyalty Engage is disabled for this store.');
        }
        $this->execute($payload);
    }

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
