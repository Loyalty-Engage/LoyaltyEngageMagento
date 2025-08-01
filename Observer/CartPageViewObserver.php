<?php
namespace LoyaltyEngage\LoyaltyShop\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use LoyaltyEngage\LoyaltyShop\Helper\Logger;
use LoyaltyEngage\LoyaltyShop\Helper\EnterpriseDetection;
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
     * @var EnterpriseDetection
     */
    private $enterpriseDetection;

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
     * @param EnterpriseDetection $enterpriseDetection
     * @param Session $customerSession
     * @param StoreManagerInterface $storeManager
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        Logger $loyaltyLogger,
        EnterpriseDetection $enterpriseDetection,
        Session $customerSession,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        protected LoyaltyHelper $loyaltyHelper
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->enterpriseDetection = $enterpriseDetection;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Execute observer when cart page is viewed
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if ($this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            try {
                // Only process if this is the cart page
                $fullActionName = $observer->getEvent()->getFullActionName();
                if ($fullActionName !== 'checkout_cart_index') {
                    return;
                }

                // Get the quote from checkout session
                $quote = $this->checkoutSession->getQuote();

                if (!$quote || !$quote->getId()) {
                    $this->loyaltyLogger->info(
                        Logger::COMPONENT_CART,
                        Logger::ACTION_REGULAR,
                        'Cart page viewed - No active quote found',
                        ['customer_email' => $this->getCustomerEmail()]
                    );
                    return;
                }

                // Skip if no items in cart
                $items = $quote->getAllVisibleItems();
                if (empty($items)) {
                    $this->loyaltyLogger->info(
                        Logger::COMPONENT_CART,
                        Logger::ACTION_REGULAR,
                        'Cart page viewed - Empty cart',
                        [
                            'customer_email' => $this->getCustomerEmail(),
                            'quote_id' => $quote->getId()
                        ]
                    );
                    return;
                }

                // Log environment context
                $this->logEnvironmentContext();

                // Log cart overview
                $this->logCartOverview($quote, $items);

                // Log each item in detail
                foreach ($items as $item) {
                    $this->logCartItem($item);
                }

                // Log cart totals
                $this->logCartTotals($quote);

            } catch (\Exception $e) {
                $this->loyaltyLogger->error(
                    Logger::COMPONENT_CART,
                    Logger::ACTION_ERROR,
                    'Exception in CartPageViewObserver: ' . $e->getMessage(),
                    [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                );
            }
        }
    }

    /**
     * Log environment context
     */
    private function logEnvironmentContext(): void
    {
        static $logged = false;
        if (!$logged) {
            $environmentData = [
                'is_enterprise' => $this->enterpriseDetection->isEnterpriseEdition(),
                'is_b2b' => $this->enterpriseDetection->isB2BEnabled(),
                'store_code' => $this->storeManager->getStore()->getCode(),
                'store_id' => $this->storeManager->getStore()->getId(),
                'website_id' => $this->storeManager->getStore()->getWebsiteId()
            ];

            $this->loyaltyLogger->logEnvironmentContext($environmentData);
            $logged = true;
        }
    }

    /**
     * Log cart overview
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param array $items
     */
    private function logCartOverview($quote, $items): void
    {
        $loyaltyCount = 0;
        $regularCount = 0;
        $totalItems = count($items);

        foreach ($items as $item) {
            if ($this->isLoyaltyProduct($item)) {
                $loyaltyCount++;
            } else {
                $regularCount++;
            }
        }

        $this->loyaltyLogger->info(
            Logger::COMPONENT_CART,
            Logger::ACTION_REGULAR,
            sprintf(
                'Cart page viewed - %d total items (%d loyalty, %d regular)',
                $totalItems,
                $loyaltyCount,
                $regularCount
            ),
            [
                'customer_email' => $this->getCustomerEmail(),
                'quote_id' => $quote->getId(),
                'total_items' => $totalItems,
                'loyalty_items' => $loyaltyCount,
                'regular_items' => $regularCount,
                'grand_total' => $quote->getGrandTotal(),
                'subtotal' => $quote->getSubtotal()
            ]
        );
    }

    /**
     * Log individual cart item details
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    private function logCartItem($item): void
    {
        $isLoyalty = $this->isLoyaltyProduct($item);
        $productType = $isLoyalty ? Logger::ACTION_LOYALTY : Logger::ACTION_REGULAR;

        $itemData = [
            'item_id' => $item->getId(),
            'product_id' => $item->getProductId(),
            'product_name' => $item->getName(),
            'sku' => $item->getSku(),
            'qty' => $item->getQty(),
            'price' => $item->getPrice(),
            'custom_price' => $item->getCustomPrice(),
            'original_custom_price' => $item->getOriginalCustomPrice(),
            'row_total' => $item->getRowTotal(),
            'row_total_incl_tax' => $item->getRowTotalInclTax(),
            'is_loyalty' => $isLoyalty,
            'detection_methods' => $this->getDetectionMethods($item)
        ];

        $this->loyaltyLogger->info(
            Logger::COMPONENT_CART,
            $productType,
            sprintf(
                'Cart item: %s (%s) - Qty: %s, Price: $%.2f, Type: %s',
                $item->getName(),
                $item->getSku(),
                $item->getQty(),
                $item->getPrice(),
                $isLoyalty ? 'LOYALTY' : 'REGULAR'
            ),
            $itemData
        );

        // Log additional details for loyalty products
        if ($isLoyalty) {
            $this->logLoyaltyItemDetails($item);
        }
    }

    /**
     * Log cart totals
     *
     * @param \Magento\Quote\Model\Quote $quote
     */
    private function logCartTotals($quote): void
    {
        $totalsData = [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_incl_tax' => $quote->getSubtotalInclTax(),
            'grand_total' => $quote->getGrandTotal(),
            'base_grand_total' => $quote->getBaseGrandTotal(),
            'items_count' => $quote->getItemsCount(),
            'items_qty' => $quote->getItemsQty(),
            'coupon_code' => $quote->getCouponCode(),
            'applied_rule_ids' => $quote->getAppliedRuleIds()
        ];

        $this->loyaltyLogger->info(
            Logger::COMPONENT_CART,
            Logger::ACTION_REGULAR,
            sprintf(
                'Cart totals - Subtotal: $%.2f, Grand Total: $%.2f, Items: %d',
                $quote->getSubtotal(),
                $quote->getGrandTotal(),
                $quote->getItemsCount()
            ),
            $totalsData
        );
    }

    /**
     * Get customer email
     *
     * @return string
     */
    private function getCustomerEmail(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->customerSession->getCustomer()->getEmail();
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
            $value = @unserialize($additionalOptions->getValue());
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

        // Method 4: Check product data (Enterprise fallback)
        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $item->getProduct();
            if ($product && $product->getData('loyalty_locked_qty')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get detection methods used for loyalty identification
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return array
     */
    private function getDetectionMethods($item): array
    {
        $methods = [];

        // Check each detection method
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        $methods['option_check'] = $loyaltyOption ? $loyaltyOption->getValue() : 'not_found';

        $loyaltyData = $item->getData('loyalty_locked_qty');
        $methods['data_check'] = $loyaltyData ?? 'not_found';

        $additionalOptions = $item->getOptionByCode('additional_options');
        $methods['additional_options'] = $additionalOptions ? 'found' : 'not_found';

        if ($this->enterpriseDetection->isEnterpriseEdition()) {
            $product = $item->getProduct();
            $methods['enterprise_fallback'] = ($product && $product->getData('loyalty_locked_qty')) ? 'found' : 'not_found';
        }

        return $methods;
    }

    /**
     * Log additional details for loyalty items
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    private function logLoyaltyItemDetails($item): void
    {
        $options = [];
        foreach ($item->getOptions() as $option) {
            $options[$option->getCode()] = substr($option->getValue(), 0, 100); // Limit length
        }

        $details = [
            'all_options' => $options,
            'product_type' => $item->getProductType(),
            'has_children' => $item->getHasChildren(),
            'parent_item_id' => $item->getParentItemId(),
            'free_shipping' => $item->getFreeShipping(),
            'discount_amount' => $item->getDiscountAmount(),
            'base_discount_amount' => $item->getBaseDiscountAmount()
        ];

        $this->loyaltyLogger->info(
            Logger::COMPONENT_CART,
            Logger::ACTION_LOYALTY,
            sprintf(
                'Loyalty item details for %s: %d options found',
                $item->getSku(),
                count($options)
            ),
            $details
        );
    }
}
