<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer class to process Return events
 */
class ReturnConsumer extends AbstractConsumer
{
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
        ApiClient $apiClient,
    ) {
        parent::__construct($helper);
        $this->apiClient = $apiClient;
    }

    /**
     * Process return event payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        $storeId = isset($payload['store_id']) ? (int) $payload['store_id'] : null;

        if (!$this->helper->isReturnExportEnabled($storeId)) {
            return;
        }

        // Validation
        if (empty($payload)) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Empty return payload'
            );
            return;
        }

        $apiUrl = rtrim((string)$this->helper->getApiUrl($storeId), '/');
        $endpoint = "{$apiUrl}/api/v1/events";

        try {
            // LoyaltyEngage /api/v1/events expects an array of events
            $response = $this->apiClient->post($endpoint, [$payload], $storeId);

            $this->helper->log(
                'info',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                'Return Success',
                [
                    'event_type' => $payload['event'] ?? 'return',
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
                'Return Failed',
                ['error' => $e->getMessage()]
            );
            throw $e;
        }
    }
}
