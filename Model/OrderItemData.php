<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Item;

class OrderItemData
{
    private ?bool $legacyColumnAvailable = null;

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function isRedemption(Item $item): bool
    {
        $options = $item->getProductOptions() ?: [];
        if (!empty($options['loyalty_locked_qty'])) {
            return true;
        }
        $db = $this->resource->getConnection();
        $value = $db->fetchOne($db->select()
            ->from($this->resource->getTableName('loyaltyshop_order_item_data'), ['loyalty_locked_qty'])
            ->where('order_item_id = ?', (int) $item->getId()));
        if ($value === false && $this->hasLegacyColumn()) {
            $value = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order_item'), ['loyalty_locked_qty'])
                ->where('item_id = ?', (int) $item->getId()));
        }
        return (bool) $value;
    }

    public function save(Item $item, bool $redemption): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->resource->getTableName('loyaltyshop_order_item_data'), [
            'order_item_id' => (int) $item->getId(), 'loyalty_locked_qty' => $redemption ? 1 : 0,
        ], ['loyalty_locked_qty']);
    }

    private function hasLegacyColumn(): bool
    {
        if ($this->legacyColumnAvailable !== null) {
            return $this->legacyColumnAvailable;
        }
        $db = $this->resource->getConnection();
        $this->legacyColumnAvailable = (bool) $db->fetchOne($db->select()
            ->from('information_schema.columns', ['column_name'])
            ->where('table_schema = DATABASE()')
            ->where('table_name = ?', $this->resource->getTableName('sales_order_item'))
            ->where('column_name = ?', 'loyalty_locked_qty'));
        return $this->legacyColumnAvailable;
    }
}
