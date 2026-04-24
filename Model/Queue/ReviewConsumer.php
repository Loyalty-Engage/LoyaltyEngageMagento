<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer class to process Review export events
 */
class ReviewConsumer extends AbstractConsumer
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
        ApiClient $apiClient
    ) {
        parent::__construct($helper);
        $this->apiClient = $apiClient;
    }

    /**
     * Process review event payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        // Feature flag
        if (!$this->helper->isReviewExportEnabled()) {
            return;
        }

        // Validation
        if (empty($payload['review_id']) || empty($payload['customer_email'])) {

            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Invalid review payload',
                $payload
            );
            return;
        }

        $apiUrl = rtrim((string)$this->helper->getApiUrl(), '/');
        $endpoint = "{$apiUrl}/api/v1/events";

        $requestPayload = [
            'event'      => 'Review',
            'identifier' => (string)$payload['customer_email'],
            'reviewid'   => (string)$payload['review_id']
        ];

        try {
            $this->apiClient->post($endpoint, $requestPayload);

            $this->helper->log(
                'debug',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('Review Success (ID: %s)', $payload['review_id']),
                [
                    'review_id' => $payload['review_id'],
                    'email' => $this->helper->logMaskedEmail($payload['customer_email']),
                    'event' => 'Review'
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('Review Failed (ID: %s)', $payload['review_id']),
                [
                    'error' => $e->getMessage()
                ]
            );
            throw $e;
        }
    }
}
