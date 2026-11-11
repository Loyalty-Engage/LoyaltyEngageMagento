<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Accrual;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Relation;

/** Relations already have IDs here, while the order transaction is still open. */
class OrderRelation
{
    public function __construct(private Accrual $accrual, private \LoyaltyEngage\LoyaltyShop\Model\Wallet\Tender $tender)
    {
    }

    public function afterProcessRelation(Relation $subject, $result, AbstractModel $object)
    {
        if ($object instanceof Order) {
            $this->tender->settle($object);
            $this->accrual->orderSaved($object);
        }
        return $result;
    }
}
