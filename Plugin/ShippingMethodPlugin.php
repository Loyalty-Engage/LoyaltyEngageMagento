<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\Address\RateResult\Method as ShippingMethod;
use Magento\Shipping\Model\Rate\CarrierResult;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoyaltyTierChecker $loyaltyTierChecker
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoyaltyTierChecker $loyaltyTierChecker,
        LoyaltyHelper $loyaltyHelper,
        LoggerInterface $logger
    ) {
        $this->loyaltyTierChecker = $loyaltyTierChecker;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->logger = $logger;
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
                return $result;
            }

            // Apply free shipping to all methods
            $this->applyFreeShippingToRates($result);

        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Error applying free shipping: ' . $e->getMessage());
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
        foreach ($result->getAllRates() as $rate) {
            if ($rate instanceof ShippingMethod) {
                $rate->setPrice(0);
                $rate->setCost(0);
            }
        }
    }
}
