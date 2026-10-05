<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Creditmemo;

class CreditmemoData
{
    private ?bool $legacyColumnAvailable = null;

    public function __construct(private ResourceConnection $resource)
    {
    }

    public function isReturnExported(Creditmemo $creditmemo): bool
    {
        $db = $this->resource->getConnection();
        $value = $db->fetchOne($db->select()
            ->from($this->resource->getTableName('loyaltyshop_creditmemo_data'), ['loyalty_return_exported'])
            ->where('creditmemo_id = ?', (int) $creditmemo->getId()));
        if ($value === false && $this->hasLegacyColumn()) {
            $value = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_creditmemo'), ['loyalty_return_exported'])
                ->where('entity_id = ?', (int) $creditmemo->getId()));
        }
        return (bool) $value;
    }

    public function markExported(Creditmemo $creditmemo): void
    {
        $this->markExportedById((int) $creditmemo->getId());
    }

    public function markExportedById(int $creditmemoId): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->resource->getTableName('loyaltyshop_creditmemo_data'), [
            'creditmemo_id' => $creditmemoId, 'loyalty_return_exported' => 1,
        ], ['loyalty_return_exported']);
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
            ->where('table_name = ?', $this->resource->getTableName('sales_creditmemo'))
            ->where('column_name = ?', 'loyalty_return_exported'));
        return $this->legacyColumnAvailable;
    }
}
