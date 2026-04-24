<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

class QuoteItemQtyValidatorPlugin
{
    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * QuoteItemQtyValidatorPlugin construct
     *
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Enforce quantity = 1 for loyalty products (before validation)
     *
     * @param QuantityValidator $subject
     * @param Observer $observer
     * @return void
     */
    public function beforeValidate(QuantityValidator $subject, Observer $observer)
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $quoteItem = $observer->getEvent()->getItem();

        if (!$quoteItem) {
            return;
        }

        // ONLY process if this is a 100% confirmed loyalty product
        if (!$this->loyaltyHelper->isLoyaltyProduct($quoteItem, true)) {
            return; // Leave regular products completely untouched
        }

        $currentQty = $quoteItem->getQty();

        if ($currentQty != 1) {
            $quoteItem->setQty(1);

            $this->loyaltyHelper->log(
                "debug",
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_VALIDATION,
                'Loyalty product quantity enforced',
                [
                    'product_name' => $quoteItem->getName(),
                    'sku' => $quoteItem->getSku(),
                    'old_qty' => $currentQty,
                    'new_qty' => 1
                ]
            );
        }
    }
}
