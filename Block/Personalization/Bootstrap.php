<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Block\Personalization;

use LoyaltyEngage\LoyaltyShop\Model\Personalization\Config;
use LoyaltyEngage\LoyaltyShop\Model\Personalization\Amounts;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\View\Element\Template;

class Bootstrap extends Template
{
    public function __construct(Template\Context $context, private Config $config, private Amounts $amounts,
        private Data $helper, private \Magento\Framework\Registry $registry, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function rules(): array
    {
        return $this->config->rules((int) $this->_storeManager->getStore()->getId());
    }

    public static function scopeForAction(string $action): string
    {
        return ['catalog_product_view' => 'product', 'checkout_cart_index' => 'cart',
            'checkout_index_index' => 'checkout', 'hyva_checkout_index_index' => 'checkout'][$action] ?? 'pages';
    }

    public function settings(): string
    {
        $store = $this->_storeManager->getStore();
        $storeId = (int) $store->getId();
        $currency = $this->config->value('currency', $storeId);
        $displayCurrency = $store->getCurrentCurrencyCode();
        $includingTax = $this->helper->isEventPriceIncludingTax($storeId);
        $scope = self::scopeForAction($this->getRequest()->getFullActionName());
        $product = $scope === 'product' ? $this->registry->registry('current_product') : null;
        $productPrice = null;
        if ($product && in_array($product->getTypeId(), ['simple', 'virtual', 'downloadable'], true)) {
            $amount = $product->getPriceInfo()->getPrice('final_price')->getAmount();
            $productPrice = (float) ($includingTax ? $amount->getValue() : $amount->getBaseAmount());
        }
        return json_encode([
            'tenant' => $this->config->tenant($storeId), 'rules' => $this->rules(),
            'debug' => $this->config->value('debug', $storeId) === '1',
            'contextUrl' => $this->getUrl('loyalty/personalization/context', ['_nosid' => true]),
            'currency' => $currency, 'displayCurrency' => $displayCurrency,
            'displayRate' => $this->amounts->rate($displayCurrency, $currency),
            'includingTax' => $includingTax,
            'displayTaxMode' => (int) $this->_scopeConfig->getValue('tax/display/type', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId),
            'page' => ['scope' => $scope, 'path' => '/' . trim($this->getRequest()->getOriginalPathInfo(), '/')],
            'product' => ['id' => $product ? (int) $product->getId() : null,
                'type' => $product ? $product->getTypeId() : null, 'price' => $productPrice],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
