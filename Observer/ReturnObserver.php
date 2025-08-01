<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

class ReturnObserver implements ObserverInterface
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
        if ($this->helper->isLoyaltyEngageEnabled()) {
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

            $payload = [
                [
                    'event' => 'Return',
                    'email' => $email,
                    'orderDate' => $returnDate,
                    'products' => $products
                ]
            ];

            try {
                $this->publisher->publish('loyaltyshop.return_event', json_encode($payload));
                $this->logger->info('[LoyaltyShop] Return payload published to queue.', $payload[0]);
            } catch (\Exception $e) {
                $this->logger->error('[LoyaltyShop] Failed to queue Return event: ' . $e->getMessage());
            }
        }
    }
}
