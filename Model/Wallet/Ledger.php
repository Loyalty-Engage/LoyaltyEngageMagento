<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;

/** Mutations are internal only; no controller accepts a caller-provided customer. */
class Ledger
{
    public function __construct(private ResourceConnection $resource, private Money $money, private ?\Psr\Log\LoggerInterface $logger = null)
    {
    }

    public function balance(int $customerId, int $storeId): int
    {
        $db = $this->resource->getConnection();
        return (int) $db->fetchOne($db->select()->from($this->table('balance'), ['balance_cents'])
            ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
    }

    public function history(int $customerId, int $storeId, int $limit = 25): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchAll($db->select()->from($this->table('ledger'), [
            'entity_id', 'kind', 'amount_cents', 'created_at', 'order_id',
        ])->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId)
            ->order('entity_id DESC')->limit(max(1, min(100, $limit))));
    }

    public function available(int $customerId, int $storeId): int
    {
        return $this->summary($customerId, $storeId)['available'];
    }

    public function summary(int $customerId, int $storeId): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table('balance'), ['balance_cents', 'reserved_cents'])
            ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
        $balance = (int) ($row['balance_cents'] ?? 0);
        $reserved = (int) ($row['reserved_cents'] ?? 0);
        return ['balance' => $balance, 'reserved' => $reserved, 'available' => max(0, $balance - $reserved)];
    }

    /** Caller must hold the wallet order row lock in an active DB transaction. */
    public function book(array $state, string $key, string $kind, int $amount): bool
    {
        $this->money->assertCents($amount, true);
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1) {
            throw new \LogicException('Wallet ledger writes require an active transaction.');
        }
        $customerId = (int) $state['customer_id'];
        $storeId = (int) $state['store_id'];
        if ($customerId < 1 || $storeId < 1 || (int) $state['order_id'] < 1 || $key === '' || strlen($key) > 100
            || !in_array($kind, ['order_accrual', 'accrual_reversal', 'order_payment', 'payment_refund', 'payment_release'], true)) {
            throw new \InvalidArgumentException('Invalid wallet booking context.');
        }
        // Current shared reads protect account/store deletion until this booking
        // commits. These are two primary-key rows, not an exclusive store lock.
        $owner = $db->select()->from(['c' => $this->resource->getTableName('customer_entity')], ['entity_id'])
            ->joinInner(['s' => $this->resource->getTableName('store')], $db->quoteInto('s.store_id = ?', $storeId), [])
            ->where('c.entity_id = ?', $customerId);
        if (!$db->fetchOne($owner . ' LOCK IN SHARE MODE')) {
            if ($db->fetchOne($db->select()->from($this->table('ledger'), ['entity_id'])->where('operation_key = ?', $key))) { return false; }
            $db->insert($this->table('ledger'), [
                'operation_key' => $key, 'customer_id' => $customerId, 'store_id' => $storeId,
                'order_id' => (int) $state['order_id'], 'kind' => 'unclaimed_' . $kind, 'amount_cents' => $amount,
            ]);
            if ($amount !== 0) {
                $this->logger?->warning('Wallet booking has a deleted owner; merchant reconciliation required',
                    ['order_id' => (int) $state['order_id'], 'operation_key' => $key]);
            }
            return true;
        }
        $db->insertOnDuplicate($this->table('balance'), [
            'customer_id' => $customerId, 'store_id' => $storeId, 'balance_cents' => 0,
        ], ['customer_id']);
        $balance = (int) $db->fetchOne($db->select()->from($this->table('balance'), ['balance_cents'])
            ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId)->forUpdate(true));
        if ($db->fetchOne($db->select()->from($this->table('ledger'), ['entity_id'])
            ->where('operation_key = ?', $key))) {
            return false;
        }
        $this->money->assertCents($balance + $amount, true);
        $db->insert($this->table('ledger'), [
            'operation_key' => $key, 'customer_id' => $customerId, 'store_id' => $storeId,
            'order_id' => (int) $state['order_id'], 'kind' => $kind, 'amount_cents' => $amount,
        ]);
        $db->update($this->table('balance'), ['balance_cents' => $balance + $amount], [
            'customer_id = ?' => $customerId, 'store_id = ?' => $storeId,
        ]);
        return true;
    }

    public function table(string $name): string
    {
        if (!in_array($name, ['balance', 'ledger', 'order', 'quote', 'tender', 'document', 'funding'], true)) {
            throw new \InvalidArgumentException('Unknown wallet table.');
        }
        return $this->resource->getTableName('loyaltyshop_wallet_' . $name);
    }
}
