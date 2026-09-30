-- ===================================================================
-- Real Stall 2 (TOP layer) parts data — from handwritten stock list,
-- transcribed to Stall2_Inventory.xlsx and verified by Inventory Staff.
--
-- ADDITIVE ONLY: unlike seed_stall1_real_data.sql, this script does NOT
-- delete or reset any existing table. It only adds two new categories
-- and inserts new items/variants under Stall 2 > Top. Safe to run
-- after schema.sql and seed_stall1_real_data.sql have already run.
--
-- Re-run safety: categories use ON DUPLICATE KEY UPDATE (name is
-- UNIQUE). Items use ON DUPLICATE KEY UPDATE (item_code is UNIQUE) so
-- re-running won't create duplicate rows, but note it WILL overwrite
-- quantity_on_hand back to the values below if stock has since moved —
-- only re-run this file if that's what you want.
-- ===================================================================

-- -------------------------------------------------------------------
-- New categories for this stall's stock (none of the existing
-- Stall 1 categories — Filters, Seals & Bearings, Truck Parts &
-- Components, Fluids/Lubricants & Chemicals — fit horns/bulbs/lamps).
-- -------------------------------------------------------------------
INSERT INTO categories (name, code_prefix, is_equipment) VALUES
    ('Horns & Sirens', 'HRN', 0),
    ('Bulbs & Lamps',  'BLB', 0)
ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Horns & Sirens (5 items)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'HRN-001', 'Early Warning Device', 'Tai', NULL, 'Philippines 2000',
    (SELECT id FROM categories WHERE name = 'Horns & Sirens'),
    'pc', 4, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'HRN-002', 'Back-up Horn', 'Halco', NULL, 'L12-98V',
    (SELECT id FROM categories WHERE name = 'Horns & Sirens'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'HRN-003', 'Backup Horn', 'Metro', NULL, '12-98V',
    (SELECT id FROM categories WHERE name = 'Horns & Sirens'),
    'pc', 9, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'HRN-004', 'Motor Siren', 'Emergency', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Horns & Sirens'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'HRN-005', 'Disc Horn', 'Zappa', NULL, '24V',
    (SELECT id FROM categories WHERE name = 'Horns & Sirens'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Bulbs & Lamps (26 single items + 3 RH/LH variant items = 29 items)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-001', 'LED Auxiliary Lighting', 'Realight', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pair', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-002', 'White Beam', 'Super White Beam', NULL, 'Offroad 4, 24V',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 5, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-003', 'LED Lighting', 'SGM', NULL, 'SAL 1226L',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pair', 13, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-004', 'LED Lighting', 'SGM', NULL, 'SAL 1222L',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pair', 10, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-005', 'LED 2217', 'Koehler Brightstar', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-006', 'LED Headlight', 'FSL', NULL, '1.5W',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-007', 'Auto Bulb', 'Narva', NULL, 'MED D/C 24V',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 60, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-008', 'Auto Bulb', 'Narva', NULL, 'Stop G18 (P21/5W)',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 30, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-009', 'Auto Bulb', 'Narva', NULL, 'R10',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 10, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-010', 'Auto Bulb', 'Hella', NULL, '12V, P21W',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 30, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-011', 'Halogen Bulb', 'Hella', NULL, 'H4, 24V',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 17, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-012', 'Auto Bulb', 'Hella', NULL, 'R5W, 24V',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-013', 'Auto Bulb', 'Hella', NULL, 'P21/5W',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 20, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-014', 'Lead Side Lamp', 'Eagleye', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pair', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-015', 'Clearance Light', 'Deba Lighting', NULL, 'DB-3074',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 12, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-016', 'Clearance Light', 'Deba Lighting', NULL, 'DB-3062',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-017', 'Peanut Bulb', 'Asahi', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 32, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-018', 'Halogen Bulb', 'Asahi', NULL, 'H4',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 14, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-019', 'Halogen Bulb', 'Asahi', NULL, 'H1',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 25, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-020', 'Halogen Bulb', 'Asahi', NULL, 'H3',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 4, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-021', 'Auto Bulb', 'Wruth', NULL, '24V',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 14, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-022', 'HID Bulb', 'Asahi', NULL, '12/24V, 35W',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 9, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-023', 'Fog Lamp Assy', 'DLAA', 'Halogen lamp', NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 5, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-025', 'Motorcycle Headlight LED', 'HS Silver', NULL, 'H:06',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'set', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-027', 'Bumper Lamp / Fog Lamp', 'Super Great Sonic', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;

-- --- RH/LH pair items (one catalog item each, variant_label = 'Side') ---

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-024', 'Tail Lamp', 'SGM', NULL, 'Fuso / Isuzu',
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 4, 0, 'Side',
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (SELECT 'LH' AS value, 2 AS qty UNION ALL SELECT 'RH', 2) v ON items.item_code = 'BLB-024'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = VALUES(quantity_on_hand);

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-026', 'Giga Bumper Lamp', 'Nuvo', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 10, 0, 'Side',
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (SELECT 'RH' AS value, 5 AS qty UNION ALL SELECT 'LH', 5) v ON items.item_code = 'BLB-026'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = VALUES(quantity_on_hand);

INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-028', 'Tail Lamp Assy', 'Nuvo', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 3, 0, 'Side',
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (SELECT 'RH' AS value, 1 AS qty UNION ALL SELECT 'LH', 2) v ON items.item_code = 'BLB-028'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = VALUES(quantity_on_hand);

-- Matsumoto: only LH quantity was recorded on the source sheet (no RH
-- line was written) — modeled as a Side variant item with just one
-- option for now. Add an RH row later (via the app's item-edit form)
-- if/when there's RH stock too.
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'BLB-029', 'Tail Lamp Assy', 'Matsumoto', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Bulbs & Lamps'),
    'pc', 1, 0, 'Side',
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (SELECT 'LH' AS value, 1 AS qty) v ON items.item_code = 'BLB-029'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = VALUES(quantity_on_hand);

-- -------------------------------------------------------------------
-- Not a horn, bulb, or lamp — a lawn mower part. Filed under the
-- existing "Truck Parts & Components" category (from
-- seed_stall1_real_data.sql) as the closest fit among current
-- categories. Continues PRT numbering from the highest existing code
-- (PRT-006 in seed_stall1_real_data.sql) -> PRT-007.
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, brand, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-007', 'Lawn Mower Blade Kit', 'Honda', NULL, NULL,
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'set', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id WHERE s.stall_number = 2 AND sl.layer_name = 'Top')
) ON DUPLICATE KEY UPDATE name = name;
