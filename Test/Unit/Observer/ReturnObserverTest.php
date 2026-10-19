<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\CreditmemoData;
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
    /** @dataProvider priceProvider */
    public function testPublishesVisibleItemsOnceWithOrderId(bool $includingTax, ?float $memoPrice, ?float $orderPrice,
        float $qty, string $expected): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('isReturnExportEnabled')->with(20)->willReturn(true);
        $helper->expects(self::once())->method('isEventPriceIncludingTax')->with(20)->willReturn($includingTax);

        $parentOrderItem = $this->createMock(OrderItem::class);
        $parentOrderItem->method('getParentItemId')->willReturn(null);
        $parentOrderItem->method('getPriceInclTax')->willReturn($orderPrice);
        $childOrderItem = $this->createMock(OrderItem::class);
        $childOrderItem->method('getParentItemId')->willReturn(123);

        $parentItem = $this->createMock(Item::class);
        $parentItem->method('getOrderItem')->willReturn($parentOrderItem);
        $parentItem->method('getSku')->willReturn('CONFIGURABLE-SKU');
        $parentItem->method('getPrice')->willReturn(25.0);
        $parentItem->method('getPriceInclTax')->willReturn($memoPrice);
        $parentItem->method('getQty')->willReturn($qty);

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
                self::callback(static function (string $json) use ($expected, $qty): bool {
                    $payload = json_decode($json, true);
                    self::assertSame($expected, $payload[0]['products'][0]['price']);
                    self::assertEquals($qty, $payload[0]['products'][0]['quantity']);
                    return $payload[0]['orderId'] === '100000123'
                        && $payload[0]['store_id'] === 20
                        && count($payload[0]['products']) === 1
                        && $payload[0]['products'][0]['sku'] === 'CONFIGURABLE-SKU';
                })
            );

        $observer = new Observer();
        $observer->setEvent(new Event(['creditmemo' => $creditmemo]));

        $creditmemoData = $this->createMock(CreditmemoData::class);
        $creditmemoData->method('isReturnExported')->willReturn(false);
        (new ReturnObserver($helper, $publisher, $history, $creditmemoData))->execute($observer);
    }

    public static function priceProvider(): array
    {
        return [
            'default unchanged' => [false, 30.25, 30.25, 1, '25.00'],
            'inclusive creditmemo unit price' => [true, 30.25, 31.00, 2, '30.25'],
            'partial fractional refund' => [true, 30.25, 30.25, 0.5, '30.25'],
            'historical order fallback' => [true, null, 30.25, 1, '30.25'],
            'missing historical gross price does not guess tax' => [true, null, null, 1, '25.00'],
            'zero gross is preserved' => [true, 0, 30.25, 1, '0.00'],
            'tax exempt' => [true, 25.00, 25.00, 1, '25.00'],
            'unit price rounded once' => [true, 30.255, 30.255, 2, '30.26'],
        ];
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
            $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory::class),
            $this->createMock(CreditmemoData::class)
        ))->execute($observer);
    }
}
