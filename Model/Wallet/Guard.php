<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;

/** Per-order document serialization, held through native resource transactions. */
class Guard implements ResetInterface
{
    private array $held = [];

    public function __construct(private LockManagerInterface $locks)
    {
        register_shutdown_function([$this, 'release']);
    }

    public function order(int $orderId): void
    {
        if ($orderId < 1 || isset($this->held[$orderId])) {
            return;
        }
        if (!$this->locks->lock('le-wallet-order-' . $orderId, 5)) {
            throw new LocalizedException(__('This order is being updated. Please try again shortly.'));
        }
        $this->held[$orderId] = true;
    }

    public function release(): void
    {
        foreach (array_keys($this->held) as $orderId) {
            $this->releaseOrder($orderId);
        }
        $this->held = [];
    }

    public function releaseOrder(int $orderId): void
    {
        if (isset($this->held[$orderId])) {
            $this->locks->unlock('le-wallet-order-' . $orderId);
            unset($this->held[$orderId]);
        }
    }

    public function afterCommit(\Magento\Framework\DB\Adapter\AdapterInterface $connection, int $orderId): void
    {
        if (!isset($this->held[$orderId])) { return; }
        \Magento\Framework\Model\CallbackPool::attach(spl_object_hash($connection), function () use ($orderId): void {
            $this->releaseOrder($orderId);
        });
    }

    public function _resetState(): void
    {
        $this->release();
    }
}
