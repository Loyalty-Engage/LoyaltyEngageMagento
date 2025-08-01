<?php

namespace LoyaltyEngage\LoyaltyShop\Model\Queue;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ReturnConsumer
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
                $apiUrl = $this->helper->getApiUrl();
                $clientId = $this->helper->getClientId();
                $clientSecret = $this->helper->getClientSecret();
                $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

                $this->curl->addHeader("Content-Type", "application/json");
                $this->curl->addHeader("Authorization", $authHeader);
                $this->curl->post(rtrim($apiUrl, '/') . '/api/v1/events', json_encode($payload));

                $response = $this->curl->getBody();
                $status = $this->curl->getStatus();

                $this->logger->info("[LoyaltyShop] Return API Response (HTTP $status): $response");
            } catch (\Exception $e) {
                $this->logger->error('[LoyaltyShop] Return API Error: ' . $e->getMessage());
            }
        }
    }
}