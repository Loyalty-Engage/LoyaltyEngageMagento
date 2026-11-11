<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

class CreditmemoTotal extends \Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal
{
    public function __construct(private Documents $documents, array $data = [])
    {
        parent::__construct($data);
    }

    public function collect(\Magento\Sales\Model\Order\Creditmemo $creditmemo)
    {
        $this->documents->collect($creditmemo, 'creditmemo');
        return $this;
    }
}
