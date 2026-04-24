<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Address\RateResult\Method as ShippingMethod;
use Magento\Shipping\Model\Rate\CarrierResult;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class ShippingMethodPlugin
{
    /**
     * @var LoyaltyTierChecker
     */
    private $loyaltyTierChecker;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @param LoyaltyTierChecker $loyaltyTierChecker
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        LoyaltyTierChecker $loyaltyTierChecker,
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyTierChecker = $loyaltyTierChecker;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Apply free shipping for qualifying loyalty tiers
     * Intercepts shipping rate results and applies free shipping
     *
     * @param CarrierResult $subject
     * @param CarrierResult $result
     * @return CarrierResult
     */
    public function afterGetResult(CarrierResult $subject, CarrierResult $result)
    {
        try {
            // Early exit if features disabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isFreeShippingEnabled()) {
                return $result;
            }

            // Check if customer qualifies for free shipping
            if (!$this->loyaltyTierChecker->qualifiesForFreeShipping()) {
                $this->loyaltyHelper->log(
                    'debug',
                    'LoyaltyShop',
                    'FreeShippingNotQualified',
                    'Customer does not qualify for free shipping.'
                );
                return $result;
            }

            // Apply free shipping to all methods
            $this->applyFreeShippingToRates($result);

            $this->loyaltyHelper->log(
                'info',
                'LoyaltyShop',
                'FreeShippingApplied',
                'Free shipping applied to all available shipping methods.'
            );

        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                'LoyaltyShop',
                'FreeShippingError',
                'Error applying free shipping.',
                [
                    'error_message' => $e->getMessage()
                ]
            );
        }

        return $result;
    }

    /**
     * Apply free shipping to all shipping rates
     *
     * @param CarrierResult $result
     * @return void
     */
    private function applyFreeShippingToRates(CarrierResult $result): void
    {
        $modifiedRates = [];

        foreach ($result->getAllRates() as $rate) {
            if ($rate instanceof ShippingMethod) {
                $rate->setPrice(0);
                $rate->setCost(0);

                $modifiedRates[] = [
                    'carrier' => $rate->getCarrier(),
                    'method' => $rate->getMethod()
                ];
            }
        }

        if (!empty($modifiedRates)) {
            $this->loyaltyHelper->log(
                'debug',
                'LoyaltyShop',
                'FreeShippingRatesModified',
                'Shipping rates updated to free.',
                [
                    'rates' => $modifiedRates,
                    'count' => count($modifiedRates)
                ]
            );
        }
    }
}