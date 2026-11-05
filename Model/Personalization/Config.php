<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Model\Personalization;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private array $loaded = [];

    public function __construct(private ScopeConfigInterface $scopeConfig, private Validation $validation)
    {
    }

    public function value(string $field, int $storeId): string
    {
        return (string) $this->scopeConfig->getValue('loyalty/personalizations/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function tenant(int $storeId): string
    {
        $tenant = $this->value('tenant_id', $storeId);
        return preg_match('/^' . Validation::UUID . '$/iD', $tenant) ? strtolower($tenant) : '';
    }

    public function rules(int $storeId): array
    {
        if (isset($this->loaded[$storeId])) {
            return $this->loaded[$storeId];
        }
        if (!$this->scopeConfig->isSetFlag('loyalty/general/module_enable', ScopeInterface::SCOPE_STORE, $storeId)
            || !$this->scopeConfig->isSetFlag('loyalty/personalizations/enabled', ScopeInterface::SCOPE_STORE, $storeId)
            || !$this->tenant($storeId)) {
            return $this->loaded[$storeId] = [];
        }
        try {
            $rows = json_decode($this->value('rules', $storeId), true, 32, JSON_THROW_ON_ERROR);
            $rows = $this->validation->rules(is_array($rows) ? $rows : []);
            return $this->loaded[$storeId] = array_values(array_filter($rows, static fn(array $row): bool => (bool) $row['enabled']));
        } catch (\Throwable) {
            // Invalid imported configuration must not break the storefront or execute unvalidated data.
            return $this->loaded[$storeId] = [];
        }
    }

    public function rule(string $key, int $storeId): ?array
    {
        foreach ($this->rules($storeId) as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }
        return null;
    }
}
