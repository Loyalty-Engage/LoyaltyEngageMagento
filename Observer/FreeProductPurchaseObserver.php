<?php

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

class FreeProductPurchaseObserver implements ObserverInterface
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

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();

        // Only trigger when status has just changed to 'complete'
        if ($originalStatus === $currentStatus || $currentStatus !== 'complete') {
            return;
        }

        $freeProducts = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ((float)$item->getPrice() === 0.0) {
                $freeProducts[] = [
                    'sku' => $item->getSku(),
                    'quantity' => (int)$item->getQtyOrdered()
                ];
            }
        }

        if (empty($freeProducts)) {
            $this->logger->info("[LoyaltyShop] No free products found in order {$order->getIncrementId()}.");
            return;
        }

        $email = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();
        $payload = [
            'orderId' => $orderId,
            'products' => $freeProducts
        ];

        try {
            $clientId = $this->helper->getClientId();
            $clientSecret = $this->helper->getClientSecret();
            $apiUrl = rtrim($this->helper->getApiUrl(), '/');
            $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
            $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$email}/cart/purchase";

            $this->logger->info("[LoyaltyShop] Sending free product purchase payload to: $endpoint", $payload);

            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            $this->curl->post($endpoint, json_encode($payload));

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();
            $this->logger->info("[LoyaltyShop] Free Product API Response (HTTP $status): $response");
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Error sending free product purchase: " . $e->getMessage());
        }
    }
}
