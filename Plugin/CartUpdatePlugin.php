<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Controller\Cart\UpdatePost;
use Magento\Framework\App\RequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;
use LoyaltyEngage\LoyaltyShop\Logger\Logger as LoyaltyLogger;

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
     * CartUpdatePlugin constructor
     *
     * @param RequestInterface $request
     * @param CheckoutSession $checkoutSession
     * @param LoyaltyHelper $loyaltyHelper
     */
    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        LoyaltyHelper $loyaltyHelper
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->loyaltyHelper = $loyaltyHelper;
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

        $this->loyaltyHelper->log(
            'info',
            LoyaltyLogger::COMPONENT_PLUGIN,
            LoyaltyLogger::ACTION_LOYALTY,
            'Cart update plugin triggered - processing cart data'
        );

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
            if ($this->loyaltyHelper->isLoyaltyProduct($quoteItem, true)) {
                if ($requestedQty != 1) {
                    // Override the requested quantity to 1
                    $cartData[$itemId]['qty'] = 1;
                    $updatesEnforced++;

                    $this->loyaltyHelper->log(
                        'info',
                        LoyaltyLogger::COMPONENT_PLUGIN,
                        LoyaltyLogger::ACTION_LOYALTY,
                        sprintf(
                            'Cart update - Loyalty product quantity enforced: %s (SKU: %s) - Requested %s, enforced 1',
                            $quoteItem->getName(),
                            $quoteItem->getSku(),
                            $requestedQty
                        ),
                        [
                            'sku' => $quoteItem->getSku(),
                            'name' => $quoteItem->getName(),
                            'requested_qty' => $requestedQty,
                            'enforced_qty' => 1
                        ]
                    );
                }
            }
        }

        if ($updatesEnforced > 0) {
            // Update the request with the enforced quantities
            $this->request->setParam('cart', $cartData);

            $this->loyaltyHelper->log(
                'info',
                LoyaltyLogger::COMPONENT_PLUGIN,
                LoyaltyLogger::ACTION_LOYALTY,
                sprintf(
                    'Cart update plugin complete - %d loyalty product quantities enforced',
                    $updatesEnforced
                ),
                ['enforced_count' => $updatesEnforced]
            );
        }
    }
}