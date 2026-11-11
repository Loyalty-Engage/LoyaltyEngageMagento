<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testRedemptionMappingsRequireFourDistinctValidStoreSkus(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function ($path, $type, $id) {
            self::assertSame('store', $type);
            self::assertStringStartsWith('loyalty/wallet/redemption_sku_', $path);
            $denomination = (int) substr($path, strrpos($path, '_') + 1);
            return match ($id) {
                1 => ' NL_' . $denomination . ' ',
                2 => 'BE-' . $denomination,
                3 => $denomination === 50 ? null : 'SKU_' . $denomination,
                4 => 'SAME_SKU',
                5 => 'bad sku',
                default => ['invalid'],
            };
        });
        $config = new Config($scope, new Policy(new Money()));
        self::assertSame([100 => 'NL_1', 500 => 'NL_5', 1000 => 'NL_10', 5000 => 'NL_50'], $config->redemptionSkus(1));
        self::assertSame([100 => 'BE-1', 500 => 'BE-5', 1000 => 'BE-10', 5000 => 'BE-50'], $config->redemptionSkus(2));
        foreach ([0, 3, 4, 5, 6] as $storeId) {
            self::assertSame([], $config->redemptionSkus($storeId));
        }
        self::assertTrue(Config::isRedemptionSku(str_repeat('a', 255)));
        self::assertFalse(Config::isRedemptionSku(str_repeat('a', 256)));
        self::assertFalse(Config::isRedemptionSku("SKU\n"));
    }

    public function testSettingsAreExplicitlyScopedAndInvalidImportsDoNotEarn(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static function ($path, $type, $id): bool {
            self::assertSame('store', $type);
            return $id === 2;
        });
        $scope->method('getValue')->willReturnCallback(static function ($path, $type, $id) {
            self::assertSame('store', $type);
            if (str_ends_with($path, '/name')) { return $id === 2 ? 'Member credit' : ''; }
            return match ($id) {
                1 => json_encode([['tier' => 'gold', 'percentage' => '1']]),
                2 => json_encode([['tier' => 'gold', 'percentage' => '2']]),
                3 => 'invalid json',
                4 => json_encode([['tier' => 'gold', 'percentage' => '101']]),
                default => 'null',
            };
        });
        $config = new Config($scope, new Policy(new Money()));
        self::assertFalse($config->enabled(0));
        self::assertFalse($config->enabled(1));
        self::assertFalse($config->coinsRequired(1));
        self::assertTrue($config->coinsRequired(2));
        self::assertSame(1250, $config->payable(1250, 1));
        self::assertSame(1200, $config->payable(1250, 2));
        self::assertSame(0, $config->payable(99, 2));
        self::assertTrue($config->enabled(2));
        self::assertSame(['gold' => 100], $config->rates(1));
        self::assertSame(['gold' => 200], $config->rates(2));
        self::assertSame([], $config->rates(3));
        self::assertSame([], $config->rates(4));
        self::assertSame([], $config->rates(5));
        self::assertSame('Member credit', $config->name(2));
        self::assertSame('Wallet', $config->name(1));
    }
}
