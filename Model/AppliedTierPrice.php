<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item\AbstractItem;

class AppliedTierPrice
{
    public const EXCLUDED_OPTION = 'loyalty_purchase_tier_excluded';

    public function isApplied(AbstractItem $item): bool
    {
        if ($item->hasCustomPrice() || $item->hasOriginalCustomPrice()) {
            return false;
        }
        $parent = $item->getParentItem();
        if (($parent && !$item->isChildrenCalculated()) || ($item->getHasChildren() && $item->isChildrenCalculated())) {
            return false;
        }
        $qty = (float) $item->getQty();
        $parentQty = $parent ? (float) $parent->getQty() : 1.0;
        if ($qty * $parentQty <= 1 && $parentQty <= 1) {
            return false;
        }
        $groupId = (int) $item->getQuote()->getCustomerGroupId();
        $product = $this->copyProduct($item->getProduct(), $groupId);
        $withoutTiers = $this->copyProduct($product, $groupId);
        $hasTier = $this->removeQuantityTiers($withoutTiers, $qty * $parentQty);

        if ($parent) {
            // Dynamic bundles calculate the selection price through the parent price model.
            $bundle = $this->copyProduct($parent->getProduct(), $groupId);
            $bundleWithoutTiers = $this->copyProduct($bundle, $groupId);
            $bundleQty = $parentQty;
            $hasTier = $this->removeQuantityTiers($bundleWithoutTiers, $bundleQty) || $hasTier;
            if (!$hasTier) {
                return false;
            }
            $price = $bundle->getPriceModel()->getChildFinalPrice($bundle, $bundleQty, $product, $qty);
            $priceWithoutTiers = $bundleWithoutTiers->getPriceModel()
                ->getChildFinalPrice($bundleWithoutTiers, $bundleQty, $withoutTiers, $qty);
        } else {
            if (!$hasTier) {
                return false;
            }
            $price = $product->getPriceModel()->getFinalPrice($qty, $product);
            $priceWithoutTiers = $withoutTiers->getPriceModel()->getFinalPrice($qty, $withoutTiers);
        }

        // Magento applies special/catalog prices and options in both calculations. A lower
        // special price (or an equal-price tier) must not be mistaken for a tier discount.
        return is_numeric($price) && is_numeric($priceWithoutTiers)
            && is_finite((float) $price) && is_finite((float) $priceWithoutTiers)
            && (float) $priceWithoutTiers - (float) $price > 0.0001;
    }

    private function copyProduct(Product $product, int $groupId): Product
    {
        $copy = clone $product;
        $copy->setCustomerGroupId($groupId)->setFinalPrice(null)->setCalculatedFinalPrice(null);
        $simple = $product->getCustomOption('simple_product');
        if ($simple && $simple->getProduct() instanceof Product && $simple->getProduct() !== $product) {
            $option = clone $simple;
            $option->setProduct($this->copyProduct($simple->getProduct(), $groupId));
            $options = $copy->getCustomOptions();
            $options['simple_product'] = $option;
            $copy->setCustomOptions($options);
        }
        return $copy;
    }

    private function removeQuantityTiers(Product $product, float $qty): bool
    {
        $simple = $product->getCustomOption('simple_product');
        if ($product->getTypeId() === 'configurable' && $simple && $simple->getProduct() instanceof Product) {
            return $this->removeQuantityTiers($simple->getProduct(), $qty);
        }
        // Let Magento load store/website-scoped tier data. Keep quantity-one group prices.
        $product->getTierPrice();
        $tiers = (array) $product->getData('tier_price');
        $remaining = [];
        $candidate = false;
        foreach ($tiers as $tier) {
            $threshold = (float) ($tier['price_qty'] ?? 0);
            if ($threshold > 1) {
                $candidate = $candidate || $threshold <= $qty;
            } else {
                $remaining[] = $tier;
            }
        }
        $product->setData('tier_price', $remaining);
        return $candidate;
    }
}
