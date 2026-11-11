<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class WalletHolds
{
    public function __construct(
        private ResourceConnection $resource,
        private Ledger $ledger,
        private Selection $selection,
        private LockManagerInterface $locks,
        private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $db = $this->resource->getConnection();
        $ids = $db->fetchCol($db->select()->from($this->ledger->table('quote'), ['quote_id'])
            ->where('status = ?', 'reserved')->where('updated_at < ?', gmdate('Y-m-d H:i:s', time() - 900))
            ->order('updated_at')->limit(50));
        $deadline = microtime(true) + 10;
        foreach ($ids as $id) {
            if (microtime(true) > $deadline) { break; }
            $name = 'le-wallet-quote-' . $id;
            if (!$this->locks->lock($name, 0)) { continue; }
            try {
                $this->selection->release((int) $id);
            } catch (\LoyaltyEngage\LoyaltyShop\Model\Wallet\ReconciliationException $exception) {
                $db->update($this->ledger->table('quote'), ['status' => 'review'], ['quote_id = ?' => (int) $id, 'status = ?' => 'reserved']);
                $this->logger->critical('Wallet hold quarantined for reconciliation', ['quote_id' => (int) $id, 'exception' => $exception]);
            } catch (\Throwable $exception) {
                $db->update($this->ledger->table('quote'), ['updated_at' => gmdate('Y-m-d H:i:s')], ['quote_id = ?' => (int) $id, 'status = ?' => 'reserved']);
                $this->logger->error('Wallet hold requires reconciliation', ['quote_id' => (int) $id, 'exception' => $exception]);
            } finally {
                $this->locks->unlock($name);
            }
        }
    }
}
