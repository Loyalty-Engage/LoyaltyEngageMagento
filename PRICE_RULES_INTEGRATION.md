# Price Rules Integration with Customer Loyalty

## Overview

The LoyaltyEngage_LoyaltyShop module now integrates customer loyalty data with Magento's price rules system, allowing you to create discounts and promotions based on customer loyalty tiers and points.

## Features

### Catalog Price Rules Integration
- Create product discounts based on customer loyalty tier
- Set different pricing for different loyalty levels
- Apply automatic discounts when customers browse products

### Cart Price Rules Integration
- Free shipping for specific loyalty tiers
- Percentage or fixed amount discounts based on loyalty points
- Tier-based promotional offers
- Minimum points requirements for discounts

## Available Loyalty Conditions

### Customer Loyalty Tier (`le_current_tier`)
- **Type**: Select dropdown
- **Available Values**: Brons, Zilver, Goud, Platina
- **Use Cases**: 
  - Free shipping for Gold and Platinum customers
  - 10% discount for Silver tier and above
  - Exclusive products for Platinum members

### Loyalty Points (`le_points`)
- **Type**: Numeric
- **Operators**: Equals, Not equals, Greater than, Less than, Greater or equal, Less or equal
- **Use Cases**:
  - 5% discount for customers with 1000+ points
  - Free shipping for customers with 500+ points
  - Bonus offers for high-point customers

### Available Loyalty Coins (`le_available_coins`)
- **Type**: Numeric
- **Operators**: Equals, Not equals, Greater than, Less than, Greater or equal, Less or equal
- **Use Cases**:
  - Special offers for customers with available coins
  - Encourage coin spending with bonus discounts

### Next Loyalty Tier (`le_next_tier`)
- **Type**: Select dropdown
- **Available Values**: Brons, Zilver, Goud, Platina
- **Use Cases**:
  - Incentive offers to reach next tier
  - "Almost there" promotions

### Points to Next Tier (`le_points_to_next_tier`)
- **Type**: Numeric
- **Operators**: Equals, Not equals, Greater than, Less than, Greater or equal, Less or equal
- **Use Cases**:
  - Boost offers when close to next tier
  - Targeted promotions for tier advancement

## How to Create Price Rules

### Catalog Price Rules (Product Discounts)

1. **Navigate to Admin Panel**
   - Go to `Marketing > Promotions > Catalog Price Rules`

2. **Create New Rule**
   - Click "Add New Rule"
   - Fill in basic rule information (name, description, status, etc.)

3. **Set Conditions**
   - In the "Conditions" tab, click the "+" icon
   - Select "Customer Loyalty Conditions"
   - Choose your desired loyalty attribute (tier, points, etc.)
   - Set the operator and value

4. **Configure Actions**
   - Set discount amount (percentage or fixed)
   - Apply to specific products or categories
   - Set priority and stop further rules processing if needed

### Cart Price Rules (Shopping Cart Discounts)

1. **Navigate to Admin Panel**
   - Go to `Marketing > Promotions > Cart Price Rules`

2. **Create New Rule**
   - Click "Add New Rule"
   - Fill in basic rule information

3. **Set Conditions**
   - In the "Conditions" tab, click the "+" icon
   - Select "Customer Loyalty" or find it under customer conditions
   - Choose your loyalty condition and configure it

4. **Configure Actions**
   - Set discount type (percentage, fixed amount, free shipping)
   - Configure discount amount
   - Set usage limits if needed

## Example Use Cases

### Example 1: Free Shipping for Gold Tier
**Cart Price Rule Configuration:**
- **Condition**: Customer Loyalty Tier equals "Goud"
- **Action**: Free Shipping = Yes
- **Result**: All Gold tier customers get free shipping

### Example 2: 10% Discount for 1000+ Points
**Cart Price Rule Configuration:**
- **Condition**: Loyalty Points >= 1000
- **Action**: Percentage discount = 10%
- **Result**: Customers with 1000+ points get 10% off their order

### Example 3: Platinum Exclusive Products
**Catalog Price Rule Configuration:**
- **Condition**: Customer Loyalty Tier equals "Platina"
- **Action**: Apply to specific category (Exclusive Products)
- **Discount**: 15% off
- **Result**: Only Platinum customers see discounted prices on exclusive products

### Example 4: Tier Advancement Incentive
**Cart Price Rule Configuration:**
- **Condition**: Points to Next Tier <= 100
- **Action**: Fixed discount = €5
- **Result**: Customers within 100 points of next tier get €5 off

## Technical Implementation

### Condition Class
- **Location**: `Model/Rule/Condition/Customer/LoyaltyTier.php`
- **Extends**: `Magento\Rule\Model\Condition\AbstractCondition`
- **Features**: 
  - Supports all loyalty attributes
  - Proper validation logic
  - Admin UI integration

### Plugin Integration
- **Catalog Rules**: `Plugin/CatalogRule/Model/Rule/Condition/CombinePlugin.php`
- **Cart Rules**: `Plugin/SalesRule/Model/Rule/Condition/CombinePlugin.php`
- **Purpose**: Adds loyalty conditions to rule condition dropdowns

## Testing Your Rules

### 1. Create Test Customer
- Create a customer account
- Use the API to set loyalty data:
```bash
curl -X POST "https://your-site.com/rest/V1/loyalty/customer/update" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "le_current_tier": "Goud",
    "le_points": 1500
  }'
```

### 2. Test Catalog Rules
- Log in as the test customer
- Browse products that should have the discount applied
- Verify pricing reflects the loyalty discount

### 3. Test Cart Rules
- Add products to cart
- Proceed to checkout
- Verify discounts and free shipping are applied correctly

## Troubleshooting

### Rules Not Working
1. **Clear Cache**: Always clear cache after creating/modifying rules
2. **Check Rule Status**: Ensure rules are enabled and within date range
3. **Verify Conditions**: Double-check condition logic and values
4. **Customer Data**: Confirm customer has the required loyalty data

### Conditions Not Appearing
1. **Clear Cache**: Flush all cache types
2. **Recompile**: Run `bin/magento setup:di:compile`
3. **Check Plugins**: Verify plugins are registered in `di.xml`

### Customer Data Not Loading
1. **API Integration**: Ensure loyalty data is properly set via API
2. **Customer Session**: Customer must be logged in for conditions to work
3. **Attribute Values**: Verify attribute values match exactly (case-sensitive)

## Best Practices

### Rule Priority
- Set appropriate priority levels for multiple rules
- Use "Stop Further Rules Processing" when needed
- Test rule combinations thoroughly

### Performance
- Avoid overly complex condition combinations
- Use specific date ranges to limit rule evaluation
- Monitor performance with many active rules

### Customer Experience
- Clearly communicate loyalty benefits to customers
- Show tier progress and available benefits
- Make loyalty status visible in customer account

## Advanced Configurations

### Combining Conditions
You can combine loyalty conditions with other rule conditions:
- Loyalty tier + Product category
- Points + Order subtotal
- Tier + Customer group
- Multiple loyalty conditions (AND/OR logic)

### Dynamic Tier Values
If you need different tier names, update the `getValueSelectOptions()` method in the `LoyaltyTier.php` condition class.

### Custom Loyalty Logic
Extend the condition class to add custom validation logic or additional loyalty-based conditions.

## API Integration

Remember to keep customer loyalty data updated via the API:
- Update tier changes immediately
- Sync points after purchases/returns
- Maintain accurate coin balances
- Update tier progression data

This ensures price rules always work with current loyalty status.
