<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class OrderPlace
{
    private const HTTP_OK = 200;
    private const LOYALTY_ORDER_PLACE = 0;

    /**
     * Constructor
     *
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        protected LoyaltyengageCart $loyaltyengageCart,
        protected OrderRepositoryInterface $orderRepository,
        protected SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Execute Cron from Order Place
     *
     * @return void
     */
    public function execute(): void
    {
        $LoyaltyOrderRetrieveLimit = $this->loyaltyengageCart->getLoyaltyOrderRetrieveLimit();

        $this->searchCriteriaBuilder->addFilter('loyalty_order_place', self::LOYALTY_ORDER_PLACE, 'eq');
        $this->searchCriteriaBuilder->addFilter('loyalty_order_place_retrieve', $LoyaltyOrderRetrieveLimit, 'lt');

        $searchCriteria = $this->searchCriteriaBuilder->create();
        // Get list of orders
        $orders = $this->orderRepository->getList($searchCriteria)->getItems();

        // Process each order
        foreach ($orders as $order) {
            $email = $order->getCustomerEmail();
            $orderId = $order->getIncrementId();

            // Prepare order data
            $products = [];
            foreach ($order->getAllItems() as $item) {
                $products[] = [
                    'sku' => $item->getSku(),
                    'quantity' => (int) $item->getQtyOrdered()
                ];
            }

            // Place order
            $response = $this->loyaltyengageCart->placeOrder($email, $orderId, $products);

            if ($response && $response == self::HTTP_OK) {
                $order->setData('loyalty_order_place', 1);
            } else {
                $currentValue = (int) $order->getData('loyalty_order_place_retrieve');
                $order->setData('loyalty_order_place_retrieve', $currentValue + 1);
            }
            $this->orderRepository->save($order);
        }
    }
}
