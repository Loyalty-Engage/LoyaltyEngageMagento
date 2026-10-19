<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Integration;

use LoyaltyEngage\LoyaltyShop\Model\AppliedTierPrice;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\TestCase;

/** Native Magento price models, rollback fixtures, no outbound API. */
class TierPriceTest extends TestCase
{
    private static $om;
    private $db;
    private int $storeId;
    private AppliedTierPrice $detector;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 6) . '/app/bootstrap.php';
        self::$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        try {
            self::$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
        }
    }

    protected function setUp(): void
    {
        $this->db = self::$om->get(ResourceConnection::class)->getConnection();
        $this->db->beginTransaction();
        $this->storeId = (int) $this->db->fetchOne('SELECT store_id FROM store WHERE store_id > 0 ORDER BY store_id LIMIT 1');
        $this->detector = new AppliedTierPrice();
    }

    protected function tearDown(): void
    {
        $this->db->rollBack();
    }

    /** @dataProvider quantityProvider */
    public function testNativeQuantityThresholds(float $qty, ?float $special, bool $expected): void
    {
        $product = $this->product();
        if ($special !== null) {
            $product->setSpecialPrice($special);
        }
        $tiers = $product->getData('tier_price');
        self::assertSame($expected, $this->detector->isApplied($this->item($product, $qty)));
        self::assertSame($tiers, $product->getData('tier_price'), 'Checkout product prices must not be mutated.');
    }

    public static function quantityProvider(): array
    {
        return [
            'single unit' => [1.0, null, false],
            'below threshold' => [9.0, null, false],
            'first threshold' => [10.0, null, true],
            'between thresholds' => [49.0, null, true],
            'second threshold' => [50.0, null, true],
            'special price beats tiers' => [50.0, 11.56, false],
            'special price ties first tier' => [10.0, 13.29, false],
            'tier beats special price' => [50.0, 14.0, true],
        ];
    }

    public function testTierMustMatchTheCustomerGroup(): void
    {
        $product = $this->product();
        $product->setTierPrice([$this->tier(10, 13.29, 2)]);
        self::assertFalse($this->detector->isApplied($this->item($product, 10, 1)));
        self::assertTrue($this->detector->isApplied($this->item($product, 10, 2)));
    }

    public function testQuantityOneGroupPriceIsNotABulkDiscount(): void
    {
        $product = $this->product();
        $product->setTierPrice([$this->tier(1, 12), $this->tier(10, 13.29)]);
        self::assertFalse($this->detector->isApplied($this->item($product, 10)));
        $product->setTierPrice([$this->tier(1, 14), $this->tier(10, 13.29)]);
        self::assertTrue($this->detector->isApplied($this->item($product, 10)));
    }

    public function testCustomPricesAreNotMisclassifiedAsNativeTierPrices(): void
    {
        $item = $this->item($this->product(), 10);
        $item->setCustomPrice(0)->setOriginalCustomPrice(0);
        self::assertFalse($this->detector->isApplied($item));
    }

    public function testCouponDiscountDoesNotQualifyAsTierPricing(): void
    {
        $item = $this->item($this->product(), 9);
        $item->getQuote()->setCouponCode('TIER-TEST-COUPON');
        $item->setDiscountAmount(10)->setBaseDiscountAmount(10)->setDiscountPercent(10);
        self::assertFalse($this->detector->isApplied($item), 'A cart-rule discount is not a quantity tier.');
        $item->setQty(10);
        self::assertTrue($this->detector->isApplied($item), 'An applied quantity tier still qualifies with a coupon.');
        $item->getProduct()->setTierPrice([]);
        self::assertFalse($this->detector->isApplied($item), 'Coupon-only items must remain exportable.');
    }

    public function testRegisteredConverterPluginReplacesStaleSnapshot(): void
    {
        $item = $this->item($this->product(), 9)->setProductType('simple')->setSku('TIER-CONVERT')
            ->setProductOrderOptions([AppliedTierPrice::EXCLUDED_OPTION => 1, 'fixture' => 'preserved']);
        $result = self::$om->get(\Magento\Quote\Model\Quote\Item\ToOrderItem::class)->convert($item);
        $helper = self::$om->get(\LoyaltyEngage\LoyaltyShop\Helper\Data::class);
        $enabled = $helper->isLoyaltyEngageEnabled($this->storeId)
            && $helper->isTierPricePurchaseExclusionEnabled($this->storeId);
        $options = $result->getProductOptions();
        self::assertSame($enabled ? 0 : null, $options[AppliedTierPrice::EXCLUDED_OPTION] ?? null);
        self::assertSame('preserved', $options['fixture']);
    }

    public function testConfigurableUsesSelectedSimpleAndKeepsItsPricesUntouched(): void
    {
        $simple = $this->product();
        $parent = $this->product('configurable')->setTierPrice([]);
        $parent->addCustomOption('simple_product', 'fixture', $simple);
        $tiers = $simple->getTierPrice();
        self::assertTrue($this->detector->isApplied($this->item($parent, 10)));
        self::assertSame($simple, $parent->getCustomOption('simple_product')->getProduct());
        self::assertSame($tiers, $simple->getTierPrice());
        self::assertFalse($this->detector->isApplied($this->item($parent, 9)));
    }

    public function testDynamicBundleChildUsesBundleQuantityAndBundleTier(): void
    {
        $parent = $this->item($this->product('bundle')->setPriceType(0)
            ->setTierPrice([$this->tier(10, 0) + ['percentage_value' => 10]]), 10);
        $parent->setHasChildren(true);
        $child = $this->item($this->product()->setTierPrice([]), 1)->setParentItem($parent);
        self::assertTrue($child->isChildrenCalculated());
        self::assertTrue($this->detector->isApplied($child));
        self::assertFalse($this->detector->isApplied($parent));
    }

    public function testDynamicBundleChildCanUseItsOwnQuantityTier(): void
    {
        $parent = $this->item($this->product('bundle')->setPriceType(0)->setTierPrice([]), 5);
        $parent->setHasChildren(true);
        $child = $this->item($this->product(), 2)->setParentItem($parent);
        self::assertTrue($this->detector->isApplied($child));
    }

    public function testFixedBundleUsesPercentageTier(): void
    {
        $bundle = $this->product('bundle')->setPriceType(1)->setPrice(100)
            ->setTierPrice([$this->tier(10, 0) + ['percentage_value' => 10]]);
        self::assertTrue($this->detector->isApplied($this->item($bundle, 10)));
        $bundle->setSpecialPrice(80);
        self::assertFalse($this->detector->isApplied($this->item($bundle, 10)));
    }

    public function testSnapshotSurvivesFirstSaveAndReloadWithoutNewOrderColumns(): void
    {
        $order = self::$om->get(\Magento\Sales\Model\OrderFactory::class)->create()
            ->setStoreId($this->storeId)->setIncrementId('TIER-' . bin2hex(random_bytes(6)))
            ->setState('new')->setStatus('pending')->setCustomerEmail('tier-fixture@example.invalid');
        $order->setPayment(self::$om->get(\Magento\Sales\Model\Order\PaymentFactory::class)->create()->setMethod('checkmo'));
        $quoteItem = $this->item($this->product(), 10);
        $result = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class)->create()
            ->setSku('TIER-FIXTURE')->setQtyOrdered(10)->setPrice(13.29);
        $helper = $this->createMock(\LoyaltyEngage\LoyaltyShop\Helper\Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with($this->storeId)->willReturn(true);
        $helper->method('isTierPricePurchaseExclusionEnabled')->with($this->storeId)->willReturn(true);
        $plugin = new \LoyaltyEngage\LoyaltyShop\Plugin\CaptureTierPriceExclusionPlugin(
            $helper, $this->detector, new \Psr\Log\NullLogger()
        );
        $plugin->afterConvert($this->createMock(\Magento\Quote\Model\Quote\Item\ToOrderItem::class), $result, $quoteItem);
        $order->addItem($result);
        self::$om->get(\Magento\Sales\Model\ResourceModel\Order::class)->save($order);
        $reloaded = self::$om->get(\Magento\Sales\Model\Order\ItemFactory::class)->create();
        self::$om->get(\Magento\Sales\Model\ResourceModel\Order\Item::class)->load($reloaded, $result->getId());
        self::assertSame(1, $reloaded->getProductOptions()[AppliedTierPrice::EXCLUDED_OPTION]);
    }

    public function testDatabasePercentageTierIsLoadedByNativeMagentoBackend(): void
    {
        $id = (int) $this->db->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE type_id = 'simple' LIMIT 1");
        self::assertGreaterThan(0, $id);
        $this->db->delete('catalog_product_entity_tier_price', ['entity_id = ?' => $id]);
        $this->db->insert('catalog_product_entity_tier_price', ['entity_id' => $id, 'all_groups' => 1,
            'customer_group_id' => 0, 'qty' => 10, 'value' => 0, 'percentage_value' => 20, 'website_id' => 0]);
        $product = $this->product()->setId($id)->setPrice(100)->setTierPrice(null);
        $item = $this->item($product, 10);
        self::assertTrue($this->detector->isApplied($item));
        $product->setSpecialPrice(70);
        self::assertFalse($this->detector->isApplied($item));
    }

    public function testTierForAnotherWebsiteDoesNotQualify(): void
    {
        $id = (int) $this->db->fetchOne("SELECT entity_id FROM catalog_product_entity WHERE type_id = 'simple' LIMIT 1");
        $this->db->insert('store_website', ['code' => 'tier_fixture_' . bin2hex(random_bytes(4)), 'name' => 'Tier fixture']);
        $otherWebsite = (int) $this->db->lastInsertId('store_website');
        $this->db->delete('catalog_product_entity_tier_price', ['entity_id = ?' => $id]);
        $this->db->insert('catalog_product_entity_tier_price', ['entity_id' => $id, 'all_groups' => 1,
            'customer_group_id' => 0, 'qty' => 10, 'value' => 5, 'website_id' => $otherWebsite]);
        $product = $this->product()->setId($id)->setTierPrice(null);
        self::assertFalse($this->detector->isApplied($this->item($product, 10)));
    }

    public function testLowerCatalogRulePriceWinsOverQuantityTier(): void
    {
        $product = $this->product()->setId(random_int(1000000000, 2000000000));
        $websiteId = (int) self::$om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore($this->storeId)->getWebsiteId();
        $date = self::$om->get(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)->scopeDate($this->storeId);
        $this->db->insert('catalogrule_product_price', ['product_id' => $product->getId(), 'website_id' => $websiteId,
            'customer_group_id' => 1, 'rule_price' => 10, 'rule_date' => $date->format('Y-m-d')]);
        self::assertFalse($this->detector->isApplied($this->item($product, 50)));
    }

    private function product(string $type = 'simple'): Product
    {
        return self::$om->get(\Magento\Catalog\Model\ProductFactory::class)->create()
            ->setStoreId($this->storeId)->setTypeId($type)->setPrice(15.97)
            ->setTierPrice([$this->tier(10, 13.29), $this->tier(50, 12.59)]);
    }

    private function tier(float $qty, float $price, int $group = 32000): array
    {
        return ['price_qty' => $qty, 'price' => $price, 'website_price' => $price, 'cust_group' => $group];
    }

    private function item(Product $product, float $qty, int $group = 1): Item
    {
        $quote = self::$om->get(\Magento\Quote\Model\QuoteFactory::class)->create()
            ->setStoreId($this->storeId)->setCustomerGroupId($group);
        $item = self::$om->get(\Magento\Quote\Model\Quote\ItemFactory::class)->create()
            ->setQuote($quote)->setData('product', $product)->setData('qty', $qty);
        foreach ($product->getCustomOptions() as $option) {
            $item->addOption($option);
        }
        return $item;
    }
}
