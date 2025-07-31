<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ReviewConsumer
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

        if (!$payload) {
            $this->logger->error('[LoyaltyShop] Review Consumer: Invalid JSON payload', [
                'payload' => $payloadJson
            ]);
            return;
        }

        if ($this->helper->isReviewDebugLoggingEnabled()) {
            $this->logger->info('[LoyaltyShop] Review Consumer: Processing payload', [
                'payload' => $payload
            ]);
        }

        try {
            $clientId = $this->helper->getClientId();
            $clientSecret = $this->helper->getClientSecret();
            $apiUrl = rtrim($this->helper->getApiUrl(), '/');

            if (!$clientId || !$clientSecret || !$apiUrl) {
                $this->logger->error('[LoyaltyShop] Review Consumer: Missing API configuration', [
                    'has_client_id' => !empty($clientId),
                    'has_client_secret' => !empty($clientSecret),
                    'has_api_url' => !empty($apiUrl)
                ]);
                return;
            }

            $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
            $endpoint = "{$apiUrl}/api/v1/events";

            if ($this->helper->isReviewDebugLoggingEnabled()) {
                $this->logger->info('[LoyaltyShop] Review Consumer: Making API request', [
                    'endpoint' => $endpoint,
                    'payload_count' => count($payload)
                ]);
            }

            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            $this->curl->post($endpoint, json_encode($payload));

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();

            if ($status >= 200 && $status < 300) {
                if ($this->helper->isReviewDebugLoggingEnabled()) {
                    $this->logger->info("[LoyaltyShop] Review API Success (HTTP $status)", [
                        'response' => $response,
                        'payload' => $payload
                    ]);
                } else {
                    $this->logger->info("[LoyaltyShop] Review API Response (HTTP $status): $response");
                }
            } else {
                $this->logger->error("[LoyaltyShop] Review API Error (HTTP $status)", [
                    'response' => $response,
                    'payload' => $payload,
                    'endpoint' => $endpoint
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Review API Exception', [
                'error' => $e->getMessage(),
                'payload' => $payload ?? null,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
