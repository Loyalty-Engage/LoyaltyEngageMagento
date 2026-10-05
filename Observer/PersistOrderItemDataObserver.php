<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Observer;

use LoyaltyEngage\LoyaltyShop\Model\OrderItemData;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Item;

class PersistOrderItemDataObserver implements ObserverInterface
{
    public function __construct(private OrderItemData $data)
    {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getItem();
        if (!$item instanceof Item || !$item->getId()) {
            return;
        }
        $options = $item->getProductOptions() ?: [];
        if (!empty($options['loyalty_locked_qty'])
            && ($item->isObjectNew() || $item->dataHasChangedFor('product_options'))) {
            $this->data->save($item, true);
        }
    }
}
