<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class FreeProductRemoveConsumer
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
            $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$email}/cart/remove";

            $this->logger->info("[LoyaltyShop] Sending DELETE request to: $endpoint with payload", $payload);

            // Using Curl class for DELETE request
            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            
            // Magento's Curl class doesn't have a direct delete method, so we use setOption
            $this->curl->setOption(CURLOPT_CUSTOMREQUEST, "DELETE");
            $this->curl->post($endpoint, json_encode([
                'sku' => $payload['sku'],
                'quantity' => $payload['quantity']
            ]));

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();

            $this->logger->info("[LoyaltyShop] Remove API Response (HTTP $status): $response");
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Free product remove error: " . $e->getMessage());
        }
    }
}
