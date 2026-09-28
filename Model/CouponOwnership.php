<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\App\ResourceConnection;

class CouponOwnership
{
    public function __construct(private ResourceConnection $resource)
    {
    }

    public function isManaged(string $code, int $websiteId): bool
    {
        if ($code === '') {
            return false;
        }
        $db = $this->resource->getConnection();
        return (bool) $db->fetchOne($db->select()
            ->from(['c' => $this->resource->getTableName('salesrule_coupon')], ['coupon_id'])
            ->joinInner(['r' => $this->resource->getTableName('salesrule')], 'r.rule_id = c.rule_id', [])
            ->joinInner(['w' => $this->resource->getTableName('salesrule_website')], 'w.rule_id = r.rule_id', [])
            ->where('c.code = ?', $code)->where('r.loyaltyengage_managed = ?', 1)
            ->where('w.website_id = ?', $websiteId));
    }
}
