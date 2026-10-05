<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

class OrderData
{
    private ?bool $legacyColumnsAvailable = null;

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function get(Order $order): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName('loyaltyshop_order_data'))
            ->where('order_id = ?', (int) $order->getId()));
        if (!$row && $this->hasLegacyColumns()) {
            $row = $db->fetchRow($db->select()->from($this->resource->getTableName('sales_order'), [
                'order_id' => 'entity_id', 'store_id', 'loyalty_order_place',
                'loyalty_order_place_retrieve', 'loyalty_purchase_exported', 'loyalty_order_source',
            ])->where('entity_id = ?', (int) $order->getId()));
        }
        return $row ?: ['order_id' => (int) $order->getId(), 'store_id' => (int) $order->getStoreId(),
            'loyalty_order_place' => 0, 'loyalty_order_place_retrieve' => 0,
            'loyalty_purchase_exported' => 0, 'loyalty_order_source' => OrderSource::UNKNOWN];
    }

    public function source(Order $order): string
    {
        $runtimeSource = $order->getData('loyalty_order_source');
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
        $values += ['order_id' => $orderId, 'store_id' => $storeId];
        $db = $this->resource->getConnection();
        $db->insertOnDuplicate($this->resource->getTableName('loyaltyshop_order_data'), $values, array_keys($values));
    }

    private function hasLegacyColumns(): bool
    {
        if ($this->legacyColumnsAvailable !== null) {
            return $this->legacyColumnsAvailable;
        }
        $db = $this->resource->getConnection();
        $this->legacyColumnsAvailable = (bool) $db->fetchOne($db->select()
            ->from('information_schema.columns', ['column_name'])
            ->where('table_schema = DATABASE()')
            ->where('table_name = ?', $this->resource->getTableName('sales_order'))
            ->where('column_name = ?', 'loyalty_order_source'));
        return $this->legacyColumnsAvailable;
    }
}
