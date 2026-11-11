<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\{Tender, Ledger, Selection, Guard};
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class OrdinaryOrderTest extends TestCase
{
    public function testSettlementDoesNotLoadItemsForOrdersWithoutWalletPayment(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->createMock(AdapterInterface::class));
        $tender = $this->getMockBuilder(Tender::class)
            ->setConstructorArgs([$resource, $this->createMock(Ledger::class),
                $this->createMock(Selection::class), $this->createMock(Guard::class)])
            ->onlyMethods(['get'])->getMock();
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(123);
        $order->expects(self::never())->method('getAllItems');
        $tender->expects(self::once())->method('get')->with($order)->willReturn([]);
        $tender->settle($order);
    }
}
