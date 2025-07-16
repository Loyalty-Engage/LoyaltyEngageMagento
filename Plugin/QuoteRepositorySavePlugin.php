<?php
namespace LoyaltyEngage\LoyaltyShop\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use LoyaltyEngage\LoyaltyShop\Helper\Logger as LoyaltyLogger;
use Psr\Log\LoggerInterface;

class QuoteRepositorySavePlugin
{
    // Component identifier for quote operations
    const COMPONENT_QUOTE = 'QUOTE-SAVE';

    /**
     * @var LoyaltyLogger
     */
    private $loyaltyLogger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $originalPrices = [];

    /**
     * @var array
     */
    private $originalTotals = [];

    /**
     * @param LoyaltyLogger $loyaltyLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoyaltyLogger $loyaltyLogger,
        LoggerInterface $logger
    ) {
        $this->loyaltyLogger = $loyaltyLogger;
        $this->logger = $logger;
    }

    /**
     * Before quote save - preserve original prices and log state
     *
     * @param CartRepositoryInterface $subject
     * @param Quote $quote
     * @return array
     */
    public function beforeSave(CartRepositoryInterface $subject, Quote $quote)
    {
        $quoteId = $quote->getId() ?: 'new';
        
        // Store original prices for regular products
        $this->preserveOriginalPrices($quote);
        
        // Store original totals for comparison
        $this->originalTotals[$quoteId] = [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'base_subtotal' => $quote->getBaseSubtotal(),
            'grand_total' => $quote->getGrandTotal(),
            'base_grand_total' => $quote->getBaseGrandTotal(),
            'coupon_code' => $quote->getCouponCode(),
            'applied_rule_ids' => $quote->getAppliedRuleIds()
        ];

        // Get stack trace to identify caller
        $stackTrace = $this->getStackTrace();
        
        // Log detailed quote state before save
        $this->loyaltyLogger->info(
            self::COMPONENT_QUOTE,
            'BEFORE-SAVE',
            sprintf('Quote %s before save - Triggered by: %s', $quoteId, $stackTrace['summary']),
            [
                'phase' => 'BEFORE-SAVE',
                'quote_id' => $quoteId,
                'items_count' => $quote->getItemsCount(),
                'items_qty' => $quote->getItemsQty(),
                'subtotal' => $quote->getSubtotal(),
                'grand_total' => $quote->getGrandTotal(),
                'base_grand_total' => $quote->getBaseGrandTotal(),
                'items' => $this->getItemsData($quote),
                'totals_data' => $this->originalTotals[$quoteId],
                'save_trigger_stack_trace' => $stackTrace
            ]
        );

        return [$quote];
    }

    /**
     * After quote save - restore prices and detect changes
     *
     * @param CartRepositoryInterface $subject
     * @param Quote $result
     * @param Quote $quote
     * @return Quote
     */
    public function afterSave(CartRepositoryInterface $subject, $result, Quote $quote)
    {
        $quoteId = $quote->getId() ?: 'new';
        
        if ($result === null) {
            $this->loyaltyLogger->error(
                self::COMPONENT_QUOTE,
                'NULL-RESULT',
                sprintf('Quote save returned null - Triggered by: %s', $this->getStackTrace()['summary']),
                [
                    'original_quote_id' => $quoteId,
                    'save_trigger' => $this->getStackTrace()['summary'],
                    'stack_trace' => $this->getStackTrace()['full_chain']
                ]
            );
            return $result;
        }

        // Restore original prices for regular products
        $this->restoreOriginalPrices($result);
        
        // Detect and log any suspicious changes
        $this->detectSuspiciousChanges($result, $quoteId);
        
        // Log final state
        $this->logFinalState($result, $quoteId);
        
        // Clean up stored data
        unset($this->originalPrices[$quoteId], $this->originalTotals[$quoteId]);
        
        return $result;
    }

    /**
     * Preserve original prices for regular products
     *
     * @param Quote $quote
     */
    private function preserveOriginalPrices(Quote $quote): void
    {
        $quoteId = $quote->getId() ?: 'new';
        $this->originalPrices[$quoteId] = [];
        
        foreach ($quote->getAllItems() as $item) {
            if (!$this->isLoyaltyProduct($item)) {
                $itemId = $item->getId() ?: $item->getSku();
                $this->originalPrices[$quoteId][$itemId] = [
                    'sku' => $item->getSku(),
                    'price' => $item->getPrice(),
                    'custom_price' => $item->getCustomPrice(),
                    'original_custom_price' => $item->getOriginalCustomPrice(),
                    'base_price' => $item->getBasePrice()
                ];
                
                $this->loyaltyLogger->info(
                    self::COMPONENT_QUOTE,
                    'PROTECTION-SET',
                    sprintf('Save protection enabled for regular product %s', $item->getSku()),
                    [
                        'sku' => $item->getSku(),
                        'original_price' => $item->getPrice(),
                        'original_custom_price' => $item->getCustomPrice()
                    ]
                );
            }
        }
    }

