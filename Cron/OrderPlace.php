<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Psr\Log\LoggerInterface;

class OrderPlace
{
    private const HTTP_OK = 200;
    private const LOYALTY_ORDER_PLACE = 0;
    private const BATCH_SIZE = 100; // Maximum orders to process per run
    private const TIME_WINDOW_MINUTES = 10; // Process orders from last 10 minutes

    /**
     * Constructor
     *
     * @param LoyaltyengageCart $loyaltyengageCart
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected LoyaltyengageCart $loyaltyengageCart,
        protected OrderRepositoryInterface $orderRepository,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected SortOrderBuilder $sortOrderBuilder,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Execute Cron from Order Place
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $startTime = microtime(true);
            $this->logger->info('LoyaltyEngage OrderPlace cron started');

            $loyaltyOrderRetrieveLimit = $this->loyaltyengageCart->getLoyaltyOrderRetrieveLimit();
            
            // Calculate time window - only process orders from last 10 minutes
            $timeWindow = date('Y-m-d H:i:s', strtotime('-' . self::TIME_WINDOW_MINUTES . ' minutes'));

            // Build search criteria with date filter and batch size
            $this->searchCriteriaBuilder->addFilter('loyalty_order_place', self::LOYALTY_ORDER_PLACE, 'eq');
            $this->searchCriteriaBuilder->addFilter('loyalty_order_place_retrieve', $loyaltyOrderRetrieveLimit, 'lt');
            $this->searchCriteriaBuilder->addFilter('created_at', $timeWindow, 'gteq');
            
            // Add sorting and pagination
            $sortOrder = $this->sortOrderBuilder
                ->setField('created_at')
                ->setDirection('ASC')
                ->create();
            $this->searchCriteriaBuilder->addSortOrder($sortOrder);
            $this->searchCriteriaBuilder->setPageSize(self::BATCH_SIZE);

            $searchCriteria = $this->searchCriteriaBuilder->create();
            
            // Get list of orders
            $orderList = $this->orderRepository->getList($searchCriteria);
            $orders = $orderList->getItems();
            
            $processedCount = 0;
            $successCount = 0;
            $errorCount = 0;

            $this->logger->info('LoyaltyEngage OrderPlace found orders to process', [
                'count' => count($orders),
                'time_window' => $timeWindow
            ]);

            // Process each order
            foreach ($orders as $order) {
                try {
                    $email = $order->getCustomerEmail();
                    $orderId = $order->getIncrementId();

                    if (empty($email)) {
                        $this->logger->warning('LoyaltyEngage OrderPlace: Skipping order without email', [
                            'order_id' => $orderId
                        ]);
                        continue;
                    }

                    // Prepare order data
                    $products = [];
                    foreach ($order->getAllItems() as $item) {
                        if ($item->getParentItem()) {
                            continue; // Skip child items of configurable products
                        }
                        
                        $products[] = [
                            'sku' => $item->getSku(),
                            'quantity' => (int) $item->getQtyOrdered()
                        ];
                    }

                    if (empty($products)) {
                        $this->logger->warning('LoyaltyEngage OrderPlace: Skipping order without products', [
                            'order_id' => $orderId
                        ]);
                        continue;
                    }

                    // Place order
                    $response = $this->loyaltyengageCart->placeOrder($email, $orderId, $products);

                    if ($response && $response == self::HTTP_OK) {
                        $order->setData('loyalty_order_place', 1);
                        $successCount++;
                        
                        $this->logger->info('LoyaltyEngage OrderPlace: Successfully processed order', [
                            'order_id' => $orderId,
                            'email' => $email
                        ]);
                    } else {
                        $currentValue = (int) $order->getData('loyalty_order_place_retrieve');
                        $order->setData('loyalty_order_place_retrieve', $currentValue + 1);
                        $errorCount++;
                        
                        $this->logger->warning('LoyaltyEngage OrderPlace: Failed to process order', [
                            'order_id' => $orderId,
                            'email' => $email,
                            'response_code' => $response,
                            'retry_count' => $currentValue + 1
                        ]);
                    }
                    
                    $this->orderRepository->save($order);
                    $processedCount++;
                    
                    // Free memory
                    unset($order, $products);
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error('LoyaltyEngage OrderPlace: Error processing individual order', [
                        'order_id' => $order->getIncrementId() ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $executionTime = microtime(true) - $startTime;
            $memoryUsage = memory_get_peak_usage(true) / 1024 / 1024; // MB

            $this->logger->info('LoyaltyEngage OrderPlace cron completed', [
                'processed_count' => $processedCount,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'execution_time' => round($executionTime, 2) . 's',
                'memory_usage' => round($memoryUsage, 2) . 'MB',
                'time_window' => $timeWindow
            ]);

        } catch (\Exception $e) {
            $this->logger->error('LoyaltyEngage OrderPlace cron failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
