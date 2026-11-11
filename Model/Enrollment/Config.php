<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    public function __construct(private ScopeConfigInterface $scope) {}

    public function mode(int $storeId): string
    {
        if (!$this->scope->isSetFlag('loyalty/general/module_enable', 'store', $storeId)) { return 'disabled'; }
        $mode = (string) $this->scope->getValue('loyalty/enrollment/mode', 'store', $storeId);
        return in_array($mode, ['advisory', 'required'], true) ? $mode : 'disabled';
    }

    public function label(int $storeId): string
    {
        return trim((string) $this->scope->getValue('loyalty/enrollment/label', 'store', $storeId))
            ?: (string) __('I would like to join the loyalty program.');
    }
}
