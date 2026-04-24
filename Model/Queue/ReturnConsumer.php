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
        if (!$this->helper->isReturnExportEnabled()) {
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

        $apiUrl = rtrim((string)$this->helper->getApiUrl(), '/');
        $endpoint = "{$apiUrl}/api/v1/events";

        try {
            $this->apiClient->post($endpoint, $payload);

            $this->helper->log(
                'debug',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                'Return Success',
                [
                    'event_type' => $payload['event'] ?? 'return',
                    'email' => isset($payload['email'])
                        ? $this->helper->logMaskedEmail($payload['email'])
                        : null,
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
