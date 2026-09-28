<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use LoyaltyEngage\LoyaltyShop\Observer\CaptureOrderSourceObserver;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class CaptureOrderSourceObserverTest extends TestCase
{
    public function testPersistsApiSourceOnOrderBeforeSubmit(): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn(Area::AREA_WEBAPI_REST);

        $order = $this->createMock(Order::class);
        $order->expects(self::once())
            ->method('setData')
            ->with('loyalty_order_source', OrderSource::API);

        $observer = new Observer();
        $observer->setEvent(new Event(['order' => $order]));

        (new CaptureOrderSourceObserver($appState, new OrderSource()))->execute($observer);
    }

    public function testFallsBackToUnknownWhenAreaIsUnavailable(): void
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willThrowException(new \RuntimeException('Area is not set'));

        $order = $this->createMock(Order::class);
        $order->expects(self::once())
            ->method('setData')
            ->with('loyalty_order_source', OrderSource::UNKNOWN);

        $observer = new Observer();
        $observer->setEvent(new Event(['order' => $order]));

        (new CaptureOrderSourceObserver($appState, new OrderSource()))->execute($observer);
    }
}
