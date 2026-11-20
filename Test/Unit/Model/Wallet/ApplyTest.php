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

    public function testRemovingCreditAlsoPersistsTheZeroPreviewWithoutAQuoteSaveEvent(): void
    {
        $this->runApply('0', false);
    }

    public function testApplyRejectsACartThatDidNotResolveToTheCurrentStore(): void
    {
        $this->runApply('10', false, 2);
    }

    private function runApply(string $amount, bool $invalid, int $quoteStoreId = 1): void
    {
        $rejected = $invalid || $quoteStoreId !== 1;
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getParam')->willReturnCallback(static fn($name, $default = null) => $name === 'amount' ? $amount : $default);
        $messages = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $messages->expects(self::never())->method('addSuccessMessage');
        $messages->expects(self::never())->method('getMessages');
        $messages->expects($rejected ? self::once() : self::never())->method('addErrorMessage');
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
            ->onlyMethods(['getQuoteId', 'getQuote'])->getMock();
        $checkout->method('getQuoteId')->willReturn(123);
        $store = $this->createMock(\Magento\Store\Api\Data\StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $quote = $this->getMockBuilder(\Magento\Quote\Model\Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['collectTotals', 'getPayment'])->getMock();
        $quote->setId(123)->setCustomerId(7)->setBaseGrandTotal(20)->setIsActive(true);
        $quote->setData('store_id', $quoteStoreId);
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getPayment')->willReturn($this->createMock(\Magento\Quote\Model\Quote\Payment::class));
        $quotes = $this->createMock(\Magento\Quote\Api\CartRepositoryInterface::class);
        $checkout->expects($invalid ? self::never() : self::once())->method('getQuote')->willReturn($quote);
        $quotes->expects(self::never())->method('getActive');
        $saved = false;
        // No save event is dispatched when Magento considers the quote unchanged.
        $quotes->expects($rejected ? self::never() : self::once())->method('save')->with($quote)
            ->willReturnCallback(static function () use (&$saved): void { $saved = true; });
        $selection = $this->createMock(Selection::class);
        $selection->expects($rejected ? self::never() : self::once())->method('choose')->with($quote, (int) $amount * 100);
        $selection->expects($rejected ? self::never() : self::once())->method('savePreview')->with($quote)
            ->willReturnCallback(static function () use (&$saved): void { self::assertTrue($saved); });
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
