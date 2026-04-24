<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

class CartUpdateObserver implements ObserverInterface
{
    /**
     * @var LoyaltyHelper
     */
    private LoyaltyHelper $loyaltyHelper;

    /**
     * Constructor
     *
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(LoyaltyHelper $loyaltyHelper)
    {
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Execute observer - enforce loyalty product quantities
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        // Get the cart/quote from the observer
        $cart = $observer->getEvent()->getCart();
        if (!$cart) {
            return;
        }

        $quote = $cart->getQuote();
        if (!$quote) {
            return;
        }

        $itemsUpdated = 0;
        foreach ($quote->getAllItems() as $item) {
            if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
                $currentQty = $item->getQty();
                if ($currentQty != 1) {
                    $item->setQty(1);
                    $itemsUpdated++;

                    $this->loyaltyHelper->log(
                        'info',
                        LoyaltyLogger::COMPONENT_OBSERVER,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf(
                            'Cart update - Loyalty product quantity enforced: %s (SKU: %s) - Changed from %s to 1',
                            $item->getName(),
                            $item->getSku(),
                            $currentQty
                        ),
                        ['quote_item_id' => $item->getId()]
                    );
                }
            }
        }

        if ($itemsUpdated > 0) {
            $this->loyaltyHelper->log(
                'info',
                LoyaltyLogger::COMPONENT_OBSERVER,
                LoyaltyLogger::ACTION_REGULAR,
                sprintf('Cart update complete - %d loyalty product quantities enforced', $itemsUpdated),
                ['quote_id' => $quote->getId()]
            );
        }
    }
}