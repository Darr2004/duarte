-- ===================================================================
-- Real Stall 1 parts data (from research_parts_list.xlsx)
-- Replaces the sample/demo catalog items with actual inventory.
-- Run AFTER migration_stall_layers.sql.
-- ===================================================================

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM stock_movements;
DELETE FROM tool_loans;
DELETE FROM requisition_items;
DELETE FROM item_variants;
DELETE FROM items;
DELETE FROM categories;
ALTER TABLE items AUTO_INCREMENT = 1;
ALTER TABLE categories AUTO_INCREMENT = 1;
ALTER TABLE item_variants AUTO_INCREMENT = 1;
ALTER TABLE stock_movements AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO categories (name, code_prefix) VALUES
    ('Filters', 'FLT'), ('Seals & Bearings', 'SEL'), ('Truck Parts & Components', 'PRT'), ('Fluids, Lubricants & Chemicals', 'FLD');

INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-001', 'Oil filter', 'Brand/Supplier: ASUKI; Part #: TO-8609P', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 8, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-002', 'Oil filter', 'Brand/Supplier: ASUKI; Part #: TO-4579P', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-003', 'Fuel filter', 'Brand/Supplier: KYOTO; Part #: 23301-64010', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-001', 'Fuel pump assembly', 'Brand/Supplier: KYOTO; Part #: ASSY - TPR', NULL,
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-001', 'Oil seal', 'Brand/Supplier: NOK', '64 x 77 x 12',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 7, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-002', 'Oil seal', 'Brand/Supplier: NOK', '120 x 140 x 10.5',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-003', 'Oil seal - two wheel outer', 'Brand/Supplier: MUSASHI', NULL,
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-004', 'Oil seal - ISUZU inner back', 'Brand/Supplier: MUSASHI', '117 x 174 x 11.5 x 25',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-005', 'Oil seal - two wheel inner', 'Brand/Supplier: MUSASHI', '174 x 172 x 14',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-006', 'Oil seal C/shaft', 'Brand/Supplier: ISUZU', '114 x 150 x 17',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 4, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'SEL-007', 'Oil seal', 'Brand/Supplier: MUSASHI / TTK', '6D40 / 8DC9 / 139 x 158 x 11; front hub',
    (SELECT id FROM categories WHERE name = 'Seals & Bearings'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-002', 'Power window motor', 'Brand/Supplier: GTX', 'Giga / Forward RH, FH 3T',
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-003', 'Power window motor', 'Brand/Supplier: NPR', 'Giga CT 24V LH',
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-004', 'Quick release hub kit', 'Brand/Supplier: MOMO', 'Black',
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-005', 'Air brake system / air dryer cartridge', 'Brand/Supplier: AIRTECH', 'Hino; air dryer cartridge',
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-004', 'Oil filter', 'Brand/Supplier: BOSCH; Part #: 0 1074', 'Isuzu, Mazda, Suzuki (as written)',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 7, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-005', 'Oil filter', 'Brand/Supplier: BOSCH; Part #: 0 1073', 'Isuzu only',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-006', 'Oil filter', 'Brand/Supplier: VIC; Part #: C-110', 'Toyota',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-007', 'Fuel filter', 'Brand/Supplier: VIC; Part #: FC-208A', 'Nissan, Mazda, Isuzu',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 7, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-008', 'Fuel filter', 'Brand/Supplier: VIC; Part #: FC-331', 'Mitsubishi Fuso',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 7, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-009', 'Oil filter', 'Brand/Supplier: VIC; Part #: D-306', 'Mitsubishi',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-010', 'Oil filter', 'Brand/Supplier: VIC; Part #: C-412', 'Nissan, Mazda, Suzuki, Daihatsu',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-011', 'Oil filter', 'Brand/Supplier: VIC; Part #: C-513', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-012', 'Oil filter', 'Brand/Supplier: VIC; Part #: C-525', 'Isuzu',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-013', 'Oil filter', 'Brand/Supplier: VIC; Part #: C-506', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-014', 'Oil filter', 'Brand/Supplier: SAKURA; Part #: D-1522', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-015', 'Fuel filter', 'Brand/Supplier: SAKURA; Part #: EF-1301', 'Hino (E13C-T, P11C, 6VZ7)',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-016', 'Oil filter', 'Brand/Supplier: MICRO; Part #: MPR 67G09', 'Element6WA1T/65D1/T/8PDI/10PD/PE1/6UZ1/T',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 20, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'PRT-006', 'Air brake system / air dryer cartridge', 'Brand/Supplier: AIRTECH', NULL,
    (SELECT id FROM categories WHERE name = 'Truck Parts & Components'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-017', 'Oil filter element', 'Brand/Supplier: A-Z FILTERS; Part #: OE23359SET', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 20, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-018', 'Oil filter', 'Brand/Supplier: MICRO; Part #: T6757; NOTE: handwritten source unclear, verify part number/spec.', '4HF1 / 4HF1T / 4HE1T / 4?61',
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-019', 'Fuel filter element', 'Brand/Supplier: MICRO; Part #: 8M21 / 22T / 8DC11 / 6M70T / 10M21 / MRF -7232', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-020', 'Fuel filter element', 'Brand/Supplier: MICRO; Part #: F6240', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-021', 'Fuel filter element', 'Brand/Supplier: MICRO; Part #: M86 7004', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 5, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-022', 'Fuel filter element', 'Brand/Supplier: MICRO; Part #: M86 7007', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 5, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLT-023', 'Oil filter element', 'Brand/Supplier: MICRO; Part #: FT 6235', NULL,
    (SELECT id FROM categories WHERE name = 'Filters'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Top')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-001', 'Tire & tube Mounting Compound', 'Brand/Supplier: TRUFLEX/PANG', '2kg',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-002', 'Fine Polishing Compound', 'Brand/Supplier: R-M; Part #: BRIL 852 A 2010', '1kg',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-003', 'Texamatic 1888', 'Brand/Supplier: CALTEX', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 17, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-004', 'Delo Gold Sae 15 W-40 API CH -4', 'Brand/Supplier: CALTEX', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 4, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-005', 'Delo Gear EP-4 API GL -4 SAE 140', 'Brand/Supplier: CALTEX', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 2, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-006', 'Brake & Clutch Fluid DOT 3', 'Brand/Supplier: CALTEX', '500 ml',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 11, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-007', 'Multifak EP2 Quality Grease', 'Brand/Supplier: CALTEX', '500 grams',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-008', 'GEP API GL - 4 SAE 140', 'Brand/Supplier: Petron', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-009', '2T Powerburn JASO FB', 'Brand/Supplier: Petron', NULL,
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 1, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-010', 'Super ATF DM - 3 Dexron III/ Mercon', 'Brand/Supplier: Top 1 Formula 1', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 6, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-011', 'Axle Oil EP 140 API GL4', 'Brand/Supplier: Sea Oil', '1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 13, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-012', 'Heavy Duty DOT 3 Brake Fluid', 'Brand/Supplier: Sure Break', '900 ml',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 29, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-013', 'Engine Flush', 'Brand/Supplier: PetroMate', '500 ml',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 3, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-014', 'WD - 40', NULL, '9.3oz / 277ml',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 24, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label, stall_layer_id) VALUES (
    'FLD-015', 'Eco Cool Engine Coolant & Anti Rust', 'Brand/Supplier: LubriGold', '(Green) 1 Liter',
    (SELECT id FROM categories WHERE name = 'Fluids, Lubricants & Chemicals'),
    'pc', 17, 0, NULL,
    (SELECT sl.id FROM stall_layers sl JOIN stalls s ON s.id = sl.stall_id
       WHERE s.stall_number = 1 AND sl.layer_name = 'Middle')
);
