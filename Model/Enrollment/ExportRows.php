<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\{CustomerLoyaltyDataProvider, EventOutbox};
use Magento\Customer\Model\Config\Share;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;

class ExportRows
{
    private const BATCH_SIZE = 500;
    private const TOPIC = 'loyaltyshop.opt_in_event';

    public function __construct(
        private ResourceConnection $resource,
        private Share $sharing,
        private CollectionFactory $customers,
        private EventOutbox $outbox
    ) {}

    public function get(StoreInterface $store): iterable
    {
        $storeId = (int) $store->getId();
        $db = $this->resource->getConnection();
        $customerTable = $this->resource->getTableName('customer_entity');
        $upperId = (int) $db->fetchOne($db->select()->from($customerTable, ['MAX(entity_id)']));
        $select = $db->select()->from(['c' => $customerTable], ['entity_id', 'email', 'store_id'])
            ->joinLeft(
                ['m' => $this->resource->getTableName(CustomerLoyaltyDataProvider::TABLE_NAME)],
                'm.customer_id = c.entity_id AND m.store_id = ' . $storeId,
                ['scoped_id' => 'entity_id', ...CustomerLoyaltyDataProvider::ATTRIBUTE_CODES]
            )->joinLeft(
                ['w' => $this->resource->getTableName('loyaltyshop_wallet_balance')],
                'w.customer_id = c.entity_id AND w.store_id = ' . $storeId,
                ['balance_cents', 'reserved_cents']
            )->where('c.entity_id <= ?', $upperId)
            ->order('c.entity_id ASC')
            ->limit(self::BATCH_SIZE);
        if ($this->sharing->isWebsiteScope()) {
            $select->where('c.website_id = ?', (int) $store->getWebsiteId());
        }

        $after = 0;
        while ($batch = $db->fetchAll((clone $select)->where('c.entity_id > ?', $after))) {
            $keys = $legacyIds = [];
            foreach ($batch as $row) {
                $id = (int) $row['entity_id'];
                $keys[$id] = EventOutbox::eventKey(self::TOPIC, ['store_id' => $storeId, 'identifier' => $row['email']]);
                if ($row['scoped_id'] === null && (int) $row['store_id'] === $storeId) {
                    $legacyIds[] = $id;
                }
            }
            // Use the same legacy fallback as the storefront, without loading a customer per CSV row.
            $legacy = [];
            if ($legacyIds) {
                $collection = $this->customers->create()
                    ->addAttributeToSelect(CustomerLoyaltyDataProvider::ATTRIBUTE_CODES)
                    ->addFieldToFilter('entity_id', ['in' => $legacyIds]);
                foreach ($collection as $customer) {
                    $legacy[(int) $customer->getId()] = Membership::hasFields($customer->getData());
                }
            }
            $events = [];
            foreach ($db->fetchAll($db->select()->from($this->outbox->table(), ['event_key', 'status', 'payload'])
                ->where('event_key IN (?)', array_values(array_unique($keys)))) as $event) {
                $events[$event['event_key']] = $event;
            }
            foreach ($batch as $row) {
                $after = (int) $row['entity_id'];
                $event = $events[$keys[$after]] ?? [];
                [$status, $source] = self::describe(
                    $event,
                    Membership::hasFields($row),
                    $legacy[$after] ?? false
                );
                $reserved = (int) $row['reserved_cents'];
                yield [
                    $storeId,
                    $store->getCode(),
                    $after,
                    $row['email'],
                    $status,
                    $source,
                    self::euros(max(0, (int) $row['balance_cents'] - $reserved)),
                    self::euros($reserved),
                ];
            }
        }
    }

    public static function describe(array $event, bool $synced, bool $legacy): array
    {
        $payload = json_decode((string) ($event['payload'] ?? ''), true);
        $source = $payload['source'] ?? null;
        if (!in_array($source, ['checkout', 'registration', 'account', 'admin'], true)) {
            $source = empty($payload['order_id']) ? 'unknown' : 'checkout';
        }

        return match (true) {
            ($event['status'] ?? '') === 'sent' => ['enrolled', $source],
            $synced => ['enrolled', 'loyalty_sync'],
            $legacy => ['enrolled', 'magento_legacy'],
            ($event['status'] ?? '') === 'failed' => ['failed', $source],
            (bool) $event => ['pending', $source],
            default => ['not_enrolled', ''],
        };
    }

    private static function euros(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', abs(intdiv($cents, 100)), abs($cents % 100));
    }
}
