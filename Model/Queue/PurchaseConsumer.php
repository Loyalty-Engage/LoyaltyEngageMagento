<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer for Purchase events
 */
class PurchaseConsumer extends AbstractConsumer
{
    public const TOPIC = 'loyaltyshop.purchase_event';

    /**
     * API client for external requests
     *
     * @var ApiClient
     */
    protected ApiClient $apiClient;

    /**
     * Constructor
     *
     * @param Data $helper
     * @param ApiClient $apiClient
     */
    public function __construct(
        Data $helper,
        ApiClient $apiClient
    ) {
        parent::__construct($helper);
        $this->apiClient = $apiClient;
    }

    /**
     * Process purchase event payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        $storeId = isset($payload['store_id']) ? (int) $payload['store_id'] : null;

        if (!$this->helper->isPurchaseExportEnabled($storeId)) {
            throw new \LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException('Purchase export is disabled.');
        }

        if (empty($payload)) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Empty purchase payload'
            );
            return;
        }

        $wirePayload = $payload;
        unset($wirePayload['store_id'], $wirePayload['creditmemo_id']);
        $apiUrl = rtrim((string)$this->helper->getApiUrl($storeId), '/');
        $endpoint = "{$apiUrl}/api/v1/events";

        try {
            // LoyaltyEngage /api/v1/events expects an array of events
            $response = $this->apiClient->post($endpoint, [$wirePayload], $storeId);

            $this->helper->log(
                'info',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                'Purchase Success',
                [
                    'event_type' => $payload['event'] ?? 'purchase',
                    'identifier' => $payload['identifier'] ?? null,
                    'orderId' => $payload['orderId'] ?? null,
                    'api_response' => $response,
                    'payload_keys' => array_keys($payload)
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                'Purchase Failed',
                ['error' => $e->getMessage()]
            );

            throw $e;
        }
    }
}
