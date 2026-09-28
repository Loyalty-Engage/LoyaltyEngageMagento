<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Cron;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Service\ApiException;
use LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

class DeliverEvents
{
    public function __construct(
        private ResourceConnection $resource,
        private EventOutbox $outbox,
        private LockManagerInterface $lock,
        private ApiClient $apiClient,
        private LoggerInterface $logger,
        private \Magento\Framework\App\MaintenanceMode $maintenance,
        private array $consumers = []
    ) {
    }

    public function execute(): void
    {
        if ($this->maintenance->isOn()) {
            return;
        }
        if (!$this->lock->lock('loyaltyshop_outbox', 0)) {
            return;
        }
        try {
            $db = $this->resource->getConnection();
            $rows = $db->fetchAll($db->select()->from($this->outbox->table())
                ->where('status = ?', 'pending')->where('available_at <= ?', gmdate('Y-m-d H:i:s'))
                ->order('entity_id ASC')->limit(500));
            $deadline = microtime(true) + 45;
            foreach ($rows as $row) {
                $this->deliver($row);
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } finally {
            $this->apiClient->setIdempotencyKey(null);
            $this->lock->unlock('loyaltyshop_outbox');
        }
    }

    private function deliver(array $row): void
    {
        $db = $this->resource->getConnection();
        $attempts = (int) $row['attempts'];
        try {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $consumer = $this->consumers[$row['topic']] ?? null;
            if (!$consumer) {
                throw new \InvalidArgumentException('No consumer registered for ' . $row['topic']);
            }
            if ($row['topic'] === 'loyaltyshop.return_event') {
                $purchase = $this->outbox->find('loyaltyshop.purchase_event', $payload);
                if ($purchase && $purchase['status'] !== 'sent') {
                    throw new DeferredDeliveryException('Waiting for the associated Purchase to be delivered.');
                }
            }
            $this->apiClient->setIdempotencyKey($row['event_key']);
            $consumer->deliver($payload);
            $db->beginTransaction();
            try {
                $db->update($this->outbox->table(), [
                    'status' => 'sent', 'sent_at' => gmdate('Y-m-d H:i:s'),
                    'attempts' => $attempts + 1, 'last_error' => null,
                ], ['entity_id = ?' => $row['entity_id']]);
                $this->markDelivered($row['topic'], $payload);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $deferred = $e instanceof DeferredDeliveryException;
            $attempts += $deferred ? 0 : 1;
            $retry = $deferred || ($attempts < 10 && (!$e instanceof ApiException || $e->isRetryable())
                && !$e instanceof \InvalidArgumentException && !$e instanceof \JsonException);
            $db->update($this->outbox->table(), [
                'status' => $retry ? 'pending' : 'failed',
                'attempts' => $attempts,
                'available_at' => gmdate('Y-m-d H:i:s', time() + ($deferred ? 300 : min(3600, 60 * (2 ** min(6, $attempts))))),
                'last_error' => substr($e->getMessage(), 0, 2000),
            ], ['entity_id = ?' => $row['entity_id']]);
            if (!$deferred) {
                $this->logger->error('Loyalty Engage event delivery failed.', [
                    'event_id' => $row['entity_id'], 'topic' => $row['topic'],
                    'attempts' => $attempts, 'retry' => $retry, 'error' => $e->getMessage(),
                ]);
            }
        } finally {
            $this->apiClient->setIdempotencyKey(null);
        }
    }

    private function markDelivered(string $topic, array $payload): void
    {
        $db = $this->resource->getConnection();
        if (in_array($topic, ['loyaltyshop.purchase_event', 'loyaltyshop.free_product_purchase_event'], true)) {
            $column = $topic === 'loyaltyshop.purchase_event' ? 'loyalty_purchase_exported' : 'loyalty_order_place';
            $db->update($this->resource->getTableName('sales_order'), [$column => 1], [
                'increment_id = ?' => $payload['orderId'], 'store_id = ?' => $payload['store_id'],
            ]);
        } elseif ($topic === 'loyaltyshop.return_event' && !empty($payload['creditmemo_id'])) {
            $db->update($this->resource->getTableName('sales_creditmemo'), ['loyalty_return_exported' => 1], [
                'entity_id = ?' => $payload['creditmemo_id'],
            ]);
        }
    }
}
