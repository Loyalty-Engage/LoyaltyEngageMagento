<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Logger\Logger;
use Magento\Customer\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartProductAddObserver implements ObserverInterface
{
    /**
     * @var Logger
     */
    private $loyaltyLogger;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Logger $loyaltyLogger
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        Logger $loyaltyLogger,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        private LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
    }

    /**
     * Execute observer
     * Note: Logging is now minimal and uses masked emails for privacy
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        // Only log if logging is enabled
        if (!$this->loyaltyLogger->isLoggingEnabled()) {
            return;
        }

        try {
            $quoteItem = $observer->getEvent()->getQuoteItem();
            $product = $observer->getEvent()->getProduct();

            if (!$quoteItem || !$product) {
                return;
            }

            // Only log loyalty product additions (reduces noise significantly)
            if ($this->loyaltyHelper->isLoyaltyProduct($quoteItem)) {
                $customerEmail = $this->getMaskedCustomerEmail();

                $this->loyaltyHelper->log(
                    'info',
                    Logger::COMPONENT_CART_ADD,
                    Logger::ACTION_LOYALTY,
                    sprintf(
                        'Loyalty product added to cart: %s for %s',
                        $product->getSku(),
                        $customerEmail
                    ),
                    [
                        'product_name' => $product->getName(),
                        'sku'          => $product->getSku(),
                        'qty'          => $quoteItem->getQty(),
                        'source'       => 'loyalty-api'
                    ]
                );
            }

        } catch (\Exception $e) {
            $this->loyaltyHelper->log(
                'error',
                Logger::COMPONENT_OBSERVER,
                Logger::ACTION_ERROR,
                'Exception in CartProductAddObserver: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get masked customer email for privacy
     *
     * @return string
     */
    private function getMaskedCustomerEmail(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->loyaltyLogger->maskEmail(
                $this->customerSession->getCustomer()->getEmail()
            );
        }
        return 'guest';
    }
}
