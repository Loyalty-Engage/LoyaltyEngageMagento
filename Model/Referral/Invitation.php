<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Referral;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Invitation
{
    public const CONFIG_PATH = 'loyalty/referral/url_template';
    public const PLACEHOLDER = '{contact_id}';

    public function __construct(
        private ScopeConfigInterface $config,
        private CustomerLoyaltyDataProvider $customerData
    ) {}

    public function getUrl(int $customerId, int $storeId): ?string
    {
        if ($customerId < 1 || $storeId < 1
            || !$this->config->isSetFlag('loyalty/general/module_enable', 'store', $storeId)) {
            return null;
        }
        $template = trim((string) $this->config->getValue(self::CONFIG_PATH, 'store', $storeId));
        if (!self::isValidTemplate($template)) {
            return null;
        }
        $contactId = $this->customerData->getContactId($customerId, $storeId);
        if ($contactId === null || trim($contactId) === '') {
            return null;
        }
        return str_replace(self::PLACEHOLDER, rawurlencode($contactId), $template);
    }

    public static function isValidTemplate(string $template): bool
    {
        if (strlen($template) > 2048 || substr_count($template, self::PLACEHOLDER) !== 1
            || preg_match('/[\x00-\x20\x7f\\\\]/', $template)) {
            return false;
        }
        $parts = parse_url($template);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || str_contains($parts['host'], self::PLACEHOLDER)
            || !(str_contains($parts['path'] ?? '', self::PLACEHOLDER)
                || str_contains($parts['query'] ?? '', self::PLACEHOLDER))) {
            return false;
        }
        $url = str_replace(self::PLACEHOLDER, 'example-contact', $template);
        return !strpbrk($url, '{}') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
