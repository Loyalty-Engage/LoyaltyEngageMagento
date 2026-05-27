<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Service\AbstractConsumer;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

/**
 * Consumer class to process Redeem Discount events
 * Calls PUT /api/v1/discount/{code}/redeem to mark a voucher as redeemed
 */
class RedeemDiscountConsumer extends AbstractConsumer
{
    /**
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
     * Process redeem discount payload
     *
     * @param array $payload
     * @return void
     */
    protected function execute(array $payload): void
    {
        if (empty($payload['discount_code']) || empty($payload['identifier'])) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_VALIDATION,
                'Invalid redeem discount payload — missing discount_code or identifier',
                $payload
            );
            return;
        }

        $discountCode = (string) $payload['discount_code'];
        $identifier   = (string) $payload['identifier'];
        $orderId      = (string) ($payload['order_id'] ?? '');

        $apiUrl   = rtrim((string) $this->helper->getApiUrl(), '/');
        $endpoint = "{$apiUrl}/api/v1/discount/" . urlencode($discountCode) . "/redeem";

        try {
            $response = $this->apiClient->put($endpoint, [
                'identifier' => $identifier
            ]);

            $this->helper->log(
                'info',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf('RedeemDiscount Success (Code: %s, Order: %s)', $discountCode, $orderId),
                [
                    'discount_code' => $discountCode,
                    'identifier'    => $identifier,
                    'order_id'      => $orderId,
                    'response'      => $response
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                LoyaltyLogger::COMPONENT_QUEUE,
                LoyaltyLogger::ACTION_ERROR,
                sprintf('RedeemDiscount Failed (Code: %s, Order: %s)', $discountCode, $orderId),
                [
                    'discount_code' => $discountCode,
                    'order_id'      => $orderId,
                    'error'         => $e->getMessage()
                ]
            );
            throw $e;
        }
    }
}
