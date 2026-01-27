<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin\Quote;

use Magento\Quote\Model\Quote\Address;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class AddressPlugin
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
     * Apply free shipping after collecting shipping rates
     *
     * @param Address $subject
     * @param Address $result
     * @return Address
     */
    public function afterCollectShippingRates(Address $subject, Address $result)
    {
        // Temporary debug - force log to system log
        error_log('[LOYALTY-DEBUG] AddressPlugin::afterCollectShippingRates called');
        
        try {
            // Early exit if features disabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isFreeShippingEnabled()) {
                error_log('[LOYALTY-DEBUG] Free shipping disabled, skipping');
                return $result;
            }

            error_log('[LOYALTY-DEBUG] Free shipping enabled, checking customer qualification');

            // Check if customer qualifies for free shipping
            if (!$this->loyaltyTierChecker->qualifiesForFreeShipping()) {
                error_log('[LOYALTY-DEBUG] Customer does not qualify for free shipping');
                return $result;
            }

            error_log('[LOYALTY-DEBUG] Customer qualifies for free shipping, applying to rates');

            // Apply free shipping to all shipping rates
            $this->applyFreeShippingToRates($result);

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-SHIPPING] Error applying free shipping: ' . $e->getMessage());
            error_log('[LOYALTY-DEBUG] Error applying free shipping: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Apply free shipping to all shipping rates in the address
     *
     * @param Address $address
     * @return void
     */
    private function applyFreeShippingToRates(Address $address): void
    {
        $shippingRatesCollection = $address->getShippingRatesCollection();
        $freeShippingApplied = false;

        error_log('[LOYALTY-DEBUG] Processing shipping rates collection');

        foreach ($shippingRatesCollection as $rate) {
            $originalPrice = $rate->getPrice();
            
            // Apply free shipping - set price to 0 but keep original method
            $rate->setPrice(0);
            $rate->setCost(0);
            
            $freeShippingApplied = true;

            error_log(sprintf(
                '[LOYALTY-DEBUG] Free shipping applied - Method: %s_%s, Original Price: $%.2f, New Price: $0.00',
                $rate->getCarrier(),
                $rate->getMethod(),
                $originalPrice
            ));
        }

        if ($freeShippingApplied) {
            error_log('[LOYALTY-DEBUG] Loyalty tier free shipping applied to all existing methods');
        } else {
            error_log('[LOYALTY-DEBUG] No shipping methods found to apply free shipping');
        }
    }
}
