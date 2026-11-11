<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class Documents
{
    public const ALLOCATION = '_loyaltyshop_wallet_document';
    private const ORIGINAL_REFUND_DISALLOWED = '_loyaltyshop_wallet_original_refund_disallowed';

    public function __construct(
        private ResourceConnection $resource,
        private Ledger $ledger,
        private Tender $tender,
        private Money $money,
        private Policy $policy,
        private Guard $guard
    ) {
    }

    public function saved(string $kind, int $id): array
    {
        if (!$id) {
            return [];
        }
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->ledger->table('document'))
            ->where('kind = ?', $kind)->where('document_id = ?', $id)) ?: [];
    }

    public function collect($document, string $kind): void
    {
        $state = $this->tender->get($document->getOrder());
        if (!$state) {
            return;
        }
        $gross = $this->money->native(max(0, (float) $document->getBaseGrandTotal()));
        $stored = $this->saved($kind, (int) $document->getId());
        if ($stored) {
            $wallet = (int) $stored['wallet_cents'];
        } elseif ($kind === 'invoice') {
            $remainingGross = (int) $state['gross_cents'] - (int) $state['invoiced_gross'];
            $remainingWallet = (int) $state['spent_cents'] - (int) $state['released_wallet'] - (int) $state['invoiced_wallet'];
            $this->assertGross($gross, $remainingGross);
            $cumulative = $this->policy->proportion((int) $state['spent_cents'],
                min((int) $state['gross_cents'], (int) $state['invoiced_gross'] + $gross), (int) $state['gross_cents']);
            $wallet = min($gross, $remainingWallet, max(0, $cumulative - (int) $state['invoiced_wallet']));
        } else {
            $remainingGross = (int) $state['invoiced_gross'] - (int) $state['refunded_gross'];
            $remainingWallet = (int) $state['invoiced_wallet'] - (int) $state['refunded_wallet'];
            if ($document->getInvoiceId()) {
                $invoice = $this->saved('invoice', (int) $document->getInvoiceId());
                if (!$invoice || $invoice['canceled']) {
                    throw new LocalizedException(__('The original wallet invoice allocation is missing.'));
                }
                $db = $this->resource->getConnection();
                $refunded = $db->fetchRow($db->select()->from($this->ledger->table('document'), [
                    'gross' => new \Zend_Db_Expr('COALESCE(SUM(gross_cents),0)'),
                    'wallet' => new \Zend_Db_Expr('COALESCE(SUM(wallet_cents),0)'),
                ])->where('invoice_id = ?', (int) $document->getInvoiceId())->where('kind = ?', 'creditmemo'));
                $remainingGross = (int) $invoice['gross_cents'] - (int) $refunded['gross'];
                $remainingWallet = min($remainingWallet, (int) $invoice['wallet_cents'] - (int) $refunded['wallet']);
            }
            $this->assertGross($gross, $remainingGross);
            $wallet = $remainingGross > 0 ? $this->policy->proportion($remainingWallet, $gross, $remainingGross) : 0;
            $cashRemaining = max(0, $this->money->native($document->getOrder()->getBaseTotalPaid() ?? 0)
                - $this->money->native($document->getOrder()->getBaseTotalRefunded() ?? 0));
            $wallet = max($wallet, $gross - $cashRemaining);
            if ($wallet > $remainingWallet || $wallet > $gross) {
                throw new LocalizedException(__('The requested refund exceeds the remaining payment allocation.'));
            }
        }
        $allocation = ['gross_cents' => $gross, 'wallet_cents' => $wallet];
        $document->setData(self::ALLOCATION, $allocation);
        $document->setGrandTotal(($gross - $wallet) / 100)->setBaseGrandTotal(($gross - $wallet) / 100);
        if ($kind === 'creditmemo') {
            if (!$document->hasData(self::ORIGINAL_REFUND_DISALLOWED)) {
                $document->setData(self::ORIGINAL_REFUND_DISALLOWED, (bool) $document->getPaymentRefundDisallowed());
            }
            $document->setPaymentRefundDisallowed((bool) $document->getData(self::ORIGINAL_REFUND_DISALLOWED)
                || ($wallet > 0 && $wallet === $gross));
        }
    }

    public function reallocate($document, string $kind): void
    {
        $previous = $document->getData(self::ALLOCATION);
        if (is_array($previous)) {
            $document->setGrandTotal($previous['gross_cents'] / 100)->setBaseGrandTotal($previous['gross_cents'] / 100);
        }
        $this->collect($document, $kind);
    }

    public function invoiceWalletRemaining(int $invoiceId): int
    {
        $invoice = $this->saved('invoice', $invoiceId);
        if (!$invoice || $invoice['canceled']) { return 0; }
        $db = $this->resource->getConnection();
        $refunded = (int) $db->fetchOne($db->select()->from($this->ledger->table('document'),
            [new \Zend_Db_Expr('COALESCE(SUM(wallet_cents),0)')])
            ->where('invoice_id = ?', $invoiceId)->where('kind = ?', 'creditmemo'));
        return max(0, (int) $invoice['wallet_cents'] - $refunded);
    }

    public function persist($document, string $kind): void
    {
        $allocation = $document->getData(self::ALLOCATION) ?: $this->saved($kind, (int) $document->getId());
        if (!$document->getId() || !is_array($allocation) || !$allocation) {
            return;
        }
        $state = $this->tender->get($document->getOrder(), true);
        if (!$state) {
            throw new \LogicException('Wallet tender missing during document persistence.');
        }
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1) {
            throw new \LogicException('Wallet documents require a native sales transaction.');
        }
        $this->guard->afterCommit($db, (int) $state['order_id']);
        $existing = $this->saved($kind, (int) $document->getId());
        if ($existing) {
            if ($kind === 'invoice' && (int) $document->getState() === Invoice::STATE_CANCELED && !$existing['canceled']) {
                $this->tender->update($state, [
                    'invoiced_gross' => (int) $state['invoiced_gross'] - (int) $existing['gross_cents'],
                    'invoiced_wallet' => (int) $state['invoiced_wallet'] - (int) $existing['wallet_cents'],
                ]);
                $db->update($this->ledger->table('document'), ['canceled' => 1], ['kind = ?' => $kind, 'document_id = ?' => (int) $document->getId()]);
            }
            return;
        }
        if (($kind === 'invoice' && (int) $document->getState() === Invoice::STATE_CANCELED)
            || ($kind === 'creditmemo' && (int) $document->getState() !== Creditmemo::STATE_REFUNDED)) {
            return;
        }
        $gross = (int) $allocation['gross_cents'];
        $wallet = (int) $allocation['wallet_cents'];
        if ($kind === 'invoice') {
            if ((int) $state['invoiced_gross'] + $gross > (int) $state['gross_cents']
                || (int) $state['invoiced_wallet'] + $wallet + (int) $state['released_wallet'] > (int) $state['spent_cents']) {
                throw new LocalizedException(__('Wallet invoice allocation changed. Please retry.'));
            }
            $values = ['invoiced_gross' => (int) $state['invoiced_gross'] + $gross,
                'invoiced_wallet' => (int) $state['invoiced_wallet'] + $wallet];
        } else {
            if ((int) $state['refunded_gross'] + $gross > (int) $state['invoiced_gross']
                || (int) $state['refunded_wallet'] + $wallet > (int) $state['invoiced_wallet']) {
                throw new LocalizedException(__('Wallet refund allocation changed. Please retry.'));
            }
            $values = ['refunded_gross' => (int) $state['refunded_gross'] + $gross,
                'refunded_wallet' => (int) $state['refunded_wallet'] + $wallet];
            if (self::restoresCredit($state)) {
                $this->ledger->book($state, 'wallet:refund-payment:' . $document->getId(), 'payment_refund', $wallet);
            }
        }
        $db->insert($this->ledger->table('document'), ['gross_cents' => $gross, 'wallet_cents' => $wallet] + [
            'kind' => $kind, 'document_id' => (int) $document->getId(), 'order_id' => (int) $state['order_id'],
            'invoice_id' => $kind === 'creditmemo' ? ($document->getInvoiceId() ?: null) : null,
        ]);
        $this->tender->update($state, $values);
        if ($kind === 'invoice') { $this->tender->settle($document->getOrder()); }
    }

    private function assertGross(int $gross, int $remaining): void
    {
        if ($gross > $remaining) {
            throw new LocalizedException(__('The requested document exceeds the remaining wallet order amount.'));
        }
    }

    public static function restoresCredit(array $state): bool
    {
        // Previously stored tenders keep their original refund promise.
        return (bool) ($state['restore_on_refund'] ?? true);
    }
}
