<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

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
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        protected LoyaltyengageCart $loyaltyengageCart,
        protected OrderRepositoryInterface $orderRepository,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected LoyaltyHelper $loyaltyHelper
    ) {
    }

    /**
     * Execute Cron from Order Place
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            $LoyaltyOrderRetrieveLimit = $this->loyaltyengageCart->getLoyaltyOrderRetrieveLimit();

            // Add filter for created_at between now-15min and now
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $minus15 = (new \DateTime('-15 minutes'))->format('Y-m-d H:i:s');

            $this->searchCriteriaBuilder->addFilter('loyalty_order_place', self::LOYALTY_ORDER_PLACE, 'eq');
            $this->searchCriteriaBuilder->addFilter('loyalty_order_place_retrieve', $LoyaltyOrderRetrieveLimit, 'lt');
            $this->searchCriteriaBuilder->addFilter('created_at', $minus15, 'gteq');
            $this->searchCriteriaBuilder->addFilter('created_at', $now, 'lteq');

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
}
