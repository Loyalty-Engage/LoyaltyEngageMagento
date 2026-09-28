<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterfaceFactory;
use LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartItemInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class LoyaltyCart implements LoyaltyCartInterface
{
    public function __construct(
        private CartRepositoryInterface $quotes,
        private QuoteFactory $quoteFactory,
        private ProductRepositoryInterface $products,
        private StoreManagerInterface $stores,
        private StoreContext $context,
        private Data $helper,
        private ApiClient $api,
        private LoyaltyCartResponseInterfaceFactory $responses,
        private CouponRules $couponRules,
        private MutationJournal $journal,
        private EventOutbox $outbox,
        private LockManagerInterface $lock,
        private ResourceConnection $resource,
        private LoggerInterface $logger,
        private \Magento\Quote\Api\CartRepositoryInterfaceFactory $quoteRepositories,
        private \Magento\Sales\Api\OrderRepositoryInterface $orders,
        private OrderExportEligibility $exportEligibility
    ) {
    }

    public function addProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        return $this->locked($customerId, function () use ($customerId, $sku) {
            if (trim($sku) === '') {
                throw new LocalizedException(__('SKU is required.'));
            }
            $quote = $this->getOrCreateCustomerQuote($customerId);
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($item->getSku() === $sku && $this->helper->isLoyaltyProduct($item, true)) {
                    return 'Product is already in your loyalty cart.';
                }
            }
            $this->validateCartLimits($quote);
            $key = $this->operationKey($quote, 'product', $sku);
            $product = clone $this->products->get($sku, false, (int) $quote->getStoreId(), true);
            if (!$this->isValidProduct($product)) {
                throw new LocalizedException(__('Invalid or unavailable product.'));
            }
            // Custom product options prevent Magento merging this into a paid line of the same SKU.
            $product->addCustomOption('loyalty_locked_qty', '1');
            $item = $quote->addProduct($product, new DataObject(['qty' => 1]));
            if (is_string($item)) {
                throw new LocalizedException(__($item));
            }
            if (!$item || $item->getHasError()) {
                throw new LocalizedException(__('The product cannot be added to this cart.'));
            }
            $item->setCustomPrice(0)->setOriginalCustomPrice(0)->setData('loyalty_locked_qty', 1);
            $item->addOption(['code' => 'loyalty_locked_qty', 'value' => '1']);
            $item->getProduct()->setIsSuperMode(true);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $storeId = (int) $quote->getStoreId();
            $hash = $this->helper->hashEmail($quote->getCustomerEmail());
            try {
                $this->journal->response($key, $storeId, $customerId, (int) $quote->getId(), 'product', $sku,
                function () use ($hash, $sku, $storeId, $key): array {
                    $this->api->setIdempotencyKey($key);
                    return $this->api->post($this->endpoint($storeId, $hash, 'add'),
                        ['sku' => $sku, 'quantity' => 1], $storeId);
                });
            } catch (\Throwable $e) {
                // Never let a later bulk-cart save persist an unreserved draft item.
                $item->isDeleted(true);
                $quote->setTotalsCollectedFlag(false);
                throw $e;
            }
            // A confirmed reservation is retained in the journal if this local save fails.
            $this->quotes->save($quote);
            $this->journal->complete($key);
            return 'Product added successfully.';
        });
    }

    public function addMultipleProducts(int $customerId, array $skus): LoyaltyCartResponseInterface
    {
        if (!$skus || count($skus) > 100 || array_filter($skus, static fn($sku) => !is_string($sku) || trim($sku) === '')) {
            return $this->helper->errorResponse($this->responses->create(), 'Provide between 1 and 100 valid SKUs.', 'validation');
        }
        $added = [];
        $failed = [];
        foreach (array_unique($skus) as $sku) {
            $response = $this->addProduct($customerId, $sku);
            if ($response->getSuccess()) {
                $added[] = $sku;
            } else {
                $failed[] = $sku;
            }
        }
        if ($failed) {
            return $this->helper->errorResponse($this->responses->create(),
                'Added: ' . implode(', ', $added) . '. Not added: ' . implode(', ', $failed) . '.', 'partial_failure');
        }
        return $this->helper->successResponse($this->responses->create(), 'All requested products were added.');
    }

    public function buyDiscountCodeProduct(int $customerId, string $sku): LoyaltyCartResponseInterface
    {
        return $this->locked($customerId, function () use ($customerId, $sku) {
            if (trim($sku) === '') {
                throw new LocalizedException(__('SKU is required.'));
            }
            $quote = $this->getOrCreateCustomerQuote($customerId);
            if (!$quote->getAllVisibleItems()) {
                throw new LocalizedException(__('Add a product to your cart before buying a discount.'));
            }
            $storeId = (int) $quote->getStoreId();
            $hash = $this->helper->hashEmail($quote->getCustomerEmail());
            $key = $this->operationKey($quote, 'discount', $sku);
            $result = $this->journal->response($key, $storeId, $customerId, (int) $quote->getId(), 'discount', $sku,
                function () use ($hash, $sku, $storeId, $key): array {
                    $this->api->setIdempotencyKey($key);
                    return $this->api->post($this->endpoint($storeId, $hash, 'buy_discount_code'), ['sku' => $sku], $storeId);
                });
            $percentage = (float) ($result['discountPercentage'] ?? 0);
            $amount = $percentage > 0 ? $percentage : (float) ($result['discountAmount'] ?? 0);
            $code = $this->couponRules->ensure((string) ($result['discountCode'] ?? ''), $amount, $percentage <= 0);
            $quote->setCouponCode($code)->setTotalsCollectedFlag(false)->collectTotals();
            if ($quote->getCouponCode() !== $code) {
                throw new LocalizedException(__('Your coupon was purchased but cannot be applied to this cart. Please contact the shop.'));
            }
            $this->quotes->save($quote);
            $this->journal->complete($key);
            return "Discount code '" . $code . "' applied successfully.";
        });
    }

    public function ensureCartRuleExists(?string $code, float $discountRate, bool $forceCartFixed = false): string
    {
        return $this->couponRules->ensure((string) $code, $discountRate, $forceCartFixed);
    }

    public function claimDiscountAfterAddToLoyaltyCart(int $customerId, string $orderId, array $products): LoyaltyCartResponseInterface
    {
        return $this->locked($customerId, function () use ($customerId, $orderId, $products) {
            $customer = $this->context->customer($customerId);
            $storeId = $this->context->storeId();
            $db = $this->resource->getConnection();
            $order = $db->fetchRow($db->select()->from($this->resource->getTableName('sales_order'))
                ->where('increment_id = ?', $orderId)->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
            if (!$order || !$products) {
                throw new LocalizedException(__('The order does not belong to this customer and store.'));
            }
            if ($order['loyalty_order_place']) {
                return 'Order redemption was already delivered.';
            }
            $orderModel = $this->orders->get((int) $order['entity_id']);
            if (!in_array($orderModel->getStatus(), $this->helper->getPurchaseOrderStatuses($storeId), true)
                || !$this->exportEligibility->evaluate($orderModel)['eligible']) {
                throw new LocalizedException(__('This order does not qualify for loyalty export.'));
            }
            $requested = [];
            foreach ($products as $product) {
                if (!$product instanceof LoyaltyCartItemInterface || !$product->getSku() || $product->getQuantity() <= 0) {
                    throw new LocalizedException(__('Invalid redemption products.'));
                }
                $requested[$product->getSku()] = (float) $product->getQuantity();
            }
            $actual = [];
            foreach ($db->fetchAll($db->select()->from($this->resource->getTableName('sales_order_item'))
                ->where('order_id = ?', $order['entity_id'])->where('loyalty_locked_qty = ?', 1)) as $item) {
                $quantity = (float) $item['qty_ordered'] - (float) $item['qty_canceled'];
                if ($quantity > 0) {
                    $actual[$item['sku']] = ($actual[$item['sku']] ?? 0.0) + $quantity;
                }
            }
            ksort($requested);
            ksort($actual);
            if ($requested !== $actual || !$actual) {
                throw new LocalizedException(__('Products do not match the loyalty items on the order.'));
            }
            $this->outbox->publish('loyaltyshop.free_product_purchase_event', json_encode([
                'email' => $customer->getEmail(), 'orderId' => $orderId, 'store_id' => $storeId,
                'products' => array_map(static fn($sku, $qty) => ['sku' => $sku, 'quantity' => $qty], array_keys($actual), $actual),
            ], JSON_THROW_ON_ERROR));
            return 'Order redemption queued.';
        });
    }

    public function getOrCreateCustomerQuote(int $customerId)
    {
        $customer = $this->context->customer($customerId);
        try {
            $quote = $this->quoteRepositories->create()->getActiveForCustomer($customerId, [$this->context->storeId()]);
            $this->context->assertQuote($quote);
            return $quote;
        } catch (NoSuchEntityException $e) {
            $quote = $this->quoteFactory->create();
            $quote->setStore($this->stores->getStore())->assignCustomer($customer)->setIsActive(true);
            $this->quotes->save($quote);
            return $quote;
        }
    }

    public function isValidProduct($product): bool
    {
        return (bool) $product->isSalable() && (int) $product->getStatus() === 1;
    }

    private function validateCartLimits($quote): void
    {
        $count = 0;
        $subtotal = 0.0;
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($this->helper->isLoyaltyProduct($item, true)) {
                $count++;
            } else {
                $subtotal += (float) ($item->getRowTotalInclTax() ?? $item->getRowTotal());
            }
        }
        $max = $this->helper->getMaxLoyaltyProducts();
        if ($max > 0 && $count >= $max) {
            throw new LocalizedException(__('You can add a maximum of %1 loyalty products.', $max));
        }
        $minimum = $this->helper->getMinimumOrderValueForLoyalty();
        if ($subtotal < $minimum) {
            throw new \LoyaltyEngage\LoyaltyShop\Service\MinimumOrderValueException(
                __($this->helper->getFormattedMinimumOrderValueMessage($minimum, $subtotal))
            );
        }
    }

    private function operationKey($quote, string $operation, string $sku): string
    {
        $cycle = 0;
        if ($operation === 'product') {
            $db = $this->resource->getConnection();
            $row = $db->fetchRow($db->select()->from($this->outbox->table(), ['entity_id', 'status'])
                ->where('aggregate_key = ?', hash('sha256', $quote->getStoreId() . ':' . $quote->getId() . ':' . $sku))
                ->order('entity_id DESC')->limit(1));
            if ($row) {
                if ($row['status'] !== 'sent') {
                    throw new LocalizedException(__('The previous removal is still being synchronized. Please retry later.'));
                }
                $cycle = (int) $row['entity_id'];
            }
        }
        return hash('sha256', $quote->getStoreId() . ':' . $quote->getId() . ':' . $operation . ':' . $sku . ':' . $cycle);
    }

    private function endpoint(int $storeId, string $hash, string $action): string
    {
        return rtrim((string) $this->helper->getApiUrl($storeId), '/') . '/api/v1/loyalty/shop/' . rawurlencode($hash) . '/cart/' . $action;
    }

    private function locked(int $customerId, callable $operation): LoyaltyCartResponseInterface
    {
        $response = $this->responses->create();
        $key = 'le_cart_' . $customerId;
        if (!$this->helper->isLoyaltyEngageEnabled($this->context->storeId())) {
            return $this->helper->errorResponse($response, 'Loyalty Engage is disabled.', 'disabled', 403);
        }
        if (!$this->lock->lock($key, 5)) {
            return $this->helper->errorResponse($response, 'Your cart is being updated. Please retry.', 'busy', 409);
        }
        try {
            return $this->helper->successResponse($response, $operation());
        } catch (\LoyaltyEngage\LoyaltyShop\Service\MinimumOrderValueException $e) {
            $this->helper->setHttpResponseCode(400);
            return $response->setSuccess(false)->setMessage($e->getMessage())->setErrorType('minimum_order_value')
                ->setBarColor($this->helper->getMinimumOrderValueBarColor())
                ->setTextColor($this->helper->getMinimumOrderValueTextColor());
        } catch (LocalizedException $e) {
            return $this->helper->errorResponse($response, $e->getMessage(), 'validation');
        } catch (\Throwable $e) {
            $this->logger->error('Loyalty cart operation failed.', ['customer_id' => $customerId, 'error' => $e->getMessage()]);
            return $this->helper->errorResponse($response,
                'The request could not be completed. Please contact the shop if your balance changed.', 'system_error', 502);
        } finally {
            $this->api->setIdempotencyKey(null);
            $this->lock->unlock($key);
        }
    }
}
