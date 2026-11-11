<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Framework\App\ResourceConnection;

class Choice
{
    public function __construct(private ResourceConnection $resource) {}

    public function get(int $quoteId, int $storeId): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->resource->getTableName('loyaltyshop_enrollment_quote'))
            ->where('quote_id = ?', $quoteId)->where('store_id = ?', $storeId)) ?: [];
    }

    public function save(int $quoteId, int $storeId, bool $selected, string $mode, string $label): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->resource->getTableName('loyaltyshop_enrollment_quote'), [
            'quote_id' => $quoteId, 'store_id' => $storeId, 'selected' => (int) $selected,
            'mode' => $mode, 'label' => $label,
        ], ['store_id', 'selected', 'mode', 'label']);
    }
}
