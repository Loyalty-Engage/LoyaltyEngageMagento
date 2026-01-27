<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\SalesRule\Model\Rule\Condition;

use Magento\SalesRule\Model\Rule\Condition\Combine;

class CombinePlugin
{
    /**
     * Add loyalty conditions to cart price rules
     *
     * @param Combine $subject
     * @param array $result
     * @return array
     */
    public function afterGetNewChildSelectOptions(Combine $subject, array $result): array
    {
        $loyaltyConditions = [
            'value' => 'LoyaltyEngage\LoyaltyShop\Model\Rule\Condition\Customer\SalesLoyaltyTier',
            'label' => __('Customer Loyalty Conditions')
        ];

        // Find the customer conditions section and add our loyalty conditions
        foreach ($result as &$condition) {
            if (isset($condition['label']) && $condition['label'] == __('Customer Attribute')) {
                if (!isset($condition['value'])) {
                    $condition['value'] = [];
                }
                $condition['value'][] = $loyaltyConditions;
                return $result;
            }
        }

        // If customer attribute section doesn't exist, create it
        $result[] = [
            'label' => __('Customer Loyalty'),
            'value' => [$loyaltyConditions]
        ];

        return $result;
    }
}
