# Varnish Cache Fix for Hyvä Theme Menu Issue

## Problem
The menu was not loading in production with Varnish caching enabled, while working correctly in staging without Varnish. This was caused by cache-incompatible theme detection logic.

## Root Cause
The `ThemeLayoutPlugin` used `DesignInterface` for runtime theme detection, which is not compatible with Varnish full-page caching. The theme detection could fail or return inconsistent results when pages were served from cache.

## Solution Implemented

### 1. ThemeLayoutPlugin.php - Removed Theme Detection
**Before:** Used `Helper\Data::isHyvaTheme()` with runtime theme detection
**After:** Always adds Hyvä layout handles without detection

**Changes:**
- Removed `Helper\Data` dependency
- Eliminated conditional theme checking
- Always executes Hyvä-specific layout handle logic
- Cache-safe implementation

### 2. loyalty-cart-js.phtml - Simplified Template
**Before:** Conditional logic based on theme detector
**After:** Always includes Hyvä template

**Changes:**
- Removed theme detector dependency
- Always includes `hyva/loyalty-cart-js.phtml`
- No conditional logic that could cause caching issues

### 3. default.xml - Cleaned Layout
**Before:** Injected `ThemeDetector` ViewModel
**After:** Simple block without theme detector

**Changes:**
- Removed `theme_detector` argument
- Simplified block configuration

### 4. Helper/Data.php - Removed Problematic Method
**Before:** Had `isHyvaTheme()` method with `DesignInterface` dependency
**After:** Clean helper with only API configuration methods

**Changes:**
- Removed `isHyvaTheme()` method
- Removed `DesignInterface` dependency
- Kept essential API configuration methods

## Benefits
- ✅ Menu loads correctly with Varnish caching
- ✅ No runtime theme detection overhead
- ✅ Cache-safe implementation
- ✅ Consistent behavior across all requests
- ✅ Simplified codebase

## Deployment Steps
1. Deploy updated files to production
2. Clear Magento caches: `bin/magento cache:flush`
3. Test menu functionality across different pages
4. Verify consistent behavior with Varnish enabled

## Notes
- This solution is specifically for Hyvä-only installations
- The plugin now always applies Hyvä-specific layout handles
- No fallback to Luma theme (separate plugin handles Luma)
- Compatible with Varnish full-page caching
