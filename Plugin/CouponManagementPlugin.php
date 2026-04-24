<?php

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\CouponManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use LoyaltyEngage\LoyaltyShop\Model\LoyaltyengageCart;
use LoyaltyEngage\LoyaltyShop\Helper\Data as LoyaltyHelper;

class CouponManagementPlugin
{
    protected CartRepositoryInterface $cartRepository;
    protected LoyaltyengageCart $loyaltyCart;
    protected LoyaltyHelper $helper;

    public function __construct(
        CartRepositoryInterface $cartRepository,
        LoyaltyengageCart $loyaltyCart,
        LoyaltyHelper $helper
    ) {
        $this->cartRepository = $cartRepository;
        $this->loyaltyCart    = $loyaltyCart;
        $this->helper         = $helper;
    }

    /**
     * After plugin for API coupon apply
     */
    public function afterSet(CouponManagement $subject, $result, $cartId, $couponCode)
    {
        try {
            $quote = $this->cartRepository->getActive($cartId);

            if (!$quote) {
                return $result;
            }

            $customerEmail = $quote->getCustomerEmail() ?? '';
            $customerEmailhash = hash('sha256', $customerEmail);

            $subtotal = (float)$quote->getSubtotal();
            $subtotalWithDiscount = (float)$quote->getSubtotalWithDiscount();
            $discountAmount = abs($subtotal - $subtotalWithDiscount);
            $currency = $quote->getQuoteCurrencyCode();

            $this->helper->log(
                'info',
                'API',
                'COUPON_API_APPLIED',
                'Coupon applied via API',
                [
                    'coupon'   => $couponCode,
                    'emailHash'=> $customerEmailhash,
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
                'error',
                'API',
                'COUPON_API_ERROR',
                'Error in API coupon plugin',
                ['error' => $e->getMessage()]
            );
        }

        return $result;
    }
}