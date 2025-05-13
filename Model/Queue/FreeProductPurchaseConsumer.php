<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class FreeProductPurchaseConsumer
{
    protected $helper;
    protected $curl;
    protected $logger;

    public function __construct(
        Data $helper,
        Curl $curl,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->logger = $logger;
    }

    public function process(string $payloadJson): void
    {
        $payload = json_decode($payloadJson, true);

        try {
            $clientId = $this->helper->getClientId();
            $clientSecret = $this->helper->getClientSecret();
            $apiUrl = rtrim($this->helper->getApiUrl(), '/');
            $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
            
            $email = $payload['email'];
            $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$email}/cart/purchase";

            $this->logger->info("[LoyaltyShop] Sending free product purchase payload to: $endpoint", $payload);

            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            $this->curl->post($endpoint, json_encode([
                'orderId' => $payload['orderId'],
                'products' => $payload['products']
            ]));

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();
            $this->logger->info("[LoyaltyShop] Free Product API Response (HTTP $status): $response");
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Error sending free product purchase: " . $e->getMessage());
        }
    }
}
