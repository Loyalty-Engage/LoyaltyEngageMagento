<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

class InvoiceTotal extends \Magento\Sales\Model\Order\Invoice\Total\AbstractTotal
{
    public function __construct(private Documents $documents, array $data = [])
    {
        parent::__construct($data);
    }

    public function collect(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        $this->documents->collect($invoice, 'invoice');
        return $this;
    }
}
