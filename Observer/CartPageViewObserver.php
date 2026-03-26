<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Logger;
use Magento\Customer\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CartPageViewObserver implements ObserverInterface
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
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @param Logger $loyaltyLogger
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        Logger $loyaltyLogger,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        protected LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Execute observer when cart page is viewed
     * Note: Logging is now minimal and only enabled when debug mode is on
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
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
            if ($this->loyaltyLogger->isDebugEnabled()) {
                $this->logCartSummary($quote, $items);
            }

        } catch (\Exception $e) {
            $this->loyaltyLogger->error(
                Logger::COMPONENT_CART,
                Logger::ACTION_ERROR,
                'Exception in CartPageViewObserver: ' . $e->getMessage()
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
            if ($this->isLoyaltyProduct($item)) {
                $loyaltyCount++;
            }
        }

        // Only log if there are loyalty items (reduces noise)
        if ($loyaltyCount > 0) {
            $this->loyaltyLogger->debug(
                Logger::COMPONENT_CART,
                Logger::ACTION_REGULAR,
                sprintf(
                    'Cart viewed - %d items (%d loyalty) for %s',
                    $totalItems,
                    $loyaltyCount,
                    $this->getMaskedCustomerEmail()
                ),
                [
                    'quote_id' => $quote->getId(),
                    'loyalty_items' => $loyaltyCount
                ]
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

    /**
     * Check if quote item is a loyalty product
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProduct($item): bool
    {
        // Method 1: Check for loyalty_locked_qty option
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() == '1') {
            return true;
        }

        // Method 2: Check item data directly
        $loyaltyData = $item->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options
        $additionalOptions = $item->getOptionByCode('additional_options');
        if ($additionalOptions) {
            $value = $this->safeUnserialize($additionalOptions->getValue());
            if (is_array($value)) {
                foreach ($value as $option) {
                    if (
                        isset($option['label']) && $option['label'] === 'loyalty_locked_qty' &&
                        isset($option['value']) && $option['value'] === '1'
                    ) {
                        return true;
                    }
                }
            }
        }

        // Method 4: Check product data (universal fallback)
        $product = $item->getProduct();
        if ($product && $product->getData('loyalty_locked_qty')) {
            return true;
        }

        return false;
    }

    /**
     * Safely unserialize data with JSON fallback
     * Prevents PHP object injection vulnerabilities
     *
     * @param string|null $data
     * @return array|null
     */
    private function safeUnserialize(?string $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        $jsonResult = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonResult)) {
            return $jsonResult;
        }

        try {
            $result = @unserialize($data, ['allowed_classes' => false]);
            return is_array($result) ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
