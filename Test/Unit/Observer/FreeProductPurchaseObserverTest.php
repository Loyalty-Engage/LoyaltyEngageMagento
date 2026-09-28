<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use LoyaltyEngage\LoyaltyShop\Observer\FreeProductPurchaseObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class FreeProductPurchaseObserverTest extends TestCase
{
    public function testExcludedOrderNeverReachesFreeProductQueue(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $order->method('getStoreId')->willReturn(20);
        $order->method('getOrigData')->with('status')->willReturn('processing');
        $order->method('getStatus')->willReturn('complete');
        $order->method('getIncrementId')->willReturn('100000123');

        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('getPurchaseOrderStatuses')->willReturn(['complete']);

        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->expects(self::once())->method('evaluate')->with($order)->willReturn([
            'eligible' => false,
            'source' => OrderSource::ADMIN,
            'reason' => 'order_source_not_allowed',
        ]);

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        $observer = new Observer();
        $observer->setEvent(new Event(['order' => $order]));

        (new FreeProductPurchaseObserver($helper, $publisher, $eligibility, $this->createMock(\LoyaltyEngage\LoyaltyShop\Model\LoyaltyOrderItems::class)))->execute($observer);
    }
}
