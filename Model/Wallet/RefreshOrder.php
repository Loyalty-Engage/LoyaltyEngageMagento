<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/** Refresh monetary state after taking the wallet order lock, before a gateway call. */
class RefreshOrder
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function execute(Order $order, $document = null, string $kind = ''): void
    {
        if (!$order->getId()) { return; }
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName('sales_order'))
            ->where('entity_id = ?', (int) $order->getId()));
        if (!$row) { throw new LocalizedException(__('The wallet order no longer exists.')); }
        foreach ($row as $field => $value) {
            if (in_array($field, ['state', 'status', 'customer_id'], true)
                || preg_match('/(?:invoiced|refunded|canceled|paid)$/D', $field)) {
                $order->setData($field, $value);
            }
        }
        $rows = $db->fetchAssoc($db->select()->from($this->resource->getTableName('sales_order_item'),
            ['item_id', 'qty_ordered', 'qty_invoiced', 'qty_refunded', 'qty_canceled', 'qty_shipped',
                'row_invoiced', 'base_row_invoiced', 'tax_invoiced', 'base_tax_invoiced',
                'discount_invoiced', 'base_discount_invoiced', 'amount_refunded', 'base_amount_refunded',
                'tax_refunded', 'base_tax_refunded', 'discount_refunded', 'base_discount_refunded'])
            ->where('order_id = ?', (int) $order->getId()));
        foreach ($order->getAllItems() as $item) {
            if (isset($rows[(int) $item->getId()])) { $item->addData($rows[(int) $item->getId()]); }
        }
        if (!$document) { return; }
        if ($order->canUnhold() || $order->isPaymentReview() || in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            throw new LocalizedException(__('The order state changed. Please reload this document.'));
        }
        foreach ($document->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if (!$orderItem || $orderItem->isDummy()) { continue; }
            $remaining = $kind === 'invoice'
                ? (float) $orderItem->getQtyOrdered() - (float) $orderItem->getQtyInvoiced() - (float) $orderItem->getQtyCanceled()
                : (float) $orderItem->getQtyInvoiced() - (float) $orderItem->getQtyRefunded();
            if ((float) $item->getQty() > $remaining + .00001) {
                throw new LocalizedException(__('Item quantities changed. Please reload this document.'));
            }
        }
        if ($kind === 'creditmemo' && (float) $document->getBaseShippingAmount()
            > (float) $order->getBaseShippingAmount() - (float) $order->getBaseShippingRefunded() + .0001) {
            throw new LocalizedException(__('Shipping has already been refunded. Please reload this document.'));
        }
    }
}
