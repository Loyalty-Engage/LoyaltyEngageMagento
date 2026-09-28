<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit;

use PHPUnit\Framework\TestCase;

class FrontendRoutesTest extends TestCase
{
    public function testCurrentAndLegacyCouponRoutesAreRegistered(): void
    {
        $routesFile = dirname(__DIR__, 2) . '/etc/frontend/routes.xml';
        $xml = simplexml_load_file($routesFile);

        self::assertNotFalse($xml);

        $routes = [];
        foreach ($xml->router->route as $route) {
            $routes[(string) $route['frontName']] = (string) $route->module['name'];
        }

        self::assertSame('LoyaltyEngage_LoyaltyShop', $routes['loyalty'] ?? null);
        self::assertSame('LoyaltyEngage_LoyaltyShop', $routes['loyaltyshop'] ?? null);
    }
}
