<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\CatalogRule\Model\Rule\Condition;

use Magento\CatalogRule\Model\Rule\Condition\Combine;

class CombinePlugin
{
    /**
     * Add loyalty conditions to catalog price rules
     *
     * @param Combine $subject
     * @param array $result
     * @return array
     */
    public function afterGetNewChildSelectOptions(Combine $subject, array $result): array
    {
        $loyaltyConditions = [
            'value' => 'LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer\LoyaltyTier',
            'label' => __('Customer Loyalty Conditions')
        ];

        // Find the conditions array and add our loyalty conditions
        foreach ($result as &$condition) {
            if (isset($condition['value']) && is_array($condition['value'])) {
                foreach ($condition['value'] as &$subCondition) {
                    if (isset($subCondition['label']) && $subCondition['label'] == __('Conditions Combination')) {
                        if (!isset($subCondition['value'])) {
                            $subCondition['value'] = [];
                        }
                        $subCondition['value'][] = $loyaltyConditions;
                        break 2;
                    }
                }
            }
        }

        // If we couldn't find the right place, add it to the main array
        if (!$this->hasLoyaltyConditions($result)) {
            $result[] = [
                'label' => __('Customer Loyalty'),
                'value' => [$loyaltyConditions]
            ];
        }

        return $result;
    }

    /**
     * Check if loyalty conditions are already added
     *
     * @param array $conditions
     * @return bool
     */
    private function hasLoyaltyConditions(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (isset($condition['value'])) {
                if (is_string($condition['value']) && 
                    $condition['value'] === 'LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer\LoyaltyTier') {
                    return true;
                }
                if (is_array($condition['value']) && $this->hasLoyaltyConditions($condition['value'])) {
                    return true;
                }
            }
        }
        return false;
    }
}
