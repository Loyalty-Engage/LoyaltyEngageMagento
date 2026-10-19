<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Observer;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice;
use LoyaltyEngage\LoyaltyShop\Model\CouponOwnership;
use LoyaltyEngage\LoyaltyShop\Model\CreditmemoData;
use LoyaltyEngage\LoyaltyShop\Model\OrderData;
use LoyaltyEngage\LoyaltyShop\Model\OrderExportEligibility;
use LoyaltyEngage\LoyaltyShop\Model\PurchaseHistory;
use LoyaltyEngage\LoyaltyShop\Observer\PurchaseObserver;
use LoyaltyEngage\LoyaltyShop\Observer\ReturnObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as MemoItem;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;

class TierPriceExportTest extends TestCase
{
    /** @dataProvider lineProvider */
    public function testPurchaseUsesSnapshotAndDoesNotExportAnEmptyEvent(array $flags, int $expected): void
    {
        $items = array_map(fn($flag) => $this->item($flag), $flags);
        $order = $this->order($items);
        $helper = $this->helper();
        $helper->expects(self::never())->method('isTierPricePurchaseExclusionEnabled');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($expected ? self::once() : self::never())->method('publish')
            ->with('loyaltyshop.purchase_event', self::callback(static function ($json) use ($expected): bool {
                $event = json_decode($json, true)[0];
                self::assertCount($expected, $event['products']);
                self::assertSame('100000123', $event['orderId']);
                self::assertSame(20, $event['store_id']);
                return true;
            }));
        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->method('evaluate')->willReturn(['eligible' => true]);
        (new PurchaseObserver($helper, $publisher, $eligibility, $this->createMock(CouponOwnership::class),
            $this->createMock(OrderData::class)))->execute(new Observer(['event' => new Event(['order' => $order])]));
    }

    /** @dataProvider lineProvider */
    public function testPartialReturnsUseOriginalPerLineDecisionEvenForTheSameSku(array $flags, int $expected): void
    {
        $orderItems = array_map(fn($flag) => $this->item($flag), $flags);
        $items = [];
        foreach ($orderItems as $orderItem) {
            $item = $this->createMock(MemoItem::class);
            $item->method('getOrderItem')->willReturn($orderItem);
            $item->method('getSku')->willReturn('SAME-SKU');
            $item->method('getQty')->willReturn(1.0);
            $item->method('getPrice')->willReturn(13.29);
            $items[] = $item;
        }
        $order = $this->order($orderItems);
        $memo = $this->createMock(Creditmemo::class);
        $memo->method('getId')->willReturn(42);
        $memo->method('getState')->willReturn(Creditmemo::STATE_REFUNDED);
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getAllItems')->willReturn($items);
        $memo->method('getCreatedAt')->willReturn('2026-10-18 12:00:00');
        $history = $this->createMock(PurchaseHistory::class);
        $history->method('canExportReturn')->willReturn(true);
        $helper = $this->helper();
        $helper->expects(self::never())->method('isTierPricePurchaseExclusionEnabled');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($expected ? self::once() : self::never())->method('publish')
            ->with('loyaltyshop.return_event', self::callback(static function ($json) use ($expected): bool {
                $event = json_decode($json, true)[0];
                self::assertCount($expected, $event['products']);
                foreach ($event['products'] as $product) {
                    self::assertEquals(1, $product['quantity']);
                }
                return true;
            }));
        (new ReturnObserver($helper, $publisher, $history, $this->createMock(CreditmemoData::class)))
            ->execute(new Observer(['event' => new Event(['creditmemo' => $memo])]));
    }

    public static function lineProvider(): array
    {
        return [
            'mixed same SKU' => [[1, 0], 1],
            'all tier excluded' => [[1, 1], 0],
            'no applied tiers' => [[0, 0], 2],
            'historical order without snapshots' => [[null, null], 2],
        ];
    }

    public function testFirstSaveDoesNotLeakConfigurableChildOfExcludedParent(): void
    {
        $parent = $this->item(1)->setHasChildren(true);
        $child = $this->item(null)->setParentItem($parent);
        self::assertNull($child->getParentItemId());
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->method('evaluate')->willReturn(['eligible' => true]);
        (new PurchaseObserver($this->helper(), $publisher, $eligibility, $this->createMock(CouponOwnership::class),
            $this->createMock(OrderData::class)))
            ->execute(new Observer(['event' => new Event(['order' => $this->order([$parent, $child])])]));
    }

    public function testCouponDiscountedLineIsStillExported(): void
    {
        $item = $this->item(0)->setDiscountAmount(10)->setDiscountPercent(10);
        $order = $this->order([$item]);
        $order->method('getCouponCode')->willReturn('SALE10');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('loyaltyshop.purchase_event',
            self::callback(static function ($json): bool {
                $products = json_decode($json, true)[0]['products'];
                self::assertCount(1, $products);
                self::assertSame('SAME-SKU', $products[0]['sku']);
                return true;
            }));
        $eligibility = $this->createMock(OrderExportEligibility::class);
        $eligibility->method('evaluate')->willReturn(['eligible' => true]);
        (new PurchaseObserver($this->helper(), $publisher, $eligibility, $this->createMock(CouponOwnership::class),
            $this->createMock(OrderData::class)))
            ->execute(new Observer(['event' => new Event(['order' => $order])]));
    }

    private function item(?int $flag): Item
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods(['isChildrenCalculated'])->getMock();
        $item->method('isChildrenCalculated')->willReturn(false);
        return $item->setData(['sku' => 'SAME-SKU', 'price' => 13.29, 'qty_ordered' => 10,
            'product_options' => $flag === null ? [] : [AppliedTierPrice::EXCLUDED_OPTION => $flag]]);
    }

    private function order(array $items): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $order->method('getStoreId')->willReturn(20);
        $order->method('getOrigData')->with('status')->willReturn('pending');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getCreatedAt')->willReturn('2026-10-18 10:00:00');
        $order->method('getIncrementId')->willReturn('100000123');
        $order->method('getCustomerEmail')->willReturn('test@example.invalid');
        $order->method('getAllItems')->willReturn($items);
        $order->method('getStore')->willReturn($this->createMock(Store::class));
        return $order;
    }

    private function helper(): Data
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(20)->willReturn(true);
        $helper->method('isPurchaseExportEnabled')->with(20)->willReturn(true);
        $helper->method('isReturnExportEnabled')->with(20)->willReturn(true);
        $helper->method('getPurchaseOrderStatuses')->with(20)->willReturn(['processing']);
        return $helper;
    }
}
