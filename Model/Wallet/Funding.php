<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\{EventOutbox, MutationJournal};
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/** The caller holds the customer/store named lock and the quote placement lock. */
class Funding
{
    public function __construct(
        private ResourceConnection $resource,
        private Ledger $ledger,
        private Selection $selection,
        private Config $config,
        private Policy $policy,
        private DiscountPurchase $purchase,
        private MutationJournal $journal,
        private EventOutbox $outbox,
        private CustomerRepositoryInterface $customers
    ) {
    }

    public function prepare(Quote $quote): void
    {
        $db = $this->resource->getConnection();
        $row = $this->selection->row((int) $quote->getId());
        $this->selection->assertOwner($quote, $row);
        if ($row['status'] !== 'reserved') { throw new \LogicException('Reserve wallet credit before funding.'); }
        if (!$row['coins_required'] || (int) $row['funded_held'] === (int) $row['held_cents']) { return; }
        if ($db->getTransactionLevel() !== 0) { throw new \LogicException('Coin purchases require autocommit.'); }
        $skus = $this->config->redemptionSkus((int) $row['store_id']);
        if (!$skus) { throw new LocalizedException(__('Configure all four wallet discount product SKUs before using coin-backed payments.')); }
        $customer = $this->customers->getById((int) $row['customer_id']);
        $identifier = hash('sha256', strtolower(trim((string) $customer->getEmail())));

        // A crash may leave one purchase unacknowledged. Never create a new
        // purchase until that durable response has been inspected.
        $pending = $db->fetchRow($db->select()->from($this->ledger->table('funding'))
            ->where('customer_id = ?', (int) $row['customer_id'])->where('store_id = ?', (int) $row['store_id'])
            ->where('status = ?', 'pending')->limit(1));
        if ($pending) {
            $journal = $this->journal->get($this->key($pending));
            if (!$journal || ($journal['status'] === 'rejected' && $journal['response'] === null)) {
                // Explicitly refused or not yet sent: the new selection may
                // require a different amount/SKU. No coins were spent here.
                $db->update($this->ledger->table('funding'), ['status' => 'abandoned'], ['intent = ?' => $pending['intent']]);
            } else {
                $this->confirm($pending);
            }
        }
        $missing = $this->allocate($quote);
        $rounded = intdiv($missing + 99, 100) * 100;
        $plan = $this->policy->redemptionPlan($rounded, $rounded, $rounded);
        if (array_sum($plan) > 20) {
            throw new LocalizedException(__('Please use a smaller wallet amount (at most 20 vouchers per payment).'));
        }
        foreach ($plan as $cents => $quantity) {
            for ($i = 0; $i < $quantity; ++$i) {
                $intent = ['intent' => bin2hex(random_bytes(32)), 'customer_id' => (int) $row['customer_id'],
                    'store_id' => (int) $row['store_id'], 'quote_id' => (int) $row['quote_id'],
                    'identifier' => $identifier, 'sku' => $skus[$cents], 'amount_cents' => $cents, 'status' => 'pending'];
                $db->insert($this->ledger->table('funding'), $intent);
                $this->confirm($intent);
                $this->allocate($quote);
            }
        }
        if ($this->allocate($quote) !== 0) { throw new \LogicException('Wallet funding incomplete.'); }
    }

    private function key(array $intent): string
    {
        return hash('sha256', 'wallet-voucher:' . $intent['store_id'] . ':' . $intent['customer_id']
            . ':' . $intent['quote_id'] . ':' . $intent['intent'] . ':1');
    }

    private function confirm(array $intent): void
    {
        $response = $this->purchase->purchase((int) $intent['store_id'], (int) $intent['customer_id'],
            (int) $intent['quote_id'], $intent['intent'], 1, $intent['identifier'], $intent['sku']);
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $stored = $db->fetchRow($db->select()->from($this->ledger->table('funding'))
                ->where('intent = ?', $intent['intent'])->forUpdate(true));
            if ($stored['status'] === 'pending') {
                $this->selection->lockBalance($intent);
                // This marks existing euros as paid for; it does not mint a second balance.
                $this->selection->changeFunded($intent, (int) $intent['amount_cents'], 0);
                $db->update($this->ledger->table('funding'), ['status' => 'credited',
                    'discount_code' => $response['discountCode'], 'spent_coins' => $response['spentCoins']],
                    ['intent = ?' => $intent['intent']]);
                // No order_id: each voucher has a distinct payload identity,
                // including when several vouchers pay for the same order.
                $this->outbox->publish('loyaltyshop.redeem_discount_event', json_encode([
                    'store_id' => (int) $intent['store_id'], 'identifier' => $intent['identifier'],
                    'discount_code' => $response['discountCode'], 'wallet_intent' => $intent['intent'],
                ], JSON_THROW_ON_ERROR));
                $this->journal->complete($this->key($intent));
            }
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }

    private function allocate(Quote $quote): int
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $row = $this->selection->row((int) $quote->getId(), true);
            $this->selection->assertOwner($quote, $row);
            if ($row['status'] !== 'reserved') { throw new \LogicException('Wallet hold disappeared during funding.'); }
            $this->selection->lockBalance($row);
            $missing = (int) $row['held_cents'] - (int) $row['funded_held'];
            $add = $this->selection->fundedAvailable($row, $missing);
            $this->selection->changeFunded($row, 0, $add);
            $db->update($this->ledger->table('quote'), ['funded_held' => (int) $row['funded_held'] + $add],
                ['quote_id = ?' => (int) $quote->getId()]);
            $db->commit();
            return $missing - $add;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
}
