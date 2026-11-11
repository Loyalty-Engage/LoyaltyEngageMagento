<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

class Tender
{
    public function __construct(private ResourceConnection $resource, private Ledger $ledger, private Selection $selection, private Guard $guard)
    {
    }

    public function get(Order $order, bool $lock = false): array
    {
        if (!$order->getId()) {
            $hold = $order->getData(Selection::HOLD);
            return is_array($hold) ? [
                'gross_cents' => $hold['gross_cents'], 'spent_cents' => $hold['amount'],
                'invoiced_gross' => 0, 'invoiced_wallet' => 0, 'refunded_gross' => 0,
                'refunded_wallet' => 0, 'released_wallet' => 0,
            ] : [];
        }
        return $this->byId((int) $order->getId(), $lock);
    }

    public function byId(int $orderId, bool $lock = false): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->ledger->table('tender'))
            ->where('order_id = ?', $orderId)->forUpdate($lock)) ?: [];
    }

    /** Native sales_order_save_after: within the order transaction, before related invoices. */
    public function bind(Order $order): void
    {
        $hold = $order->getData(Selection::HOLD);
        if (!is_array($hold) || !$order->getId()) {
            return;
        }
        $db = $this->resource->getConnection();
        if ($db->getTransactionLevel() < 1) {
            throw new \LogicException('Wallet order binding requires the native order transaction.');
        }
        if ($this->byId((int) $order->getId())) {
            return;
        }
        $row = $this->selection->row((int) $order->getQuoteId(), true);
        if ($row && $row['status'] === 'committed' && (int) $row['order_id'] === (int) $order->getId()) {
            return;
        }
        if (!$row || $row['status'] !== 'reserved' || !hash_equals((string) $row['token'], (string) $hold['hold_token'])
            || (int) $row['held_cents'] !== (int) $hold['amount']
            || (int) $row['customer_id'] !== (int) $order->getCustomerId()
            || (int) $row['store_id'] !== (int) $order->getStoreId()
            || $row['increment_id'] !== (string) $order->getIncrementId()) {
            throw new \LogicException('Wallet reservation does not match the order.');
        }
        if ((int) $row['funded_held'] > (int) $row['held_cents']
            || ($row['coins_required'] && (int) $row['funded_held'] !== (int) $row['held_cents'])) {
            throw new \LogicException('Coin-backed wallet payment has not been funded.');
        }
        $state = ['order_id' => (int) $order->getId(), 'customer_id' => (int) $row['customer_id'],
            'store_id' => (int) $row['store_id'], 'gross_cents' => (int) $hold['gross_cents'], 'spent_cents' => (int) $row['held_cents'],
            'restore_on_refund' => 0, 'funded_spent' => (int) $row['funded_held']];
        $db->insert($this->ledger->table('tender'), $state);
        $this->selection->lockBalance($row);
        $this->selection->changeReserved($row, -(int) $row['held_cents']);
        $this->selection->changeFunded($row, -(int) $row['funded_held'], -(int) $row['funded_held']);
        $this->ledger->book($state, 'wallet:spend:' . $order->getId(), 'order_payment', -(int) $row['held_cents']);
        $db->update($this->ledger->table('quote'), ['status' => 'committed', 'held_cents' => 0, 'funded_held' => 0, 'order_id' => (int) $order->getId()],
            ['quote_id = ?' => (int) $order->getQuoteId()]);
        \Magento\Framework\Model\CallbackPool::attach(spl_object_hash($db), static function () use ($order): void {
            $order->unsetData(Selection::HOLD);
        });
    }

    /** After relations: invoices have now been persisted in this same transaction. */
    public function settle(Order $order): void
    {
        if (!$order->getId()) {
            return;
        }
        $this->guard->afterCommit($this->resource->getConnection(), (int) $order->getId());
        if (!$this->get($order)) {
            return;
        }
        $hasCanceled = $order->getState() === Order::STATE_CANCELED;
        $hasUninvoiced = false;
        foreach ($order->getAllItems() as $item) {
            if ($item->isDummy()) { continue; }
            $hasCanceled = $hasCanceled || (float) $item->getQtyCanceled() > 0;
            $hasUninvoiced = $hasUninvoiced || ((float) $item->getQtyOrdered()
                - (float) $item->getQtyCanceled() - (float) $item->getQtyInvoiced() > 0.00001);
        }
        if (!$hasCanceled || ($order->getState() !== Order::STATE_CANCELED && $hasUninvoiced)) { return; }
        $state = $this->get($order, true);
        if (!$state) {
            return;
        }
        foreach ($order->getInvoiceCollection()->getItems() as $invoice) {
            if (!$invoice->getId() && $invoice->getData(Documents::ALLOCATION)) { return; }
        }
        $release = (int) $state['spent_cents'] - (int) $state['invoiced_wallet'] - (int) $state['released_wallet'];
        if ($release > 0) {
            $this->ledger->book($state, 'wallet:unused:' . $order->getId(), 'payment_release', $release);
            // Preserve purchased backing for canceled, uninvoiced value only.
            $fundedRelease = min($release, max(0, (int) $state['funded_spent'] - (int) $state['funded_released']));
            if ($fundedRelease > 0 && $this->resource->getConnection()->fetchOne(
                $this->resource->getConnection()->select()->from($this->ledger->table('balance'), ['customer_id'])
                    ->where('customer_id = ?', (int) $state['customer_id'])->where('store_id = ?', (int) $state['store_id'])
            )) {
                // Ledger::book already locks a surviving owner's balance.
                // Deleted owners retain an unclaimed audit booking, not a new wallet.
                $this->selection->changeFunded($state, $fundedRelease, 0);
            }
            $this->update($state, ['released_wallet' => (int) $state['released_wallet'] + $release,
                'funded_released' => (int) $state['funded_released'] + $fundedRelease]);
        }
    }

    public function update(array $state, array $values): void
    {
        $this->resource->getConnection()->update($this->ledger->table('tender'), $values,
            ['order_id = ?' => (int) $state['order_id']]);
    }
}
