<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Model\Quote;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;

class QuoteStateMonitorPlugin
{
    // Component identifier for quote state monitoring
    const COMPONENT_QUOTE_STATE = 'QUOTE-STATE';

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @param LoyaltyLogger $loyaltyLogger
     */
    public function __construct(LoyaltyLogger $loyaltyLogger)
    {
        $this->loyaltyLogger = $loyaltyLogger;
    }

    /**
     * Monitor setGrandTotal calls
     *
     * @param Quote $subject
     * @param float $grandTotal
     * @return array
     */
    public function beforeSetGrandTotal(Quote $subject, $grandTotal)
    {

        $currentGrandTotal = $subject->getGrandTotal();
         $this->loyaltyLogger->info(
            self::COMPONENT_QUOTE_STATE,
            'DEBUG',
            'Entered beforeSetGrandTotal',
            ['currentGrandTotal' => $currentGrandTotal]
        );
        $quoteId = $subject->getId() ?: 'new';
        
        // Log every grand total change with stack trace
        if ($currentGrandTotal != $grandTotal) {

            $this->loyaltyLogger->info(
                self::COMPONENT_QUOTE_STATE,
                'DEBUG',
                'Entered beforeSetGrandTotal If'
            );
            $stackTrace = $this->getStackTrace();
            
            $logLevel = ($currentGrandTotal > 0 && $grandTotal == 0) ? 'critical' : 'info';
            
            $this->loyaltyLogger->$logLevel(
                self::COMPONENT_QUOTE_STATE,
                'GRAND-TOTAL-CHANGE',
                sprintf('Quote %s grand total changing: %.2f → %.2f - Triggered by: %s', 
                    $quoteId, $currentGrandTotal, $grandTotal, $stackTrace['summary']),
                [
                    'quote_id' => $quoteId,
                    'current_grand_total' => $currentGrandTotal,
                    'new_grand_total' => $grandTotal,
                    'difference' => $grandTotal - $currentGrandTotal,
                    'is_suspicious_zero' => ($currentGrandTotal > 0 && $grandTotal == 0),
                    'stack_trace' => $stackTrace,
                    'potential_conflict' => $this->detectModuleConflicts($stackTrace),
                    'quote_state' => [
                        'subtotal' => $subject->getSubtotal(),
                        'base_subtotal' => $subject->getBaseSubtotal(),
                        'items_count' => $subject->getItemsCount(),
                        'items_qty' => $subject->getItemsQty(),
                        'coupon_code' => $subject->getCouponCode()
                    ]
                ]
            );
        }

        return [$grandTotal];
    }

    /**
     * Monitor setBaseGrandTotal calls
     *
     * @param Quote $subject
     * @param float $baseGrandTotal
     * @return array
     */
    public function beforeSetBaseGrandTotal(Quote $subject, $baseGrandTotal)
    {

        $currentBaseGrandTotal = $subject->getBaseGrandTotal();

        $this->loyaltyLogger->info(
            self::COMPONENT_QUOTE_STATE,
            'DEBUG',
            'Entered beforeSetBaseGrandTotal',
            ['currentBaseGrandTotal' => $currentBaseGrandTotal]
        );

         $this->loyaltyLogger->info(
            self::COMPONENT_QUOTE_STATE,
            'DEBUG',
            'Entered baseGrandTotal',
            ['baseGrandTotal' => $baseGrandTotal]
        );


        $quoteId = $subject->getId() ?: 'new';
        
        // Log every base grand total change with stack trace
        if ($currentBaseGrandTotal != $baseGrandTotal) {

            $this->loyaltyLogger->info(
                self::COMPONENT_QUOTE_STATE,
                'DEBUG',
                'Entered beforeSetBaseGrandTotal If'
            );


            $stackTrace = $this->getStackTrace();
            
            $logLevel = ($currentBaseGrandTotal > 0 && $baseGrandTotal == 0) ? 'critical' : 'info';
            
            $this->loyaltyLogger->$logLevel(
                self::COMPONENT_QUOTE_STATE,
                'BASE-GRAND-TOTAL-CHANGE',
                sprintf('Quote %s base grand total changing: %.2f → %.2f - Triggered by: %s', 
                    $quoteId, $currentBaseGrandTotal, $baseGrandTotal, $stackTrace['summary']),
                [
                    'quote_id' => $quoteId,
                    'current_base_grand_total' => $currentBaseGrandTotal,
                    'new_base_grand_total' => $baseGrandTotal,
                    'difference' => $baseGrandTotal - $currentBaseGrandTotal,
                    'is_suspicious_zero' => ($currentBaseGrandTotal > 0 && $baseGrandTotal == 0),
                    'stack_trace' => $stackTrace,
                    'potential_conflict' => $this->detectModuleConflicts($stackTrace)
                ]
            );
        }

        return [$baseGrandTotal];
    }

    /**
     * Monitor setSubtotalWithDiscount calls
     *
     * @param Quote $subject
     * @param float $subtotalWithDiscount
     * @return array
     */
    public function beforeSetSubtotalWithDiscount(Quote $subject, $subtotalWithDiscount)
    {
        $currentSubtotalWithDiscount = $subject->getSubtotalWithDiscount();
        $quoteId = $subject->getId() ?: 'new';
        
        // Log subtotal with discount changes that might affect grand total
        if ($currentSubtotalWithDiscount != $subtotalWithDiscount) {
            $stackTrace = $this->getStackTrace();
            
            $this->loyaltyLogger->info(
                self::COMPONENT_QUOTE_STATE,
                'SUBTOTAL-DISCOUNT-CHANGE',
                sprintf('Quote %s subtotal with discount changing: %.2f → %.2f - Triggered by: %s', 
                    $quoteId, $currentSubtotalWithDiscount, $subtotalWithDiscount, $stackTrace['summary']),
                [
                    'quote_id' => $quoteId,
                    'current_subtotal_with_discount' => $currentSubtotalWithDiscount,
                    'new_subtotal_with_discount' => $subtotalWithDiscount,
                    'difference' => $subtotalWithDiscount - $currentSubtotalWithDiscount,
                    'stack_trace' => $stackTrace['summary'],
                    'current_grand_total' => $subject->getGrandTotal(),
                    'current_subtotal' => $subject->getSubtotal()
                ]
            );
        }

        return [$subtotalWithDiscount];
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
            'context' => 'QUOTE_STATE_CHANGE',
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
     * @param array $stackTrace
     * @return array
     */
    private function detectModuleConflicts(array $stackTrace): array
    {
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
