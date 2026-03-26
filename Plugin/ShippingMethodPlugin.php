<?php
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
                $this->logger->debug('[LOYALTY-SHIPPING] Free shipping disabled, skipping');
                return $result;
            }

            // Check if customer qualifies for free shipping
            if (!$this->loyaltyTierChecker->qualifiesForFreeShipping()) {
                $this->logger->debug('[LOYALTY-SHIPPING] Customer does not qualify for free shipping');
                return $result;
            }

            $this->logger->debug('[LOYALTY-SHIPPING] Customer qualifies for free shipping, applying to rates');

            // Apply free shipping to all methods
            $this->applyFreeShippingToRates($result);

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-SHIPPING] Error applying free shipping: ' . $e->getMessage());
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
        $rates = $result->getAllRates();
        $freeShippingApplied = false;

        $this->logger->debug('[LOYALTY-SHIPPING] Processing ' . count($rates) . ' shipping rates');

        foreach ($rates as $rate) {
            if ($rate instanceof ShippingMethod) {
                $originalPrice = $rate->getPrice();
                
                // Apply free shipping - set price to 0 but keep original method
                $rate->setPrice(0);
                $rate->setCost(0);
                
                // Don't modify the method title - keep original shipping method names
                // This ensures existing shipping options remain unchanged except for price

                $freeShippingApplied = true;

                $this->logger->debug(sprintf(
                    '[LOYALTY-SHIPPING] Free shipping applied - Method: %s_%s, Original Price: $%.2f, New Price: $0.00',
                    $rate->getCarrier(),
                    $rate->getMethod(),
                    $originalPrice
                ));
            }
        }

        if ($freeShippingApplied) {
            $this->logger->info('[LOYALTY-SHIPPING] Loyalty tier free shipping applied to all existing methods');
        } else {
            $this->logger->warning('[LOYALTY-SHIPPING] No shipping methods found to apply free shipping');
        }
    }
}
