<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;

/** Wallet-only cross-node locks; never changes the platform's lock provider. */
class DatabaseLocks implements LockManagerInterface
{
    private ?string $database = null;

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function lock(string $name, int $timeout = -1): bool
    {
        return (bool) $this->resource->getConnection()->fetchOne('SELECT GET_LOCK(?, ?)',
            [$this->name($name), $timeout < 0 ? 60 : $timeout]);
    }

    public function unlock(string $name): bool
    {
        return (bool) $this->resource->getConnection()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->name($name)]);
    }

    public function isLocked(string $name): bool
    {
        return (bool) $this->resource->getConnection()->fetchOne('SELECT IS_USED_LOCK(?)', [$this->name($name)]);
    }

    private function name(string $name): string
    {
        $this->database ??= (string) $this->resource->getConnection()->fetchOne('SELECT DATABASE()');
        return 'le-wallet:' . substr(hash('sha256', $this->database . ':' . $name), 0, 54);
    }
}
