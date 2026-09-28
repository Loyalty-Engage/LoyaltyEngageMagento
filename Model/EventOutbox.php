<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;

class EventOutbox
{
    public const TABLE = 'loyaltyshop_outbox';
    public const TOPICS = [
        'loyaltyshop.purchase_event', 'loyaltyshop.return_event',
        'loyaltyshop.free_product_purchase_event', 'loyaltyshop.free_product_remove_event',
        'loyaltyshop.review_event', 'loyaltyshop.redeem_discount_event', 'loyaltyshop.claim_discount_event',
    ];

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function publish(string $topic, string $json): void
    {
        if (!in_array($topic, self::TOPICS, true)) {
            throw new \InvalidArgumentException('Unknown LoyaltyShop event topic.');
        }
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload[0]) && is_array($payload[0])) {
            foreach ($payload as $event) {
                $this->publish($topic, json_encode($event, JSON_THROW_ON_ERROR));
            }
            return;
        }
        if (!is_array($payload) || !$payload) {
            throw new \InvalidArgumentException('Event payload must be a nonempty object.');
        }
        $storeId = $this->resolveStore($payload);
        $payload['store_id'] = $storeId;
        $key = self::eventKey($topic, $payload);
        $row = [
            'event_key' => $key,
            'aggregate_key' => isset($payload['quote_id'], $payload['sku'])
                ? hash('sha256', $storeId . ':' . $payload['quote_id'] . ':' . $payload['sku']) : null,
            'topic' => $topic,
            'store_id' => $storeId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => $storeId > 0 ? 'pending' : 'failed',
            'last_error' => $storeId > 0 ? null : 'Legacy message has ambiguous store scope; assign a store before replay.',
        ];
        // The unique key protects against repeated saves and concurrent requests.
        $this->resource->getConnection()->insertOnDuplicate($this->table(), $row, ['event_key']);
    }

    public static function eventKey(string $topic, array $payload): string
    {
        $identity = match ($topic) {
            'loyaltyshop.purchase_event', 'loyaltyshop.free_product_purchase_event' =>
                $payload['orderId'] ?? null,
            'loyaltyshop.return_event' => $payload['creditmemo_id'] ?? null,
            'loyaltyshop.review_event' => $payload['review_id'] ?? null,
            'loyaltyshop.claim_discount_event' => $payload['claim_id'] ?? null,
            'loyaltyshop.redeem_discount_event' => $payload['order_id'] ?? null,
            'loyaltyshop.free_product_remove_event' => $payload['removal_id'] ?? null,
            default => null,
        };
        // Preserve distinct legacy refunds; their old payload did not include a creditmemo ID.
        return hash('sha256', $topic . ':' . ($payload['store_id'] ?? 0) . ':'
            . ($identity === null ? json_encode($payload, JSON_THROW_ON_ERROR) : (string) $identity));
    }

    public function find(string $topic, array $identity): ?array
    {
        $row = $this->resource->getConnection()->fetchRow(
            $this->resource->getConnection()->select()->from($this->table())
                ->where('event_key = ?', self::eventKey($topic, $identity))
        );
        return $row ?: null;
    }

    public function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function resolveStore(array $payload): int
    {
        if (!empty($payload['store_id'])) {
            return (int) $payload['store_id'];
        }
        $db = $this->resource->getConnection();
        $orderId = $payload['orderId'] ?? $payload['order_id'] ?? null;
        if ($orderId) {
            $query = $db->select()->from($this->resource->getTableName('sales_order'), ['store_id'])
                ->where('increment_id = ?', $orderId);
            $stores = array_unique($db->fetchCol($query));
        } elseif (!empty($payload['review_id'])) {
            $stores = $db->fetchCol($db->select()
                ->from($this->resource->getTableName('review_detail'), ['store_id'])
                ->where('review_id = ?', $payload['review_id']));
        } else {
            $stores = $db->fetchCol($db->select()->from($this->resource->getTableName('store'), ['store_id'])
                ->where('store_id > ?', 0)->where('is_active = ?', 1));
        }
        return count($stores) === 1 ? (int) reset($stores) : 0;
    }
}
