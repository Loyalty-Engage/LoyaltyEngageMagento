<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;

/** Native cash-only eligibility must also recognize refundable wallet tender. */
class RefundValidation
{
    public function __construct(private Tender $tender, private Documents $documents)
    {
    }

    public function aroundValidate($subject, callable $proceed, $entity)
    {
        if ($entity instanceof Order) {
            $state = $this->tender->get($entity);
            $remaining = max(0, (int) ($state['invoiced_wallet'] ?? 0) - (int) ($state['refunded_wallet'] ?? 0));
            if ($remaining > 0) {
                $copy = clone $entity;
                $copy->setTotalPaid((float) $entity->getTotalPaid() + $remaining / 100);
                return $proceed($copy);
            }
        } elseif ($entity instanceof Invoice) {
            $remaining = $this->documents->invoiceWalletRemaining((int) $entity->getId());
            if ($remaining > 0) {
                $order = $entity->getOrder();
                if ((int) $entity->getState() === Invoice::STATE_PAID
                    && !in_array($order->getState(), [Order::STATE_PAYMENT_REVIEW, Order::STATE_HOLDED, Order::STATE_CANCELED, Order::STATE_CLOSED], true)
                    && (float) $order->getBaseTotalPaid() - (float) $order->getBaseTotalRefunded() < .0001) {
                    // No gateway operation can occur: document allocation requires
                    // the remaining refund to fit entirely within wallet tender.
                    return [];
                }
                $copy = clone $entity;
                $copy->setBaseGrandTotal((float) $entity->getBaseGrandTotal() + $remaining / 100);
                return $proceed($copy);
            }
        }
        return $proceed($entity);
    }
}
