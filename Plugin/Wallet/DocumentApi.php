<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Framework\App\ResourceConnection;

class DocumentApi
{
    public function __construct(private Guard $guard, private Tender $tender, private ResourceConnection $resource)
    {
    }

    public function aroundExecute($subject, callable $proceed, $id, ...$arguments)
    {
        $orderId = (int) $id;
        if ($subject instanceof \Magento\Sales\Model\RefundInvoice) {
            $db = $this->resource->getConnection();
            $orderId = (int) $db->fetchOne($db->select()->from($this->resource->getTableName('sales_invoice'), ['order_id'])
                ->where('entity_id = ?', (int) $id));
        }
        $protected = $orderId && $this->tender->byId($orderId);
        if ($protected) {
            $this->guard->order($orderId);
        }
        try {
            return $proceed($id, ...$arguments);
        } finally {
            if ($protected && $this->resource->getConnection()->getTransactionLevel() === 0) {
                $this->guard->releaseOrder($orderId);
            }
        }
    }
}
