<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Block;

use LoyaltyEngage\LoyaltyShop\Block\Account\LoyaltyMeta;
use Magento\Framework\View\Layout;
use PHPUnit\Framework\TestCase;

class MetadataPrivacyTest extends TestCase
{
    public function testAccountPageIsPrivateWithoutDisablingStorefrontCache(): void
    {
        $layoutDirectory = dirname(__DIR__, 3) . '/view/frontend/layout/';
        $account = simplexml_load_file($layoutDirectory . 'loyalty_account_index.xml');
        $blocks = $account->xpath('//block[@name="loyaltyshop.customer.account.page"]');
        self::assertCount(1, $blocks);
        self::assertSame('false', (string) $blocks[0]['cacheable']);

        $default = simplexml_load_file($layoutDirectory . 'default.xml');
        self::assertCount(0, $default->xpath('//block[@cacheable="false"]'));
    }

    public function testCacheableLayoutNeverReadsTheCustomerSnapshot(): void
    {
        $layout = $this->createMock(Layout::class);
        $layout->method('isCacheable')->willReturn(true);
        $block = $this->getMockBuilder(LoyaltyMeta::class)->disableOriginalConstructor()
            ->onlyMethods(['canRender', 'getLayout'])->getMock();
        $block->method('canRender')->willReturn(true);
        $block->method('getLayout')->willReturn($layout);
        // Session and repository are deliberately uninitialized: touching them would fail this test.
        self::assertSame([], $block->getLoyaltyMeta());
    }
}
