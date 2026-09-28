<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EncryptLegacyCredentials implements DataPatchInterface
{
    private const CONFIG_PATHS = [
        'loyalty/general/tenant_id',
        'loyalty/general/bearer_token',
    ];

    public function __construct(
        private ResourceConnection $resourceConnection,
        private EncryptorInterface $encryptor
    ) {
    }

    public function apply(): self
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('core_config_data');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['config_id', 'value'])
                ->where('path IN (?)', self::CONFIG_PATHS)
        );

        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value === '' || $this->isEncrypted($value)) {
                continue;
            }

            $connection->update(
                $table,
                ['value' => $this->encryptor->encrypt($value)],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }

        return $this;
    }

    private function isEncrypted(string $value): bool
    {
        if (!preg_match('/^\d+:\d+:/', $value)) {
            return false;
        }

        try {
            return $this->encryptor->decrypt($value) !== '';
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
