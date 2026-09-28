<?php

declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model;

use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Sales\Model\Order;

class OrderExportEligibility
{
    public function __construct(
        private Data $helper,
        private OrderSource $orderSource
    ) {
    }

    /**
     * @return array{eligible: bool, source: string, reason: string, marker?: string}
     */
    public function evaluate(Order $order): array
    {
        $storeId = (int) $order->getStoreId();
        $source = $this->orderSource->normalize((string) $order->getData('loyalty_order_source'));

        if (!in_array($source, $this->helper->getAllowedOrderSources($storeId), true)) {
            return [
                'eligible' => false,
                'source' => $source,
                'reason' => 'order_source_not_allowed',
            ];
        }

        $markers = $this->helper->getExcludedOrderCommentMarkers($storeId);
        if ($markers === []) {
            return ['eligible' => true, 'source' => $source, 'reason' => 'eligible'];
        }

        $histories = $order->getAllStatusHistory();
        $attachedHistories = $order->getStatusHistories();
        if (is_array($attachedHistories)) {
            $histories = array_merge($histories, $attachedHistories);
        }

        foreach ($histories as $history) {
            $comment = trim((string) $history->getComment());
            if ($comment === '') {
                continue;
            }

            foreach ($markers as $marker) {
                if (stripos($comment, $marker) !== false) {
                    return [
                        'eligible' => false,
                        'source' => $source,
                        'reason' => 'excluded_order_comment',
                        'marker' => $marker,
                    ];
                }
            }
        }

        return ['eligible' => true, 'source' => $source, 'reason' => 'eligible'];
    }
}
