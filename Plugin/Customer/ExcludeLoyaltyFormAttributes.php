<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\Customer;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use Magento\Customer\Api\CustomerMetadataInterface;

class ExcludeLoyaltyFormAttributes
{
    // Frontend form metadata only: do not remove attributes from repository/API metadata.
    public function afterGetAttributes(CustomerMetadataInterface $subject, array $attributes): array
    {
        foreach ($attributes as $key => $attribute) {
            if (in_array($attribute->getAttributeCode(), CustomerLoyaltyDataProvider::ATTRIBUTE_CODES, true)) {
                unset($attributes[$key]);
            }
        }
        return $attributes;
    }
}
