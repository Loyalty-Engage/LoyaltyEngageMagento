<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Test\Unit\Model;

use LoyaltyEngage\LoyaltyShop\Model\EventOutbox;
use PHPUnit\Framework\TestCase;

class EventOutboxTest extends TestCase
{
    public function testRepeatedOrderStatusesUseOnePurchaseIdentity(): void
    {
        $a = ['orderId' => '1001', 'store_id' => 20, 'orderDate' => '2026-09-20'];
        self::assertSame(EventOutbox::eventKey('loyaltyshop.purchase_event', $a),
            EventOutbox::eventKey('loyaltyshop.purchase_event', $a + ['status' => 'complete']));
        self::assertNotSame(EventOutbox::eventKey('loyaltyshop.purchase_event', $a),
            EventOutbox::eventKey('loyaltyshop.purchase_event', array_replace($a, ['store_id' => 21])));
    }

    public function testPartialRefundsHaveDistinctIdentities(): void
    {
        $a = ['orderId' => '1001', 'store_id' => 20, 'creditmemo_id' => 1];
        self::assertNotSame(EventOutbox::eventKey('loyaltyshop.return_event', $a),
            EventOutbox::eventKey('loyaltyshop.return_event', array_replace($a, ['creditmemo_id' => 2])));
    }

    public function testReviewsAreNotReexportedOnAnotherSave(): void
    {
        $a = ['review_id' => 12, 'store_id' => 20, 'timestamp' => 10];
        self::assertSame(EventOutbox::eventKey('loyaltyshop.review_event', $a),
            EventOutbox::eventKey('loyaltyshop.review_event', array_replace($a, ['timestamp' => 20])));
    }

    public function testEachReaddedCartItemCanBeRemovedAgain(): void
    {
        $a = ['store_id' => 20, 'sku' => 'SKU', 'removal_id' => 123];
        self::assertNotSame(EventOutbox::eventKey('loyaltyshop.free_product_remove_event', $a),
            EventOutbox::eventKey('loyaltyshop.free_product_remove_event', array_replace($a, ['removal_id' => 124])));
    }
}
