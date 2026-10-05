<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyOrderItems;
use LoyaltyEngage\LoyaltyShop\Model\OrderItemData;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order\Item;
use PHPUnit\Framework\TestCase;

class LoyaltyOrderItemsBatchTest extends TestCase
{
    public function testOneHundredItemsUseTwoMarkerQueries(): void
    {
        $items = [];
        for ($id = 1; $id <= 100; $id++) {
            $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $item->setId($id)->setQuoteItemId($id + 100);
            $items[] = $item;
        }
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $db = $this->createMock(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->expects(self::exactly(2))->method('fetchCol')->willReturnOnConsecutiveCalls([1], [102]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $data = $this->createMock(OrderItemData::class);
        $data->expects(self::never())->method('isRedemption');
        $result = (new LoyaltyOrderItems($resource, $data))->redemptionItems($items);
        self::assertSame([1, 2], array_values(array_map(static fn(Item $item) => $item->getId(), $result)));
    }
}
