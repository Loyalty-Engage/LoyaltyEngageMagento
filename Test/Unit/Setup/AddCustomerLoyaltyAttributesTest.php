<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Setup;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\AddCustomerLoyaltyAttributes;
use LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\AddCustomerLoyaltyAttributesV2;
use Magento\Customer\Model\Attribute;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\Set;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class AddCustomerLoyaltyAttributesTest extends TestCase
{
    public function testFreshInstallHidesAllSevenFieldsWithoutChangingTheirApiIdentity(): void
    {
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $db = $this->createMock(AdapterInterface::class);
        $setup->method('getConnection')->willReturn($db);
        $customerSetup = $this->createMock(CustomerSetup::class);
        $factory = $this->createMock(CustomerSetupFactory::class);
        $factory->method('create')->willReturn($customerSetup);
        $config = $this->createMock(Config::class);
        $customerSetup->method('getEavConfig')->willReturn($config);
        $type = $this->createMock(Type::class);
        $type->method('getDefaultAttributeSetId')->willReturn(1);
        $config->method('getEntityType')->with('customer')->willReturn($type);
        $set = $this->createMock(Set::class);
        $set->method('getDefaultGroupId')->willReturn(1);
        $sets = $this->createMock(SetFactory::class);
        $sets->method('create')->willReturn($set);
        $codes = [];
        $customerSetup->expects(self::exactly(7))->method('addAttribute')
            ->willReturnCallback(function ($entity, $code, $data) use (&$codes, $customerSetup) {
                self::assertSame('customer', $entity);
                self::assertFalse($data['visible']);
                self::assertFalse($data['required']);
                self::assertTrue($data['user_defined']);
                self::assertSame(0, $data['system']);
                $codes[] = $code;
                return $customerSetup;
            });
        $attribute = $this->createMock(Attribute::class);
        $attribute->expects(self::exactly(7))->method('addData')->willReturnCallback(
            function ($data) use ($attribute) {
                self::assertSame(['adminhtml_customer'], $data['used_in_forms']);
                return $attribute;
            }
        );
        $attribute->expects(self::exactly(7))->method('save')->willReturnSelf();
        $config->method('getAttribute')->willReturn($attribute);
        (new AddCustomerLoyaltyAttributes($setup, $factory, $sets))->apply();
        (new AddCustomerLoyaltyAttributesV2($setup, $factory, $sets))->apply();
        self::assertSame(CustomerLoyaltyDataProvider::ATTRIBUTE_CODES, $codes);
    }
}
