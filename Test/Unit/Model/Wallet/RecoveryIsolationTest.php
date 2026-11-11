<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Cron\RecoverMutations;
use LoyaltyEngage\LoyaltyShop\Model\{LoyaltyCart, MutationJournal};
use Magento\Framework\App\{ResourceConnection, MaintenanceMode};
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;

class RecoveryIsolationTest extends TestCase
{
    public function testCartRecoverySelectsOnlyCartOperationsNotWalletVouchers(): void
    {
        $conditions = [];
        $queries = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnCallback(static function () use (&$conditions, $select) {
            $conditions = [];
            return $select;
        });
        $select->method('where')->willReturnCallback(static function ($condition, $value) use (&$conditions, $select) {
            $conditions[$condition] = $value;
            return $select;
        });
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $db = $this->createMock(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchAll')->willReturnCallback(static function () use (&$queries, &$conditions) {
            $queries[] = $conditions;
            return [];
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects(self::once())->method('unlock')->with('loyaltyshop_recovery');
        $maintenance = $this->createMock(MaintenanceMode::class);
        $maintenance->method('isOn')->willReturn(false);
        $cron = new RecoverMutations($resource, $this->createMock(LoyaltyCart::class),
            $this->createMock(CartRepositoryInterface::class), $this->createMock(Emulation::class),
            $this->createMock(MutationJournal::class), $maintenance, $locks);
        $cron->execute();
        self::assertCount(3, $queries);
        self::assertSame('received', $queries[0]['status = ?']);
        self::assertSame(['product', 'discount'], $queries[0]['operation IN (?)']);
        self::assertSame('product', $queries[1]['operation = ?']);
        self::assertSame('product', $queries[2]['operation = ?']);
    }
}
