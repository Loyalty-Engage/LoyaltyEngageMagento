<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class MutationJournal
{
    private ?string $recoveryKey = null;

    public function setRecoveryKey(?string $key): void
    {
        $this->recoveryKey = $key;
    }
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function get(string $key): ?array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->resource->getTableName('loyaltyshop_mutation'))
            ->where('operation_key = ?', $key)) ?: null;
    }

    public function response(string $key, int $storeId, int $customerId, int $quoteId, string $operation, string $sku, callable $request): array
    {
        if ($this->recoveryKey !== null && $this->recoveryKey !== $key) {
            throw new LocalizedException(__('Cart has changed since the original reservation; manual reconciliation required.'));
        }
        $row = $this->get($key);
        if ($row) {
            if ($row['response'] !== null) {
                return json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
            }
            throw new LocalizedException(__('This request needs reconciliation. Contact the shop with reference %1.', $key));
        }
        if ($this->recoveryKey !== null) {
            throw new LocalizedException(__('Recovery requires a confirmed journal response.'));
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('loyaltyshop_mutation');
        $db->insert($table, ['operation_key' => $key, 'store_id' => $storeId, 'customer_id' => $customerId,
            'sku' => $sku, 'quote_id' => $quoteId, 'operation' => $operation, 'status' => 'started']);
        try {
            $response = $request();
            if (!is_array($response)) {
                throw new \RuntimeException('No confirmed response from Loyalty Engage.');
            }
            $db->update($table, ['response' => json_encode($response, JSON_THROW_ON_ERROR), 'status' => 'received'],
                ['operation_key = ?' => $key]);
            return $response;
        } catch (\Throwable $e) {
            if ($e instanceof \LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException) {
                // Credentials are checked before transport; no remote mutation has been attempted.
                $db->delete($table, ['operation_key = ?' => $key]);
                throw $e;
            }
            $db->update($table, ['status' => 'uncertain', 'last_error' => substr($e->getMessage(), 0, 2000)],
                ['operation_key = ?' => $key]);
            throw $e;
        }
    }

    public function complete(string $key): void
    {
        $this->resource->getConnection()->update($this->resource->getTableName('loyaltyshop_mutation'),
            ['status' => 'applied', 'last_error' => null], ['operation_key = ?' => $key]);
    }
}
