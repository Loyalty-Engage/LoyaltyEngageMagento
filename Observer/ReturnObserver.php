<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\MessageQueue\PublisherInterface;

class ReturnObserver implements ObserverInterface
{
    protected $helper;
    protected $publisher;

    public function __construct(
        Data $helper,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer)
    {
        if (!$this->helper->isLoyaltyEngageEnabled()) {
            return;
        }

        if (!$this->helper->isReturnExportEnabled()) {
            $this->helper->log(
                'info',
                'LoyaltyShop',
                'ReturnExportDisabled',
                'Return export is disabled.'
            );
            return;
        }

        $creditmemo = $observer->getEvent()->getCreditmemo();
        if (!$creditmemo) {
            return;
        }

        $order = $creditmemo->getOrder();
        if (!$order) {
            return;
        }

        $email = $order->getCustomerEmail();
        $returnDate = (new \DateTime($creditmemo->getCreatedAt()))->format(DATE_ATOM);

        $products = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $products[] = [
                'sku' => $item->getSku(),
                'price' => number_format((float) $item->getPrice(), 2, '.', ''),
                'quantity' => (int) $item->getQty()
            ];
        }

        $payload = [
            [
                'event' => 'Return',
                'identifier' => $email,
                'orderDate' => $returnDate,
                'products' => $products
            ]
        ];

        try {
            $this->publisher->publish(
                'loyaltyshop.return_event',
                json_encode($payload)
            );

            $this->helper->log(
                'info',
                'LoyaltyShop',
                'ReturnEventPublished',
                'Return payload published to queue.',
                [
                    'email' => $email,
                    'return_date' => $returnDate,
                    'products_count' => count($products),
                    'products' => $products,
                    'payload' => $payload[0]
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'ReturnEventError',
                'Failed to queue Return event.',
                [
                    'error_message' => $e->getMessage(),
                    'email' => $email,
                    'return_date' => $returnDate
                ]
            );
        }
    }
}