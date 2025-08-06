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
        if ($this->helper->isLoyaltyEngageEnabled()) {
            $payload = json_decode($payloadJson, true);
            $startTime = microtime(true);

            try {
                $clientId = $this->helper->getClientId();
                $clientSecret = $this->helper->getClientSecret();
                $apiUrl = rtrim($this->helper->getApiUrl(), '/');
                $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

                $email = $payload['email'];
                $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$email}/cart/purchase";

                $requestPayload = [
                    'orderId' => $payload['orderId'],
                    'products' => $payload['products']
                ];

                // Log the request details
                $this->logger->info("[LoyaltyShop] Free Product Purchase API Request", [
                    'endpoint' => $endpoint,
                    'email' => $email,
                    'orderId' => $payload['orderId'],
                    'products_count' => count($payload['products']),
                    'request_payload' => $requestPayload,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);

                $this->curl->addHeader("Content-Type", "application/json");
                $this->curl->addHeader("Authorization", $authHeader);
                $this->curl->post($endpoint, json_encode($requestPayload));

                $response = $this->curl->getBody();
                $status = $this->curl->getStatus();
                $processingTime = round((microtime(true) - $startTime) * 1000, 2);

                // Enhanced response logging
                if ($status >= 200 && $status < 300) {
                    $this->logger->info("[LoyaltyShop] Free Product Purchase API Success", [
                        'http_status' => $status,
                        'email' => $email,
                        'orderId' => $payload['orderId'],
                        'response_body' => $response,
                        'processing_time_ms' => $processingTime,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $this->logger->error("[LoyaltyShop] Free Product Purchase API Error", [
                        'http_status' => $status,
                        'email' => $email,
                        'orderId' => $payload['orderId'],
                        'response_body' => $response,
                        'request_payload' => $requestPayload,
                        'processing_time_ms' => $processingTime,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                }

            } catch (\Exception $e) {
                $processingTime = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->error("[LoyaltyShop] Free Product Purchase Exception", [
                    'error_message' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                    'email' => $payload['email'] ?? 'unknown',
                    'orderId' => $payload['orderId'] ?? 'unknown',
                    'processing_time_ms' => $processingTime,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }
}