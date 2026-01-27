# Customer Loyalty API Documentation

## Overview

The Customer Loyalty API extension adds functionality to update customer loyalty data via REST API. This extends the existing LoyaltyEngage_LoyaltyShop module with customer custom attributes and a new API endpoint.

## Version

Module version: 1.1.0

## Features

- **Customer Custom Attributes**: Adds loyalty-related fields to customer entities
- **REST API Endpoint**: Update customer loyalty data by email
- **Admin Integration**: View loyalty data in customer admin forms and grids
- **Bearer Token Authentication**: Secure API access using admin/integration tokens

## Customer Attributes

The following custom attributes are added to customer entities:

| Attribute Code | Label | Type | Description |
|---|---|---|---|
| `le_current_tier` | Current Loyalty Tier | varchar | Customer's current loyalty tier (e.g., "Brons", "Zilver") |
| `le_points` | Loyalty Points | int | Total loyalty points earned |
| `le_available_coins` | Available Loyalty Coins | int | Available coins for spending |
| `le_next_tier` | Next Loyalty Tier | varchar | Next tier the customer can achieve |
| `le_points_to_next_tier` | Points to Next Tier | int | Points needed to reach next tier |

## API Endpoint

### Update Customer Loyalty Data

**Endpoint:** `POST /rest/V1/loyalty/customer/update`

**Authentication:** Bearer Token (Admin/Integration token with `Magento_Customer::manage` permission)

**Content-Type:** `application/json`

#### Request Body

```json
{
  "email": "customer@example.com",
  "le_current_tier": "Brons",
  "le_points": 100,
  "le_available_coins": 200,
  "le_next_tier": "Zilver",
  "le_points_to_next_tier": 500
}
```

#### Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `email` | string | Yes | Customer email address |
| `le_current_tier` | string | No | Current loyalty tier |
| `le_points` | integer | No | Total loyalty points |
| `le_available_coins` | integer | No | Available loyalty coins |
| `le_next_tier` | string | No | Next loyalty tier |
| `le_points_to_next_tier` | integer | No | Points needed for next tier |

#### Response

**Success Response:**
```json
{
  "success": true,
  "message": "Customer loyalty data updated successfully",
  "customer_id": 123,
  "updated_fields": [
    "le_current_tier",
    "le_points",
    "le_available_coins",
    "le_next_tier",
    "le_points_to_next_tier"
  ]
}
```

**Customer Not Found Response:**
```json
{
  "success": true,
  "message": "No action taken - customer not found",
  "customer_id": null,
  "updated_fields": []
}
```

**Error Response:**
```json
{
  "success": false,
  "message": "Error updating customer loyalty data: [error details]",
  "customer_id": null,
  "updated_fields": []
}
```

## Installation

### 1. Install Customer Attributes

Run the following commands to install the new customer attributes:

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### 2. Verify Installation

Check that the module is enabled:

```bash
bin/magento module:status LoyaltyEngage_LoyaltyShop
```

### 3. Create Integration Token (Optional)

If you need a dedicated integration token:

1. Go to **System > Extensions > Integrations**
2. Click **Add New Integration**
3. Fill in the integration details
4. In **API** tab, grant **Customers** permissions
5. Save and activate the integration
6. Copy the access token for API calls

## Usage Examples

### cURL Example

```bash
curl -X POST "https://your-magento-site.com/rest/V1/loyalty/customer/update" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "customer@example.com",
    "le_current_tier": "Brons",
    "le_points": 150,
    "le_available_coins": 75,
    "le_next_tier": "Zilver",
    "le_points_to_next_tier": 350
  }'
```

### PHP Example

```php
<?php
$url = 'https://your-magento-site.com/rest/V1/loyalty/customer/update';
$token = 'YOUR_ADMIN_TOKEN';

$data = [
    'email' => 'customer@example.com',
    'le_current_tier' => 'Brons',
    'le_points' => 150,
    'le_available_coins' => 75,
    'le_next_tier' => 'Zilver',
    'le_points_to_next_tier' => 350
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
print_r($result);
?>
```

## Admin Interface

### Customer Form

Loyalty fields are visible (but not editable) in the customer edit form under the customer information section:

- Current Loyalty Tier
- Loyalty Points  
- Available Loyalty Coins
- Next Loyalty Tier
- Points to Next Tier

### Customer Grid

The customer grid includes:

- **Filterable**: Current Loyalty Tier (dropdown filter)
- **Optional Columns**: All loyalty fields can be added to the grid via column chooser

## Error Handling

The API handles the following scenarios:

1. **Customer Not Found**: Returns success with "No action taken" message
2. **Invalid Data**: Returns error with validation details
3. **Permission Denied**: Returns 401 Unauthorized
4. **Server Errors**: Returns error with generic message (details logged)

## Logging

All API operations are logged to the standard Magento log files:

- **Success**: Info level with customer ID and updated fields
- **Errors**: Error level with exception details and context

## Security

- **Authentication**: Requires valid admin or integration token
- **Authorization**: Requires `Magento_Customer::manage` permission
- **Input Validation**: All input parameters are validated and sanitized
- **Error Handling**: Sensitive information is not exposed in error messages

## Troubleshooting

### Common Issues

1. **401 Unauthorized**: Check token validity and permissions
2. **404 Not Found**: Verify the endpoint URL and module installation
3. **500 Internal Server Error**: Check Magento logs for detailed error information

### Debug Steps

1. Verify module is enabled: `bin/magento module:status`
2. Check logs: `var/log/system.log` and `var/log/exception.log`
3. Clear cache: `bin/magento cache:flush`
4. Recompile: `bin/magento setup:di:compile`

## Compatibility

- **Magento Version**: 2.4.8+
- **PHP Version**: 8.3+
- **Dependencies**: Magento_Customer module

## Support

For issues or questions regarding this API extension, please check:

1. Magento logs for error details
2. Module installation and configuration
3. API authentication and permissions
