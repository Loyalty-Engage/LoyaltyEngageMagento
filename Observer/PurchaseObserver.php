<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

class PurchaseObserver implements ObserverInterface
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
        if (!$this->helper->isPurchaseExportEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order instanceof Order) {
            return;
        }

        $originalStatus = $order->getOrigData('status');
        $currentStatus = $order->getStatus();

        if ($originalStatus === $currentStatus || $currentStatus !== 'complete') {
            return;
        }

        $email = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();
        $orderDate = (new \DateTime($order->getCreatedAt()))->format('c'); 
        $products = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'sku' => $item->getSku(),
                'price' => (float) $item->getPrice(),
                'quantity' => (int) $item->getQtyOrdered()
            ];
        }

        $payload = [[
            'event' => 'Purchase',
            'email' => $email,
            'orderId' => $orderId,
            'orderDate' => $orderDate,
            'products' => $products
        ]];

        try {
            $clientId = $this->helper->getClientId();
            $clientSecret = $this->helper->getClientSecret();
            $apiUrl = $this->helper->getApiUrl();
            $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);


            $this->curl->addHeader("Content-Type", "application/json");
            $this->curl->addHeader("Authorization", $authHeader);
            $this->curl->post(rtrim($apiUrl, '/') . '/api/v1/events', json_encode($payload));

            $response = $this->curl->getBody();
            $status = $this->curl->getStatus();

        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Purchase API Error: ' . $e->getMessage());
        }
    }
}
