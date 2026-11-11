<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Sales\Model\Order;

class OrderActions
{
    public function __construct(private Guard $guard, private Tender $tender, private \LoyaltyEngage\LoyaltyShop\Model\Wallet\RefreshOrder $refresh)
    {
    }

    public function beforeCancel(Order $order): void
    {
        if ($this->tender->get($order)) {
            $this->guard->order((int) $order->getId());
            $this->refresh->execute($order);
        }
    }

    public function afterCanCreditmemo(Order $order, bool $result): bool
    {
        if ($result || $order->canUnhold() || $order->isPaymentReview()
            || in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            return $result;
        }
        $state = $this->tender->get($order);
        return $state && (int) $state['invoiced_wallet'] > (int) $state['refunded_wallet'];
    }
}
