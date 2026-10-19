<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Psr\Log\LoggerInterface;

class CaptureTierPriceExclusionPlugin
{
    public function __construct(private Data $helper, private AppliedTierPrice $tierPrice, private LoggerInterface $logger)
    {
    }

    public function afterConvert(ToOrderItem $subject, $result, $item, $data = [])
    {
        $options = $result->getProductOptions() ?: [];
        // Never inherit an old order's export decision through reorder/buy-request data.
        unset($options[AppliedTierPrice::EXCLUDED_OPTION]);
        $storeId = (int) $item->getQuote()->getStoreId();
        if ($this->helper->isLoyaltyEngageEnabled($storeId)
            && $this->helper->isTierPricePurchaseExclusionEnabled($storeId)) {
            try {
                $options[AppliedTierPrice::EXCLUDED_OPTION] = (int) $this->tierPrice->isApplied($item);
            } catch (\Throwable $e) {
                // A third-party pricing failure must not break checkout or guess an exclusion.
                $this->logger->warning('Unable to determine Loyalty tier-price exclusion.', [
                    'store_id' => $storeId, 'product_id' => $item->getProductId(), 'error' => $e->getMessage(),
                ]);
            }
        }
        $result->setProductOptions($options);
        return $result;
    }
}
