<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Plugin\WebApi;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Plugin\WebApi\AuthenticationPlugin;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Authorization;
use Magento\Framework\Webapi\Rest\Request;
use PHPUnit\Framework\TestCase;

class AuthenticationPluginTest extends TestCase
{
    /** @dataProvider storeParameterCases */
    public function testStoreCodeCannotUseAnotherStoresCredentials(array $params): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('Basic ' . base64_encode('store-a:secret-a'));
        $request->method('getRequestData')->willReturn($params);
        $request->method('getPathInfo')->willReturn('/V1/loyalty/customer/update');
        $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(21);
        $stores->method('getStore')->with('store_b')->willReturn($store);
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('getClientId')->with(21)->willReturn('store-b');
        $helper->method('getClientSecret')->with(21)->willReturn('secret-b');
        $this->expectException(AuthorizationException::class);
        (new AuthenticationPlugin($request, $helper, $stores))->aroundIsAllowed(
            $this->createMock(Authorization::class), static fn() => false, ['LoyaltyEngage_LoyaltyShop::api']
        );
    }

    public static function storeParameterCases(): array
    {
        return [
            'camelCase' => [['storeCode' => 'store_b']],
            'snake_case' => [['store_code' => 'store_b']],
            'camelCase takes precedence' => [['storeCode' => 'store_b', 'store_code' => 'store_a']],
        ];
    }

    public function testCartCannotAuthenticateAgainstAnUnusedBodyStoreCode(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->willReturn('Basic ' . base64_encode('store-b:secret-b'));
        $request->method('getRequestData')->willReturn(['storeCode' => 'store_b']);
        $request->method('getPathInfo')->willReturn('/V1/loyalty/shop/123/cart/add');
        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->expects(self::never())->method('getClientId');
        $this->expectException(AuthorizationException::class);
        (new AuthenticationPlugin($request, $helper, $this->createMock(\Magento\Store\Model\StoreManagerInterface::class)))
            ->aroundIsAllowed($this->createMock(Authorization::class), static fn() => false, ['LoyaltyEngage_LoyaltyShop::api']);
    }

    public function testUnrelatedResourcesUseMagentoAuthorization(): void
    {
        $request = $this->createMock(Request::class);
        $helper = $this->createMock(Data::class);
        $plugin = new AuthenticationPlugin($request, $helper, $this->createMock(\Magento\Store\Model\StoreManagerInterface::class));
        $authorization = $this->createMock(Authorization::class);

        self::assertTrue($plugin->aroundIsAllowed(
            $authorization,
            static fn (array $resources): bool => $resources === ['Magento_Catalog::products'],
            ['Magento_Catalog::products']
        ));
    }

    public function testLoyaltyResourceIsDeniedWithoutValidCredentials(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')->with('Authorization')->willReturn(null);
        $request->method('getPathInfo')->willReturn('/V1/loyalty/customer/update');

        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);

        $plugin = new AuthenticationPlugin($request, $helper, $this->createMock(\Magento\Store\Model\StoreManagerInterface::class));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Invalid or missing authentication credentials.');

        $plugin->aroundIsAllowed(
            $this->createMock(Authorization::class),
            static fn (): bool => true,
            ['LoyaltyEngage_LoyaltyShop::api']
        );
    }

    public function testLoyaltyResourceAcceptsMatchingBasicCredentials(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getHeader')
            ->with('Authorization')
            ->willReturn('Basic ' . base64_encode('tenant-id:secret-token'));

        $helper = $this->createMock(Data::class);
        $helper->method('isLoyaltyEngageEnabled')->willReturn(true);
        $helper->method('getClientId')->willReturn('tenant-id');
        $helper->method('getClientSecret')->willReturn('secret-token');

        $plugin = new AuthenticationPlugin($request, $helper, $this->createMock(\Magento\Store\Model\StoreManagerInterface::class));

        self::assertTrue($plugin->aroundIsAllowed(
            $this->createMock(Authorization::class),
            static fn (): bool => false,
            ['LoyaltyEngage_LoyaltyShop::api']
        ));
    }
}
