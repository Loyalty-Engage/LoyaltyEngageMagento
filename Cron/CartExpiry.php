<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class CartExpiry
{
    public function __construct(
        private CartRepositoryInterface $quotes, private ResourceConnection $resource,
        private Data $helper, private LockManagerInterface $lock, private LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $db = $this->resource->getConnection();
        $stores = $db->fetchCol($db->select()->from($this->resource->getTableName('store'), ['store_id'])
            ->where('store_id > ?', 0)->where('is_active = ?', 1));
        foreach ($stores as $storeId) {
            if ($this->helper->isLoyaltyEngageEnabled((int) $storeId)) {
                $this->processStore((int) $storeId);
            }
        }
    }

    private function processStore(int $storeId): void
    {
        $db = $this->resource->getConnection();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->helper->getCartExpiryHours($storeId) * 3600);
        $rows = $db->fetchAll($db->select()
            ->from(['q' => $this->resource->getTableName('quote')], ['entity_id', 'customer_id', 'store_id'])
            ->joinInner(['i' => $this->resource->getTableName('quote_item')], 'i.quote_id = q.entity_id', [])
            ->joinLeft(['o' => $this->resource->getTableName('quote_item_option')],
                "o.item_id = i.item_id AND o.code = 'loyalty_locked_qty'", [])
            ->where('q.is_active = ?', 1)->where('q.customer_id IS NOT NULL')
            ->where("(i.loyalty_locked_qty = 1 OR o.value = '1')")
            ->where('q.store_id = ?', $storeId)->where('i.created_at <= ?', $cutoff)
            ->distinct()->order('q.entity_id ASC')->limit(100));
        foreach ($rows as $row) {
            $storeId = (int) $row['store_id'];
            $key = 'le_cart_' . $row['customer_id'];
            if (!$this->helper->isLoyaltyEngageEnabled($storeId) || !$this->lock->lock($key, 0)) {
                continue;
            }
            try {
                $quote = $this->quotes->getActive((int) $row['entity_id']);
                $cutoff = gmdate('Y-m-d H:i:s', time() - $this->helper->getCartExpiryHours($storeId) * 3600);
                $removed = false;
                foreach ($quote->getAllVisibleItems() as $item) {
                    if ($this->helper->isLoyaltyProduct($item, true) && $item->getCreatedAt() <= $cutoff) {
                        $quote->removeItem($item->getId());
                        $removed = true;
                    }
                }
                if ($removed) {
                    $quote->setTotalsCollectedFlag(false)->collectTotals();
                    $this->quotes->save($quote);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Loyalty cart expiry failed.', ['quote_id' => $row['entity_id'], 'error' => $e->getMessage()]);
            } finally {
                $this->lock->unlock($key);
            }
        }
    }
}
