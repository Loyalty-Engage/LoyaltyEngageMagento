<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Model\OrderSource;
use Magento\Framework\App\Area;
use PHPUnit\Framework\TestCase;

class OrderSourceTest extends TestCase
{
    /**
     * @dataProvider areaCodeProvider
     */
    public function testMapsAreaCodes(string $areaCode, string $expectedSource): void
    {
        self::assertSame($expectedSource, (new OrderSource())->fromAreaCode($areaCode));
    }

    public static function areaCodeProvider(): array
    {
        return [
            'storefront' => [Area::AREA_FRONTEND, OrderSource::STOREFRONT],
            'admin' => [Area::AREA_ADMINHTML, OrderSource::ADMIN],
            'rest' => [Area::AREA_WEBAPI_REST, OrderSource::API],
            'soap' => [Area::AREA_WEBAPI_SOAP, OrderSource::API],
            'graphql' => [Area::AREA_GRAPHQL, OrderSource::API],
            'cron' => [Area::AREA_CRONTAB, OrderSource::UNKNOWN],
        ];
    }

    public function testNormalizesUnexpectedValuesToUnknown(): void
    {
        self::assertSame(OrderSource::UNKNOWN, (new OrderSource())->normalize('custom-import'));
        self::assertSame(OrderSource::UNKNOWN, (new OrderSource())->normalize(null));
    }
}
