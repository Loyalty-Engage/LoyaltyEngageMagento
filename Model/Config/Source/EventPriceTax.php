<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class EventPriceTax implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 0, 'label' => __('Excluding Tax')],
            ['value' => 1, 'label' => __('Including Tax')],
        ];
    }
}
