<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class CartExpiryOptions implements ArrayInterface
{
    /**
     * Retrieve options array for cart expiry times
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('1 Hour')],
            ['value' => '2', 'label' => __('2 Hours')],
            ['value' => '3', 'label' => __('3 Hours')],
            ['value' => '4', 'label' => __('4 Hours')],
            ['value' => '5', 'label' => __('5 Hours')],
        ];
    }
}
