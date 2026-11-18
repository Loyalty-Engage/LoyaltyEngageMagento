<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Controller;

use LoyaltyEngage\LoyaltyShop\Controller\Account\Index;
use Magento\Customer\Controller\Plugin\Account;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\TestCase;

class AccountIndexTest extends TestCase
{
    public function testGuestRedirectCanReturnNullThroughGeneratedInterceptor(): void
    {
        $returnType = (new \ReflectionMethod(Index::class, 'execute'))->getReturnType();
        self::assertNotNull($returnType);
        self::assertTrue($returnType->allowsNull());

        $request = $this->createMock(RequestInterface::class);
        $request->method('getActionName')->willReturn('index');
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('authenticate')->willReturn(false);
        $controller = $this->createMock(Index::class);
        $plugin = new Account($request, $session);
        $intercepted = static function () use ($plugin, $controller): ?ResultInterface {
            return $plugin->aroundExecute($controller, static function () {
                self::fail('The private account page must not render for a guest.');
            });
        };
        self::assertNull($intercepted());
    }

    public function testAuthenticatedAccountResultIsPreserved(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getActionName')->willReturn('index');
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('authenticate')->willReturn(true);
        $controller = $this->createMock(Index::class);
        $result = $this->createMock(ResultInterface::class);
        $plugin = new Account($request, $session);

        self::assertSame($result, $plugin->aroundExecute($controller, static fn() => $result));
    }
}
