<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Quote\Api\CartRepositoryInterface;

class ShippingInformation
{
    public function __construct(
        private CartRepositoryInterface $quotes,
        private Selection $selection,
        private \Magento\Quote\Api\CartTotalRepositoryInterface $totals,
        private \Magento\Quote\Api\PaymentMethodManagementInterface $methods
    )
    {
    }

    public function afterSaveAddressInformation(
        \Magento\Checkout\Model\ShippingInformationManagement $subject,
        \Magento\Checkout\Api\Data\PaymentDetailsInterface $result,
        $cartId
    ): \Magento\Checkout\Api\Data\PaymentDetailsInterface {
        $quote = $this->quotes->getActive($cartId);
        if ($this->selection->preview($quote) > 0) {
            // Native shipping persistence can collect a separate quote instance, leaving
            // the saved wallet preview behind. Reconcile against the saved shipping data.
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->quotes->save($quote);
            // A fully wallet-paid quote can keep the same grand total (zero), so
            // native persistence may skip save_after despite a changed tender.
            $this->selection->savePreview($quote);
            $result->setTotals($this->totals->get($cartId));
            $result->setPaymentMethods($this->methods->getList($cartId));
        }
        return $result;
    }
}
