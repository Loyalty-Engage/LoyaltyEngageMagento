<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin\Shipping;

use Magento\OfflineShipping\Model\Carrier\Tablerate;
use Magento\Quote\Model\Quote\Address\RateRequest;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyTierChecker;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Psr\Log\LoggerInterface;

class TableratePlugin
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
     * Apply free shipping to table rate shipping
     *
     * @param Tablerate $subject
     * @param \Magento\Shipping\Model\Rate\Result $result
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result
     */
    public function afterCollectRates(Tablerate $subject, $result, RateRequest $request)
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

            // Apply free shipping to all rates in the result
            if ($result && $result->getAllRates()) {
                foreach ($result->getAllRates() as $rate) {
                    $originalPrice = $rate->getPrice();
                    $rate->setPrice(0);
                    $rate->setCost(0);
                    
                    $this->logger->debug(sprintf(
                        '[LOYALTY-SHIPPING] Tablerate: Free shipping applied - Method: %s, Original Price: $%.2f',
                        $rate->getMethod(),
                        $originalPrice
                    ));
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[LOYALTY-SHIPPING] Error in tablerate plugin: ' . $e->getMessage());
        }

        return $result;
    }
}
