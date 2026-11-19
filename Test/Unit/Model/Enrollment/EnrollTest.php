<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{CheckoutPreference, Config, Enroll, Membership};
use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class EnrollTest extends TestCase
{
    public function testNewEnrollmentQueuesOneEventWithLocalAuditAndCannotOverrideIdentity(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('mode')->with(2)->willReturn('required');
        $config->method('label')->willReturn('Join');
        $membership = $this->createMock(Membership::class);
        $membership->method('isEmailMember')->with('member@example.com', 2)->willReturn(false);
        $outbox = $this->createMock(EventOutbox::class);
        $outbox->expects(self::once())->method('publish')->with('loyaltyshop.opt_in_event', self::callback(function (string $json): bool {
            $event = json_decode($json, true);
            return $event['source'] === 'admin' && $event['admin_user_id'] === 6
                && $event['identifier'] === 'member@example.com' && $event['store_id'] === 2
                && $event['consent_label'] === 'Join';
        }));
        self::assertSame('pending', (new Enroll($config, $membership, $outbox))->execute(
            ' member@example.com ', 2, 'admin', ['admin_user_id' => 6, 'store_id' => 3, 'identifier' => 'other@example.com']
        ));
    }

    public function testAlreadyEnrolledOrQueuedCustomersAreNotPublishedAgain(): void
    {
        foreach (['member', 'pending', 'failed'] as $status) {
            $config = $this->createMock(Config::class);
            $config->method('mode')->willReturn('required');
            $membership = $this->createMock(Membership::class);
            $membership->method('isEmailMember')->willReturn($status === 'member');
            $outbox = $this->createMock(EventOutbox::class);
            $outbox->method('find')->willReturn(['status' => $status]);
            $outbox->expects(self::never())->method('publish');
            self::assertSame($status, (new Enroll($config, $membership, $outbox))->execute('member@example.com', 2, 'account'));
        }
    }

    public function testInformationalModeCannotSendOptIn(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn('advisory');
        $outbox = $this->createMock(EventOutbox::class);
        $outbox->expects(self::never())->method('publish');
        $this->expectException(LocalizedException::class);
        (new Enroll($config, $this->createMock(Membership::class), $outbox))->execute('member@example.com', 2, 'registration');
    }

    public function testCheckoutTokenBindsSessionQuoteAndStore(): void
    {
        $token = CheckoutPreference::token(10, 2, 'formkey');
        self::assertSame($token, CheckoutPreference::token(10, 2, 'formkey'));
        self::assertNotSame($token, CheckoutPreference::token(11, 2, 'formkey'));
        self::assertNotSame($token, CheckoutPreference::token(10, 1, 'formkey'));
        self::assertNotSame($token, CheckoutPreference::token(10, 2, 'other'));
    }
}
