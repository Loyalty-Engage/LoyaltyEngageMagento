<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Wallet;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use LoyaltyEngage\LoyaltyShop\Model\MutationJournal;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\DiscountPurchase;
use LoyaltyEngage\LoyaltyShop\Service\ApiClient;
use LoyaltyEngage\LoyaltyShop\Service\ApiRejectionException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class DiscountPurchaseTest extends TestCase
{
    public function testOnlyExplicitlyRejectedSlotIsRetried(): void
    {
        $rows = [];
        $selected = '';
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(static function ($condition, $key) use ($select, &$selected) {
            $selected = $key;
            return $select;
        });
        $db = $this->createMock(AdapterInterface::class);
        $db->method('getTransactionLevel')->willReturn(0);
        $db->method('select')->willReturn($select);
        $db->method('fetchRow')->willReturnCallback(static function () use (&$rows, &$selected) {
            return $rows[$selected] ?? false;
        });
        $db->method('insert')->willReturnCallback(static function ($table, $row) use (&$rows) {
            $rows[$row['operation_key']] = $row + ['response' => null];
            return 1;
        });
        $db->method('update')->willReturnCallback(static function ($table, $changes, $where) use (&$rows) {
            $key = $where['operation_key = ?'];
            $rows[$key] = array_replace($rows[$key], $changes);
            return 1;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $helper = $this->createMock(Data::class);
        $helper->method('getApiUrl')->with(2)->willReturn('https://example.test/');
        $identifier = str_repeat('a', 64);
        $calls = [];
        $api = $this->createMock(ApiClient::class);
        $api->expects(self::exactly(4))->method('post')->willReturnCallback(
            static function ($url, $payload, $store) use (&$calls, $identifier) {
                self::assertSame('https://example.test/api/v1/loyalty/shop/' . $identifier . '/cart/buy_discount_code', $url);
                self::assertSame(2, $store);
                self::assertTrue($payload['createNewDiscountCode']);
                $calls[] = $payload;
                return match (count($calls)) {
                    2 => throw new ApiRejectionException('AVAILABLE_COINS_INSUFFICIENT'),
                    4 => throw new \RuntimeException('Network timeout'),
                    default => ['discountCode' => $payload['discountCode'], 'spentCoins' => 10],
                };
            }
        );
        $service = new DiscountPurchase(new MutationJournal($resource), $api, $helper, $resource);
        $buy = static fn (int $slot, string $sku): array =>
            $service->purchase(2, 3, 4, str_repeat('b', 64), $slot, $identifier, $sku);

        $first = $buy(1, 'EUR_10');
        try {
            $buy(2, 'EUR_1');
            self::fail('The explicit rejection must be reported.');
        } catch (ApiRejectionException $exception) {
            self::assertSame('AVAILABLE_COINS_INSUFFICIENT', $exception->getReason());
        }
        self::assertSame($first, $buy(1, 'EUR_10'));
        self::assertSame(10, $buy(2, 'EUR_1')['spentCoins']);
        self::assertSame($calls[1], $calls[2]);
        self::assertNotSame($calls[0]['discountCode'], $calls[1]['discountCode']);
        try {
            $buy(3, 'EUR_1');
            self::fail('The timeout must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Network timeout', $exception->getMessage());
        }
        $this->expectException(LocalizedException::class);
        $buy(3, 'EUR_1');
    }
}
