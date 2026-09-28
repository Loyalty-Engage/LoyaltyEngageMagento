<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Item;

class LoyaltyOrderItems
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function isRedemption(Item $item): bool
    {
        if ($item->getData('loyalty_locked_qty')) {
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
}
