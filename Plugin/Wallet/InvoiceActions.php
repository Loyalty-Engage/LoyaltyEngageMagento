<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Sales\Model\Order\Invoice;

class InvoiceActions
{
    public function __construct(private Guard $guard, private Tender $tender, private \LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents $documents, private \LoyaltyEngage\LoyaltyShop\Model\Wallet\RefreshOrder $refresh)
    {
    }

    public function beforeRegister(Invoice $invoice): void
    {
        if ($this->tender->get($invoice->getOrder())) {
            $this->guard->order((int) $invoice->getOrderId());
            $this->refresh->execute($invoice->getOrder(), $invoice, 'invoice');
            $this->documents->reallocate($invoice, 'invoice');
        }
    }

    public function beforeCancel(Invoice $invoice): void
    {
        if ($this->tender->get($invoice->getOrder())) {
            $this->guard->order((int) $invoice->getOrderId());
        }
    }
}
