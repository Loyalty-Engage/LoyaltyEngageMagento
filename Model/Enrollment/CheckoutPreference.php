<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class CheckoutPreference implements ConfigProviderInterface
{
    public function __construct(
        private Config $config,
        private Choice $choice,
        private Membership $membership,
        private CheckoutSession $checkout,
        private CustomerSession $customer,
        private FormKey $formKey,
        private StoreManagerInterface $stores,
        private UrlInterface $url
    ) {}

    public static function token(int $quoteId, int $storeId, string $formKey): string
    {
        return hash_hmac('sha256', $quoteId . ':' . $storeId, $formKey);
    }

    public function settings(): array
    {
        $storeId = (int) $this->stores->getStore()->getId();
        $mode = $this->config->mode($storeId);
        $quoteId = (int) $this->checkout->getQuoteId();
        if ($mode === 'disabled' || !$quoteId
            || ($mode === 'required' && $this->membership->isMember((int) $this->customer->getCustomerId(), $storeId))) {
            return [];
        }
        $choice = $this->choice->get($quoteId, $storeId);
        $formKey = $this->formKey->getFormKey();
        return [
            'label' => $this->config->label($storeId),
            'selected' => ($choice['mode'] ?? '') === $mode && !empty($choice['selected']),
            'url' => $this->url->getUrl('loyalty/enrollment/save', ['_secure' => true]),
            'form_key' => $formKey,
            'quote_token' => self::token($quoteId, $storeId, $formKey),
            'advisory' => $mode === 'advisory',
        ];
    }

    public function getConfig(): array
    {
        return ['loyaltyEnrollment' => $this->settings()];
    }
}
