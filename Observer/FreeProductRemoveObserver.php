<?php

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Customer\Model\Session as CustomerSession;

class FreeProductRemoveObserver implements ObserverInterface
{
    protected $helper;
    protected $logger;
    protected $customerSession;

    public function __construct(
        Data $helper,
        LoggerInterface $logger,
        CustomerSession $customerSession
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->customerSession = $customerSession;
    }

    public function execute(Observer $observer)
    {
        $item = $observer->getEvent()->getQuoteItem();
        if (!$item) {
            return;
        }

        if ((float)$item->getPrice() !== 0.0) {
            $this->logger->info("[LoyaltyShop] Removed item is not free, skipping.");
            return;
        }

        $sku = $item->getSku();
        $qty = (int)$item->getQty();

        $email = $this->customerSession->getCustomer() ? $this->customerSession->getCustomer()->getEmail() : null;
        if (!$email) {
            $this->logger->warning("[LoyaltyShop] Cannot determine customer email for free product removal.");
            return;
        }

        $clientId = $this->helper->getClientId();
        $clientSecret = $this->helper->getClientSecret();
        $apiUrl = rtrim($this->helper->getApiUrl(), '/');
        $authHeader = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
        $endpoint = "{$apiUrl}/api/v1/loyalty/shop/{$email}/cart/remove";

        $payload = json_encode([
            'sku' => $sku,
            'quantity' => $qty
        ]);

        try {
            $this->logger->info("[LoyaltyShop] Sending DELETE request to: $endpoint with payload: $payload");

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                $authHeader,
                'Content-Length: ' . strlen($payload)
            ]);

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            }

            curl_close($ch);

            $this->logger->info("[LoyaltyShop] Remove API Response (HTTP $status): $response");
        } catch (\Exception $e) {
            $this->logger->error("[LoyaltyShop] Free product remove error: " . $e->getMessage());
        }
    }
}
