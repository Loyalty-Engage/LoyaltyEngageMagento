<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Personalization;

use LoyaltyEngage\LoyaltyShop\Block\Personalization\Bootstrap;
use PHPUnit\Framework\TestCase;

class PageScopeTest extends TestCase
{
    public function testNativeAndHyvaCheckoutUseTheSameScope(): void
    {
        self::assertSame('checkout', Bootstrap::scopeForAction('checkout_index_index'));
        self::assertSame('checkout', Bootstrap::scopeForAction('hyva_checkout_index_index'));
        self::assertSame('cart', Bootstrap::scopeForAction('checkout_cart_index'));
        self::assertSame('product', Bootstrap::scopeForAction('catalog_product_view'));
        self::assertSame('pages', Bootstrap::scopeForAction('cms_page_view'));
    }
}
