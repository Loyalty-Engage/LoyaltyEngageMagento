<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;

class PaymentTest extends TestCase
{
    private function documents(array $state): Documents
    {
        $tender = $this->createMock(Tender::class);
        $tender->method('get')->willReturn($state);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        return new Documents($resource, $this->createMock(Ledger::class), $tender,
            new Money(), new Policy(new Money()), $this->createMock(Guard::class));
    }

    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getAllItems'])->getMock();
        $order->addData(['base_total_paid' => 80, 'base_total_refunded' => 0]);
        return $order;
    }

    private function document(string $class, float $gross)
    {
        $document = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['getOrder'])->getMock();
        $document->setIdFieldName('entity_id');
        $document->method('getOrder')->willReturn($this->order());
        $document->setBaseGrandTotal($gross)->setGrandTotal($gross)->setBaseTaxAmount(10)->setBaseDiscountAmount(5);
        return $document;
    }

    public function testInvoiceAllocationAndGuardReallocationDoNotDoubleSubtract(): void
    {
        $documents = $this->documents(['gross_cents' => 10000, 'spent_cents' => 2000,
            'invoiced_gross' => 0, 'invoiced_wallet' => 0, 'released_wallet' => 0]);
        $invoice = $this->document(Invoice::class, 50);
        $documents->collect($invoice, 'invoice');
        self::assertSame(40, $invoice->getBaseGrandTotal());
        self::assertSame(['gross_cents' => 5000, 'wallet_cents' => 1000], $invoice->getData(Documents::ALLOCATION));
        $documents->reallocate($invoice, 'invoice');
        self::assertSame(40, $invoice->getBaseGrandTotal());
        self::assertSame(10, $invoice->getBaseTaxAmount());
        self::assertSame(5, $invoice->getBaseDiscountAmount());
    }

    public function testInvoiceRoundingConservesEveryCentAcrossThreeParts(): void
    {
        $wallets = [];
        $already = 0;
        for ($i = 0; $i < 3; ++$i) {
            $documents = $this->documents(['gross_cents' => 3, 'spent_cents' => 1,
                'invoiced_gross' => $i, 'invoiced_wallet' => $already, 'released_wallet' => 0]);
            $invoice = $this->document(Invoice::class, .01);
            $documents->collect($invoice, 'invoice');
            $value = $invoice->getData(Documents::ALLOCATION)['wallet_cents'];
            $wallets[] = $value;
            $already += $value;
        }
        self::assertSame([0, 1, 0], $wallets);
        self::assertSame(1, array_sum($wallets));
    }

    public function testPartialRefundRestoresOnlyOriginalWalletTender(): void
    {
        $documents = $this->documents(['invoiced_gross' => 10000, 'invoiced_wallet' => 2000,
            'refunded_gross' => 4000, 'refunded_wallet' => 800]);
        $memo = $this->document(Creditmemo::class, 30);
        $documents->collect($memo, 'creditmemo');
        self::assertSame(24, $memo->getBaseGrandTotal());
        self::assertSame(600, $memo->getData(Documents::ALLOCATION)['wallet_cents']);
        $documents->reallocate($memo, 'creditmemo');
        self::assertSame(24, $memo->getBaseGrandTotal());
    }

    public function testFullWalletRefundNeverRequestsAGatewayTransaction(): void
    {
        $documents = $this->documents(['invoiced_gross' => 10000, 'invoiced_wallet' => 10000,
            'refunded_gross' => 0, 'refunded_wallet' => 0]);
        $memo = $this->document(Creditmemo::class, 30);
        $documents->collect($memo, 'creditmemo');
        self::assertSame(0, $memo->getBaseGrandTotal());
        self::assertSame(3000, $memo->getData(Documents::ALLOCATION)['wallet_cents']);
        self::assertTrue($memo->getPaymentRefundDisallowed());
    }

    public function testRefundReallocationRestoresGatewayRefundWhenRoundingShareChanges(): void
    {
        $tender = $this->createMock(Tender::class);
        $tender->method('get')->willReturnOnConsecutiveCalls(
            ['invoiced_gross' => 4, 'invoiced_wallet' => 2, 'refunded_gross' => 0, 'refunded_wallet' => 0],
            ['invoiced_gross' => 4, 'invoiced_wallet' => 2, 'refunded_gross' => 1, 'refunded_wallet' => 1]
        );
        $documents = new Documents($this->createMock(ResourceConnection::class), $this->createMock(Ledger::class),
            $tender, new Money(), new Policy(new Money()), $this->createMock(Guard::class));
        $memo = $this->document(Creditmemo::class, .01);
        $documents->collect($memo, 'creditmemo');
        self::assertTrue($memo->getPaymentRefundDisallowed());
        $documents->reallocate($memo, 'creditmemo');
        self::assertSame(.01, $memo->getBaseGrandTotal());
        self::assertFalse($memo->getPaymentRefundDisallowed());
    }

    public function testOrdinaryOrdersHaveUnchangedTotals(): void
    {
        $documents = $this->documents([]);
        $invoice = $this->document(Invoice::class, 50);
        $documents->collect($invoice, 'invoice');
        self::assertSame(50.0, $invoice->getBaseGrandTotal());
        self::assertNull($invoice->getData(Documents::ALLOCATION));
    }

    public function testOrdinaryDocumentSavesNeverRequireWalletTender(): void
    {
        $tender = $this->createMock(Tender::class);
        $tender->expects(self::never())->method('get');
        $documents = $this->getMockBuilder(Documents::class)->setConstructorArgs([
            $this->createMock(ResourceConnection::class), $this->createMock(Ledger::class), $tender,
            new Money(), new Policy(new Money()), $this->createMock(Guard::class),
        ])->onlyMethods(['saved'])->getMock();
        $documents->method('saved')->willReturn([]);
        $invoice = $this->document(Invoice::class, 50);
        $invoice->setId(42);
        $documents->persist($invoice, 'invoice');
        self::assertSame(50.0, $invoice->getBaseGrandTotal());
    }

    public function testRefundCannotExceedOriginalInvoicedGross(): void
    {
        $this->expectException(LocalizedException::class);
        $this->documents(['invoiced_gross' => 1000, 'invoiced_wallet' => 200,
            'refunded_gross' => 0, 'refunded_wallet' => 0])->collect($this->document(Creditmemo::class, 11), 'creditmemo');
    }

    public function testGuardIsReentrantAndCanResetForLongLivedWorkers(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects(self::once())->method('lock')->with('le-wallet-order-42', 5)->willReturn(true);
        $locks->expects(self::once())->method('unlock')->with('le-wallet-order-42')->willReturn(true);
        $guard = new Guard($locks);
        $guard->order(42);
        $guard->order(42);
        $guard->_resetState();
        $guard->release();
    }

    public function testGuardRejectsConcurrentDocumentMutation(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(false);
        $guard = new Guard($locks);
        $this->expectException(LocalizedException::class);
        $guard->order(42);
    }
}
