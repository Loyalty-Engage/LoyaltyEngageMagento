<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote\TotalsCollector;
use Magento\Quote\Model\Quote;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class QuoteTotalsCollectorPlugin
{
    // Component identifier for totals collection operations
    const COMPONENT_TOTALS = 'TOTALS-COLLECTOR';

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var array
     */
    private $beforeTotals = [];

    /**
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(LoyaltyLogger $loyaltyLogger)
    {
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Before totals collection - capture current state
     *
     * @param TotalsCollector $subject
     * @param Quote $quote
     * @return array
     */
    public function beforeCollect(TotalsCollector $subject, Quote $quote)
    {
        $quoteId = $quote->getId() ?: 'new';
        
        // Store current totals before collection
        $this->beforeTotals[$quoteId] = [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'base_subtotal' => $quote->getBaseSubtotal(),
            'grand_total' => $quote->getGrandTotal(),
            'base_grand_total' => $quote->getBaseGrandTotal(),
            'coupon_code' => $quote->getCouponCode(),
            'applied_rule_ids' => $quote->getAppliedRuleIds(),
            'items_count' => $quote->getItemsCount(),
            'items_qty' => $quote->getItemsQty()
        ];

        // Get stack trace to identify caller
        $stackTrace = $this->getStackTrace();
        
        $this->loyaltyLogger->info(
            self::COMPONENT_TOTALS,
            'BEFORE-COLLECT',
            sprintf('Quote %s totals collection started - Triggered by: %s', $quoteId, $stackTrace['summary']),
            [
                'phase' => 'BEFORE-COLLECT',
                'quote_id' => $quoteId,
                'before_totals' => $this->beforeTotals[$quoteId],
                'items' => $this->getItemsData($quote),
                'collect_trigger_stack_trace' => $stackTrace
            ]
        );

        return [$quote];
    }

    /**
     * After totals collection - detect changes and log
     *
     * @param TotalsCollector $subject
     * @param \Magento\Quote\Model\Quote\Address\Total $result
     * @param Quote $quote
     * @return \Magento\Quote\Model\Quote\Address\Total
     */
    public function afterCollect(TotalsCollector $subject, \Magento\Quote\Model\Quote\Address\Total $result, Quote $quote)
    {
        $quoteId = $quote->getId() ?: 'new';
        
        if (!isset($this->beforeTotals[$quoteId])) {
            return $result;
        }

        $before = $this->beforeTotals[$quoteId];
        
        // Get totals from the quote after collection (not from result which is Total object)
        $after = [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'base_subtotal' => $quote->getBaseSubtotal(),
            'grand_total' => $quote->getGrandTotal(),
            'base_grand_total' => $quote->getBaseGrandTotal(),
            'coupon_code' => $quote->getCouponCode(),
            'applied_rule_ids' => $quote->getAppliedRuleIds(),
            'items_count' => $quote->getItemsCount(),
            'items_qty' => $quote->getItemsQty()
        ];

        // Detect suspicious changes during totals collection
        $this->detectTotalsChanges($quoteId, $before, $after);

         $this->loyaltyLogger->info(
            LoyaltyLogger::COMPONENT_API,
            'DEBUG',
            'afterCollect method',
            ['result' => $result->getData()]
        );
        
        // Log final state after collection
        $this->loyaltyLogger->info(
            self::COMPONENT_TOTALS,
            'AFTER-COLLECT',
            sprintf('Quote %s totals collection completed', $quote->getId()),
            [
                'phase' => 'AFTER-COLLECT',
                'quote_id' => $quoteId,
                'before_totals' => $before,
                'after_totals' => $after,
                'changes_detected' => $this->getTotalsChanges($before, $after),
                'items' => $this->getItemsData($quote),
                'total_object_data' => [
                    'subtotal' => $result->getSubtotal(),
                    'base_subtotal' => $result->getBaseSubtotal(),
                    'grand_total' => $result->getGrandTotal(),
                    'base_grand_total' => $result->getBaseGrandTotal()
                ]
            ]
        );

        // Clean up stored data
        unset($this->beforeTotals[$quoteId]);
        
        return $result;
    }

    /**
     * Detect and log suspicious changes during totals collection
     *
     * @param string $quoteId
     * @param array $before
     * @param array $after
     */
    private function detectTotalsChanges(string $quoteId, array $before, array $after): void
    {
        // Check for suspicious grand total changes
        if ($before['grand_total'] > 0 && $after['grand_total'] == 0) {
            $this->loyaltyLogger->critical(
                self::COMPONENT_TOTALS,
                'SUSPICIOUS-ZERO-TOTAL',
                sprintf('Grand total zeroed during collection: %.2f → 0.00', $before['grand_total']),
                [
                    'quote_id' => $quoteId,
                    'before_totals' => $before,
                    'after_totals' => $after,
                    'stack_trace' => $this->getStackTrace(),
                    'potential_conflict' => $this->detectModuleConflicts()
                ]
            );
        }

        // Check for other significant changes
        $significantFields = ['subtotal', 'base_subtotal', 'grand_total', 'base_grand_total'];
        foreach ($significantFields as $field) {
            if (abs($before[$field] - $after[$field]) > 0.01) {
                $logLevel = ($field === 'grand_total' && $after[$field] == 0) ? 'critical' : 'info';
                
                $this->loyaltyLogger->$logLevel(
                    self::COMPONENT_TOTALS,
                    'TOTALS-CHANGE',
                    sprintf('%s changed during collection: %.2f → %.2f', $field, $before[$field], $after[$field]),
                    [
                        'quote_id' => $quoteId,
                        'field' => $field,
                        'before_value' => $before[$field],
                        'after_value' => $after[$field],
                        'difference' => $after[$field] - $before[$field],
                        'stack_trace' => $this->getStackTrace()['summary']
                    ]
                );
            }
        }
    }

    /**
     * Get totals changes summary
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    private function getTotalsChanges(array $before, array $after): array
    {
        $changes = [];
        $fields = ['subtotal', 'subtotal_with_discount', 'base_subtotal', 'grand_total', 'base_grand_total'];
        
        foreach ($fields as $field) {
            if (abs($before[$field] - $after[$field]) > 0.01) {
                $changes[$field] = [
                    'before' => $before[$field],
                    'after' => $after[$field],
                    'difference' => $after[$field] - $before[$field]
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Check if item is a loyalty product
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @return bool
     */
    private function isLoyaltyProduct($item): bool
    {
        $loyaltyOption = $item->getOptionByCode('loyalty_locked_qty');
        if ($loyaltyOption && $loyaltyOption->getValue() === '1') {
            return true;
        }
        
        $loyaltyData = $item->getData('loyalty_locked_qty');
        return $loyaltyData === '1' || $loyaltyData === 1;
    }

    /**
     * Get items data for logging
     *
     * @param Quote $quote
     * @return array
     */
    private function getItemsData(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllItems() as $item) {
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'price' => $item->getPrice(),
                'custom_price' => $item->getCustomPrice(),
                'original_custom_price' => $item->getOriginalCustomPrice(),
                'row_total' => $item->getRowTotal(),
                'qty' => $item->getQty(),
                'is_loyalty' => $this->isLoyaltyProduct($item)
            ];
        }
        return $items;
    }

    /**
     * Get detailed stack trace information
     *
     * @return array
     */
    private function getStackTrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $relevantTrace = [];
        $summary = 'Unknown';
        $primaryCaller = null;
        
        foreach ($trace as $step) {
            if (!isset($step['file']) || !isset($step['class'])) {
                continue;
            }
            
            $isCore = strpos($step['file'], '/vendor/magento/') !== false;
            $isB2B = strpos($step['file'], '/vendor/magento/') !== false && 
                     (strpos($step['class'], 'Company') !== false || strpos($step['class'], 'B2b') !== false);
            $isExternal = !$isCore && strpos($step['file'], '/app/code/') !== false && 
                         strpos($step['file'], '/LoyaltyEngage/LoyaltyShop/') === false;
            
            $stepData = [
                'class' => $step['class'] ?? 'Unknown',
                'method' => $step['function'] ?? 'unknown',
                'file' => basename($step['file'] ?? 'unknown'),
                'full_file' => $step['file'] ?? 'unknown',
                'line' => $step['line'] ?? 0,
                'is_magento_core' => $isCore,
                'is_b2b_module' => $isB2B,
                'is_external_module' => $isExternal,
                'is_suspicious' => $isExternal || $isB2B
            ];
            
            $relevantTrace[] = $stepData;
            
            if (!$primaryCaller && ($isCore || $isExternal || $isB2B)) {
                $primaryCaller = $stepData;
                $summary = $step['class'] . '::' . $step['function'];
            }
        }
        
        return [
            'context' => 'TOTALS_COLLECT_TRIGGER',
            'summary' => $summary,
            'primary_caller' => $primaryCaller,
            'full_chain' => $relevantTrace,
            'external_modules' => array_filter($relevantTrace, function($step) { return $step['is_external_module']; }),
            'b2b_modules' => array_filter($relevantTrace, function($step) { return $step['is_b2b_module']; }),
            'magento_core_calls' => array_filter($relevantTrace, function($step) { return $step['is_magento_core']; }),
            'suspicious_callers' => array_filter($relevantTrace, function($step) { return $step['is_suspicious']; })
        ];
    }

    /**
     * Detect potential module conflicts
     *
     * @return array
     */
    private function detectModuleConflicts(): array
    {
        $stackTrace = $this->getStackTrace();
        $conflicts = [];
        
        if (!empty($stackTrace['b2b_modules'])) {
            $conflicts[] = 'B2B_MODULE_DETECTED';
        }
        
        if (!empty($stackTrace['external_modules'])) {
            $conflicts[] = 'EXTERNAL_MODULE_DETECTED';
        }
        
        if (!empty($stackTrace['suspicious_callers'])) {
            $conflicts[] = 'SUSPICIOUS_CALLER_DETECTED';
        }
        
        return $conflicts;
    }
}
