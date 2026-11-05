<?php
declare(strict_types=1);
namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Personalization;

use LoyaltyEngage\LoyaltyShop\Model\Personalization\Validation;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    private const UUID = '12345678-1234-4234-8234-123456789abc';

    private function officialSnippet(): string
    {
        return '<script type="text/javascript"> ((a,b,c,d,e,f)=>{'
            . 'a._lecloud=a._lecloud||{event:[],personalization:[]};'
            . 'e=b.getElementsByTagName(c)[0];f=b.createElement(c);'
            . 'f.type="module";f.async=!0;f.src=\'https://le-cloud.app/f/t/\'+d+".js";'
            . 'e.parentNode.insertBefore(f,e);})(window,document,"script","' . self::UUID . '"); </script>';
    }

    public function testOfficialInlineLoaderOnlyStoresUuid(): void
    {
        self::assertSame(self::UUID, (new Validation())->tenant($this->officialSnippet()));
    }

    public function testModifiedOfficialInlineLoaderIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        (new Validation())->tenant(str_replace('f.async=!0;', 'alert(1);f.async=!0;', $this->officialSnippet()));
    }

    public function testOnlyUuidIsStoredForBothOfficialInputs(): void
    {
        $validation = new Validation();
        self::assertSame(self::UUID, $validation->tenant(' ' . strtoupper(self::UUID) . ' '));
        self::assertSame(self::UUID, $validation->tenant('<script async type="module" src="https://le-cloud.app/f/t/' . self::UUID . '.js"></script>'));
        self::assertSame('', $validation->tenant(''));
    }

    /** @dataProvider unsafeLoaderProvider */
    public function testUnsafeLoaderRejected(string $snippet): void
    {
        $this->expectException(LocalizedException::class);
        (new Validation())->tenant($snippet);
    }

    public static function unsafeLoaderProvider(): array
    {
        $src = 'https://le-cloud.app/f/t/' . self::UUID . '.js';
        return [
            ['<script type="module" src="https://evil.test/' . self::UUID . '.js"></script>'],
            ['<script type="module" src="' . $src . '">alert(1)</script>'],
            ['<script type="module" onload="alert(1)" src="' . $src . '"></script>'],
            ['<script type="module" src="' . $src . '?evil=1"></script>'],
            ['<script src="' . $src . '"></script>'],
            ['<script type="module" src="' . $src . '" src="https://evil.test"></script>'],
            ['<script type="module" src="' . $src . '"></script><script>alert(1)</script>'],
            ['javascript:alert(1)'],
        ];
    }

    public function testRowsNormalizeAndEmptyUiPlaceholderIsRemoved(): void
    {
        $rows = (new Validation())->rules(['r1' => ['key' => ' pdp-punten ', 'public_id' => 'bp-12',
            'enabled' => '1', 'placement' => 'automatic', 'page_scope' => 'pages', 'pages' => '/membership/, /about-us',
            'personalized' => '0', 'use_cart_value' => '1'], '__empty' => '']);
        self::assertCount(1, $rows);
        self::assertSame('pdp-punten', $rows['r1']['key']);
        self::assertSame('/membership,/about-us', $rows['r1']['pages']);
        self::assertSame(0, $rows['r1']['personalized']);
    }

    /** @dataProvider invalidRulesProvider */
    public function testInvalidRulesAreRejected(array $rows): void
    {
        $this->expectException(LocalizedException::class);
        (new Validation())->rules($rows);
    }

    public static function invalidRulesProvider(): array
    {
        $valid = ['key' => 'pdp', 'public_id' => 'bp-12'];
        return [
            [[$valid, $valid]],
            [[array_replace($valid, ['key' => 'bad" onclick="x'])]],
            [[array_replace($valid, ['public_id' => '<script>'])]],
            [[array_replace($valid, ['enabled' => 'yes'])]],
            [[array_replace($valid, ['placement' => 'script'])]],
            [[array_replace($valid, ['page_scope' => 'all'])]],
            [[array_replace($valid, ['pages' => 'https://evil.test'])]],
            [[array_replace($valid, ['placement' => 'automatic', 'page_scope' => 'pages'])]],
            [array_fill(0, 101, $valid)],
        ];
    }
}
