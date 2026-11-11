<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Wallet;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public function __construct(private ScopeConfigInterface $scopeConfig, private Policy $policy)
    {
    }

    public function enabled(int $storeId): bool
    {
        return $storeId > 0
            && $this->scopeConfig->isSetFlag('loyalty/general/module_enable', ScopeInterface::SCOPE_STORE, $storeId)
            && $this->scopeConfig->isSetFlag('loyalty/wallet/enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function rates(int $storeId): array
    {
        $raw = (string) $this->value('rates', $storeId);
        if ($raw === '') {
            return [];
        }
        try {
            $rows = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            return is_array($rows) ? $this->policy->rates($rows) : [];
        } catch (\JsonException | \InvalidArgumentException) {
            return [];
        }
    }

    public function spending(int $storeId): bool
    {
        return $this->enabled($storeId)
            && $this->scopeConfig->isSetFlag('loyalty/wallet/spending_enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function paymentMethods(int $storeId): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->value('payment_methods', $storeId)))));
    }

    public function coinsRequired(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('loyalty/wallet/coins_required', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function payable(int $cents, int $storeId): int
    {
        return $this->coinsRequired($storeId) ? intdiv($cents, 100) * 100 : $cents;
    }

    public function name(int $storeId): string
    {
        return trim((string) $this->value('name', $storeId)) ?: 'Wallet';
    }

    /** @return array<int, string> Complete denomination map in cents, or empty if invalid. */
    public function redemptionSkus(int $storeId): array
    {
        if ($storeId < 1) {
            return [];
        }
        $skus = [];
        foreach ([1, 5, 10, 50] as $euros) {
            $raw = $this->value('redemption_sku_' . $euros, $storeId);
            $sku = is_string($raw) ? trim($raw) : '';
            if (!self::isRedemptionSku($sku) || in_array($sku, $skus, true)) {
                return [];
            }
            $skus[$euros * 100] = $sku;
        }
        return $skus;
    }

    public static function isRedemptionSku(string $sku): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $sku) === 1;
    }

    public function includingTax(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('loyalty/wallet/including_tax', ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $field, int $storeId): mixed
    {
        return $this->scopeConfig->getValue('loyalty/wallet/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
