<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer class to process Free Product Remove events
 */
class FreeProductRemoveConsumer extends AbstractConsumer
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
     * @param LoggerInterface $logger
     */
    public function __construct(
        Data $helper,
        ApiClient $apiClient
    ) {
        parent::__construct($helper);
        $this->apiClient = $apiClient;
    }

    /**
     * Process free product remove payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        // Basic validation
        if (empty($payload['email']) || empty($payload['sku'])) {

            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Invalid free product remove payload',
                $payload
            );
            return;
        }

        $apiUrl = rtrim((string)$this->helper->getApiUrl(), '/');
        $email = (string)$payload['email'];
        $hashEmail = $this->helper->hashEmail($email);

        $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$hashEmail}/cart/remove";

        $requestPayload = [
            'sku'      => $payload['sku'],
            'quantity' => $payload['quantity'] ?? 0
        ];

        try {
            $this->apiClient->delete($endpoint, $requestPayload);

            $this->helper->log(
                'debug',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('FreeProductRemove Success (SKU: %s)', $payload['sku']),
                [
                    'email' => $this->helper->logMaskedEmail($email),
                    'sku' => $payload['sku'],
                    'quantity' => $requestPayload['quantity']
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('FreeProductRemove Failed (SKU: %s)', $payload['sku']),
                [
                    'error' => $e->getMessage()
                ]
            );
            throw $e;
        }
    }
}
