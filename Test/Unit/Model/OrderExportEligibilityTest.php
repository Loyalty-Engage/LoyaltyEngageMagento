<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use PHPUnit\Framework\TestCase;

class OrderExportEligibilityTest extends TestCase
{
    public function testAllowsConfiguredStorefrontOrder(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getAllowedOrderSources')->with(20)->willReturn([OrderSource::STOREFRONT]);
        $helper->method('getExcludedOrderCommentMarkers')->with(20)->willReturn([]);

        $result = (new OrderExportEligibility($helper, new OrderSource()))->evaluate(
            $this->createOrder(20, OrderSource::STOREFRONT)
        );

        self::assertTrue($result['eligible']);
        self::assertSame(OrderSource::STOREFRONT, $result['source']);
    }

    public function testRejectsApiOrderWhenStoreAllowsOnlyStorefront(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getAllowedOrderSources')->with(20)->willReturn([OrderSource::STOREFRONT]);
        $helper->expects(self::never())->method('getExcludedOrderCommentMarkers');

        $result = (new OrderExportEligibility($helper, new OrderSource()))->evaluate(
            $this->createOrder(20, OrderSource::API)
        );

        self::assertFalse($result['eligible']);
        self::assertSame('order_source_not_allowed', $result['reason']);
    }

    public function testRejectsOrderWithConfiguredCommentMarker(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getAllowedOrderSources')->with(20)->willReturn(OrderSource::ALL);
        $helper->method('getExcludedOrderCommentMarkers')->with(20)
            ->willReturn(['created via admin panel']);

        $history = $this->createMock(History::class);
        $history->method('getComment')->willReturn('Order CREATED VIA ADMIN PANEL by integration.');
        $order = $this->createOrder(20, OrderSource::UNKNOWN);
        $order->method('getAllStatusHistory')->willReturn([$history]);

        $result = (new OrderExportEligibility($helper, new OrderSource()))->evaluate($order);

        self::assertFalse($result['eligible']);
        self::assertSame('excluded_order_comment', $result['reason']);
        self::assertSame('created via admin panel', $result['marker']);
    }

    public function testChecksStatusHistoryAttachedDuringCurrentSave(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getAllowedOrderSources')->with(20)->willReturn(OrderSource::ALL);
        $helper->method('getExcludedOrderCommentMarkers')->with(20)->willReturn(['admin import']);

        $history = $this->createMock(History::class);
        $history->method('getComment')->willReturn('Created by ADMIN IMPORT');
        $order = $this->createOrder(20, OrderSource::UNKNOWN);
        $order->method('getAllStatusHistory')->willReturn([]);
        $order->method('getStatusHistories')->willReturn([$history]);

        $result = (new OrderExportEligibility($helper, new OrderSource()))->evaluate($order);

        self::assertFalse($result['eligible']);
        self::assertSame('excluded_order_comment', $result['reason']);
    }

    private function createOrder(int $storeId, string $source): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getData')->with('loyalty_order_source')->willReturn($source);

        return $order;
    }
}
