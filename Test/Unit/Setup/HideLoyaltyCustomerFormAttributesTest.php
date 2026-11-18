<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Setup;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\AddCustomerLoyaltyAttributesV2;
use LoyaltyEngage\LoyaltyShop\Setup\Patch\Data\HideLoyaltyCustomerFormAttributes;
use Magento\Customer\Model\Metadata\AttributeMetadataCache;
use Magento\Eav\Model\Config;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class HideLoyaltyCustomerFormAttributesTest extends TestCase
{
    private function fixture(array $ids): array
    {
        $db = $this->createMock(AdapterInterface::class);
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($db);
        $setup->method('getTable')->willReturnCallback(static fn ($name) => 'prefix_' . $name);
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')
            ->with(['a' => 'prefix_eav_attribute'], ['attribute_id'])->willReturnSelf();
        $select->expects(self::once())->method('joinInner')
            ->with(['e' => 'prefix_eav_entity_type'], 'e.entity_type_id = a.entity_type_id', [])
            ->willReturnSelf();
        $conditions = [];
        $select->expects(self::exactly(2))->method('where')
            ->willReturnCallback(function ($condition, $value) use (&$conditions, $select) {
                $conditions[$condition] = $value;
                return $select;
            });
        $db->method('select')->willReturn($select);
        $db->expects(self::once())->method('fetchCol')->with($select)
            ->willReturnCallback(function () use ($ids, &$conditions) {
                self::assertSame([
                    'e.entity_type_code = ?' => 'customer',
                    'a.attribute_code IN (?)' => CustomerLoyaltyDataProvider::ATTRIBUTE_CODES,
                ], $conditions);
                return $ids;
            });
        $config = $this->createMock(Config::class);
        $cache = $this->createMock(AttributeMetadataCache::class);
        return [new HideLoyaltyCustomerFormAttributes($setup, $config, $cache), $db, $config, $cache];
    }

    public function testOnlyUpdatesMetadataAndInvalidatesBothCaches(): void
    {
        [$patch, $db, $config, $cache] = $this->fixture([41, 42]);
        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::once())->method('update')->with(
            'prefix_customer_eav_attribute', ['is_visible' => 0], ['attribute_id IN (?)' => [41, 42]]
        );
        $db->expects(self::once())->method('delete')->with('prefix_customer_form_attribute', [
            'attribute_id IN (?)' => [41, 42], 'form_code <> ?' => 'adminhtml_customer',
        ]);
        $db->expects(self::never())->method('query');
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollBack');
        $config->expects(self::once())->method('clear');
        $cache->expects(self::once())->method('clean');
        self::assertSame($patch, $patch->apply());
        self::assertSame([AddCustomerLoyaltyAttributesV2::class], $patch::getDependencies());
        self::assertSame([], $patch->getAliases());
    }

    public function testMissingAttributesNeverProduceAnUnboundedUpdate(): void
    {
        [$patch, $db, $config, $cache] = $this->fixture([]);
        foreach (['beginTransaction', 'update', 'delete', 'commit', 'rollBack'] as $method) {
            $db->expects(self::never())->method($method);
        }
        $config->expects(self::never())->method('clear');
        $cache->expects(self::never())->method('clean');
        self::assertSame($patch, $patch->apply());
    }

    public function testFailureRollsBackAndPropagatesInsteadOfSilentlySkippingRepair(): void
    {
        [$patch, $db, $config, $cache] = $this->fixture([41]);
        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::once())->method('update');
        $db->expects(self::once())->method('delete')->willThrowException(new \RuntimeException('database failure'));
        $db->expects(self::never())->method('commit');
        $db->expects(self::once())->method('rollBack');
        $config->expects(self::never())->method('clear');
        $cache->expects(self::never())->method('clean');
        $this->expectExceptionMessage('database failure');
        $patch->apply();
    }

    public function testNoOpUpdatesStillInvalidateCachesOnRepeatExecution(): void
    {
        [$patch, $db, $config, $cache] = $this->fixture([41]);
        $db->expects(self::once())->method('update')->willReturn(0);
        $db->expects(self::once())->method('delete')->willReturn(0);
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollBack');
        $config->expects(self::once())->method('clear');
        $cache->expects(self::once())->method('clean');
        self::assertSame($patch, $patch->apply());
    }
}
