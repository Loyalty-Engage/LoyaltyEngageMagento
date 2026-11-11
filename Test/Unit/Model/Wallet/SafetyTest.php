<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use LoyaltyEngage\LoyaltyShop\Observer\Wallet\Capture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class SafetyTest extends TestCase
{
    public function testDisabledGuestsForeignCurrencyAndExistingOrdersNeverCapture(): void
    {
        foreach ([
            ['enabled' => false], ['customer_is_guest' => 1], ['customer_id' => null],
            ['base_currency_code' => 'USD'], ['order_currency_code' => 'USD'], ['entity_id' => 123],
        ] as $case) {
            $config = $this->createMock(Config::class);
            $config->method('enabled')->willReturn($case['enabled'] ?? true);
            $resource = $this->createMock(ResourceConnection::class);
            $resource->expects(self::never())->method('getConnection');
            $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
            $order->setIdFieldName('entity_id');
            $order->addData(array_replace([
                'store_id' => 1, 'customer_id' => 5, 'customer_is_guest' => 0,
                'base_currency_code' => 'EUR', 'order_currency_code' => 'EUR',
            ], $case));
            $capture = new Capture($config, $resource, new Money(), new Policy(new Money()));
            $capture->execute(new Observer(['event' => new Event(['order' => $order])]));
            self::assertNull($order->getData(Capture::SNAPSHOT));
        }
    }

    public function testLedgerRejectsAnUnprotectedWriteBeforeWritingAnything(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getTransactionLevel')->willReturn(0);
        $adapter->expects(self::never())->method('insert');
        $adapter->expects(self::never())->method('insertOnDuplicate');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $this->expectException(\LogicException::class);
        (new Ledger($resource, new Money()))->book([
            'customer_id' => 1, 'store_id' => 1, 'order_id' => 1,
        ], 'test', 'order_accrual', 100);
    }

    public function testInvalidFinancialSnapshotsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Policy(new Money()))->reversal(100, 101, 50);
    }

    public function testInvalidQuantityIsNotSilentlyRounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money())->quantityUnits('1.00001');
    }
}
