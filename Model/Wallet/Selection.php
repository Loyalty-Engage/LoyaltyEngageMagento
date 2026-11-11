<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

class Selection
{
    public const AMOUNT = '_loyaltyshop_wallet_applied';
    public const HOLD = '_loyaltyshop_wallet_hold';

    public function __construct(
        private ResourceConnection $resource,
        private Ledger $ledger,
        private Config $config,
        private Money $money
    ) {
    }

    public function row(int $quoteId, bool $lock = false): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->ledger->table('quote'))
            ->where('quote_id = ?', $quoteId)->forUpdate($lock)) ?: [];
    }

    public function eligible(Quote $quote): bool
    {
        foreach (['base_customer_bal_amount_used', 'base_customer_balance_amount_used', 'base_gift_cards_amount', 'base_reward_currency_amount'] as $field) {
            if ((float) $quote->getData($field) > 0) { return false; }
        }
        return $quote->getId() && $quote->getIsActive() && $quote->getCustomerId() && !$quote->getCustomerIsGuest()
            && !$quote->getIsMultiShipping() && !$quote->getOrigOrderId()
            && $quote->getBaseCurrencyCode() === 'EUR' && $quote->getQuoteCurrencyCode() === 'EUR'
            && $this->config->spending((int) $quote->getStoreId());
    }

    public function choose(Quote $quote, int $amount): void
    {
        $this->money->assertCents($amount);
        if ($amount === 0) { $this->forget($quote); return; }
        if (!$this->eligible($quote)) {
            throw new LocalizedException(__('Wallet payment is not available for this cart.'));
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $db->insertOnDuplicate($this->ledger->table('quote'), [
                'quote_id' => (int) $quote->getId(), 'customer_id' => (int) $quote->getCustomerId(),
                'store_id' => (int) $quote->getStoreId(),
            ], ['quote_id']);
            $row = $this->row((int) $quote->getId(), true);
            // Magento may reuse an active quote across store views in a website.
            // An unreserved selection can be replaced, never transferred as money.
            if ($row['status'] === 'selected' && (int) $row['held_cents'] === 0
                && (int) $row['customer_id'] === (int) $quote->getCustomerId()) {
                $row['store_id'] = (int) $quote->getStoreId();
            }
            $this->assertOwner($quote, $row);
            if ($row['status'] !== 'selected') {
                throw new LocalizedException(__('An order is already being placed for this cart.'));
            }
            $amount = min($amount, $this->ledger->available((int) $quote->getCustomerId(), (int) $quote->getStoreId()));
            $amount = $this->config->payable($amount, (int) $quote->getStoreId());
            $db->update($this->ledger->table('quote'), ['requested_cents' => $amount, 'store_id' => (int) $quote->getStoreId()], ['quote_id = ?' => (int) $quote->getId()]);
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function forget(Quote $quote): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $row = $this->row((int) $quote->getId(), true);
            if ($row) {
                if ((int) $row['customer_id'] !== (int) $quote->getCustomerId() || $row['status'] !== 'selected') {
                    throw new LocalizedException(__('This wallet selection cannot be changed while an order is being placed.'));
                }
                $db->update($this->ledger->table('quote'), ['requested_cents' => 0, 'preview_cents' => 0, 'preview_gross' => 0],
                    ['quote_id = ?' => (int) $quote->getId()]);
            }
            $db->commit();
        } catch (\Throwable $exception) { $db->rollBack(); throw $exception; }
    }

    public function applied(Quote $quote, int $gross): int
    {
        if (!$this->eligible($quote)) {
            return 0;
        }
        $row = $this->row((int) $quote->getId());
        if (!$row || $row['status'] === 'committed') {
            return 0;
        }
        if ($row['status'] === 'selected' && (int) $row['store_id'] !== (int) $quote->getStoreId()
            && (int) $row['customer_id'] === (int) $quote->getCustomerId()) {
            return 0;
        }
        $this->assertOwner($quote, $row);
        $available = $this->ledger->available((int) $quote->getCustomerId(), (int) $quote->getStoreId());
        if ($row['status'] === 'reserved') {
            $available += (int) $row['held_cents'];
        }
        return $this->config->payable(min($gross, (int) $row['requested_cents'], $available), (int) $quote->getStoreId());
    }

    public function preview(Quote $quote): int
    {
        if (!$this->eligible($quote)) { return 0; }
        $row = $this->row((int) $quote->getId());
        return $row && (int) $row['customer_id'] === (int) $quote->getCustomerId()
            && (int) $row['store_id'] === (int) $quote->getStoreId() && $row['status'] !== 'committed'
                ? (int) $row['preview_cents'] : 0;
    }

    /** Persist presentation metadata alongside the native quote save, never during collection. */
    public function savePreview(Quote $quote): void
    {
        if (!$quote->hasData(self::AMOUNT) || !$this->eligible($quote)) { return; }
        $amount = (int) $quote->getData(self::AMOUNT);
        $this->resource->getConnection()->update($this->ledger->table('quote'), [
            'preview_cents' => $amount, 'preview_gross' => $this->money->native($quote->getBaseGrandTotal()) + $amount,
        ], ['quote_id = ?' => (int) $quote->getId(), 'customer_id = ?' => (int) $quote->getCustomerId(),
            'store_id = ?' => (int) $quote->getStoreId(), 'status = ?' => 'selected']);
    }

    /** Named quote lock must cover this and the entire native submit call. */
    public function reserve(Quote $quote, int $amount, int $gross): array
    {
        $this->money->assertCents($amount);
        $this->money->assertCents($gross);
        if (!$this->eligible($quote)) {
            throw new LocalizedException(__('Wallet payment is not available for this cart.'));
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $row = $this->row((int) $quote->getId(), true);
            $this->assertOwner($quote, $row);
            if ($row['status'] !== 'selected'
                || $this->config->payable(min((int) $row['requested_cents'], $gross), (int) $quote->getStoreId()) !== $amount || $amount < 1) {
                throw new LocalizedException(__('Wallet selection changed. Please refresh your cart.'));
            }
            $this->lockBalance($row);
            if ($this->ledger->available((int) $row['customer_id'], (int) $row['store_id']) < $amount) {
                throw new LocalizedException(__('Your wallet balance changed. Please refresh your cart before placing the order.'));
            }
            $token = bin2hex(random_bytes(32));
            $this->changeReserved($row, $amount);
            $funded = $this->fundedAvailable($row, $amount);
            $this->changeFunded($row, 0, $funded);
            $db->update($this->ledger->table('quote'), [
                'status' => 'reserved', 'held_cents' => $amount, 'token' => $token,
                'funded_held' => $funded, 'coins_required' => (int) $this->config->coinsRequired((int) $quote->getStoreId()),
                'increment_id' => (string) $quote->getReservedOrderId(),
            ], ['quote_id = ?' => (int) $quote->getId()]);
            $db->commit();
            return $row + ['gross_cents' => $gross, 'amount' => $amount, 'hold_token' => $token];
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    /** Only after acquiring the named quote lock: no live placement can be released. */
    public function release(int $quoteId): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $row = $this->row($quoteId, true);
            if ($row && $row['status'] === 'reserved') {
                $order = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])
                    ->where('increment_id = ?', $row['increment_id'])->where('store_id = ?', (int) $row['store_id']));
                if ($order) {
                    throw new ReconciliationException(__('This wallet reservation belongs to an existing order. Contact the shop.'));
                }
                $this->lockBalance($row);
                $this->changeReserved($row, -(int) $row['held_cents']);
                $this->changeFunded($row, 0, -(int) $row['funded_held']);
                $db->update($this->ledger->table('quote'), ['status' => 'selected', 'held_cents' => 0, 'funded_held' => 0, 'token' => null],
                    ['quote_id = ?' => $quoteId]);
            }
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function assertOwner(Quote $quote, array $row): void
    {
        if (!$row || (int) $row['customer_id'] !== (int) $quote->getCustomerId()
            || (int) $row['store_id'] !== (int) $quote->getStoreId()) {
            throw new LocalizedException(__('Wallet selection does not belong to this cart.'));
        }
    }

    public function lockBalance(array $row): void
    {
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate($this->ledger->table('balance'), [
            'customer_id' => (int) $row['customer_id'], 'store_id' => (int) $row['store_id'], 'balance_cents' => 0,
        ], ['customer_id']);
        $db->fetchRow($db->select()->from($this->ledger->table('balance'))
            ->where('customer_id = ?', (int) $row['customer_id'])->where('store_id = ?', (int) $row['store_id'])->forUpdate(true));
    }

    public function changeReserved(array $row, int $change): void
    {
        $db = $this->resource->getConnection();
        $statement = $db->query('UPDATE ' . $this->ledger->table('balance') . ' SET reserved_cents = reserved_cents + ?'
            . ' WHERE customer_id = ? AND store_id = ? AND reserved_cents + ? >= 0',
            [$change, (int) $row['customer_id'], (int) $row['store_id'], $change]);
        if ($statement->rowCount() !== 1) {
            throw new \LogicException('Wallet hold accounting mismatch.');
        }
    }

    /** Caller holds the balance row lock. */
    public function fundedAvailable(array $row, int $maximum): int
    {
        $db = $this->resource->getConnection();
        $balance = $db->fetchRow($db->select()->from($this->ledger->table('balance'), ['funded_cents', 'reserved_funded'])
            ->where('customer_id = ?', (int) $row['customer_id'])->where('store_id = ?', (int) $row['store_id']));
        return min($maximum, max(0, (int) $balance['funded_cents'] - (int) $balance['reserved_funded']));
    }

    public function changeFunded(array $row, int $credit, int $reserved): void
    {
        if ($credit === 0 && $reserved === 0) { return; }
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1) { throw new \LogicException('Funded credit requires a transaction.'); }
        $statement = $db->query('UPDATE ' . $this->ledger->table('balance')
            . ' SET funded_cents = funded_cents + ?, reserved_funded = reserved_funded + ?'
            . ' WHERE customer_id = ? AND store_id = ? AND funded_cents + ? >= 0'
            . ' AND reserved_funded + ? >= 0 AND funded_cents + ? >= reserved_funded + ?',
            [$credit, $reserved, (int) $row['customer_id'], (int) $row['store_id'], $credit, $reserved, $credit, $reserved]);
        if ($statement->rowCount() !== 1) { throw new \LogicException('Funded wallet accounting mismatch.'); }
    }
}
