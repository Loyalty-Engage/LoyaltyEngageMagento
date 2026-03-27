<?php

declare(strict_types=1);

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
            $this->logger->error('[LoyaltyShop] Error in tablerate plugin: ' . $e->getMessage());
        }

        return $result;
    }
}
