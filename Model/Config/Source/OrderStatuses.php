<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;

class OrderStatuses implements OptionSourceInterface
{
    public function __construct(private OrderConfig $orderConfig)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->orderConfig->getStatuses() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
