<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class PaymentSaved implements ObserverInterface
{
    public function __construct(private Tender $tender, private Documents $documents)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        if ($event->getOrder()) {
            $this->tender->bind($event->getOrder());
        } elseif ($event->getInvoice()) {
            $this->documents->persist($event->getInvoice(), 'invoice');
        } elseif ($event->getCreditmemo()) {
            $this->documents->persist($event->getCreditmemo(), 'creditmemo');
        }
    }
}
