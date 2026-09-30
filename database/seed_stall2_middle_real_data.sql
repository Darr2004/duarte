-- ===================================================================
-- Real Stall 2 (MIDDLE layer) parts data — from handwritten stock
-- list, transcribed to Stall2_Middle_Inventory.xlsx and verified by
-- Inventory Staff. Supplier/contact noted on the source paper:
-- Marieta A. Aquino — 3 Exit, Block 3.
--
-- ADDITIVE ONLY — does not delete or reset any existing table. Only
-- adds three new categories and inserts new items under
-- Stall 2 > Middle. Safe to run after schema.sql,
-- seed_stall1_real_data.sql, and seed_stall2_top_real_data.sql.
--
-- Re-run safety: categories/items use ON DUPLICATE KEY UPDATE, so
-- re-running won't duplicate rows — but it WILL overwrite
-- quantity_on_hand back to the values below if stock has since moved.
--
-- NOTE on "Power Tools" borrowability: is_borrowable is a
-- CATEGORY-level flag in this app (see category_is_equipment() in
-- includes/functions.php) — there's no per-item override. Power Tools
-- is set as equipment/borrowable (is_equipment = 1) here, since these
-- are typically checked out and returned. If you'd rather issue them
-- as consumables instead, flip the category's flag from
-- Inventory > Categories in the app — no need to re-run this file.
-- ===================================================================

INSERT INTO categories (name, code_prefix, is_equipment) VALUES
    ('Chemicals & Paint',        'CHM', 0),
    ('Power Tools',              'PWR', 1),
    ('Abrasives & Consumables',  'ABR', 0)
ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Chemicals & Paint (8 items)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-001', 'Engine Degreaser', 'Aeropak', NULL, '500ml',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 18, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-002', 'Protector Clean & Shine', 'VS1', NULL, '250ml',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 14, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-003', 'Engine Degreaser', 'Redspeed', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 9, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-004', 'Pylox Spray Paint', 'Nippon Paint', NULL, 'Matt Black, 400cc',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 20, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-005', 'Pylox Spray Paint', 'Nippon Paint', NULL, 'Chrome, 400cc',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 10, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-006', 'Pylox Spray Paint', 'Nippon Paint', NULL, 'Deep Red, 400cc',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 11, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-007', 'Pylox Spray Paint', 'Nippon Paint', NULL, 'Orange Red, 400cc',
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 12, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'CHM-008', 'Liquid Rubber Sealant Coating', 'Flexseal', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Chemicals & Paint'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Power Tools (8 items) — category is_equipment = 1, so is_borrowable
-- below is set to 1 to match (checked out / returned rather than
-- issued for good).
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-001', 'Hydraulic Jack', 'KICHI', NULL, 'Blue, 20 ton',
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-002', 'Hydraulic Jack', 'KICHI', NULL, 'Blue, 10 ton',
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-003', 'Hydraulic Jack', 'KICHI', NULL, 'Red, 16 ton',
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-004', 'Angle Grinder', 'Bosch', NULL, 'GWS 700',
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-005', 'Mini Grinder', 'Bosch', NULL, 'GWS 060',
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-006', 'Impact Drill', 'Deli', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 2, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-007', 'Impact Drill', 'Bosch', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 1, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PWR-008', 'Revit Gun', NULL, NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Power Tools'),
    'pc', 2, 1, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Abrasives & Consumables (6 items)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-001', 'Cutting Wheel', 'Tailin', NULL, '105 x 1 x 16mm',
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 660, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-002', 'Offset Wheel', 'Tailin', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 78, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-003', 'Cutting Wheel', 'Tailin', NULL, '355 x 3 x 25.4mm',
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-004', 'Tile Gauge', 'KYK', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 4, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-005', 'Carbon Brush', 'Makita', NULL, '203 A',
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 8, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'ABR-006', 'Bi-Metal Hacksaw Blade', 'Sandflex', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Abrasives & Consumables'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Middle')
) ON DUPLICATE KEY UPDATE name = name;
