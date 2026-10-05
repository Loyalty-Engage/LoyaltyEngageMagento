<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderData;
use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use LoyaltyEngage\LoyaltyShop\Observer\PersistOrderDataObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class PersistOrderDataObserverTest extends TestCase
{
    public function testOrdinarySaveDoesNotLoadItemsOrWriteLegacySource(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getAllItems'])->getMock();
        $order->setId(123)->setData('loyalty_order_source', 'unknown');
        $order->expects(self::never())->method('getAllItems');
        $data = $this->createMock(OrderData::class);
        $data->expects(self::never())->method('saveSource');
        (new PersistOrderDataObserver($data, new OrderSource()))->execute(
            new Observer(['event' => new Event(['order' => $order])])
        );
    }
}
