<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartItemHelper implements ArgumentInterface
{
    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Check if the item quantity should be locked (loyalty product)
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    public function isQtyLocked($item): bool
    {
        $isLocked = $this->loyaltyHelper->isLoyaltyProduct($item, true);

        if ($isLocked) {
            $this->loyaltyHelper->log(
                "debug",
                LoyaltyLogger::COMPONENT_VIEWMODEL,
                LoyaltyLogger::ACTION_LOYALTY,
                'Loyalty product detected in cart: %s (SKU: %s)', $item->getName(), $item->getSku()
            );
        }

        return $isLocked;
    }
}
