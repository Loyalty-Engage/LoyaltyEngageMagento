<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{Config, Membership, Choice};
use LoyaltyEngage\LoyaltyShop\Model\{CustomerLoyaltyDataProvider, EventOutbox};
use PHPUnit\Framework\TestCase;

class EnrollmentTest extends TestCase
{
    public function testWalletHtmlPluginSupportsBothRegisteredBlockTypes(): void
    {
        foreach ([false, true] as $allowed) {
            $membership = $this->createMock(Membership::class);
            $membership->method('allowsWallet')->with(7, 2)->willReturn($allowed);
            $session = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
                ->disableOriginalConstructor()->onlyMethods(['getCustomerId'])->getMock();
            $session->method('getCustomerId')->willReturn(7);
            $store = $this->createMock(\Magento\Store\Api\Data\StoreInterface::class);
            $store->method('getId')->willReturn(2);
            $stores = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
            $stores->method('getStore')->willReturn($store);
            $plugin = new \LoyaltyEngage\LoyaltyShop\Plugin\Enrollment\WalletAccess($membership, $session, $stores);
            foreach ([\LoyaltyEngage\LoyaltyShop\Block\Account\Wallet::class,
                \LoyaltyEngage\LoyaltyShop\Block\Account\WalletNavigationLink::class] as $class) {
                $block = $this->getMockBuilder($class)->disableOriginalConstructor()->getMock();
                $calls = 0;
                $html = $plugin->aroundToHtml($block, static function () use (&$calls): string { ++$calls; return 'wallet'; });
                self::assertSame($allowed ? 'wallet' : '', $html);
                self::assertSame($allowed ? 1 : 0, $calls);
            }
        }
    }

    public function testOnlySupportedFilledFieldsProveMembershipIncludingZero(): void
    {
        foreach ([[], ['le_points' => null], ['le_current_tier' => '  '], ['customer_id' => 1], ['le_points' => false]] as $row) {
            self::assertFalse(Membership::hasFields($row));
        }
        foreach ([['le_points' => 0], ['le_available_coins' => '0'], ['le_current_tier' => 'base']] as $row) {
            self::assertTrue(Membership::hasFields($row));
        }
    }

    public function testDisabledAndInformationalModesDoNotReadCustomerOrSendEvents(): void
    {
        foreach (['disabled', 'advisory'] as $mode) {
            $config = $this->createMock(Config::class);
            $config->method('mode')->willReturn($mode);
            $customers = $this->createMock(\Magento\Customer\Api\CustomerRepositoryInterface::class);
            $customers->expects(self::never())->method('getById');
            $outbox = $this->createMock(EventOutbox::class);
            $outbox->expects(self::never())->method('find');
            $membership = new Membership($config, $customers, $this->createMock(CustomerLoyaltyDataProvider::class), $outbox);
            self::assertTrue($membership->allowsWallet(7, 2));
        }
    }

    public function testUnconfirmedEnrollmentDoesNotExposeWallet(): void
    {
        foreach (['pending', 'failed', 'sent'] as $status) {
            $config = $this->createMock(Config::class);
            $config->method('mode')->willReturn('required');
            $customer = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
            $customer->method('getEmail')->willReturn('member@example.com');
            $customers = $this->createMock(\Magento\Customer\Api\CustomerRepositoryInterface::class);
            $customers->method('getById')->with(7)->willReturn($customer);
            $data = $this->createMock(CustomerLoyaltyDataProvider::class);
            $data->expects(self::once())->method('getCustomerLoyaltyData')->with($customer, 2)->willReturn([]);
            $outbox = $this->createMock(EventOutbox::class);
            $outbox->method('find')->with('loyaltyshop.opt_in_event', ['store_id' => 2, 'identifier' => 'member@example.com'])
                ->willReturn(['status' => $status]);
            self::assertSame($status === 'sent', (new Membership($config, $customers, $data, $outbox))->allowsWallet(7, 2));
        }
    }

    public function testOptInIdentityIgnoresOrderAndLabelButNotStore(): void
    {
        $first = ['store_id' => 1, 'identifier' => ' Member@Example.com ', 'order_id' => 1];
        $next = ['store_id' => 1, 'identifier' => 'member@example.com', 'order_id' => 2, 'consent_label' => 'Changed'];
        self::assertSame(EventOutbox::eventKey('loyaltyshop.opt_in_event', $first), EventOutbox::eventKey('loyaltyshop.opt_in_event', $next));
        $next['store_id'] = 2;
        self::assertNotSame(EventOutbox::eventKey('loyaltyshop.opt_in_event', $first), EventOutbox::eventKey('loyaltyshop.opt_in_event', $next));
    }

    public function testWirePayloadContainsOnlyDocumentedFields(): void
    {
        $helper = $this->createMock(\LoyaltyEngage\LoyaltyShop\Helper\Data::class);
        $helper->method('isLoyaltyEngageEnabled')->with(2)->willReturn(true);
        $helper->method('getApiUrl')->with(2)->willReturn('https://example.com');
        $api = $this->createMock(\LoyaltyEngage\LoyaltyShop\Service\ApiClient::class);
        $api->expects(self::once())->method('post')->with('https://example.com/api/v1/events', [[
            'event' => 'LoyaltyEngageOptIn', 'identifier' => 'member@example.com',
        ]], 2)->willReturn([]);
        (new \LoyaltyEngage\LoyaltyShop\Model\Queue\OptInConsumer($helper, $api))->deliver([
            'event' => 'LoyaltyEngageOptIn', 'identifier' => 'member@example.com', 'store_id' => 2,
            'consent_label' => 'Private audit text', 'order_id' => 123,
        ]);
    }

    public function testPlacementRequiresExplicitRealChoiceAndDoesNotEnrollExistingMembers(): void
    {
        foreach ([[false, false], [true, true], [true, false]] as [$selected, $existing]) {
            $config = $this->createMock(Config::class);
            $config->method('mode')->willReturn('required');
            $choice = $this->createMock(Choice::class);
            $choice->method('get')->with(10, 2)->willReturn([
                'mode' => 'required', 'selected' => $selected, 'label' => 'Join', 'updated_at' => '2026-11-11 00:00:00',
            ]);
            $membership = $this->createMock(Membership::class);
            $membership->method('isEmailMember')->willReturn($existing);
            $outbox = $this->createMock(EventOutbox::class);
            $outbox->expects($selected && !$existing ? self::once() : self::never())->method('publish');
            $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $order->setId(1)->setQuoteId(10)->setStoreId(2)->setCustomerId(7)->setCustomerEmail('member@example.com')->setIncrementId('100');
            (new \LoyaltyEngage\LoyaltyShop\Observer\Enrollment\OrderPlaced($config, $choice, $membership, $outbox))
                ->execute(new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event(['order' => $order])]));
        }
    }
}
