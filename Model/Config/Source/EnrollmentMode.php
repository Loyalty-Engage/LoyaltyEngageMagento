<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Config\Source;

class EnrollmentMode implements \Magento\Framework\Data\OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'disabled', 'label' => __('Disabled')],
            ['value' => 'advisory', 'label' => __('Informational checkbox - everyone participates')],
            ['value' => 'required', 'label' => __('Opt-in - send LoyaltyEngageOptIn')],
        ];
    }
}
