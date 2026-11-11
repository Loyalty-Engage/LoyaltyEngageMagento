<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class Capture implements ObserverInterface
{
    public const SNAPSHOT = '_loyaltyshop_wallet_snapshot';
    public const LINE = '_loyaltyshop_wallet_cents';
    public const GROSS_LINE = '_loyaltyshop_wallet_gross_cents';

    public function __construct(
        private Config $config,
        private ResourceConnection $resource,
        private Money $money,
        private Policy $policy
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || $order->getId() || !$order->getCustomerId()
            || $order->getCustomerIsGuest() || !$this->config->enabled((int) $order->getStoreId())
            || $order->getBaseCurrencyCode() !== 'EUR' || $order->getOrderCurrencyCode() !== 'EUR') {
            return;
        }
        $storeId = (int) $order->getStoreId();
        $db = $this->resource->getConnection();
        $tier = (string) $db->fetchOne($db->select()
            ->from($this->resource->getTableName('loyaltyshop_customer_store_data'), ['le_current_tier'])
            ->where('customer_id = ?', (int) $order->getCustomerId())->where('store_id = ?', $storeId));
        $rates = $this->config->rates($storeId);
        if (empty($rates[$tier])) {
            return;
        }
        $includingTax = $this->config->includingTax($storeId);
        $walletPaid = (int) ($order->getData(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection::HOLD)['amount'] ?? 0);
        $hold = $order->getData(\LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection::HOLD);
        $orderGross = is_array($hold) ? (int) $hold['gross_cents'] : null;
        $eligibleItems = [];
        $merchandise = 0;
        foreach ($order->getAllItems() as $item) {
            if ((($item->getParentItemId() || $item->getParentItem()) && !$item->isChildrenCalculated())
                || ($item->getHasChildren() && $item->isChildrenCalculated())
                || !empty(($item->getProductOptions() ?: [])[AppliedTierPrice::EXCLUDED_OPTION])) {
                continue;
            }
            $net = max(0, $this->money->cents((string) ($item->getBaseRowTotal() ?? '0'))
                - $this->money->cents((string) ($item->getBaseDiscountAmount() ?? '0')));
            if ($includingTax) {
                $net += $this->money->cents((string) ($item->getBaseTaxAmount() ?? '0'))
                    + $this->money->cents((string) ($item->getBaseDiscountTaxCompensationAmount() ?? '0'));
            }
            $this->money->assertCents($net);
            $item->setData(self::GROSS_LINE, $net);
            $eligibleItems[] = $item;
            $merchandise += $net;
        }
        $snapshot = $this->policy->snapshot($merchandise, $walletPaid, $tier, $rates, $orderGross);
        $grossSoFar = $eligibleSoFar = 0;
        foreach ($eligibleItems as $item) {
            $grossSoFar += (int) $item->getData(self::GROSS_LINE);
            $target = $merchandise > 0 ? $this->policy->proportion($snapshot['eligible_cents'], $grossSoFar, $merchandise) : 0;
            $item->setData(self::LINE, $target - $eligibleSoFar);
            $eligibleSoFar = $target;
        }
        $order->setData(self::SNAPSHOT, $snapshot + ['including_tax' => (int) $includingTax]);
    }
}
