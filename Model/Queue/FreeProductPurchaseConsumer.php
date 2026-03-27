<?php

declare(strict_types=1);

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
                $maskedEmail = $this->maskEmail($email);
                $endpoint = "{$apiUrl}/api/v1/loyalty/shop/" . rawurlencode($email) . "/cart/purchase";

                $requestPayload = [
                    'orderId' => $payload['orderId'],
                    'products' => $payload['products']
                ];

                $this->logger->info("[LoyaltyShop] Free Product Purchase API Request", [
                    'email' => $maskedEmail,
                    'orderId' => $payload['orderId'],
                    'products_count' => count($payload['products']),
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
                        'email' => $maskedEmail,
                        'orderId' => $payload['orderId'],
                        'processing_time_ms' => $processingTime,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    $this->logger->error("[LoyaltyShop] Free Product Purchase API Error", [
                        'http_status' => $status,
                        'email' => $maskedEmail,
                        'orderId' => $payload['orderId'],
                        'response_body' => $response,
                        'processing_time_ms' => $processingTime,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                }

            } catch (\Exception $e) {
                $processingTime = round((microtime(true) - $startTime) * 1000, 2);
                $maskedEmail = isset($payload['email']) ? $this->maskEmail($payload['email']) : 'unknown';
                $this->logger->error("[LoyaltyShop] Free Product Purchase Exception", [
                    'error_message' => $e->getMessage(),
                    'email' => $maskedEmail,
                    'orderId' => $payload['orderId'] ?? 'unknown',
                    'processing_time_ms' => $processingTime,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Mask email address for privacy in logs
     *
     * @param string $email
     * @return string
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }
        $name = $parts[0];
        $domain = $parts[1];
        $maskedName = strlen($name) > 2
            ? substr($name, 0, 1) . '***' . substr($name, -1)
            : '***';
        return $maskedName . '@' . $domain;
    }
}
