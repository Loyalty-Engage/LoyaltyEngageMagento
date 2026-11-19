<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\Enrollment;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

class CheckoutLayout
{
    public function afterProcess(LayoutProcessor $subject, array $layout): array
    {
        $payment = &$layout['components']['checkout']['children']['steps']['children']['billing-step']['children']['payment']['children'];
        if (isset($payment['afterMethods'])) {
            $payment['afterMethods']['children']['loyaltyshop-enrollment'] = [
                'component' => 'LoyaltyEngage_LoyaltyShop/js/view/enrollment',
                'sortOrder' => 999,
            ];
        }
        return $layout;
    }
}
