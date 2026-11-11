<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Documents;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Guard;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Service\CreditmemoService;

class RefundActions
{
    public function __construct(private Guard $guard, private Tender $tender, private Documents $documents, private \LoyaltyEngage\LoyaltyShop\Model\Wallet\RefreshOrder $refresh)
    {
    }

    public function beforeRefund(CreditmemoService $subject, $creditmemo, $offlineRequested = false): void
    {
        if ($this->tender->get($creditmemo->getOrder())) {
            $this->guard->order((int) $creditmemo->getOrderId());
            $this->refresh->execute($creditmemo->getOrder(), $creditmemo, 'creditmemo');
            $this->documents->reallocate($creditmemo, 'creditmemo');
        }
    }

    public function afterIsValidGrandTotal(Creditmemo $memo, bool $result): bool
    {
        $allocation = $memo->getData(Documents::ALLOCATION);
        return $result || (is_array($allocation) && $allocation['wallet_cents'] > 0);
    }
}
