<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Service;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Service\ApiException;
use LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException;
use LoyaltyEngage\LoyaltyShop\Service\DeferredDeliveryException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    /** @dataProvider rejectionReasons */
    public function testDocumented400IsADefinitiveRejection(string $reason): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(400);
        $curl->method('getBody')->willReturn(json_encode(['message' => $reason]));
        try {
            (new ApiClient($curl, new Json(), $this->helper()))->post('https://example.test', [], 20);
            self::fail('Expected a rejection.');
        } catch (ApiRejectionException $e) {
            self::assertSame(400, $e->getCode());
            self::assertSame($reason, $e->getReason());
            self::assertFalse($e->isRetryable(), 'Queue workers must not automatically retry a business rejection.');
        }
    }

    public static function rejectionReasons(): array
    {
        return array_map(static fn($reason) => [$reason], [
            'EMPTY_REQUEST_BODY', 'INVALID_REQUEST_BODY', 'INVALID_IDENTIFIER', 'INVALID_SKU',
            'SKU_NOT_FOUND', 'QUANTITY_INVALID', 'PRODUCT_NOT_AVAILABLE_IN_LOYALTY_SHOP',
            'DISCOUNT_CODE_TYPE_PRODUCT_NOT_PURCHASABLE', 'LOYALTY_TIER_INSUFFICIENT',
            'AVAILABLE_COINS_INSUFFICIENT', 'PRODUCTS_IN_CART_LIMIT_REACHED', 'MAXIMUM_COIN_SPEND_LIMIT_EXCEEDED',
        ]);
    }

    /** @dataProvider ambiguousResponses */
    public function testOtherFailuresAreNotClassifiedAsDefinitiveRejections(int $status, string $body): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($body);
        try {
            (new ApiClient($curl, new Json(), $this->helper()))->post('https://example.test', [], 20);
            self::fail('Expected an API failure.');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(ApiRejectionException::class, $e);
        }
    }

    public static function ambiguousResponses(): array
    {
        return [
            [400, '<html>Bad request</html>'], [400, ''], [400, '{broken'],
            [400, '{"message":"NEW_UNKNOWN_REASON"}'], [400, '{"message":["AVAILABLE_COINS_INSUFFICIENT"]}'],
            [400, '[{"message":"AVAILABLE_COINS_INSUFFICIENT"}]'],
            [502, '{"message":"AVAILABLE_COINS_INSUFFICIENT"}'],
            [429, '{"message":"AVAILABLE_COINS_INSUFFICIENT"}'],
            [200, '{"success":false,"message":"AVAILABLE_COINS_INSUFFICIENT"}'],
        ];
    }

    public function testHttpMethodDoesNotLeakBetweenRequests(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{}');
        $options = [];
        $methods = [];
        $curl->method('setOption')->willReturnCallback(function ($key, $value) use (&$options) {
            $options[$key] = $value;
        });
        $curl->method('post')->willReturnCallback(function () use (&$options, &$methods) {
            $methods[] = $options[CURLOPT_CUSTOMREQUEST] ?? 'POST';
        });
        $curl->method('get')->willReturnCallback(function () use (&$options, &$methods) {
            $methods[] = $options[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        });
        $api = new ApiClient($curl, new Json(), $this->helper());
        $api->put('https://example.test', [], 20);
        $api->post('https://example.test', [], 20);
        $api->delete('https://example.test', [], 20);
        $api->get('https://example.test', [], 20);
        self::assertSame(['PUT', 'POST', 'DELETE', 'GET'], $methods);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testHtmlSuccessResponseIsNotConsideredDelivered(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('<html>not found</html>');
        $this->expectException(ApiException::class);
        (new ApiClient($curl, new Json(), $this->helper()))->post('https://example.test', [], 20);
    }

    public function testBusinessFailureIsNotConsideredDelivered(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"success":false}');
        $this->expectException(ApiException::class);
        (new ApiClient($curl, new Json(), $this->helper()))->post('https://example.test', [], 20);
    }

    public function testMissingCredentialsDoNotSendAnonymousRequests(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects(self::never())->method('post');
        $this->expectException(DeferredDeliveryException::class);
        (new ApiClient($curl, new Json(), $this->createMock(Data::class)))->post('https://example.test', [], 20);
    }

    public function testZeroAcceptedEventsIsNotConsideredDelivered(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"acceptedEventCount":0}');
        $this->expectException(ApiException::class);
        $this->expectExceptionCode(422);
        (new ApiClient($curl, new Json(), $this->helper()))->post('https://example.test', [], 20);
    }

    public function testRetryPolicyDistinguishesPermanentAndTransientErrors(): void
    {
        self::assertTrue((new ApiException('unavailable', 503))->isRetryable());
        self::assertTrue((new ApiException('limited', 429))->isRetryable());
        self::assertFalse((new ApiException('invalid', 400))->isRetryable());
        self::assertFalse((new ApiException('unauthorized', 401))->isRetryable());
    }

    private function helper(): Data
    {
        $helper = $this->createMock(Data::class);
        $helper->method('getClientId')->with(20)->willReturn('test-tenant');
        $helper->method('getClientSecret')->with(20)->willReturn('test-secret');
        return $helper;
    }
}
