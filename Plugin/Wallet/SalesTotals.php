<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Plugin\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Display;
use Magento\Framework\DataObject;
use Magento\Sales\Block\Order\Totals;

class SalesTotals
{
    public function __construct(private Display $display)
    {
    }

    public function afterGetTotals(Totals $subject, array $result, $area = null): array
    {
        if ($area !== null && $area !== '') { return $result; }
        $total = $this->display->total($subject->getSource());
        if (!$total) { return $result; }
        $line = new DataObject($total + ['code' => 'loyalty_wallet', 'base_value' => $total['value']]);
        $sorted = [];
        foreach ($result as $code => $row) {
            if ($code === 'grand_total') { $sorted['loyalty_wallet'] = $line; }
            $sorted[$code] = $row;
        }
        return $sorted + ['loyalty_wallet' => $line];
    }
}
