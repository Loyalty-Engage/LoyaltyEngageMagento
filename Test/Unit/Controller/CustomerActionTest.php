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
