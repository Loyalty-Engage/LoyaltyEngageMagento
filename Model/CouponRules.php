<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory as Coupons;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as Rules;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Store\Model\StoreManagerInterface;

class CouponRules
{
    public function __construct(
        private RuleFactory $rules, private CouponFactory $coupons,
        private Coupons $couponCollection, private Rules $ruleCollection,
        private CouponOwnership $ownership, private GroupRepositoryInterface $groups,
        private SearchCriteriaBuilderFactory $criteria, private StoreManagerInterface $stores,
        private LockManagerInterface $lock
    ) {
    }

    public function ensure(string $code, float $amount, bool $fixed): string
    {
        if ($code === '' || strlen($code) > 255 || !is_finite($amount) || $amount <= 0 || (!$fixed && $amount > 100)) {
            throw new LocalizedException(__('Invalid Loyalty Engage coupon details.'));
        }
        $lock = 'le_coupon_' . hash('sha256', $code);
        if (!$this->lock->lock($lock, 5)) {
            throw new LocalizedException(__('This coupon is being prepared. Please retry.'));
        }
        try {
            $websiteId = (int) $this->stores->getStore()->getWebsiteId();
            $action = $fixed ? 'cart_fixed' : 'by_percent';
            $coupon = $this->couponCollection->create()->addFieldToFilter('code', $code)->getFirstItem();
            if ($coupon->getId()) {
                $rule = $this->rules->create()->load($coupon->getRuleId());
                if (!$this->ownership->isManaged($code, $websiteId) || !$rule->getIsActive()
                    || $rule->getSimpleAction() !== $action || abs((float) $rule->getDiscountAmount() - $amount) > 0.0001) {
                    throw new LocalizedException(__('Coupon code conflicts with an existing rule or website.'));
                }
                return $code;
            }
            $rule = $this->ruleCollection->create()
                ->addFieldToFilter('loyaltyengage_managed', 1)
                ->addFieldToFilter('simple_action', $action)->addFieldToFilter('discount_amount', $amount)
                ->addFieldToFilter('is_active', 1)->addWebsiteFilter($websiteId)
                ->addFieldToFilter('to_date', [['null' => true], ['gteq' => date('Y-m-d')]])
                ->getFirstItem();
            if (!$rule->getId()) {
                $groupIds = array_map(static fn($group) => (int) $group->getId(),
                    $this->groups->getList($this->criteria->create()->create())->getItems());
                $rule = $this->rules->create();
                $rule->setName('Loyalty Engage ' . $action . ' ' . $amount . ' (website ' . $websiteId . ')')
                    ->setDescription('Auto-generated from LoyaltyEngage')->setData('loyaltyengage_managed', 1)
                    ->setIsActive(1)->setSimpleAction($action)->setDiscountAmount($amount)
                    ->setStopRulesProcessing(1)->setIsAdvanced(1)->setUsesPerCustomer(0)
                    ->setCustomerGroupIds($groupIds)->setWebsiteIds([$websiteId])
                    ->setCouponType(\Magento\SalesRule\Model\Rule::COUPON_TYPE_SPECIFIC)
                    ->setUseAutoGeneration(1)->setUsesPerCoupon(1)->save();
            }
            $this->coupons->create()->setRuleId($rule->getId())->setCode($code)->setUsageLimit(1)
                ->setUsagePerCustomer(1)->setIsPrimary(false)
                ->setType(\Magento\SalesRule\Model\Coupon::TYPE_GENERATED)->save();
            return $code;
        } finally {
            $this->lock->unlock($lock);
        }
    }
}
