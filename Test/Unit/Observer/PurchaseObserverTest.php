<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use LoyaltyEngage\LoyaltyShop\Observer\PurchaseObserver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class PurchaseObserverTest extends TestCase
{
    public function testExcludedOrderNeverReachesQueueOrDatabaseClaim(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $order->method('getStoreId')->willReturn(20);
        $order->method('getOrigData')->with('status')->willReturn('processing');
        $order->method('getStatus')->willReturn('complete');
        $order->method('getIncrementId')->willReturn('100000123');

        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('isPurchaseExportEnabled')->with(20)->willReturn(true);
        $helper->method('getPurchaseOrderStatuses')->with(20)->willReturn(['processing', 'complete']);

        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->expects(self::once())->method('evaluate')->with($order)->willReturn([
            'eligible' => false,
            'source' => OrderSource::API,
            'reason' => 'order_source_not_allowed',
        ]);

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $observer = new Observer();
        $observer->setEvent(new Event(['order' => $order]));

        (new PurchaseObserver(
            $helper,
            $publisher,
            $eligibility,
            $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\CouponOwnership::class)
        ))->execute($observer);
    }
}
