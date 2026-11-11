<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Observer\Wallet\Capture;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;

class Accrual
{
    public function __construct(
        private ResourceConnection $resource,
        private Ledger $ledger,
        private Policy $policy,
        private Money $money
    ) {
    }

    public function orderSaved(Order $order): void
    {
        if (!$order->getId() || !$order->getCustomerId()) {
            return;
        }
        $db = $this->resource->getConnection();
        $snapshot = $order->getData(Capture::SNAPSHOT);
        if (!is_array($snapshot) && !$this->exists((int) $order->getId())) {
            return;
        }
        $db->beginTransaction();
        try {
            if (is_array($snapshot)) {
                $lines = [];
                foreach ($order->getAllItems() as $item) {
                    if ($item->getId() && $item->hasData(Capture::LINE)) {
                        $lines[(string) $item->getId()] = [
                            'cents' => (int) $item->getData(Capture::LINE), 'refunded' => 0, 'canceled' => 0,
                            'gross' => (int) ($item->getData(Capture::GROSS_LINE) ?? $item->getData(Capture::LINE)), 'refunded_gross' => 0,
                            'quantity' => $this->money->quantityUnits((string) $item->getQtyOrdered()),
                        ];
                    }
                }
                if (array_sum(array_column($lines, 'cents')) !== (int) $snapshot['eligible_cents']) {
                    throw new \LogicException('Wallet order items were not persisted before the order snapshot.');
                }
                $db->insertOnDuplicate($this->ledger->table('order'), [
                    'order_id' => (int) $order->getId(), 'customer_id' => (int) $order->getCustomerId(),
                    'store_id' => (int) $order->getStoreId(), 'tier' => $snapshot['tier'],
                    'rate_bps' => $snapshot['rate_bps'], 'including_tax' => $snapshot['including_tax'],
                    'eligible_cents' => $snapshot['eligible_cents'], 'reward_cents' => $snapshot['earned_cents'],
                    'lines' => json_encode($lines, JSON_THROW_ON_ERROR),
                ], ['order_id']);
            }
            $state = $this->locked((int) $order->getId());
            if ($state && !$state['voided']) {
                $lines = json_decode($state['lines'], true, 512, JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($order->getAllItems() as $item) {
                    $id = (string) $item->getId();
                    if (isset($lines[$id]) && $lines[$id]['quantity'] > 0) {
                        $canceled = $this->policy->proportion($lines[$id]['cents'],
                            min($lines[$id]['quantity'], $this->money->quantityUnits((string) ($item->getQtyCanceled() ?? '0'))),
                            $lines[$id]['quantity']);
                        if ($canceled > $lines[$id]['canceled']) {
                            $lines[$id]['canceled'] = $canceled;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    $this->update($state, ['lines' => json_encode($lines, JSON_THROW_ON_ERROR)]);
                    if ($state['vested']) {
                        $reversal = $this->reversal($state, $lines);
                        $this->ledger->book($state, 'wallet:cancelqty:' . $order->getId() . ':'
                            . substr(hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR)), 0, 32),
                            'accrual_reversal', -($reversal - (int) $state['reversed_cents']));
                        $this->update($state, ['reversed_cents' => $reversal]);
                        $state['reversed_cents'] = $reversal;
                    }
                }
                if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
                    $amount = $state['vested'] ? (int) $state['reward_cents'] - (int) $state['reversed_cents'] : 0;
                    $this->ledger->book($state, 'wallet:void:' . $order->getId(), 'accrual_reversal', -$amount);
                    $this->update($state, ['voided' => 1, 'reversed_cents' => (int) $state['reward_cents']]);
                } elseif ($order->getStatus() === 'complete' && !$state['vested']) {
                    $reversal = $this->reversal($state, $lines);
                    $this->ledger->book($state, 'wallet:earn:' . $order->getId(), 'order_accrual',
                        (int) $state['reward_cents'] - $reversal);
                    $this->update($state, ['vested' => 1, 'reversed_cents' => $reversal]);
                }
            }
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function refunded(Creditmemo $creditmemo): void
    {
        if (!$creditmemo->getId() || (int) $creditmemo->getState() !== Creditmemo::STATE_REFUNDED
            || !$this->exists((int) $creditmemo->getOrderId())) {
            return;
        }
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $state = $this->locked((int) $creditmemo->getOrderId());
            if ($state && !$state['voided']) {
                $key = 'wallet:return:' . $creditmemo->getId();
                $already = $db->fetchOne($db->select()->from($this->ledger->table('ledger'), ['entity_id'])
                    ->where('operation_key = ?', $key));
                if (!$already) {
                    $lines = json_decode($state['lines'], true, 512, JSON_THROW_ON_ERROR);
                    foreach ($creditmemo->getAllItems() as $item) {
                        $id = (string) $item->getOrderItemId();
                        if (!isset($lines[$id])) {
                            continue;
                        }
                        $refund = max(0, $this->money->cents((string) ($item->getBaseRowTotal() ?? '0'))
                            - $this->money->cents((string) ($item->getBaseDiscountAmount() ?? '0')));
                        if ($state['including_tax']) {
                            $refund += $this->money->cents((string) ($item->getBaseTaxAmount() ?? '0'))
                                + $this->money->cents((string) ($item->getBaseDiscountTaxCompensationAmount() ?? '0'));
                        }
                        $gross = $lines[$id]['gross'] ?? $lines[$id]['cents'];
                        $lines[$id]['refunded_gross'] = min($gross, ($lines[$id]['refunded_gross'] ?? $lines[$id]['refunded']) + $refund);
                        $lines[$id]['refunded'] = $gross > 0
                            ? $this->policy->proportion($lines[$id]['cents'], $lines[$id]['refunded_gross'], $gross) : 0;
                    }
                    $reversal = $this->reversal($state, $lines);
                    $delta = $state['vested'] ? $reversal - (int) $state['reversed_cents'] : 0;
                    $this->ledger->book($state, $key, 'accrual_reversal', -$delta);
                    $values = ['lines' => json_encode($lines, JSON_THROW_ON_ERROR)];
                    if ($state['vested']) {
                        $values['reversed_cents'] = $reversal;
                    }
                    $this->update($state, $values);
                }
            }
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function locked(int $orderId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->ledger->table('order'))
            ->where('order_id = ?', $orderId)->forUpdate(true)) ?: [];
    }

    private function exists(int $orderId): bool
    {
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()->from($this->ledger->table('order'), ['order_id'])
            ->where('order_id = ?', $orderId));
    }

    private function reversal(array $state, array $lines): int
    {
        $removed = 0;
        foreach ($lines as $line) {
            $removed += min($line['cents'], $line['refunded'] + $line['canceled']);
        }
        return $this->policy->reversal((int) $state['eligible_cents'], (int) $state['reward_cents'], $removed);
    }

    private function update(array $state, array $values): void
    {
        $this->resource->getConnection()->update($this->ledger->table('order'), $values,
            ['order_id = ?' => (int) $state['order_id']]);
    }
}
