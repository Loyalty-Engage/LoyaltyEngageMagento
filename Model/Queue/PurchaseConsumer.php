<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class PurchaseConsumer
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

            try {
                $clientId = $this->helper->getClientId();
                $clientSecret = $this->helper->getClientSecret();
                $apiUrl = rtrim($this->helper->getApiUrl(), '/');
                $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

                $this->curl->addHeader("Content-Type", "application/json");
                $this->curl->addHeader("Authorization", $authHeader);
                $this->curl->post("{$apiUrl}/api/v1/events", json_encode($payload));

                $response = $this->curl->getBody();
                $status = $this->curl->getStatus();

                $this->logger->info("[LoyaltyShop] Purchase API Response (HTTP $status): $response");
            } catch (\Exception $e) {
                $this->logger->error('[LoyaltyShop] Purchase API Error: ' . $e->getMessage());
            }
        }
    }
}