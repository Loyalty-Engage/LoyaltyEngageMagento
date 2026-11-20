<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Referral;

use LoyaltyEngage\LoyaltyShop\Model\CustomerLoyaltyDataProvider;
use LoyaltyEngage\LoyaltyShop\Model\Referral\Invitation;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class InvitationTest extends TestCase
{
    public function testAllowsOnlyHttpsWithOnePlaceholderInPathOrQuery(): void
    {
        foreach ([
            'https://example.com/invite/{contact_id}',
            'https://example.com/join?m=1&ref={contact_id}',
        ] as $template) {
            self::assertTrue(Invitation::isValidTemplate($template));
        }
        foreach ([
            '', 'http://example.com/{contact_id}', '//example.com/{contact_id}', 'https://example.com/join',
            'https://{contact_id}.example.com/', 'https://example.com/#{contact_id}',
            'https://example.com/{contact_id}?ref={contact_id}', 'https://example.com/{other}/{contact_id}',
            'https://example.com\\@evil.example/{contact_id}', 'https://user@example.com/{contact_id}',
            "https://example.com/\t{contact_id}", 'javascript:{contact_id}',
        ] as $template) {
            self::assertFalse(Invitation::isValidTemplate($template), $template);
        }
    }

    public function testContactIdIsRequiredAndEncodedInCurrentStore(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn(true);
        $config->method('getValue')->willReturnMap([
            [Invitation::CONFIG_PATH, 'store', 1, 'https://default.example.com/join?ref={contact_id}'],
            [Invitation::CONFIG_PATH, 'store', 2, 'https://hyva.example.com/invite/{contact_id}'],
        ]);
        $data = $this->createMock(CustomerLoyaltyDataProvider::class);
        $data->expects(self::exactly(3))->method('getContactId')->willReturnMap([
            [9, 1, '00042/&?'], [9, 2, null], [10, 2, '  '],
        ]);
        $invitation = new Invitation($config, $data);
        self::assertSame('https://default.example.com/join?ref=00042%2F%26%3F', $invitation->getUrl(9, 1));
        self::assertNull($invitation->getUrl(9, 2));
        self::assertNull($invitation->getUrl(10, 2));
        self::assertNull($invitation->getUrl(0, 1));
    }

    public function testDisabledOrInvalidConfigurationDoesNotReadContactIds(): void
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnMap([
            ['loyalty/general/module_enable', 'store', 1, true],
            ['loyalty/general/module_enable', 'store', 2, false],
        ]);
        $config->expects(self::once())->method('getValue')
            ->with(Invitation::CONFIG_PATH, 'store', 1)->willReturn('');
        $data = $this->createMock(CustomerLoyaltyDataProvider::class);
        $data->expects(self::never())->method('getContactId');
        $invitation = new Invitation($config, $data);
        self::assertNull($invitation->getUrl(9, 1));
        self::assertNull($invitation->getUrl(9, 2));
    }
}
