<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Personalization;

use LoyaltyEngage\LoyaltyShop\Controller\Personalization\Context;
use LoyaltyEngage\LoyaltyShop\Model\Personalization\Config;
use LoyaltyEngage\LoyaltyShop\Model\Personalization\Amounts;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

class ContextTest extends TestCase
{
    /** @dataProvider contextProvider */
    public function testIdentityIsSessionOnlyAndCartIsOwnerAndStoreBound(bool $loggedIn, bool $personalized,
        int $owner, int $storeId, bool $expectIdentity, bool $expectCart): void
    {
        $data = $this->invoke($loggedIn, $personalized, $owner, $storeId);
        self::assertSame($expectIdentity, isset($data['identifier']));
        self::assertSame($expectCart, isset($data['cartValue']));
        if ($expectIdentity) { self::assertSame(hash('sha256', 'session@example.invalid'), $data['identifier']); }
        if ($expectCart) { self::assertSame(42.0, $data['cartValue']); }
        self::assertArrayNotHasKey('email', $data);
    }

    public static function contextProvider(): array
    {
        return [
            'authenticated current quote' => [true, true, 17, 1, true, true],
            'guest cannot acquire supplied identity' => [false, true, 0, 1, false, true],
            'non-personalized does not read identity' => [true, false, 17, 1, false, true],
            'other customer quote' => [true, true, 99, 1, true, false],
            'other store quote' => [true, true, 17, 2, true, false],
            'logged-out quote previously owned by customer' => [false, true, 17, 1, false, false],
        ];
    }

    public function testExpiredQuoteDoesNotCreateOrMutateACart(): void
    {
        $data = $this->invoke(false, false, 0, 1, true);
        self::assertSame(['cartValue' => 0], $data);
    }

    private function invoke(bool $loggedIn, bool $personalized, int $owner, int $quoteStore, bool $missing = false): array
    {
        $captured = [];
        $headers = [];
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(static function ($data) use (&$captured, $result) {
            $captured = $data;
            return $result;
        });
        $result->method('setHeader')->willReturnCallback(static function ($name, $value) use (&$headers, $result) {
            $headers[$name] = $value;
            return $result;
        });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($result);
        $session = $this->createMock(\Magento\Customer\Model\Session::class);
        $session->method('isLoggedIn')->willReturn($loggedIn);
        $session->method('getCustomerId')->willReturn(17);
        $customer = $this->getMockBuilder(\Magento\Customer\Model\Customer::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $customer->setEmail('session@example.invalid');
        $session->expects($loggedIn && $personalized ? self::once() : self::never())->method('getCustomer')->willReturn($customer);
        $checkout = $this->createMock(\Magento\Checkout\Model\Session::class);
        $checkout->method('getQuoteId')->willReturn(123);
        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'collectTotals'])->getMock();
        $quote->setCustomerId($owner);
        $quote->method('getStoreId')->willReturn($quoteStore);
        $quote->expects(self::never())->method('collectTotals');
        $checkout->expects(self::never())->method('getQuote');
        $quotes = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);
        $quotes->expects(self::never())->method('save');
        $load = $quotes->expects(self::once())->method('getActive')->with(123, [1]);
        if ($missing) {
            $load->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
        } else {
            $load->willReturn($quote);
        }
        $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $store = $this->createMock(\Magento\Store\Model\Store::class);
        $store->method('getId')->willReturn(1);
        $stores->method('getStore')->willReturn($store);
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($key) => [
            'cart' => '1', 'personalized' => '1', 'customer_id' => '99', 'email' => 'attacker@example.invalid',
            'identifier' => str_repeat('f', 64),
        ][$key] ?? null);
        $config = $this->createMock(Config::class);
        $config->method('rules')->willReturn([['personalized' => (int) $personalized, 'use_cart_value' => 1]]);
        $config->method('value')->with('currency', 1)->willReturn('EUR');
        $amounts = $this->createMock(Amounts::class);
        $amounts->method('cart')->willReturn(42.0);
        $helper = $this->createMock(Data::class);
        $helper->method('hashEmail')->with('session@example.invalid')->willReturn(hash('sha256', 'session@example.invalid'));
        (new Context($factory, $session, $checkout, $stores, $request, $config, $amounts, $helper, $quotes))->execute();
        self::assertSame('private, no-store, max-age=0', $headers['Cache-Control']);
        return $captured;
    }
}
