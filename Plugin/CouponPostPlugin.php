<?php

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Framework\App\RequestInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CouponPostPlugin
{
    protected CheckoutSession $checkoutSession;
    protected LoyaltyengageCart $loyaltyCart;
    protected LoyaltyHelper $helper;

    public function __construct(
        CheckoutSession $checkoutSession,
        LoyaltyengageCart $loyaltyCart,
        LoyaltyHelper $helper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->loyaltyCart     = $loyaltyCart;
        $this->helper          = $helper;
    }

    /**
     * After plugin for coupon apply/remove
     */
    public function afterExecute(CouponPost $subject, $result)
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote) {
                return $result;
            }

            $couponCode = $quote->getCouponCode();
            $customerEmail = $quote->getCustomerEmail() ?? '';
            $customerEmailhash = hash('sha256', $customerEmail);

            // 🔹 Log request hit
            $this->helper->log(
                'info',
                'PLUGIN',
                'COUPON_POST_HIT',
                'CouponPost controller executed',
                ['coupon' => $couponCode]
            );

            if (empty($couponCode)) {
                $this->helper->log(
                    'info',
                    'PLUGIN',
                    'COUPON_REMOVED',
                    'Coupon removed by user'
                );
                return $result;
            }

            $subtotal = (float)$quote->getSubtotal();
            $subtotalWithDiscount = (float)$quote->getSubtotalWithDiscount();
            $discountAmount = abs($subtotal - $subtotalWithDiscount);
            $currency = $quote->getQuoteCurrencyCode();

            $this->helper->log(
                'info',
                'PLUGIN',
                'COUPON_APPLIED',
                'Coupon applied',
                [
                    'coupon'   => $couponCode,
                    'amount'   => $discountAmount,
                    'currency' => $currency
                ]
            );

            $this->loyaltyCart->claimDiscount(
                $customerEmailhash,
                $discountAmount,
                $currency
            );

        } catch (\Exception $e) {

            $this->helper->log(
                'critical',
                'PLUGIN',
                'COUPON_PLUGIN_ERROR',
                'Error in CouponPost plugin',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }
}