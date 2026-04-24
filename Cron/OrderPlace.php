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
        $this->loyaltyHelper->log(
            'info',
            'OrderPlace',
            'execute',
            'OrderPlace cron started'
        );

        try {
            if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {

                $OrderRetrieveLimit = $this->loyaltyHelper->getLoyaltyOrderRetrieveLimit();
                $this->loyaltyHelper->log(
                    'debug',
                    'OrderPlace',
                    'execute',
                    'Order retrieve limit: ' . $OrderRetrieveLimit
                );

                // Add filter for created_at between now-15min and now
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $minus15 = (new \DateTime('-15 minutes'))->format('Y-m-d H:i:s');

                $this->loyaltyHelper->log(
                    'debug',
                    'OrderPlace',
                    'execute',
                    'Processing orders from: ' . $minus15 . ' to: ' . $now
                );

                $this->searchCriteriaBuilder->addFilter('loyalty_order_place', self::LOYALTY_ORDER_PLACE, 'eq');
                $this->searchCriteriaBuilder->addFilter('loyalty_order_place_retrieve', $OrderRetrieveLimit, 'lt');
                $this->searchCriteriaBuilder->addFilter('created_at', $minus15, 'gteq');
                $this->searchCriteriaBuilder->addFilter('created_at', $now, 'lteq');

                $searchCriteria = $this->searchCriteriaBuilder->create();
                // Get list of orders
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();

                $orderCount = count($orders);

                // Process each order
                foreach ($orders as $order) {
                    try {
                        $this->processOrder($order);
                    } catch (\Exception $e) {
                        $this->loyaltyHelper->log(
                            'error',
                            'OrderPlace',
                            'execute',
                            'Error processing order ID: ' . $order->getIncrementId(),
                            ['error' => $e->getMessage()]
                        );
                        continue;
                    }
                }

                $this->loyaltyHelper->log(
                    'info',
                    'OrderPlace',
                    'execute',
                    'OrderPlace cron completed successfully'
                );
            } else {
                $this->loyaltyHelper->log(
                    'info',
                    'OrderPlace',
                    'execute',
                    'LoyaltyEngage module is disabled, skipping'
                );
            }
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'OrderPlace',
                'execute',
                'OrderPlace cron failed: ' . $e->getMessage(),
                ['exception' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Process individual order
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    private function processOrder(\Magento\Sales\Api\Data\OrderInterface $order): void
    {
        $email = $order->getCustomerEmail();
        $orderId = $order->getIncrementId();

        $maskedEmail = $this->loyaltyHelper->logMaskedEmail($email);
        $this->loyaltyHelper->log(
            'debug',
            'OrderPlace',
            'processOrder',
            'Processing order ID: ' . $orderId . ' for customer: ' . $maskedEmail
        );

        // Prepare order data
        $products = [];
        foreach ($order->getAllItems() as $item) {
            $products[] = [
                'sku' => $item->getSku(),
                'quantity' => (int) $item->getQtyOrdered()
            ];
        }

        $this->loyaltyHelper->log(
            'debug',
            'OrderPlace',
            'processOrder',
            'Prepared ' . count($products) . ' products for order ID: ' . $orderId,
            ['products' => $products]
        );

        // Place order
        $response = $this->loyaltyengageCart->placeOrder($email, $orderId, $products);

        if ($response && $response == self::HTTP_OK) {
            $order->setData('loyalty_order_place', 1);
            $this->loyaltyHelper->log(
                'debug',
                'OrderPlace',
                'processOrder',
                'Successfully placed loyalty order ID: ' . $orderId
            );
        } else {
            $currentValue = (int) $order->getData('loyalty_order_place_retrieve');
            $order->setData('loyalty_order_place_retrieve', $currentValue + 1);
            $this->loyaltyHelper->log(
                'error',
                'OrderPlace',
                'processOrder',
                'Failed to place loyalty order ID: ' . $orderId . ', response: ' . $response,
                ['attempt' => $currentValue + 1]
            );
        }

        $this->orderRepository->save($order);
    }
}
