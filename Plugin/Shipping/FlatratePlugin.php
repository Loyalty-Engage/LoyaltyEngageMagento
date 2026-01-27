<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin\Shipping;

use Magento\OfflineShipping\Model\Carrier\Flatrate;
use Magento\Quote\Model\Quote\Address\RateRequest;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class FlatratePlugin
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
     * Apply free shipping to flat rate shipping
     *
     * @param Flatrate $subject
     * @param \Magento\Shipping\Model\Rate\Result $result
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result
     */
    public function afterCollectRates(Flatrate $subject, $result, RateRequest $request)
    {
        // Debug logging
        error_log('[LOYALTY-DEBUG] FlatratePlugin::afterCollectRates called');
        
        try {
            // Early exit if features disabled
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isFreeShippingEnabled()) {
                error_log('[LOYALTY-DEBUG] Flatrate: Free shipping disabled, skipping');
                return $result;
            }

            error_log('[LOYALTY-DEBUG] Flatrate: Free shipping enabled, checking customer qualification');

            // Check if customer qualifies for free shipping
            if (!$this->loyaltyTierChecker->qualifiesForFreeShipping()) {
                error_log('[LOYALTY-DEBUG] Flatrate: Customer does not qualify for free shipping');
                return $result;
            }

            error_log('[LOYALTY-DEBUG] Flatrate: Customer qualifies for free shipping, applying to rates');

            // Apply free shipping to all rates in the result
            if ($result && $result->getAllRates()) {
                foreach ($result->getAllRates() as $rate) {
                    $originalPrice = $rate->getPrice();
                    $rate->setPrice(0);
                    $rate->setCost(0);
                    
                    error_log(sprintf(
                        '[LOYALTY-DEBUG] Flatrate: Free shipping applied - Method: %s, Original Price: $%.2f, New Price: $0.00',
                        $rate->getMethod(),
                        $originalPrice
                    ));
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-SHIPPING] Error in flatrate plugin: ' . $e->getMessage());
            error_log('[LOYALTY-DEBUG] Flatrate: Error applying free shipping: ' . $e->getMessage());
        }

        return $result;
    }
}
