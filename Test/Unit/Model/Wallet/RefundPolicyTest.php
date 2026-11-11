<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\{Documents, Tender, Ledger, Money, Policy, Guard};
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;

class RefundPolicyTest extends TestCase
{
    public function testNewPolicyRecordsRefundAllocationWithoutRestoringWalletFunds(): void
    {
        $state = ['order_id' => 123, 'restore_on_refund' => 0,
            'refunded_gross' => 0, 'refunded_wallet' => 0,
            'invoiced_gross' => 6000, 'invoiced_wallet' => 2000];
        $db = $this->createMock(AdapterInterface::class);
        $db->method('getTransactionLevel')->willReturn(1);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $ledger = $this->createMock(Ledger::class);
        $ledger->method('table')->with('document')->willReturn('wallet_document');
        $ledger->expects(self::never())->method('book');
        $tender = $this->createMock(Tender::class);
        $tender->method('get')->willReturn($state);
        $tender->expects(self::once())->method('update')->with($state,
            ['refunded_gross' => 6000, 'refunded_wallet' => 2000]);
        $documents = $this->getMockBuilder(Documents::class)
            ->setConstructorArgs([$resource, $ledger, $tender, $this->createMock(Money::class),
                $this->createMock(Policy::class), $this->createMock(Guard::class)])
            ->onlyMethods(['saved'])->getMock();
        $documents->method('saved')->willReturn([]);
        $memo = $this->getMockBuilder(Creditmemo::class)->disableOriginalConstructor()->getMock();
        $memo->method('getId')->willReturn(456);
        $memo->method('getOrder')->willReturn($this->createMock(\Magento\Sales\Model\Order::class));
        $memo->method('getState')->willReturn(Creditmemo::STATE_REFUNDED);
        $memo->method('getData')->with(Documents::ALLOCATION)
            ->willReturn(['gross_cents' => 6000, 'wallet_cents' => 2000]);
        $db->expects(self::once())->method('insert')->with('wallet_document',
            ['gross_cents' => 6000, 'wallet_cents' => 2000, 'kind' => 'creditmemo',
                'document_id' => 456, 'order_id' => 123, 'invoice_id' => null]);
        $documents->persist($memo, 'creditmemo');
    }

    public function testHistoricalPolicyIsPreserved(): void
    {
        self::assertTrue(Documents::restoresCredit([]));
        self::assertTrue(Documents::restoresCredit(['restore_on_refund' => '1']));
        self::assertFalse(Documents::restoresCredit(['restore_on_refund' => '0']));
    }
}
