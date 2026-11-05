<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Personalization;

use LoyaltyEngage\LoyaltyShop\Model\Personalization\Config;
use LoyaltyEngage\LoyaltyShop\Model\Personalization\Validation;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testEnabledRowsAreIsolatedByStoreAndInvalidImportedConfigFailsClosed(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static fn($path, $type, $id) => $id !== 3);
        $scope->method('getValue')->willReturnCallback(static function ($path, $type, $id) {
            self::assertSame('store', $type);
            if (str_ends_with($path, 'tenant_id')) { return '12345678-1234-4234-8234-123456789abc'; }
            return $id === 2 ? '<script>bad</script>' : json_encode([
                ['key' => 'on', 'public_id' => 'bp-12', 'enabled' => 1],
                ['key' => 'off', 'public_id' => 'bp-13', 'enabled' => 0],
            ]);
        });
        $config = new Config($scope, new Validation());
        self::assertSame('on', $config->rules(1)[0]['key']);
        self::assertCount(1, $config->rules(1));
        self::assertSame([], $config->rules(2));
        self::assertSame([], $config->rules(3));
        self::assertNull($config->rule('off', 1));
    }
}
