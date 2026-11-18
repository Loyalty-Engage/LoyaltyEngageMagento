-- Emergency metadata correction, not a schema change.
-- Back up the selected rows first. Replace table names if Magento uses a prefix.
-- Run against the affected Magento database, then clean config/eav/layout/block_html/full_page caches.
-- This also hides the generic EAV inputs in customer administration, not the loyalty account block.
SELECT a.attribute_code, c.*
FROM customer_eav_attribute c
JOIN eav_attribute a ON a.attribute_id = c.attribute_id
JOIN eav_entity_type e ON e.entity_type_id = a.entity_type_id
WHERE e.entity_type_code = 'customer'
AND a.attribute_code IN ('le_current_tier','le_points','le_available_coins','le_next_tier','le_points_to_next_tier','le_reserved_coins','le_expiring_points_30d');

SELECT f.* FROM customer_form_attribute f
JOIN eav_attribute a ON a.attribute_id = f.attribute_id
JOIN eav_entity_type e ON e.entity_type_id = a.entity_type_id
WHERE e.entity_type_code = 'customer'
AND a.attribute_code IN ('le_current_tier','le_points','le_available_coins','le_next_tier','le_points_to_next_tier','le_reserved_coins','le_expiring_points_30d');

START TRANSACTION;
UPDATE customer_eav_attribute c
JOIN eav_attribute a ON a.attribute_id = c.attribute_id
JOIN eav_entity_type e ON e.entity_type_id = a.entity_type_id
SET c.is_visible = 0
WHERE e.entity_type_code = 'customer'
AND a.attribute_code IN ('le_current_tier','le_points','le_available_coins','le_next_tier','le_points_to_next_tier','le_reserved_coins','le_expiring_points_30d');

DELETE f FROM customer_form_attribute f
JOIN eav_attribute a ON a.attribute_id = f.attribute_id
JOIN eav_entity_type e ON e.entity_type_id = a.entity_type_id
WHERE e.entity_type_code = 'customer'
AND f.form_code <> 'adminhtml_customer'
AND a.attribute_code IN ('le_current_tier','le_points','le_available_coins','le_next_tier','le_points_to_next_tier','le_reserved_coins','le_expiring_points_30d');
COMMIT;
