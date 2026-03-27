<?php

declare(strict_types=1);

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
        try {
            if (!$this->loyaltyHelper->isLoyaltyEngageEnabled() || !$this->loyaltyHelper->isFreeShippingEnabled()) {
                return $result;
            }

            if (!$this->loyaltyTierChecker->qualifiesForFreeShipping()) {
                return $result;
            }

            if ($result && $result->getAllRates()) {
                foreach ($result->getAllRates() as $rate) {
                    $rate->setPrice(0);
                    $rate->setCost(0);
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('[LoyaltyShop] Error in flatrate plugin: ' . $e->getMessage());
        }

        return $result;
    }
}
