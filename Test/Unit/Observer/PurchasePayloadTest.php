<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\CouponOwnership;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use LoyaltyEngage\LoyaltyShop\Observer\PurchaseObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;

class PurchasePayloadTest extends TestCase
{
    public function testPayloadExcludesConfigurableChildrenAndCancelledUnitsButKeepsDynamicBundlePrices(): void
    {
        $items = [
            $this->item('CONFIG', 10, 2, 0, 0, true, false),
            $this->item('CONFIG-SIMPLE', 0, 2, 0, 1, false, false),
            $this->item('BUNDLE', 0, 1, 0, 0, true, true),
            $this->item('BUNDLE-SIMPLE', 20, 1, 0, 3, false, true),
            $this->item('DECIMAL', 5, 2.5, 1, 0, false, false),
            $this->item('CANCELLED', 10, 1, 1, 0, false, false),
        ];
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $order->method('getStoreId')->willReturn(20);
        $order->method('getOrigData')->willReturn('pending');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getIncrementId')->willReturn('TEST-123');
        $order->method('getCreatedAt')->willReturn('2026-09-24 12:00:00');
        $order->method('getCustomerEmail')->willReturn('test@example.invalid');
        $order->method('getAllItems')->willReturn($items);
        $order->method('getStore')->willReturn($this->createMock(Store::class));
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('isPurchaseExportEnabled')->with(20)->willReturn(true);
        $helper->method('getPurchaseOrderStatuses')->with(20)->willReturn(['processing', 'complete']);
        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->method('evaluate')->willReturn(['eligible' => true]);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('loyaltyshop.purchase_event',
            self::callback(static function (string $json): bool {
                $payload = json_decode($json, true)[0];
                self::assertSame(20, $payload['store_id']);
                self::assertSame('TEST-123', $payload['orderId']);
                self::assertSame([
                    ['sku' => 'CONFIG', 'price' => '10.00', 'quantity' => 2],
                    ['sku' => 'BUNDLE-SIMPLE', 'price' => '20.00', 'quantity' => 1],
                    ['sku' => 'DECIMAL', 'price' => '5.00', 'quantity' => 1.5],
                ], $payload['products']);
                return true;
            }));
        (new PurchaseObserver($helper, $publisher, $eligibility, $this->createMock(CouponOwnership::class)))
            ->execute(new Observer(['event' => new Event(['order' => $order])]));
    }

    private function item(string $sku, float $price, float $qty, float $cancelled, int $parent, bool $hasChildren, bool $childrenCalculated): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['isChildrenCalculated'])->getMock();
        $item->setData(['sku' => $sku, 'price' => $price, 'qty_ordered' => $qty, 'qty_canceled' => $cancelled,
            'parent_item_id' => $parent, 'has_children' => $hasChildren]);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        return $item;
    }
}
