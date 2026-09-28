<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Checkout\Controller\Cart\CouponPost;
use Magento\Checkout\Model\Session;
use LoyaltyEngage\LoyaltyShop\Model\CouponClaim;

class CouponPostPlugin
{
    public function __construct(private Session $session, private CouponClaim $claim)
    {
    }

    public function afterExecute(CouponPost $subject, $result)
    {
        $this->claim->queue($this->session->getQuote());
        return $result;
    }
}
