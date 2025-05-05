<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;

class PurchaseObserver implements ObserverInterface
{
    protected $helper;
    protected $logger;
    protected $publisher;

    public function __construct(
        Data $helper,
        LoggerInterface $logger,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->logger = $logger;
        $this->publisher = $publisher;
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
            $this->publisher->publish('loyaltyshop.purchase_event', json_encode($payload));
            $this->logger->info('[LoyaltyShop] Purchase payload published to queue.', $payload[0]);
        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Failed to queue Purchase event: ' . $e->getMessage());
        }
    }
}
