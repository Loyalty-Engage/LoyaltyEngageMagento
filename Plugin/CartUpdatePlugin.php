<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Controller\Cart\UpdatePost;
use Magento\Framework\App\RequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

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
     * CartUpdatePlugin constructor
     *
     * @param RequestInterface $request
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        LoyaltyHelper $loyaltyHelper,
        LoyaltyLogger $loyaltyLogger
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->loyaltyHelper = $loyaltyHelper;
        $this->loyaltyLogger = $loyaltyLogger;
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

                    $this->loyaltyLogger->debug(
                        LoyaltyLogger::COMPONENT_PLUGIN,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf(
                            'Loyalty product quantity enforced: %s (SKU: %s) - Requested %s, enforced 1',
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

        // Not a confirmed loyalty product
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
