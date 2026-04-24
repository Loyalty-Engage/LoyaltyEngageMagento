<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer class to process Free Product Purchase events
 */
class FreeProductPurchaseConsumer extends AbstractConsumer
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
     * Process free product purchase payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        // Validation
        if (empty($payload['email']) || empty($payload['orderId'])) {

            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Invalid free product purchase payload',
                $payload
            );
            return;
        }

        $apiUrl = rtrim((string)$this->helper->getApiUrl(), '/');
        $email = (string)$payload['email'];
        $hashEmail = $this->helper->hashEmail($email);

        $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$hashEmail}/cart/purchase";

        $requestPayload = [
            'orderId' => $payload['orderId'],
            'products' => $payload['products'] ?? []
        ];

        try {
            $this->apiClient->post($endpoint, $requestPayload);

            $this->helper->log(
                'debug',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('FreeProductPurchase Success (Order: %s)', $payload['orderId']),
                [
                    'order_id' => $payload['orderId'],
                    'email' => $this->helper->logMaskedEmail($email),
                    'products_count' => count($requestPayload['products'])
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('FreeProductPurchase Failed (Order: %s)', $payload['orderId']),
                [
                    'error' => $e->getMessage()
                ]
            );
            throw $e;
        }
    }
}
