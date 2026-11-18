<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Plugin;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Plugin\Customer\ExcludeLoyaltyFormAttributes;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use PHPUnit\Framework\TestCase;

class ExcludeLoyaltyFormAttributesTest extends TestCase
{
    public function testFiltersAllLoyaltyFieldsWithoutRemovingOtherFields(): void
    {
        $attributes = [];
        foreach (array_merge(['firstname', 'telephone', 'le_unrelated'], CustomerLoyaltyDataProvider::ATTRIBUTE_CODES) as $code) {
            $attribute = $this->createMock(AttributeMetadataInterface::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attributes[$code] = $attribute;
        }
        $expected = array_intersect_key($attributes, array_flip(['firstname', 'telephone', 'le_unrelated']));
        $plugin = new ExcludeLoyaltyFormAttributes();
        $form = $this->createMock(CustomerMetadataInterface::class);
        self::assertSame($expected, $plugin->afterGetAttributes($form, $attributes));
        self::assertSame($expected, $plugin->afterGetAttributes($form, $expected));
        self::assertSame([], $plugin->afterGetAttributes($form, []));
        self::assertCount(10, $attributes);
        self::assertSame(array_values($expected), $plugin->afterGetAttributes($form, array_values($attributes)));
    }

    public function testMagentoFormDoesNotExtractSubmittedLoyaltyValuesEvenWhenVisibilityIsIgnored(): void
    {
        $attributes = [];
        foreach (CustomerLoyaltyDataProvider::ATTRIBUTE_CODES as $code) {
            $attribute = $this->createMock(AttributeMetadataInterface::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('isVisible')->willReturn(true); // Simulate old, cached metadata.
            $attributes[$code] = $attribute;
        }
        $metadata = $this->createMock(CustomerMetadataInterface::class);
        $metadata->method('getAttributes')->willReturnCallback(
            static fn () => (new ExcludeLoyaltyFormAttributes())->afterGetAttributes($metadata, $attributes)
        );
        $elements = $this->createMock(\Magento\Customer\Model\Metadata\ElementFactory::class);
        $elements->expects(self::never())->method('create');
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParams')->willReturn(array_fill_keys(CustomerLoyaltyDataProvider::ATTRIBUTE_CODES, 999999));
        $form = new \Magento\Customer\Model\Metadata\Form(
            $metadata,
            $this->createMock(\Magento\Customer\Api\AddressMetadataInterface::class),
            $elements,
            $request,
            $this->createMock(\Magento\Framework\Module\Dir\Reader::class),
            $this->createMock(\Magento\Framework\Validator\ConfigFactory::class),
            'customer',
            'customer_account_edit',
            [],
            false
        );
        self::assertSame([], $form->getAllowedAttributes());
        self::assertSame([], $form->extractData($request));
    }

    public function testPluginIsLimitedToFrontendCustomerFormMetadata(): void
    {
        $root = dirname(__DIR__, 3);
        $xml = simplexml_load_file($root . '/etc/frontend/di.xml');
        $plugins = $xml->xpath('//type[@name="Magento\\Customer\\Api\\CustomerMetadataInterface"]'
            . '/plugin[@name="loyaltyshop_exclude_loyalty_form_attributes"]');
        self::assertCount(1, $plugins);
        self::assertSame(ExcludeLoyaltyFormAttributes::class, (string) $plugins[0]['type']);
        self::assertSame(['afterGetAttributes'], get_class_methods(ExcludeLoyaltyFormAttributes::class));
        foreach (['etc/di.xml', 'etc/adminhtml/di.xml', 'etc/webapi_rest/di.xml', 'etc/graphql/di.xml'] as $file) {
            if (is_file($root . '/' . $file)) {
                self::assertStringNotContainsString(ExcludeLoyaltyFormAttributes::class, file_get_contents($root . '/' . $file));
            }
        }
    }

    public function testMagentoKeepsHiddenFieldsInCustomAttributeMetadataForRepositoryReads(): void
    {
        $attributes = [];
        foreach (CustomerLoyaltyDataProvider::ATTRIBUTE_CODES as $code) {
            $attributes[] = new \Magento\Customer\Model\Data\AttributeMetadata([
                'attribute_code' => $code,
                AttributeMetadataInterface::VISIBLE => false,
                AttributeMetadataInterface::SYSTEM => false,
                AttributeMetadataInterface::USER_DEFINED => true,
            ]);
        }
        $metadata = $this->getMockBuilder(\Magento\Customer\Model\Metadata\CustomerMetadata::class)
            ->disableOriginalConstructor()->onlyMethods(['getAllAttributesMetadata'])->getMock();
        $metadata->method('getAllAttributesMetadata')->willReturn($attributes);
        self::assertSame($attributes, $metadata->getCustomAttributesMetadata());
        foreach ($attributes as $attribute) {
            self::assertFalse($attribute->isVisible());
        }
    }
}
