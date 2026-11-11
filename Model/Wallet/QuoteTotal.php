<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

class QuoteTotal extends AbstractTotal
{
    public function __construct(private Selection $selection, private Money $money, private Config $config)
    {
        $this->setCode('loyalty_wallet');
    }

    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        parent::collect($quote, $shippingAssignment, $total);
        // A loaded checkout may collect shipping before an empty billing address.
        // Reset once per quote collection, not when an empty address follows it.
        if (!$shippingAssignment->getItems()) { return $this; }
        $quote->setData(Selection::AMOUNT, 0);
        if (!$this->selection->eligible($quote)) {
            return $this;
        }
        $gross = $this->money->native(max(0, (float) $total->getBaseGrandTotal()));
        $amount = $this->selection->applied($quote, $gross);
        $quote->setData(Selection::AMOUNT, $amount);
        $total->setTotalAmount('loyalty_wallet', -$amount / 100);
        $total->setBaseTotalAmount('loyalty_wallet', -$amount / 100);
        if ($amount > 0) {
            // A tender is applied after native grand totals, at EUR cent precision.
            // Product discounts and tax amounts are deliberately left unchanged.
            $total->setBaseGrandTotal(($gross - $amount) / 100)->setGrandTotal(($gross - $amount) / 100);
        }
        return $this;
    }

    public function fetch(Quote $quote, Total $total)
    {
        $amount = $quote->hasData(Selection::AMOUNT) ? (int) $quote->getData(Selection::AMOUNT) : $this->selection->preview($quote);
        return $amount > 0 ? ['code' => 'loyalty_wallet',
            'title' => __($this->config->name((int) $quote->getStoreId())), 'value' => -$amount / 100] : [];
    }
}
