<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\MessageQueue\PublisherInterface;

class FreeProductRemoveObserver implements ObserverInterface
{
    protected $helper;
    protected $customerSession;
    protected $publisher;

    public function __construct(
        Data $helper,
        CustomerSession $customerSession,
        PublisherInterface $publisher
    ) {
        $this->helper = $helper;
        $this->customerSession = $customerSession;
        $this->publisher = $publisher;
    }

    public function execute(Observer $observer)
    {
        if (!$this->helper->isLoyaltyEngageEnabled()) {
            return;
        }

        $item = $observer->getEvent()->getQuoteItem();
        if (!$item) {
            return;
        }

        if ((float) $item->getPrice() !== 0.0) {
            $this->helper->log(
                'info',
                'LoyaltyShop',
                'FreeProductRemoveSkipped',
                'Removed item is not free, skipping.',
                [
                    'sku' => $item->getSku()
                ]
            );
            return;
        }

        $sku = $item->getSku();
        $qty = (int) $item->getQty();

        $customer = $this->customerSession->getCustomer();
        $email = $customer ? $customer->getEmail() : null;

        if (!$email) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'FreeProductRemoveNoEmail',
                'Cannot determine customer email for free product removal.',
                [
                    'sku' => $sku,
                    'quantity' => $qty
                ]
            );
            return;
        }

        $payload = [
            'email' => $email,
            'sku' => $sku,
            'quantity' => $qty
        ];

        try {
            $this->publisher->publish(
                'loyaltyshop.free_product_remove_event',
                json_encode($payload)
            );

            $this->helper->log(
                'info',
                'LoyaltyShop',
                'FreeProductRemovePublished',
                'Free product remove payload published to queue.',
                [
                    'email' => $email,
                    'sku' => $sku,
                    'quantity' => $qty,
                    'payload' => $payload
                ]
            );

        } catch (\Exception $e) {
            $this->helper->log(
                'error',
                'LoyaltyShop',
                'FreeProductRemoveError',
                'Failed to queue free product remove event.',
                [
                    'error_message' => $e->getMessage(),
                    'email' => $email,
                    'sku' => $sku,
                    'quantity' => $qty
                ]
            );
        }
    }
}