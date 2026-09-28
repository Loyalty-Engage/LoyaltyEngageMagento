<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Observer\ReturnObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

class ReturnObserverTest extends TestCase
{
    public function testPublishesVisibleItemsOnceWithOrderId(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('isReturnExportEnabled')->with(20)->willReturn(true);

        $parentOrderItem = $this->createMock(OrderItem::class);
        $parentOrderItem->method('getParentItemId')->willReturn(null);
        $childOrderItem = $this->createMock(OrderItem::class);
        $childOrderItem->method('getParentItemId')->willReturn(123);

        $parentItem = $this->createMock(Item::class);
        $parentItem->method('getOrderItem')->willReturn($parentOrderItem);
        $parentItem->method('getSku')->willReturn('CONFIGURABLE-SKU');
        $parentItem->method('getPrice')->willReturn(25.0);
        $parentItem->method('getQty')->willReturn(1.0);

        $childItem = $this->createMock(Item::class);
        $childItem->method('getOrderItem')->willReturn($childOrderItem);

        $order = $this->createMock(Order::class);
        $order->method('getData')->with('loyalty_purchase_exported')->willReturn(1);
        $order->method('getStoreId')->willReturn(20);
        $order->method('getCustomerEmail')->willReturn('member@example.com');
        $order->method('getIncrementId')->willReturn('100000123');

        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getId')->willReturn(42);
        $creditmemo->method('getState')->willReturn(Creditmemo::STATE_REFUNDED);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getCreatedAt')->willReturn('2026-09-20 12:00:00');
        $creditmemo->method('getAllItems')->willReturn([$parentItem, $childItem]);

        $history = $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory::class);
        $history->method('canExportReturn')->with($order)->willReturn(true);

        $connection = $this->createMock(AdapterInterface::class);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->with(
                'loyaltyshop.return_event',
                self::callback(static function (string $json): bool {
                    $payload = json_decode($json, true);
                    return $payload[0]['orderId'] === '100000123'
                        && $payload[0]['store_id'] === 20
                        && count($payload[0]['products']) === 1
                        && $payload[0]['products'][0]['sku'] === 'CONFIGURABLE-SKU';
                })
            );

        $observer = new Observer();
        $observer->setEvent(new Event(['creditmemo' => $creditmemo]));

        (new ReturnObserver($helper, $publisher, $history))->execute($observer);
    }

    public function testSkipsReturnWhenPurchaseWasNotExported(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(20);

        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getId')->willReturn(42);
        $creditmemo->method('getOrder')->willReturn($order);

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $observer = new Observer();
        $observer->setEvent(new Event(['creditmemo' => $creditmemo]));

        (new ReturnObserver(
            $this->createMock(Data::class),
            $publisher,
            $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory::class)
        ))->execute($observer);
    }
}
