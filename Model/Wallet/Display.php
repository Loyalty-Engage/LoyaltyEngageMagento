<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Creditmemo;

class Display
{
    public function __construct(private Tender $tender, private Documents $documents, private Config $config)
    {
    }

    public function total($source): array
    {
        if ($source instanceof Order) {
            $state = $this->tender->get($source);
            $amount = (int) ($state['spent_cents'] ?? 0);
            $order = $source;
        } elseif ($source instanceof Invoice || $source instanceof Creditmemo) {
            $state = $source->getData(Documents::ALLOCATION)
                ?: $this->documents->saved($source instanceof Invoice ? 'invoice' : 'creditmemo', (int) $source->getId());
            $amount = (int) ($state['wallet_cents'] ?? 0);
            $order = $source->getOrder();
        } else {
            return [];
        }
        $name = $this->config->name((int) $order->getStoreId());
        $label = __('Paid with %1', $name);
        if ($source instanceof Creditmemo) {
            $label = Documents::restoresCredit($this->tender->get($order))
                ? __('Refund to %1', $name) : __('%1 credit forfeited on return', $name);
        }
        return $amount > 0 ? ['label' => $label,
            'value' => -$amount / 100] : [];
    }
}
