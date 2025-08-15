<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Controller\Cart\UpdatePost;
use Magento\Framework\App\RequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Psr\Log\LoggerInterface;

class CartUpdatePlugin
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var LoyaltyHelper
     */
    private $loyaltyHelper;

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * CartUpdatePlugin constructor
     *
     * @param RequestInterface $request
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
        $this->logger = $logger;
    }

    /**
     * Before cart update - enforce loyalty product quantities
     *
     * @param UpdatePost $subject
     * @return void
     */
    public function beforeExecute(UpdatePost $subject)
    {
        // Early exit if module is disabled
        if (!$this->loyaltyHelper->isLoyaltyEngageEnabled()) {
            return;
        }

        $cartData = $this->request->getParam('cart');
        if (!$cartData || !is_array($cartData)) {
            return;
        }

        $quote = $this->checkoutSession->getQuote();
        if (!$quote) {
            return;
        }

        $this->logger->info('[LOYALTY-CART] Cart update plugin triggered - processing cart data');

        $updatesEnforced = 0;
        
        // Process each item in the cart update request
        foreach ($cartData as $itemId => $itemData) {
            if (!isset($itemData['qty'])) {
                continue;
            }

            $requestedQty = (float)$itemData['qty'];
            $quoteItem = $quote->getItemById($itemId);
            
            if (!$quoteItem) {
                continue;
            }

            // Check if this is a loyalty product
            if ($this->isConfirmedLoyaltyProduct($quoteItem)) {
                if ($requestedQty != 1) {
                    // Override the requested quantity to 1
                    $cartData[$itemId]['qty'] = 1;
                    $updatesEnforced++;

                    $this->logger->info(sprintf(
                        '[LOYALTY-CART] Cart update plugin: Enforced qty=1 for loyalty product %s (SKU: %s) - requested qty was %s',
                        $quoteItem->getName(),
                        $quoteItem->getSku(),
                        $requestedQty
                    ));

                    $this->loyaltyLogger->info(
                        LoyaltyLogger::COMPONENT_PLUGIN,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf(
                            'Cart update - Loyalty product quantity enforced: %s (SKU: %s) - Requested %s, enforced 1',
                            $quoteItem->getName(),
                            $quoteItem->getSku(),
                            $requestedQty
                        )
                    );
                }
            }
        }

        if ($updatesEnforced > 0) {
            // Update the request with the enforced quantities
            $this->request->setParam('cart', $cartData);
            
            $this->logger->info(sprintf(
                '[LOYALTY-CART] Cart update plugin complete - %d loyalty product quantities enforced',
                $updatesEnforced
            ));
        }
    }

    /**
     * Reliable loyalty product detection - only confirmed loyalty products
     *
     * @param \Magento\Quote\Model\Quote\Item $quoteItem
     * @return bool
     */
    private function isConfirmedLoyaltyProduct($quoteItem): bool
    {
        // Method 1: Check for explicit loyalty_locked_qty option (most reliable)
        $loyaltyOption = $quoteItem->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }

        // Method 2: Check item data directly (secondary check)
        $loyaltyData = $quoteItem->getData('loyalty_locked_qty');
        if ($loyaltyData === '1' || $loyaltyData === 1) {
            return true;
        }

        // Method 3: Check additional_options for loyalty flag
        $additionalOptions = $quoteItem->getOptionByCode('additional_options');
        if ($additionalOptions) {
            try {
                $value = unserialize($additionalOptions->getValue());
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
            } catch (\Exception $e) {
                // Silently handle unserialize errors - not a loyalty product
            }
        }

        // Not a confirmed loyalty product
        return false;
    }
}
