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

    public function response(string $key, int $storeId, int $customerId, int $quoteId, string $operation, string $sku, callable $request, ?callable $reconcile = null): array
    {
        if ($this->recoveryKey !== null && $this->recoveryKey !== $key) {
            throw new LocalizedException(__('Cart has changed since the original reservation; manual reconciliation required.'));
        }
        $row = $this->get($key);
        if ($row) {
            if ((int) $row['store_id'] !== $storeId || (int) $row['customer_id'] !== $customerId
                || (int) $row['quote_id'] !== $quoteId || $row['operation'] !== $operation || $row['sku'] !== $sku) {
                throw new LocalizedException(__('The original redemption belongs to a different cart or store.'));
            }
            if ($row['response'] !== null) {
                return json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR);
            }
            if ($operation === 'product' && in_array($row['status'], ['started', 'uncertain'], true) && $reconcile !== null) {
                $confirmed = $reconcile();
                if (is_array($confirmed)) {
                    $saved = $this->resource->getConnection()->update(
                        $this->resource->getTableName('loyaltyshop_mutation'),
                        ['response' => json_encode($confirmed, JSON_THROW_ON_ERROR), 'status' => 'received', 'last_error' => null],
                        ['operation_key = ?' => $key, 'response IS NULL', 'status IN (?)' => ['started', 'uncertain']]
                    );
                    if (!$saved) {
                        throw new LocalizedException(__('Your cart is being updated. Please retry.'));
                    }
                    return $confirmed;
                }
            }
            if ($row['status'] !== 'rejected') {
                throw new LocalizedException(__('This request needs reconciliation. Contact the shop with reference %1.', $key));
            }
        }
        if ($this->recoveryKey !== null) {
            throw new LocalizedException(__('Recovery requires a confirmed journal response.'));
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('loyaltyshop_mutation');
        if ($row) {
            // Only an explicit refusal is safe to retry; atomically claim the next attempt.
            $claimed = $db->update($table, ['status' => 'started', 'last_error' => null],
                ['operation_key = ?' => $key, 'status = ?' => 'rejected', 'response IS NULL']);
            if (!$claimed) {
                throw new LocalizedException(__('Your cart is being updated. Please retry.'));
            }
        } else {
            $db->insert($table, ['operation_key' => $key, 'store_id' => $storeId, 'customer_id' => $customerId,
                'sku' => $sku, 'quote_id' => $quoteId, 'operation' => $operation, 'status' => 'started']);
        }
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
            $status = $e instanceof \LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException ? 'rejected' : 'uncertain';
            $db->update($table, ['status' => $status, 'last_error' => substr($e->getMessage(), 0, 2000)],
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
