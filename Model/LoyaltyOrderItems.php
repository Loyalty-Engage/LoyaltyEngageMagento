<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Item;

class LoyaltyOrderItems
{
    public function __construct(private ResourceConnection $resource, private OrderItemData $orderItemData)
    {
    }

    public function isRedemption(Item $item): bool
    {
        if ($this->orderItemData->isRedemption($item)) {
            return true;
        }
        $options = $item->getProductOptions() ?: [];
        if (!empty($options['loyalty_locked_qty'])) {
            return true;
        }
        // Legacy orders only retained the marker on their source quote item.
        if ($item->getQuoteItemId()) {
            $db = $this->resource->getConnection();
            return $db->fetchOne($db->select()->from($this->resource->getTableName('quote_item_option'), ['value'])
                ->where('item_id = ?', $item->getQuoteItemId())->where('code = ?', 'loyalty_locked_qty')) === '1';
        }
        return false;
    }

    /** Resolve one order's markers in batches, including pre-migration quote markers. */
    public function redemptionItems(array $items): array
    {
        $matched = [];
        $pending = [];
        foreach ($items as $key => $item) {
            $options = $item->getProductOptions() ?: [];
            if (!empty($options['loyalty_locked_qty']) || $item->getData('loyalty_locked_qty')) {
                $matched[$key] = $item;
            } else {
                $pending[$key] = $item;
            }
        }
        if (!$pending) {
            return $matched;
        }
        $db = $this->resource->getConnection();
        $ids = array_filter(array_map(static fn(Item $item) => (int) $item->getId(), $pending));
        $markedIds = $ids ? $db->fetchCol($db->select()
            ->from($this->resource->getTableName('loyaltyshop_order_item_data'), ['order_item_id'])
            ->where('order_item_id IN (?)', $ids)->where('loyalty_locked_qty = ?', 1)) : [];
        $quoteIds = array_filter(array_map(static fn(Item $item) => (int) $item->getQuoteItemId(), $pending));
        $markedQuotes = $quoteIds ? $db->fetchCol($db->select()
            ->from($this->resource->getTableName('quote_item_option'), ['item_id'])
            ->where('item_id IN (?)', $quoteIds)->where('code = ?', 'loyalty_locked_qty')->where('value = ?', '1')) : [];
        $markedIds = array_fill_keys($markedIds, true);
        $markedQuotes = array_fill_keys($markedQuotes, true);
        foreach ($pending as $key => $item) {
            if (isset($markedIds[$item->getId()]) || isset($markedQuotes[$item->getQuoteItemId()])) {
                $matched[$key] = $item;
            }
        }
        return $matched;
    }
}
