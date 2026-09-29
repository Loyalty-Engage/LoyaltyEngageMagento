<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\Quote;

class PhysicalCartReconciler
{
    public function __construct(private ResourceConnection $resource, private ApiClient $api, private Data $helper)
    {
    }

    public function confirm(Quote $quote, string $sku, string $key): ?array
    {
        if (!$quote->getIsActive() || $this->hasConflict($quote, $sku, $key)) {
            return null;
        }
        $storeId = (int) $quote->getStoreId();
        $url = rtrim((string) $this->helper->getApiUrl($storeId), '/') . '/api/v1/loyalty/shop/'
            . rawurlencode($this->helper->hashEmail($quote->getCustomerEmail())) . '/cart';
        $this->api->setIdempotencyKey(null);
        $cart = $this->api->get($url, [], $storeId);
        if (!isset($cart['products']) || !is_array($cart['products']) || !array_is_list($cart['products'])) {
            return null;
        }
        $matches = [];
        foreach ($cart['products'] as $product) {
            if (!is_array($product) || !isset($product['sku']) || !is_string($product['sku'])) {
                return null;
            }
            if ($product['sku'] === $sku) {
                $matches[] = $product;
            }
        }
        if (count($matches) !== 1 || !is_numeric($matches[0]['quantity'] ?? null)
            || (float) $matches[0]['quantity'] !== 1.0 || !is_numeric($matches[0]['coinPrice'] ?? null)
            || !is_finite((float) $matches[0]['coinPrice']) || (float) $matches[0]['coinPrice'] < 1) {
            // Missing/ambiguous items do not prove that the earlier mutation was refused.
            return null;
        }
        return ['reconciled_from' => 'loyalty_cart', 'product' => $matches[0]];
    }

    private function hasConflict(Quote $quote, string $sku, string $key): bool
    {
        $db = $this->resource->getConnection();
        $conflict = $db->fetchOne($db->select()
            ->from(['m' => $this->resource->getTableName('loyaltyshop_mutation')], ['operation_key'])
            ->joinLeft(['q' => $this->resource->getTableName('quote')], 'q.entity_id = m.quote_id', [])
            ->where('m.customer_id = ?', (int) $quote->getCustomerId())
            ->where('m.operation = ?', 'product')->where('m.sku = ?', $sku)->where('m.operation_key <> ?', $key)
            ->where("m.status IN ('started', 'uncertain', 'received', 'manual') OR (m.status = 'applied' AND q.is_active = 1)")
            ->limit(1));
        if ($conflict) {
            return true;
        }
        $otherCart = $db->fetchOne($db->select()
            ->from(['q' => $this->resource->getTableName('quote')], ['entity_id'])
            ->joinInner(['i' => $this->resource->getTableName('quote_item')], 'i.quote_id = q.entity_id', [])
            ->where('q.customer_email = ?', $quote->getCustomerEmail())->where('q.is_active = ?', 1)
            ->where('q.entity_id <> ?', (int) $quote->getId())->where('i.sku = ?', $sku)
            ->where('i.loyalty_locked_qty = ?', 1)->limit(1));
        if ($otherCart) {
            return true;
        }
        // Do not attach a reservation that a pending removal or purchase can consume.
        return (bool) $db->fetchOne($db->select()
            ->from($this->resource->getTableName(EventOutbox::TABLE), ['entity_id'])
            ->where('topic IN (?)', ['loyaltyshop.free_product_remove_event', 'loyaltyshop.free_product_purchase_event'])
            ->where('status IN (?)', ['pending', 'failed'])
            ->where("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.email')) = ?", $quote->getCustomerEmail())
            ->limit(1));
    }
}
