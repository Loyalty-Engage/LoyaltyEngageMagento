<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Controller;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Controller\Cart\Add;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use PHPUnit\Framework\TestCase;

class CustomerActionTest extends TestCase
{
    /** @dataProvider storefrontRejections */
    public function testBusinessRejectionReturns400AndSpecificReason(string $controllerClass, string $method, string $reason): void
    {
        $message = (new \LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException($reason))->getCustomerMessage();
        $errorType = strtolower($reason) . '_400';
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn('{"sku":"TEST","form_key":"valid-key"}');
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('valid-key');
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])->getMock();
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getCustomerId')->willReturn(123);
        $response = $this->createMock(\LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface::class);
        $response->method('getSuccess')->willReturn(false);
        $response->method('getMessage')->willReturn($message);
        $response->method('getErrorType')->willReturn($errorType);
        $cart = $this->createMock(LoyaltyCartInterface::class);
        $cart->expects(self::once())->method($method)->with(123, 'TEST')->willReturn($response);
        $json = $this->createMock(\Magento\Framework\Controller\Result\Json::class);
        $json->expects(self::once())->method('setHttpResponseCode')->with(400)->willReturnSelf();
        $json->expects(self::once())->method('setData')->with(self::callback(static fn($data) =>
            $data['success'] === false && $data['error_type'] === $errorType
            && $data['message'] === $message
        ))->willReturnSelf();
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        self::assertSame($json, (new $controllerClass($request, $factory, $session, $cart,
            $this->createMock(Data::class), $formKey))->execute());
    }

    public static function storefrontRejections(): array
    {
        $cases = [];
        foreach (\LoyaltyEngage\LoyaltyShop\Test\Unit\Service\ApiClientTest::rejectionReasons() as [$reason]) {
            $cases['product:' . $reason] = [Add::class, 'addProduct', $reason];
            $cases['coupon:' . $reason] = [\LoyaltyEngage\LoyaltyShop\Controller\Discount\Claim::class,
                'buyDiscountCodeProduct', $reason];
        }
        return $cases;
    }

    public function testEveryDocumentedRejectionHasADutchTranslation(): void
    {
        $file = fopen(dirname(__DIR__, 3) . '/i18n/nl_NL.csv', 'r');
        self::assertNotFalse($file);
        $translations = [];
        try {
            while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
                self::assertCount(2, $row);
                $translations[$row[0]] = $row[1];
            }
        } finally {
            fclose($file);
        }
        foreach (\LoyaltyEngage\LoyaltyShop\Test\Unit\Service\ApiClientTest::rejectionReasons() as [$reason]) {
            $message = (new \LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException($reason))->getCustomerMessage();
            self::assertArrayHasKey($message, $translations);
            self::assertNotSame('', $translations[$message]);
        }
    }

    /** @dataProvider csrfCases */
    public function testCsrfProtectionPreservesSameOriginAjax(?string $key, bool $ajax, string $origin, string $site, bool $allowed): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn(json_encode(['sku' => 'TEST', 'form_key' => $key]));
        $request->method('isXmlHttpRequest')->willReturn($ajax);
        $request->method('getHeader')->willReturnCallback(static fn($name) => [
            'Origin' => $origin, 'Sec-Fetch-Site' => $site,
        ][$name] ?? false);
        $request->method('getScheme')->willReturn('https');
        $request->method('getHttpHost')->willReturn('shop.example');
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('valid-key');
        $controller = new Add($request, $this->createMock(JsonFactory::class), $this->createMock(Session::class),
            $this->createMock(LoyaltyCartInterface::class), $this->createMock(Data::class), $formKey);
        self::assertSame($allowed, $controller->validateForCsrf($request));
    }

    public static function csrfCases(): array
    {
        return [
            'form key' => ['valid-key', false, '', '', true],
            'same origin legacy AJAX' => [null, true, 'https://shop.example', 'same-origin', true],
            'legacy browser without origin headers' => [null, true, '', '', true],
            'cross origin AJAX' => [null, true, 'https://attacker.example', 'cross-site', false],
            'sibling domain' => [null, true, 'https://other.shop.example', 'same-site', false],
            'plain form without key' => [null, false, 'https://shop.example', 'same-origin', false],
            'invalid key' => ['wrong', false, '', '', false],
        ];
    }
}
