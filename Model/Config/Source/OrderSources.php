<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\Data\OptionSourceInterface;

class OrderSources implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => OrderSource::STOREFRONT, 'label' => __('Storefront')],
            ['value' => OrderSource::ADMIN, 'label' => __('Admin Panel')],
            ['value' => OrderSource::API, 'label' => __('API (REST, SOAP and GraphQL)')],
            ['value' => OrderSource::UNKNOWN, 'label' => __('Unknown / Legacy Orders')],
        ];
    }
}
