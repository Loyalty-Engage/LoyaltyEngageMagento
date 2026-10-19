<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\LoyaltyCart;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\App\Emulation;

class RecoverMutations
{
    public function __construct(
        private ResourceConnection $resource, private LoyaltyCart $cart,
        private CartRepositoryInterface $quotes, private Emulation $emulation,
        private \LoyaltyEngage\LoyaltyShop\Model\MutationJournal $journal,
        private \Magento\Framework\App\MaintenanceMode $maintenance,
        private \Magento\Framework\Lock\LockManagerInterface $lock
    ) {
    }

    public function execute(): void
    {
        if ($this->maintenance->isOn() || !$this->lock->lock('loyaltyshop_recovery', 0)) {
            return;
        }
        try {
            $this->recover();
        } finally {
            $this->lock->unlock('loyaltyshop_recovery');
        }
    }

    private function recover(): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('loyaltyshop_mutation');
        $rows = [];
        foreach (['received', 'started', 'uncertain'] as $status) {
            $query = $db->select()->from($table)->where('status = ?', $status)
                ->where('attempts < ?', 10)->where('available_at <= ?', gmdate('Y-m-d H:i:s'))
                ->order(['available_at ASC', 'operation_key ASC'])->limit(25);
            if ($status !== 'received') {
                $query->where('operation = ?', 'product');
            }
            $rows = array_merge($rows, $db->fetchAll($query));
        }
        usort($rows, static fn(array $a, array $b): int =>
            [$a['available_at'], $a['operation_key']] <=> [$b['available_at'], $b['operation_key']]);
        $deadline = microtime(true) + 45;
        foreach (array_slice($rows, 0, 25) as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $started = false;
            try {
                $this->emulation->startEnvironmentEmulation((int) $row['store_id'], 'frontend', true);
                $started = true;
                $quote = $this->quotes->getActive((int) $row['quote_id']);
                if ((int) $quote->getCustomerId() !== (int) $row['customer_id']
                    || (int) $quote->getStoreId() !== (int) $row['store_id']) {
                    throw new \RuntimeException('Original cart is no longer active; reconcile the confirmed reservation manually.');
                }
                // Only a stored response or read-only cart confirmation can complete recovery.
                $this->journal->setRecoveryKey($row['operation_key']);
                $result = $row['operation'] === 'discount'
                    ? $this->cart->buyDiscountCodeProduct((int) $row['customer_id'], $row['sku'])
                    : $this->cart->addProduct((int) $row['customer_id'], $row['sku']);
                if (!$result->getSuccess()) {
                    throw new \RuntimeException($result->getMessage());
                }
                $db->update($table, ['status' => 'applied', 'last_error' => null], ['operation_key = ?' => $row['operation_key']]);
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $latest = $this->journal->get($row['operation_key']);
                if (!$latest || in_array($latest['status'], ['applied', 'rejected'], true)) {
                    continue;
                }
                $pendingStatus = $latest['response'] !== null ? 'received' : 'uncertain';
                $db->update($table, [
                    'status' => $attempts >= 10 ? 'manual' : $pendingStatus, 'attempts' => $attempts,
                    'available_at' => gmdate('Y-m-d H:i:s', time() + 300 * min(12, $attempts)),
                    'last_error' => substr($e->getMessage(), 0, 2000),
                ], ['operation_key = ?' => $row['operation_key'], 'status = ?' => $latest['status'],
                    'attempts = ?' => $row['attempts']]);
            } finally {
                $this->journal->setRecoveryKey(null);
                if ($started) {
                    $this->emulation->stopEnvironmentEmulation();
                }
            }
        }
    }
}
