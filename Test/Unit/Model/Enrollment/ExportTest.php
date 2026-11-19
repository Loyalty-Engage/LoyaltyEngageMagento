<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Enrollment;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{ExportAccess, ExportCsv, ExportRows};
use Magento\Authorization\Model\Role;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class ExportTest extends TestCase
{
    public function testStatusAndHistoricalSource(): void
    {
        $pending = ['status' => 'pending', 'payload' => '{"source":"admin"}'];
        self::assertSame(['pending', 'admin'], ExportRows::describe($pending, false, false));
        self::assertSame(['enrolled', 'loyalty_sync'], ExportRows::describe($pending, true, false));
        self::assertSame(['enrolled', 'magento_legacy'], ExportRows::describe([], false, true));
        self::assertSame(['enrolled', 'checkout'], ExportRows::describe([
            'status' => 'sent', 'payload' => '{"order_id":"000001"}',
        ], true, false));
        self::assertSame(['failed', 'unknown'], ExportRows::describe([
            'status' => 'failed', 'payload' => '{"source":"unexpected"}',
        ], false, false));
        self::assertSame(['not_enrolled', ''], ExportRows::describe([], false, false));
    }

    public function testCsvRoundTripEscapesFormulaAndPreservesNumericFields(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $rows = $this->createMock(ExportRows::class);
        $rows->expects(self::once())->method('get')->with($store)->willReturn([
            [2, 'hyva', 7, '+test@example.invalid', 'pending', 'account', '15.01', '9.99'],
            [2, 'hyva', 8, 'normal@example.invalid', 'not_enrolled', '', '0.00', '0.00'],
        ]);
        $stream = fopen('php://memory', 'w+b');
        try {
            (new ExportCsv($rows))->write($stream, $store);
            rewind($stream);
            self::assertSame("\xEF\xBB\xBF", fread($stream, 3));
            self::assertSame(ExportCsv::COLUMNS, fgetcsv($stream, 0, ',', '"', ''));
            self::assertSame(['2', 'hyva', '7', "'+test@example.invalid", 'pending', 'account', '15.01', '9.99'],
                fgetcsv($stream, 0, ',', '"', ''));
            self::assertSame(['2', 'hyva', '8', 'normal@example.invalid', 'not_enrolled', '', '0.00', '0.00'],
                fgetcsv($stream, 0, ',', '"', ''));
            self::assertFalse(fgetcsv($stream, 0, ',', '"', ''));
        } finally {
            fclose($stream);
        }
        foreach (['=a', '+a', '-a', '@a', '  =a', "\t123", "\r123", "\n123"] as $cell) {
            self::assertSame("'" . $cell, ExportCsv::safeCell($cell));
        }
    }

    public function testExportRequiresBothPermissionsAndFailsClosedForRestrictedCommerceScopes(): void
    {
        foreach ([
            [true, false, false, null, false],
            [false, true, false, null, false],
            [true, true, false, null, true],
            [true, true, true, 0, false],
            [true, true, true, null, false],
            [true, true, true, '1', true],
        ] as [$customer, $export, $commerce, $global, $expected]) {
            $authorization = $this->createMock(AuthorizationInterface::class);
            $authorization->method('isAllowed')->willReturnMap([
                ['Magento_Customer::manage', null, $customer],
                [ExportAccess::RESOURCE, null, $export],
            ]);
            $role = $this->createMock(Role::class);
            $role->method('getData')->with('gws_is_all')->willReturn($global);
            $user = $this->createMock(User::class);
            $user->method('getRole')->willReturn($role);
            $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()
                ->addMethods(['getUser'])->getMock();
            $session->method('getUser')->willReturn($user);
            $modules = $this->createMock(Manager::class);
            $modules->method('isEnabled')->with('Magento_AdminGws')->willReturn($commerce);

            self::assertSame($expected, (new ExportAccess($authorization, $session, $modules))->isAllowed());
        }
    }
}
