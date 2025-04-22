<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class ReturnObserver implements ObserverInterface
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
        if (!$this->helper->isReturnExportEnabled()) {
            $this->logger->info('[LoyaltyShop] Return export is disabled.');
            return;
        }

        $creditmemo = $observer->getEvent()->getCreditmemo();
        $order = $creditmemo->getOrder();

        $email = $order->getCustomerEmail();
        $returnDate = (new \DateTime($creditmemo->getCreatedAt()))->format(DATE_ATOM);
        $products = [];

        foreach ($creditmemo->getAllItems() as $item) {
            $products[] = [
                'sku' => $item->getSku(),
                'price' => (float) $item->getPrice(),
                'quantity' => (int) $item->getQty()
            ];
        }

        $payload = [[
            'event' => 'Return',
            'email' => $email,
            'orderDate' => $returnDate,
            'products' => $products
        ]];

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

        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Return API Error: ' . $e->getMessage());
        }
    }
}
