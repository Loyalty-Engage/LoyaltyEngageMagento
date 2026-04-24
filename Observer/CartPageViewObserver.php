<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

class CartPageViewObserver implements ObserverInterface
{
    /**
     * @var LoyaltyHelper
     */
    private LoyaltyHelper $loyaltyHelper;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * Constructor
     *
     * @param StoreManagerInterface $storeManager
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->loyaltyHelper = $loyaltyHelper;
    }

    /**
     * Execute observer when cart page is viewed
     * Note: Logging is now minimal and only enabled when debug mode is on
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        try {
            // Only process if this is the cart page
            $fullActionName = $observer->getEvent()->getFullActionName();
            if ($fullActionName !== 'checkout_cart_index') {
                return;
            }

            // Get the quote from checkout session
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                return;
            }

            // Skip if no items in cart
            $items = $quote->getAllVisibleItems();
            if (empty($items)) {
                return;
            }

            // Only log if debug is enabled - significantly reduces log volume
            if ($this->loyaltyHelper->isDebugEnabled()) {
                $this->logCartSummary($quote, $items);
            }
        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                LoyaltyLogger::COMPONENT_OBSERVER,
                LoyaltyLogger::ACTION_ERROR,
                'Exception in CartPageViewObserver: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Log a brief cart summary (only when debug is enabled)
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array $items
     */
    private function logCartSummary($quote, $items): void
    {
        $loyaltyCount = 0;
        $totalItems = count($items);

        foreach ($items as $item) {
            if ($this->loyaltyHelper->isLoyaltyProduct($item)) {
                $loyaltyCount++;
            }
        }

        if ($loyaltyCount > 0) {
            $maskedEmail = $this->loyaltyHelper->getMaskedCustomerEmail();

            $this->loyaltyHelper->log(
                'debug',
                LoyaltyLogger::COMPONENT_CART,
                LoyaltyLogger::ACTION_REGULAR,
                sprintf(
                    'Cart viewed - %d items (%d loyalty) for %s',
                    $totalItems,
                    $loyaltyCount,
                    $maskedEmail
                ),
                [
                    'quote_id' => $quote->getId(),
                    'loyalty_items' => $loyaltyCount
                ]
            );

            $this->loyaltyHelper->log(
                'info',
                LoyaltyLogger::COMPONENT_CART,
                LoyaltyLogger::ACTION_SUCCESS,
                sprintf(
                    'Cart processed successfully - %d loyalty items detected for %s',
                    $loyaltyCount,
                    $maskedEmail
                ),
                [
                    'quote_id' => $quote->getId(),
                    'total_items' => $totalItems,
                    'loyalty_items' => $loyaltyCount
                ]
            );
        }
    }
}