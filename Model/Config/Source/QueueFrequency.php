<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class QueueFrequency implements OptionSourceInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '*/2 * * * *', 'label' => __('Every 2 minutes (High frequency)')],
            ['value' => '*/5 * * * *', 'label' => __('Every 5 minutes (Recommended)')],
            ['value' => '*/10 * * * *', 'label' => __('Every 10 minutes (Low frequency)')],
            ['value' => '*/15 * * * *', 'label' => __('Every 15 minutes (Very low frequency)')],
            ['value' => '0 * * * *', 'label' => __('Every hour (Minimal API calls)')],
        ];
    }
}