    /**
     * Restore original prices for regular products
     *
     * @param Quote $quote
     */
    private function restoreOriginalPrices(Quote $quote): void
    {
        $quoteId = $quote->getId() ?: 'new';
        
        if (!isset($this->originalPrices[$quoteId])) {
            return;
        }
        
        foreach ($quote->getAllItems() as $item) {
            if (!$this->isLoyaltyProduct($item)) {
                $itemId = $item->getId() ?: $item->getSku();
                
                if (isset($this->originalPrices[$quoteId][$itemId])) {
                    $original = $this->originalPrices[$quoteId][$itemId];
                    
                    // Check if prices were modified
                    $priceChanged = $item->getPrice() != $original['price'];
                    $customPriceChanged = $item->getCustomPrice() != $original['custom_price'];
                    
                    if ($priceChanged || $customPriceChanged) {
                        $this->loyaltyLogger->error(
                            self::COMPONENT_QUOTE,
                            'PROTECTION-RESTORE',
                            sprintf('Restoring original prices for regular product %s', $item->getSku()),
                            [
                                'sku' => $item->getSku(),
                                'price_before' => $item->getPrice(),
                                'price_restored' => $original['price'],
                                'custom_price_before' => $item->getCustomPrice(),
                                'custom_price_restored' => $original['custom_price'],
                                'stack_trace' => $this->getStackTrace()['summary']
                            ]
                        );
                        
                        // Restore original prices
                        $item->setPrice($original['price']);
                        $item->setCustomPrice($original['custom_price']);
                        $item->setOriginalCustomPrice($original['original_custom_price']);
                        $item->setBasePrice($original['base_price']);
                    }
                }
            }
        }
    }

    /**
     * Detect suspicious changes in quote totals
     *
     * @param Quote $quote
     * @param string $quoteId
     */
    private function detectSuspiciousChanges(Quote $quote, string $quoteId): void
    {
        if (!isset($this->originalTotals[$quoteId])) {
            return;
        }
        
        $original = $this->originalTotals[$quoteId];
        $current = [
            'subtotal' => $quote->getSubtotal(),
            'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
            'base_subtotal' => $quote->getBaseSubtotal(),
            'grand_total' => $quote->getGrandTotal(),
            'base_grand_total' => $quote->getBaseGrandTotal(),
            'coupon_code' => $quote->getCouponCode(),
            'applied_rule_ids' => $quote->getAppliedRuleIds()
        ];
        
        // Check for suspicious grand total changes
        if ($original['grand_total'] > 0 && $current['grand_total'] == 0) {
            $this->loyaltyLogger->critical(
                self::COMPONENT_QUOTE,
                'SUSPICIOUS-ZERO-TOTAL',
                sprintf('Grand total changed from %.2f to 0.00 during save', $original['grand_total']),
                [
                    'quote_id' => $quoteId,
                    'original_totals' => $original,
                    'current_totals' => $current,
                    'stack_trace' => $this->getStackTrace(),
                    'potential_conflict' => $this->detectModuleConflicts()
                ]
            );
        }
        
        // Check for other suspicious changes
        foreach (['subtotal', 'base_subtotal', 'grand_total', 'base_grand_total'] as $field) {
            if (abs($original[$field] - $current[$field]) > 0.01) {
                $this->loyaltyLogger->error(
                    self::COMPONENT_QUOTE,
                    'TOTALS-CHANGE',
                    sprintf('%s changed from %.2f to %.2f during save', $field, $original[$field], $current[$field]),
                    [
                        'quote_id' => $quoteId,
                        'field' => $field,
                        'original_value' => $original[$field],
                        'new_value' => $current[$field],
                        'difference' => $current[$field] - $original[$field],
                        'stack_trace' => $this->getStackTrace()['summary']
                    ]
                );
            }
        }
    }

