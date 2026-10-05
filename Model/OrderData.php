<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

class OrderData
{
    public const CAPTURED_SOURCE = '_loyaltyshop_captured_source';
    private ?array $legacyColumns = null;

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function get(Order $order): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName('loyaltyshop_order_data'))
            ->where('order_id = ?', (int) $order->getId()));
        return $row ?: $this->legacyData((int) $order->getId()) + [
            'order_id' => (int) $order->getId(), 'store_id' => (int) $order->getStoreId(),
            'loyalty_order_place' => 0, 'loyalty_order_place_retrieve' => 0,
            'loyalty_purchase_exported' => 0, 'loyalty_order_source' => OrderSource::UNKNOWN];
    }

    public function source(Order $order): string
    {
        $runtimeSource = $order->getData(self::CAPTURED_SOURCE);
        if (is_string($runtimeSource) && $runtimeSource !== '') {
            return $runtimeSource;
        }
        return (string) $this->get($order)['loyalty_order_source'];
    }

    public function isPurchaseExported(Order $order): bool
    {
        return (bool) $this->get($order)['loyalty_purchase_exported'];
    }

    public function isOrderPlaced(Order $order): bool
    {
        return (bool) $this->get($order)['loyalty_order_place'];
    }

    public function markDelivered(Order $order, string $field): void
    {
        $this->markDeliveredById((int) $order->getId(), (int) $order->getStoreId(), $field);
    }

    public function markDeliveredById(int $orderId, int $storeId, string $field): void
    {
        if (!in_array($field, ['loyalty_order_place', 'loyalty_purchase_exported'], true)) {
            throw new \InvalidArgumentException('Invalid loyalty order flag.');
        }
        $this->upsert($orderId, $storeId, [$field => 1]);
    }

    public function saveSource(Order $order, string $source): void
    {
        $this->upsert((int) $order->getId(), (int) $order->getStoreId(), ['loyalty_order_source' => $source]);
    }

    private function upsert(int $orderId, int $storeId, array $values): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('loyaltyshop_order_data');
        $exists = $db->fetchOne($db->select()->from($table, ['order_id'])->where('order_id = ?', $orderId));
        $changed = array_keys($values);
        // Seed all legacy flags on first write. A concurrent insert must only receive
        // the requested changes, never stale flags from our compatibility read.
        $row = $values + ['order_id' => $orderId, 'store_id' => $storeId];
        if (!$exists) {
            $row += $this->legacyData($orderId);
        }
        $db->insertOnDuplicate($table, $row, $changed);
    }

    private function legacyData(int $orderId): array
    {
        if (!$orderId) {
            return [];
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order');
        if ($this->legacyColumns === null) {
            $this->legacyColumns = array_values(array_intersect([
                'loyalty_order_place', 'loyalty_order_place_retrieve',
                'loyalty_purchase_exported', 'loyalty_order_source',
            ], array_keys($db->describeTable($table))));
        }
        return $this->legacyColumns ? ($db->fetchRow($db->select()->from($table, $this->legacyColumns)
            ->where('entity_id = ?', $orderId)) ?: []) : [];
    }
}
