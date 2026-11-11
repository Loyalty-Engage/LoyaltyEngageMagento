<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Controller\Wallet\Apply;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use PHPUnit\Framework\TestCase;

class ApplyTest extends TestCase
{
    public function testSuccessfulApplyDoesNotQueueOrClearMessages(): void
    {
        $this->runApply('10', false);
    }

    public function testInvalidAmountStillQueuesAnError(): void
    {
        $this->runApply('-10', true);
    }

    private function runApply(string $amount, bool $invalid): void
    {
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($name, $default = null) => $name === 'amount' ? $amount : $default);
        $messages = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $messages->expects(self::never())->method('addSuccessMessage');
        $messages->expects(self::never())->method('getMessages');
        $messages->expects($invalid ? self::once() : self::never())->method('addErrorMessage');
        $redirect = $this->createMock(\Magento\Framework\Controller\Result\Redirect::class);
        $redirect->method('setPath')->with('checkout')->willReturnSelf();
        $factory = $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);
        $context = $this->createMock(\Magento\Framework\App\Action\Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $validator = $this->createMock(\Magento\Framework\Data\Form\FormKey\Validator::class);
        $validator->method('validate')->willReturn(true);
        $customer = $this->getMockBuilder(\Magento\Customer\Model\Session::class)->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])->getMock();
        $customer->method('isLoggedIn')->willReturn(true);
        $customer->method('getCustomerId')->willReturn(7);
        $checkout = $this->getMockBuilder(\Magento\Checkout\Model\Session::class)->disableOriginalConstructor()
            ->onlyMethods(['getQuoteId'])->getMock();
        $checkout->method('getQuoteId')->willReturn(123);
        $store = $this->createMock(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['collectTotals', 'getPayment'])->getMock();
        $quote->setId(123)->setCustomerId(7)->setBaseGrandTotal(20);
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getPayment')->willReturn($this->createMock(\Magento\Quote\Model\Quote\Payment::class));
        $quotes = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);
        $quotes->method('getActive')->willReturn($quote);
        $quotes->expects($invalid ? self::never() : self::once())->method('save')->with($quote);
        $selection = $this->createMock(Selection::class);
        $selection->expects($invalid ? self::never() : self::once())->method('choose')->with($quote, 1000);
        $locks = $this->createMock(\Magento\Framework\Lock\LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->expects($invalid ? self::never() : self::once())->method('unlock')->with('le-wallet-quote-123');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $controller = new Apply($context, $validator, $customer, $checkout, $stores, $quotes,
            $selection, new Money(), $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class), $logger, $locks);
        self::assertSame($redirect, $controller->execute());
    }
}
