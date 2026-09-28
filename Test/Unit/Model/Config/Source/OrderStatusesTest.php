<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model\Config\Source;

use LoyaltyEngage\LoyaltyShop\Model\Config\Source\OrderStatuses;
use Magento\Sales\Model\Order\Config as OrderConfig;
use PHPUnit\Framework\TestCase;

class OrderStatusesTest extends TestCase
{
    public function testReturnsEveryConfiguredMagentoOrderStatus(): void
    {
        $orderConfig = $this->createMock(OrderConfig::class);
        $orderConfig->method('getStatuses')->willReturn([
            'processing' => 'Processing',
            'complete' => 'Complete',
            'custom_erp_hold' => 'ERP Hold',
        ]);

        self::assertSame(
            [
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'complete', 'label' => 'Complete'],
                ['value' => 'custom_erp_hold', 'label' => 'ERP Hold'],
            ],
            (new OrderStatuses($orderConfig))->toOptionArray()
        );
    }
}
