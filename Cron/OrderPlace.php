<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class OrderPlace
{
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

                // Filter on updated_at between now-15min and now, so orders that
                // transition to the trigger status within the last 15 minutes are picked up.
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $minus15 = (new \DateTime('-15 minutes'))->format('Y-m-d H:i:s');

                $this->loyaltyHelper->log(
                    'debug',
                    'OrderPlace',
                    'execute',
                    'Processing orders updated from: ' . $minus15 . ' to: ' . $now
                );

                $triggerStatus = $this->loyaltyHelper->getPurchaseOrderStatus();

                $this->loyaltyHelper->log(
                    'debug',
                    'OrderPlace',
                    'execute',
                    'Filtering orders with status: ' . $triggerStatus
                );

                $this->searchCriteriaBuilder->addFilter('loyalty_order_place', self::LOYALTY_ORDER_PLACE, 'eq');
                $this->searchCriteriaBuilder->addFilter('loyalty_order_place_retrieve', $OrderRetrieveLimit, 'lt');
                $this->searchCriteriaBuilder->addFilter('status', $triggerStatus, 'eq');
                $this->searchCriteriaBuilder->addFilter('updated_at', $minus15, 'gteq');
                $this->searchCriteriaBuilder->addFilter('updated_at', $now, 'lteq');

                $searchCriteria = $this->searchCriteriaBuilder->create();
                // Get list of orders
                $orders = $this->orderRepository->getList($searchCriteria)->getItems();

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

        // Prepare order data — only loyalty products (price = 0.0) belong in the LoyaltyEngage cart.
        // Skip child items (simple products under configurable/bundle) to avoid duplicates.
        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // Skip child items (e.g. simple under configurable)
            }
            if ((float) $item->getPrice() !== 0.0) {
                continue; // Skip regular (paid) products — only loyalty products go to /cart/purchase
            }
            $products[] = [
                'sku'      => $item->getSku(),
                'quantity' => (int) $item->getQtyOrdered()
            ];
        }

        $this->loyaltyHelper->log(
            'debug',
            'OrderPlace',
            'processOrder',
            'Prepared ' . count($products) . ' loyalty product(s) for order ID: ' . $orderId,
            ['products' => $products]
        );

        // If no loyalty products in this order, mark as processed and skip
        if (empty($products)) {
            $order->setData('loyalty_order_place', 1);
            $this->loyaltyHelper->log(
                'debug',
                'OrderPlace',
                'processOrder',
                'No loyalty products in order ID: ' . $orderId . ', marking as processed'
            );
            $this->orderRepository->save($order);
            return;
        }

        // Place order
        $response = $this->loyaltyengageCart->placeOrder($email, $orderId, $products);

        if ($response && $response == LoyaltyHelper::HTTP_OK) {
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
