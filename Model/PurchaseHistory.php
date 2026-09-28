<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

class PurchaseHistory
{
    public function __construct(
        private ResourceConnection $resource,
        private EventOutbox $outbox,
        private OrderExportEligibility $eligibility,
        private Data $helper
    ) {
    }

    public function canExportReturn(Order $order): bool
    {
        if ($this->outbox->find('loyaltyshop.purchase_event', [
            'orderId' => $order->getIncrementId(), 'store_id' => (int) $order->getStoreId(),
        ])) {
            return true;
        }
        $db = $this->resource->getConnection();
        $exported = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order'),
            ['loyalty_purchase_exported'])->where('entity_id = ?', $order->getId()));
        if ($exported) {
            return true;
        }
        $cutover = $db->fetchOne($db->select()->from($this->resource->getTableName('core_config_data'), ['value'])
            ->where('scope = ?', 'default')->where('scope_id = ?', 0)
            ->where('path = ?', 'loyalty/export/tracking_started_at'));
        if (!$cutover || !$order->getCreatedAt() || $order->getCreatedAt() >= $cutover
            || !$this->helper->isPurchaseExportEnabled((int) $order->getStoreId())
            || !$this->eligibility->evaluate($order)['eligible']) {
            return false;
        }
        // Old releases had no sent ledger. Retain returns for qualifying legacy orders.
        $statuses = $this->helper->getPurchaseOrderStatuses((int) $order->getStoreId());
        return in_array($order->getStatus(), $statuses, true) || (bool) $db->fetchOne($db->select()
            ->from($this->resource->getTableName('sales_order_status_history'), ['entity_id'])
            ->where('parent_id = ?', $order->getId())->where('status IN (?)', $statuses)->limit(1));
    }
}