    /**
     * Log final quote state after save
     *
     * @param Quote $quote
     * @param string $quoteId
     */
    private function logFinalState(Quote $quote, string $quoteId): void
    {
        $this->loyaltyLogger->info(
            self::COMPONENT_QUOTE,
            'AFTER-SAVE',
            sprintf('Quote %s after save - Final state', $quote->getId()),
            [
                'phase' => 'AFTER-SAVE',
                'original_quote_id' => $quoteId,
                'final_quote_id' => $quote->getId(),
                'items_count' => $quote->getItemsCount(),
                'items_qty' => $quote->getItemsQty(),
                'subtotal' => $quote->getSubtotal(),
                'grand_total' => $quote->getGrandTotal(),
                'base_grand_total' => $quote->getBaseGrandTotal(),
                'items' => $this->getItemsData($quote),
                'totals_data' => [
                    'subtotal' => $quote->getSubtotal(),
                    'subtotal_with_discount' => $quote->getSubtotalWithDiscount(),
                    'base_subtotal' => $quote->getBaseSubtotal(),
                    'grand_total' => $quote->getGrandTotal(),
                    'base_grand_total' => $quote->getBaseGrandTotal(),
                    'coupon_code' => $quote->getCouponCode(),
                    'applied_rule_ids' => $quote->getAppliedRuleIds()
                ]
            ]
        );
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
                'is_loyalty' => $this->isLoyaltyProduct($item),
                'loyalty_detection' => [
                    'option_check' => $item->getOptionByCode('loyalty_locked_qty') ? 
                        $item->getOptionByCode('loyalty_locked_qty')->getValue() : 'not_found',
                    'data_check' => $item->getData('loyalty_locked_qty') ?? 'not_found'
                ]
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
            
            // Specific module detection
            $isEuVat = strpos($step['class'], 'Geissweb\\Euvat') !== false || 
                       strpos($step['file'], '/geissweb/module-euvat/') !== false;
            $isNegotiableQuote = strpos($step['class'], 'NegotiableQuote') !== false || 
                                strpos($step['file'], '/module-negotiable-quote/') !== false;
            $isPurchaseOrder = strpos($step['class'], 'PurchaseOrder') !== false || 
                              strpos($step['file'], '/module-purchase-order/') !== false;
            
            $stepData = [
                'class' => $step['class'] ?? 'Unknown',
                'method' => $step['function'] ?? 'unknown',
                'file' => basename($step['file'] ?? 'unknown'),
                'full_file' => $step['file'] ?? 'unknown',
                'line' => $step['line'] ?? 0,
                'is_magento_core' => $isCore,
                'is_b2b_module' => $isB2B,
                'is_external_module' => $isExternal,
                'is_eu_vat_module' => $isEuVat,
                'is_negotiable_quote_module' => $isNegotiableQuote,
                'is_purchase_order_module' => $isPurchaseOrder,
                'is_suspicious' => $isExternal || $isB2B || $isEuVat || $isNegotiableQuote || $isPurchaseOrder
            ];
            
            $relevantTrace[] = $stepData;
            
            if (!$primaryCaller && ($isCore || $isExternal || $isB2B || $isEuVat || $isNegotiableQuote || $isPurchaseOrder)) {
                $primaryCaller = $stepData;
                $summary = $step['class'] . '::' . $step['function'];
            }
        }
        
        return [
            'context' => 'QUOTE_SAVE_TRIGGER',
            'summary' => $summary,
            'primary_caller' => $primaryCaller,
            'full_chain' => $relevantTrace,
            'external_modules' => array_filter($relevantTrace, function($step) { return $step['is_external_module']; }),
            'b2b_modules' => array_filter($relevantTrace, function($step) { return $step['is_b2b_module']; }),
            'eu_vat_modules' => array_filter($relevantTrace, function($step) { return $step['is_eu_vat_module']; }),
            'negotiable_quote_modules' => array_filter($relevantTrace, function($step) { return $step['is_negotiable_quote_module']; }),
            'purchase_order_modules' => array_filter($relevantTrace, function($step) { return $step['is_purchase_order_module']; }),
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
        
        // Specific module detection for known problematic modules
        if (!empty($stackTrace['eu_vat_modules'])) {
            $conflicts[] = 'EU_VAT_MODULE_DETECTED';
        }
        
        if (!empty($stackTrace['negotiable_quote_modules'])) {
            $conflicts[] = 'NEGOTIABLE_QUOTE_MODULE_DETECTED';
        }
        
        if (!empty($stackTrace['purchase_order_modules'])) {
            $conflicts[] = 'PURCHASE_ORDER_MODULE_DETECTED';
        }
        
        return $conflicts;
    }
}
