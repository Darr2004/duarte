-- ===================================================================
-- DuaRTE: Duarte Trucking Requisition and Equipment Borrowing System
-- Database Schema — Foundation Module (Users & Roles)
-- ===================================================================

CREATE DATABASE IF NOT EXISTS duarte_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE duarte_db;

-- -------------------------------------------------------------------
-- Table: users
-- Holds every account in the system, distinguished by `role`.
-- Company Management and Administrator have been merged into a
-- single 'admin' role, since both need full oversight of the
-- system. 'driver_helper' and 'inventory_staff' were added for the
-- Digital Inventory Catalog module. 'field_supervisor' is added now
-- for the Online Requisition & Approval module.
--
-- `position` is a display-only tag for 'driver_helper' accounts
-- (Driver / Helper / Mechanic / Electrician / Office Staff). All of
-- them share the same 'driver_helper' access level ("Personnel");
-- `position` never affects permissions, only how the account is
-- labeled in the UI and reports.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id       VARCHAR(20)  NOT NULL UNIQUE,
    full_name         VARCHAR(100) NOT NULL,
    email             VARCHAR(150) NOT NULL UNIQUE,
    username          VARCHAR(50)  NOT NULL UNIQUE,
    password_hash     VARCHAR(255) NOT NULL,
    role              ENUM(
                          'admin',
                          'inventory_staff',
                          'driver_helper',
                          'field_supervisor'
                      ) NOT NULL,
    position          ENUM(
                          'driver',
                          'helper',
                          'mechanic',
                          'electrician',
                          'office_staff'
                      ) NULL DEFAULT NULL,
    contact_number    VARCHAR(20)  DEFAULT NULL,
    profile_picture   VARCHAR(255) DEFAULT NULL,
    status            ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    last_login_at     TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: login_attempts
-- Basic audit trail / brute-force awareness for the login module.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: mobile_tokens
-- The access token api/login.php hands the Flutter app. Every other
-- api/*.php endpoint requires this token (Authorization: Bearer ...)
-- and resolves the acting user from it — a posted user_id/role is
-- never trusted on its own. Single row per active login; a fresh
-- login replaces the old token so an old device session stops working.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mobile_tokens (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    TIMESTAMP    NOT NULL,
    last_used_at  TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mobile_tokens_token (token)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Seed: default administrator account
-- Username: admin | Password: Admin@123
-- (Change this immediately after first login — see README.)
-- -------------------------------------------------------------------
INSERT INTO users (employee_id, full_name, email, username, password_hash, role)
VALUES (
    'EMP-0001',
    'System Administrator',
    'admin@duartetrucking.local',
    'admin',
    '$2b$10$ERffR38KJwXm5BB1jV2Ra.lH6AO6qaxlZTf0VB/.BrF6Khs0CvR6m', -- Admin@123
    'admin'
)
ON DUPLICATE KEY UPDATE username = username;

-- -------------------------------------------------------------------
-- Seed: one demo account per new role, for testing the catalog module.
-- -------------------------------------------------------------------
INSERT INTO users (employee_id, full_name, email, username, password_hash, role)
VALUES
(
    'EMP-0002', 'Inventory Staff Demo', 'inventory@duartetrucking.local',
    'inventorystaff', '$2b$10$hoFqerrMrQyQfPMCyYlHSeVjxdI9S8.zyRaZeRrGNbUA9gAM67pgu', -- Staff@123
    'inventory_staff'
),
(
    'EMP-0003', 'Driver Demo', 'driver@duartetrucking.local',
    'driverhelper', '$2b$10$BcmWneHPOi3I7QvhBNavjOpxqch3vcdH9GIxqBuhyvyCv..u0F/Oi', -- Driver@123
    'driver_helper'
),
(
    'EMP-0004', 'Field Supervisor Demo', 'supervisor@duartetrucking.local',
    'fieldsupervisor', '$2b$10$tWlPDeDeFmY9Io7slJYWLOPiEc6MpSa8HAxIBTxypG2BZmrgpMwzO', -- Super@123
    'field_supervisor'
)
ON DUPLICATE KEY UPDATE username = username;

-- ===================================================================
-- Module: Digital Inventory Catalog
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: categories
-- Simple grouping for the catalog (e.g. Hand Tools, PPE, Consumables).
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(80) NOT NULL UNIQUE,
    -- Short code (e.g. "FLT", "PPE") item codes are generated from —
    -- see resolve_category_code_prefix() in includes/functions.php.
    -- Left NULL until first needed, then derived from the name and
    -- saved back so it stays stable from then on.
    code_prefix   VARCHAR(10) DEFAULT NULL,
    -- Whether items in this category are borrowed (checked out and
    -- returned) rather than consumed. This is a real, editable flag —
    -- not derived from the category's name — see category_is_equipment()
    -- in includes/functions.php. Superseded by borrow_mode below, but
    -- kept in sync for anything that still reads it.
    is_equipment  TINYINT(1) NOT NULL DEFAULT 0,
    -- 'consume': always issued as a consumable (no return tracking).
    -- 'borrow': always checked out and returned.
    -- 'choice': the requester picks, per request line, whether they
    -- want to consume it or borrow it. See category_borrow_mode() in
    -- includes/functions.php — editable from Inventory > Categories.
    borrow_mode   ENUM('consume','borrow','choice') NOT NULL DEFAULT 'consume',
    -- Stock Alerts (inventory/alerts.php) flag an item as "low stock"
    -- once its quantity_on_hand drops to this many units or fewer.
    -- Editable per category from Inventory > Categories, so inventory
    -- staff can set a tighter threshold for fast-moving categories
    -- (e.g. PPE) and a looser one for slow-moving ones — see
    -- category_low_stock_threshold() in includes/functions.php. Items
    -- with no category fall back to DEFAULT_STOCK_ALERT_THRESHOLD.
    low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    -- Whether items in this category are truck-specific (e.g. Truck Parts,
    -- Engine Oil, Rigging) requiring the requester to specify the truck
    -- plate number when requesting.
    requires_truck      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: trucks
-- Duarte Trucking fleet vehicles. Tracked for maintenance, trip status,
-- and assigned to parts/materials requisitions.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trucks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plate_number    VARCHAR(20) NOT NULL UNIQUE,
    model           VARCHAR(100) NOT NULL,
    status          ENUM('available', 'on_trip', 'under_maintenance') NOT NULL DEFAULT 'available',
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: rooms
-- Top-level physical storage location (e.g. a warehouse room). Each
-- room contains one or more stalls (storage bays); see `stalls`
-- below. Visibility: same as stalls/stall_layers — operational/
-- staff-facing data, not shown to Driver/Helper or Field Supervisor
-- accounts — enforced in application code.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_number   INT UNSIGNED NOT NULL UNIQUE,
    name          VARCHAR(60)  NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: stalls
-- Physical storage bay within a room. Storage location tracking for
-- the Digital Inventory Catalog — each item can optionally sit in one
-- "layer" (shelf level) of one "stall" (storage bay) of one "room".
-- Visibility: stall/layer is operational/staff-facing data, not shown
-- to Driver/Helper or Field Supervisor accounts — enforced in
-- application code. stall_number is only unique within its room (two
-- different rooms can each have a "Stall 1").
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stalls (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id       INT UNSIGNED NOT NULL,
    stall_number  INT UNSIGNED NOT NULL,
    name          VARCHAR(60)  NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_room_stall_number (room_id, stall_number),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: stall_layers
-- One row per shelf level within a stall. layer_number orders the
-- layers top-to-bottom within their stall (1 = topmost).
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stall_layers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stall_id      INT UNSIGNED NOT NULL,
    layer_number  INT UNSIGNED NOT NULL,
    layer_name    VARCHAR(40)  NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_stall_layer_number (stall_id, layer_number),
    UNIQUE KEY uniq_stall_layer_name (stall_id, layer_name),
    FOREIGN KEY (stall_id) REFERENCES stalls(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: items
-- The digital catalog itself. `quantity_on_hand` is shown to
-- everyone browsing; later modules (Automated Stock Recording,
-- Stock Monitoring & Alerts) will read and update this same column
-- rather than duplicating stock data elsewhere.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_code         VARCHAR(20)   NOT NULL UNIQUE,
    name              VARCHAR(120)  NOT NULL,
    -- Brand/supplier name, broken out as its own column so the catalog
    -- and forms don't have to parse it back out of free-text
    -- description (previously stored as "Brand/Supplier: X" inside
    -- `description`). See database/migration_add_brand_column.sql for
    -- existing installs.
    brand             VARCHAR(80)   DEFAULT NULL,
    description       TEXT          DEFAULT NULL,
    specification     VARCHAR(100)  DEFAULT NULL,
    category_id       INT UNSIGNED  DEFAULT NULL,
    -- Optional physical storage location (see stalls/stall_layers
    -- above). NULL means not yet assigned a shelf.
    stall_layer_id    INT UNSIGNED  DEFAULT NULL,
    unit              VARCHAR(20)   NOT NULL DEFAULT 'pc',
    quantity_on_hand  INT UNSIGNED  NOT NULL DEFAULT 0,
    -- Whether this item can ever be borrowed — true when borrow_mode
    -- is 'borrow' or 'choice'. Kept as its own column (rather than
    -- computed on the fly) since a few read-only badges/filters key
    -- off it directly; category_borrow_mode() keeps it in sync
    -- whenever an item's category is set or changed.
    is_borrowable     TINYINT(1)    NOT NULL DEFAULT 0,
    -- Snapshotted from the item's category at add/edit time (see
    -- item_add.php / item_edit.php). 'choice' means the requester
    -- gets an explicit Consume/Borrow picker on the catalog and cart
    -- — the actual pick for a given request lives on
    -- requisition_items.is_borrowable, untouched by this column.
    borrow_mode       ENUM('consume','borrow','choice') NOT NULL DEFAULT 'consume',
    -- variant_label names the choice a requester makes on the catalog
    -- for this item — could be "Size" (PPE), "Amperage" (fuses),
    -- "Color" (Pylox), or anything else inventory staff define per
    -- item. The actual options and their own stock counts live in
    -- item_variants; quantity_on_hand here is kept as the running
    -- total across all of an item's variants (or its own count, for
    -- items with no variants at all).
    variant_label     VARCHAR(40)   DEFAULT NULL,
    image_filename    VARCHAR(255)  DEFAULT NULL,
    status            ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (stall_layer_id) REFERENCES stall_layers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: item_variants
-- One row per selectable option on an item that has variant_label
-- set (e.g. item "Fuse" + variant_label "Amperage" -> rows "10A",
-- "20A", "30A", each with its own quantity_on_hand). Items with no
-- variants simply have no rows here and rely on items.quantity_on_hand
-- directly. record_stock_movement() keeps a variant's quantity_on_hand
-- AND the parent item's quantity_on_hand (the cross-variant total) in
-- sync in the same transaction — see includes/stock.php.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_variants (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id           INT UNSIGNED NOT NULL,
    variant_value     VARCHAR(60)   NOT NULL,
    -- Optional context shown as a hover tooltip on the option in the
    -- catalog dropdown (e.g. vehicle fitment for a Part # option) —
    -- never shown in the dropdown label itself, to keep it short.
    -- See database/migration_variant_notes.sql for the standalone
    -- migration that adds this column to an already-running install.
    variant_note      VARCHAR(150)  DEFAULT NULL,
    -- Optional per-option photo (e.g. a close-up of a specific Part #),
    -- shown on the requester-facing catalog once that option is picked
    -- from the dropdown. Falls back to the parent item's own
    -- image_filename when a variant has none. Stored the same way as
    -- items.image_filename — see includes/uploads.php.
    image_filename    VARCHAR(255)  DEFAULT NULL,
    quantity_on_hand  INT UNSIGNED  NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_item_variant (item_id, variant_value),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Seed: sample categories and items so the catalog isn't empty on
-- first run. Safe to delete once real inventory data is entered.
-- -------------------------------------------------------------------
-- NOTE: "Equipment" is seeded with is_equipment=1, so its items are
-- borrowed (checked out and returned). Every other category — including
-- Hand Tools, Power Tools, and Rigging — is issued as a consumable, even
-- for durable items like wrenches or chains. is_borrowable on the items
-- below is derived from is_equipment and is kept in sync automatically
-- whenever an item's category is set or changed (see
-- category_is_equipment() in includes/functions.php). The flag lives on
-- the category row itself and is editable from Inventory > Categories —
-- it is no longer inferred from the category's name.
INSERT INTO categories (name, code_prefix, is_equipment) VALUES
    ('Hand Tools', 'HDT', 0), ('Power Tools', 'PWT', 0), ('PPE', 'PPE', 0), ('Consumables', 'CNS', 0),
    ('Rigging', 'RIG', 0), ('Equipment', 'EQP', 1), ('Truck Parts', 'TRK', 0)
ON DUPLICATE KEY UPDATE name = name;

-- -------------------------------------------------------------------
-- Seed: 1 room holding 4 storage stalls, each with its shelf layers.
-- Stall 1–3: 3 layers each (Top, Middle, Bottom). Stall 4: 4 layers.
-- -------------------------------------------------------------------
INSERT INTO rooms (room_number, name) VALUES
    (1, 'Room 1')
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO stalls (room_id, stall_number, name)
SELECT r.id, 1, 'Stall 1' FROM rooms r WHERE r.room_number = 1
UNION ALL
SELECT r.id, 2, 'Stall 2' FROM rooms r WHERE r.room_number = 1
UNION ALL
SELECT r.id, 3, 'Stall 3' FROM rooms r WHERE r.room_number = 1
UNION ALL
SELECT r.id, 4, 'Stall 4' FROM rooms r WHERE r.room_number = 1
ON DUPLICATE KEY UPDATE name = name;

INSERT INTO stall_layers (stall_id, layer_number, layer_name)
SELECT s.id, 1, 'Top'    FROM stalls s WHERE s.stall_number IN (1, 2, 3, 4)
UNION ALL
SELECT s.id, 2, 'Middle' FROM stalls s WHERE s.stall_number IN (1, 2, 3, 4)
UNION ALL
SELECT s.id, 3, 'Bottom' FROM stalls s WHERE s.stall_number IN (1, 2, 3, 4)
ON DUPLICATE KEY UPDATE layer_name = layer_name;

INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label)
VALUES
    ('ITM-0001', 'Adjustable Wrench 10"', 'Chrome-vanadium adjustable wrench.', '10" jaw', 1, 'pc', 14, 0, NULL),
    ('ITM-0002', 'Claw Hammer 16oz', 'Standard claw hammer, fiberglass handle.', '16oz head', 1, 'pc', 3, 0, NULL),
    ('ITM-0003', 'Cordless Impact Driver', '18V cordless impact driver, battery included.', '18V', 2, 'pc', 6, 0, NULL),
    ('ITM-0004', 'Safety Helmet', 'Hard hat, adjustable strap, ANSI rated.', 'Universal size, ANSI Z89.1', 3, 'pc', 0, 0, 'Size'),
    ('ITM-0005', 'Work Gloves (pair)', 'Cut-resistant work gloves, sized S–XL.', 'Sizes S–XL', 3, 'pair', 22, 0, 'Size'),
    ('ITM-0006', 'Cable Ties (100pcs)', 'Nylon cable ties, 300mm, pack of 100.', '300mm x 4.8mm', 4, 'pack', 40, 0, NULL),
    ('ITM-0007', 'Tow Chain 5m', 'Grade 70 tow chain with hooks, 5000kg WLL.', 'Grade 70, 5000kg WLL', 5, 'pc', 2, 0, NULL),
    ('ITM-0008', 'Diesel Generator 5kW', 'Portable diesel generator for jobsite power.', '5kW, 220V', 6, 'pc', 2, 1, NULL),
    ('ITM-0009', 'Hydraulic Floor Jack 3-Ton', 'Heavy-duty hydraulic floor jack for truck maintenance.', '3-ton capacity', 6, 'pc', 3, 1, NULL),
    ('ITM-0010', 'Portable Air Compressor', 'Gas-powered air compressor for tires and pneumatic tools.', '185 CFM', 6, 'pc', 1, 1, NULL),
    ('ITM-0011', 'Oil Filter', 'Standard truck engine oil filter.', 'Spin-on, fits most diesel trucks', 7, 'pc', 30, 0, NULL),
    ('ITM-0012', 'Brake Pads (set)', 'Front brake pad set for heavy trucks.', 'Set of 4', 7, 'set', 8, 0, NULL),
    ('ITM-0013', 'Engine Oil (per liter)', '15W-40 diesel engine oil.', '15W-40', 4, 'liter', 60, 0, NULL),
    ('ITM-0014', 'Welding Machine', 'Arc welding machine for repair jobs.', '200A inverter welder', 6, 'pc', 1, 1, NULL),
    ('ITM-0015', 'OBD Diagnostic Scanner', 'Handheld diagnostic scanner for truck engine systems.', 'Heavy-duty OBD II', 6, 'pc', 2, 1, NULL),
    ('ITM-0016', 'Automotive Fuse', 'Blade-type automotive fuse, assorted amperage.', 'Mini blade fuse', 7, 'pc', 0, 0, 'Amperage'),
    ('ITM-0017', 'Pylox Spray Paint', 'Quick-dry aerosol spray paint for touch-ups and signage.', '400mL can', 4, 'can', 0, 0, 'Color')
ON DUPLICATE KEY UPDATE name = name;

-- Per-variant stock for the seeded items above that use variant_label.
-- items.quantity_on_hand for each of these is kept as the sum of its
-- variant rows (see item_variants table comment).
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'S' AS value, 0 AS qty UNION ALL SELECT 'M', 0 UNION ALL SELECT 'L', 0 UNION ALL SELECT 'XL', 0
) v ON items.item_code = 'ITM-0004'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'S' AS value, 4 AS qty UNION ALL SELECT 'M', 6 UNION ALL SELECT 'L', 8 UNION ALL SELECT 'XL', 4
) v ON items.item_code = 'ITM-0005'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT '10A' AS value, 0 AS qty UNION ALL SELECT '20A', 0 UNION ALL SELECT '30A', 0
) v ON items.item_code = 'ITM-0016'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Red' AS value, 0 AS qty UNION ALL SELECT 'Black', 0 UNION ALL SELECT 'White', 0 UNION ALL SELECT 'Yellow', 0
) v ON items.item_code = 'ITM-0017'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = item_variants.quantity_on_hand;

-- -------------------------------------------------------------------
-- Demo: Oil Filters, Engine Oil, and Brake Fluid — one catalog item
-- per brand, with the size/grade axis modeled as that item's
-- variants. A single item can only carry one variant_label (one
-- axis), so where a real-world part has two axes (brand AND
-- size/grade), brand becomes the item and size/grade becomes the
-- variant, same pattern as the Safety Helmet/Fuse/Pylox items above.
--
-- Oil Filters use "Size" (Small/Medium/Big) since that's what's
-- printed on the boxes on hand. Engine Oil and Brake Fluid use their
-- real spec grade instead of a size: SAE viscosity (e.g. 15W-40) for
-- engine oil, and DOT rating for brake fluid — DOT 3/4/5.1 are
-- boiling-point classifications (dry boiling point roughly 205C,
-- 230C, and 260C respectively), not a size or volume.
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label)
VALUES
    ('ITM-0018', 'FRAM Oil Filter', 'Spin-on oil filter, FRAM brand.', 'Passenger & light truck', 7, 'pc', 16, 0, 'Size'),
    ('ITM-0019', 'WIX Oil Filter', 'Spin-on oil filter, WIX brand.', 'Passenger & light truck', 7, 'pc', 12, 0, 'Size'),
    ('ITM-0020', 'Bosch Oil Filter', 'Spin-on oil filter, Bosch brand.', 'Passenger & light truck', 7, 'pc', 11, 0, 'Size'),
    ('ITM-0021', 'Purolator Oil Filter', 'Spin-on oil filter, Purolator brand.', 'Passenger & light truck', 7, 'pc', 4, 0, 'Size'),
    ('ITM-0022', 'K&N Oil Filter', 'Spin-on oil filter, K&N brand.', 'Passenger & light truck', 7, 'pc', 6, 0, 'Size'),
    ('ITM-0023', 'ACDelco Oil Filter', 'Spin-on oil filter, ACDelco brand.', 'Passenger & light truck', 7, 'pc', 25, 0, 'Size'),
    ('ITM-0024', 'Shell Rimula Engine Oil', 'Heavy-duty diesel engine oil.', 'Diesel engine oil', 4, 'liter', 60, 0, 'Viscosity Grade'),
    ('ITM-0025', 'Caltex Delo Engine Oil', 'Heavy-duty diesel engine oil.', 'Diesel engine oil', 4, 'liter', 45, 0, 'Viscosity Grade'),
    ('ITM-0026', 'Petron Blaze Engine Oil', 'Heavy-duty diesel engine oil.', 'Diesel engine oil', 4, 'liter', 35, 0, 'Viscosity Grade'),
    ('ITM-0027', 'Bosch Brake Fluid', 'Glycol-based hydraulic brake fluid.', 'Brake fluid', 4, 'liter', 20, 0, 'DOT Grade'),
    ('ITM-0028', 'ATE Brake Fluid', 'Glycol-based hydraulic brake fluid.', 'Brake fluid', 4, 'liter', 9, 0, 'DOT Grade')
ON DUPLICATE KEY UPDATE name = name;

-- Per-variant stock for the brand items above (see comment block
-- directly above the INSERT for why brand is the item and
-- size/grade is the variant here).
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 5 AS qty UNION ALL SELECT 'Medium', 8 UNION ALL SELECT 'Big', 3
) v ON items.item_code = 'ITM-0018'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 4 AS qty UNION ALL SELECT 'Medium', 6 UNION ALL SELECT 'Big', 2
) v ON items.item_code = 'ITM-0019'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 6 AS qty UNION ALL SELECT 'Medium', 5 UNION ALL SELECT 'Big', 0
) v ON items.item_code = 'ITM-0020'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 0 AS qty UNION ALL SELECT 'Medium', 3 UNION ALL SELECT 'Big', 1
) v ON items.item_code = 'ITM-0021'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 2 AS qty UNION ALL SELECT 'Medium', 2 UNION ALL SELECT 'Big', 2
) v ON items.item_code = 'ITM-0022'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'Small' AS value, 10 AS qty UNION ALL SELECT 'Medium', 10 UNION ALL SELECT 'Big', 5
) v ON items.item_code = 'ITM-0023'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT '15W-40' AS value, 40 AS qty UNION ALL SELECT '20W-50', 20
) v ON items.item_code = 'ITM-0024'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT '15W-40' AS value, 30 AS qty UNION ALL SELECT '20W-50', 15
) v ON items.item_code = 'ITM-0025'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT '15W-40' AS value, 25 AS qty UNION ALL SELECT '20W-50', 10
) v ON items.item_code = 'ITM-0026'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'DOT 3' AS value, 12 AS qty UNION ALL SELECT 'DOT 4', 8
) v ON items.item_code = 'ITM-0027'
UNION ALL
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'DOT 4' AS value, 6 AS qty UNION ALL SELECT 'DOT 5.1', 3
) v ON items.item_code = 'ITM-0028'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = item_variants.quantity_on_hand;

-- -------------------------------------------------------------------
-- Padding seed: bring every category up to at least 5 sample items so
-- the catalog/category filter looks populated for each one. Categories
-- that already had 5+ items (Consumables, Equipment, Truck Parts) are
-- left untouched.
--   Hand Tools : 2 -> 5 (+3)
--   Power Tools: 1 -> 5 (+4)
--   PPE        : 2 -> 5 (+3)
--   Rigging    : 1 -> 5 (+4)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label)
VALUES
    ('ITM-0029', 'Screwdriver Set', 'Flathead and Phillips screwdriver set, magnetic tips.', '6-piece set', 1, 'set', 10, 0, NULL),
    ('ITM-0030', 'Combination Pliers 8"', 'Heavy-duty combination pliers, insulated grip.', '8" length', 1, 'pc', 12, 0, NULL),
    ('ITM-0031', 'Measuring Tape 5m', 'Retractable steel measuring tape.', '5m x 19mm', 1, 'pc', 15, 0, NULL),
    ('ITM-0032', 'Angle Grinder 4"', 'Corded angle grinder for cutting and grinding.', '4" disc, 750W', 2, 'pc', 5, 0, NULL),
    ('ITM-0033', 'Cordless Drill 18V', '18V cordless drill/driver with charger.', '18V, 2 batteries', 2, 'pc', 4, 0, NULL),
    ('ITM-0034', 'Circular Saw 7-1/4"', 'Corded circular saw for wood and light metal.', '7-1/4" blade', 2, 'pc', 3, 0, NULL),
    ('ITM-0035', 'Bench Grinder', 'Dual-wheel bench grinder for sharpening and deburring.', '6" wheels', 2, 'pc', 2, 0, NULL),
    ('ITM-0036', 'Safety Goggles', 'Clear anti-fog safety goggles.', 'ANSI Z87.1', 3, 'pc', 30, 0, NULL),
    ('ITM-0037', 'Ear Plugs (pair)', 'Foam ear plugs, NRR 32dB.', 'NRR 32dB', 3, 'pair', 50, 0, NULL),
    ('ITM-0038', 'Reflective Safety Vest', 'High-visibility reflective vest, mesh back.', 'One size, Class 2', 3, 'pc', 18, 0, 'Size'),
    ('ITM-0039', 'Nylon Sling 2m', 'Flat nylon lifting sling, 2-tonne rated.', '2m, 2000kg WLL', 5, 'pc', 6, 0, NULL),
    ('ITM-0040', 'D-Shackle 10mm', 'Galvanized D-shackle for rigging and towing.', '10mm pin, 1000kg WLL', 5, 'pc', 20, 0, NULL),
    ('ITM-0041', 'Wire Rope Clip', 'Galvanized wire rope clip / cable clamp.', 'Fits 8mm rope', 5, 'pc', 25, 0, NULL),
    ('ITM-0042', 'Turnbuckle 3/8"', 'Jaw-and-jaw turnbuckle for tensioning rigging.', '3/8" x 6"', 5, 'pc', 10, 0, NULL)
ON DUPLICATE KEY UPDATE name = name;

-- Variant stock for the padding items above that carry a variant_label.
INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT 'S' AS value, 4 AS qty UNION ALL SELECT 'M', 6 UNION ALL SELECT 'L', 6 UNION ALL SELECT 'XL', 2
) v ON items.item_code = 'ITM-0038'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = item_variants.quantity_on_hand;

-- -------------------------------------------------------------------
-- Split "Oil Filter" and "Engine Oil" out of Truck Parts / Consumables
-- into their own dedicated categories (many brands each, so they
-- earn their own bucket instead of being lumped in with everything
-- else). Existing brand items are reassigned by item_code lookup
-- (not a hardcoded category id, since the new rows' auto-increment
-- ids depend on what's already in the categories table).
-- -------------------------------------------------------------------
INSERT INTO categories (name, code_prefix) VALUES
    ('Oil Filter', 'FLT'), ('Engine Oil', 'OIL')
ON DUPLICATE KEY UPDATE name = name;

-- Move existing oil filter items (generic + brand-specific) out of
-- Truck Parts and into the new Oil Filter category.
UPDATE items SET category_id = (SELECT id FROM categories WHERE name = 'Oil Filter')
WHERE item_code IN ('ITM-0011', 'ITM-0018', 'ITM-0019', 'ITM-0020', 'ITM-0021', 'ITM-0022', 'ITM-0023');

-- Move existing engine oil items (generic + brand-specific) out of
-- Consumables and into the new Engine Oil category.
UPDATE items SET category_id = (SELECT id FROM categories WHERE name = 'Engine Oil')
WHERE item_code IN ('ITM-0013', 'ITM-0024', 'ITM-0025', 'ITM-0026');

-- Engine Oil only had 4 items after the move above, so one more brand
-- is added to bring it up to 5 (Oil Filter already had 7, no padding
-- needed there).
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label)
SELECT 'ITM-0043', 'Total Rubia Engine Oil', 'Heavy-duty diesel engine oil.', 'Diesel engine oil', c.id, 'liter', 28, 0, 'Viscosity Grade'
FROM categories c WHERE c.name = 'Engine Oil'
ON DUPLICATE KEY UPDATE items.name = items.name;

INSERT INTO item_variants (item_id, variant_value, quantity_on_hand)
SELECT id, v.value, v.qty FROM items
JOIN (
    SELECT '15W-40' AS value, 18 AS qty UNION ALL SELECT '20W-50', 10
) v ON items.item_code = 'ITM-0043'
ON DUPLICATE KEY UPDATE item_variants.quantity_on_hand = item_variants.quantity_on_hand;

-- -------------------------------------------------------------------
-- The Oil Filter / Engine Oil split above leaves Truck Parts with
-- only 2 items (Brake Pads, Automotive Fuse) and Consumables with
-- only 4 (Cable Ties, Pylox Spray Paint, Bosch/ATE Brake Fluid) —
-- both under the 5-item floor, so pad them back up too.
--   Truck Parts: 2 -> 5 (+3)
--   Consumables: 4 -> 5 (+1)
-- -------------------------------------------------------------------
INSERT INTO items (item_code, name, description, specification, category_id, unit, quantity_on_hand, is_borrowable, variant_label)
VALUES
    ('ITM-0044', 'Wiper Blade', 'Frameless windshield wiper blade.', '20" universal fit', 7, 'pc', 16, 0, NULL),
    ('ITM-0045', 'Headlight Bulb', 'Halogen headlight bulb, sealed beam.', 'H4 type', 7, 'pc', 22, 0, NULL),
    ('ITM-0046', 'Serpentine Belt', 'Ribbed drive belt for engine accessories.', 'Fits most diesel trucks', 7, 'pc', 6, 0, NULL),
    ('ITM-0047', 'Coolant / Anti-Freeze', 'Ethylene glycol coolant, ready-mixed.', '1 liter, green', 4, 'liter', 30, 0, NULL)
ON DUPLICATE KEY UPDATE name = name;

-- ===================================================================
-- Upgrading an existing database that predates item_variants?
-- Run once, in order:
--
--   CREATE TABLE item_variants (... see definition above ...);
--   ALTER TABLE stock_movements
--     ADD COLUMN item_variant_id INT UNSIGNED DEFAULT NULL AFTER item_id,
--     ADD FOREIGN KEY (item_variant_id) REFERENCES item_variants(id) ON DELETE SET NULL;
--   -- migrate each item's old comma-separated variant_options into
--   -- item_variants rows (quantity_on_hand 0, or split however you like —
--   -- adjust via inventory/stock_adjust.php afterwards), then:
--   ALTER TABLE items DROP COLUMN variant_options;
--
-- Nothing in the application reads variant_options as of this
-- version — variant options and their stock now live entirely in
-- item_variants.
-- ===================================================================

-- ===================================================================
-- Module: Online Requisition & Approval
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: requisitions
-- One row per request a Driver/Helper submits. On approval, a unique
-- `qr_token` is generated — that's what gets encoded into the QR
-- code the requester shows at pickup. Verifying/scanning that QR
-- (inventory/verify.php) moves the requisition to 'released' and
-- deducts stock at that moment, since that's the actual point the
-- items leave the shelf. A full stock movement ledger/audit trail
-- is built out in the Automated Stock Recording module.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requisitions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id    INT UNSIGNED NOT NULL,
    status          ENUM('pending', 'approved', 'declined', 'cancelled', 'released') NOT NULL DEFAULT 'pending',
    purpose         VARCHAR(255) DEFAULT NULL,
    truck_id        INT UNSIGNED DEFAULT NULL,
    truck_plate_snapshot VARCHAR(20) DEFAULT NULL,
    -- Set when the requester marks this request as being for repairing
    -- the selected truck, not a trip. Lets the request through, and past
    -- the approve/release checks, even while that truck is
    -- under_maintenance (see migration_add_maintenance_request_flag.php).
    is_maintenance_request TINYINT(1) NOT NULL DEFAULT 0,
    decided_by      INT UNSIGNED DEFAULT NULL,
    decision_note   VARCHAR(255) DEFAULT NULL,
    -- A Field Supervisor's manual override when a request is urgent for
    -- a reason none of the four scored criteria in includes/priority.php
    -- can see (see migration_add_manual_urgent_flag.php). Reason is
    -- required whenever the flag is set, so the override is accountable
    -- rather than a silent reorder.
    manual_urgent        TINYINT(1) NOT NULL DEFAULT 0,
    manual_urgent_reason VARCHAR(255) DEFAULT NULL,
    manual_urgent_by     INT UNSIGNED DEFAULT NULL,
    manual_urgent_at     TIMESTAMP NULL DEFAULT NULL,
    decided_at      TIMESTAMP NULL DEFAULT NULL,
    qr_token        VARCHAR(64) DEFAULT NULL UNIQUE,
    released_by     INT UNSIGNED DEFAULT NULL,
    released_at     TIMESTAMP NULL DEFAULT NULL,
    defective_part_surrendered TINYINT(1) NOT NULL DEFAULT 0,
    defective_part_note        VARCHAR(255) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (truck_id)     REFERENCES trucks(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by)   REFERENCES users(id),
    FOREIGN KEY (released_by)  REFERENCES users(id),
    FOREIGN KEY (manual_urgent_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: requisition_items
-- Line items for a requisition. `item_name_snapshot` and
-- `unit_snapshot` preserve what was requested even if the catalog
-- item is later renamed or removed.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS requisition_items (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requisition_id      INT UNSIGNED NOT NULL,
    item_id             INT UNSIGNED DEFAULT NULL,
    item_name_snapshot  VARCHAR(120) NOT NULL,
    unit_snapshot        VARCHAR(20)  NOT NULL DEFAULT 'pc',
    quantity_requested  INT UNSIGNED NOT NULL,
    is_borrowable       TINYINT(1)   NOT NULL DEFAULT 0,
    requested_days      INT UNSIGNED DEFAULT NULL,
    variant_selected    VARCHAR(60)  DEFAULT NULL,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: notifications
-- Lightweight in-app notification, standing in for the "real-time
-- notification or SMS alert" described in the proposal. Field
-- Supervisors are notified of new requests; requesters are notified
-- of approval/decline decisions.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    message       VARCHAR(255) NOT NULL,
    link          VARCHAR(255) DEFAULT NULL,
    is_read       TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: item_stock_subscriptions
-- Requesters subscribe to get notified when an out-of-stock borrowable
-- item or specific variant is returned/restocked.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_stock_subscriptions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    item_id         INT UNSIGNED NOT NULL,
    variant_value   VARCHAR(60) DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_item_variant (user_id, item_id, variant_value),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===================================================================
-- Module: Item Availability Requests
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: item_requests
-- Separate from `requisitions` on purpose: this is Personnel flagging
-- "I need this but the catalog can't give it to me right now" (out of
-- stock, or not in the catalog at all) — not a request-and-approval
-- transaction. It goes straight to Inventory Staff as a heads-up, not
-- through Field Supervisor approval. `item_id` is set when the flag
-- was raised from an existing (out-of-stock) catalog item; it's NULL
-- when Personnel typed in something that isn't in the catalog at all,
-- in which case `item_name` is their free-text description instead of
-- a snapshot. `image_filename` is the optional proof/reference photo
-- the requester attaches (e.g. a photo of the part they need).
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_requests (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id      INT UNSIGNED NOT NULL,
    item_id           INT UNSIGNED DEFAULT NULL,
    item_name         VARCHAR(120) NOT NULL,
    quantity          INT UNSIGNED NOT NULL DEFAULT 1,
    reason            VARCHAR(255) NOT NULL,
    image_filename    VARCHAR(255) DEFAULT NULL,
    status            ENUM('pending', 'fulfilled', 'rejected') NOT NULL DEFAULT 'pending',
    decision_note     VARCHAR(255) DEFAULT NULL,
    decided_by        INT UNSIGNED DEFAULT NULL,
    decided_at        TIMESTAMP NULL DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===================================================================
-- Module: Automated Stock Recording
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: stock_movements
-- Every change to `items.quantity_on_hand` gets a row here — the
-- audit trail the earlier modules deferred to this one. Written by
-- record_stock_movement() in includes/stock.php, which is the only
-- place quantity_on_hand should ever be changed from now on.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id           INT UNSIGNED NOT NULL,
    -- Set only when the movement affected one specific variant's own
    -- count (see item_variants). NULL for items with no variants.
    item_variant_id   INT UNSIGNED DEFAULT NULL,
    movement_type     ENUM('stock_in', 'release', 'adjustment', 'return') NOT NULL,
    quantity_change   INT NOT NULL,
    quantity_before   INT UNSIGNED NOT NULL,
    quantity_after    INT UNSIGNED NOT NULL,
    reference_type    VARCHAR(30)  DEFAULT NULL,
    reference_id      INT UNSIGNED DEFAULT NULL,
    recorded_by       INT UNSIGNED NOT NULL,
    note              VARCHAR(255) DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (item_variant_id) REFERENCES item_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===================================================================
-- Module: Tool Borrowing & Due Date Monitoring
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: tool_loans
-- One row per borrowable line item released. `due_date` is set at
-- release time (borrow start), not when the requisition was
-- submitted, since that's the moment the requester actually has the
-- tool in hand. `returned_at` NULL means still out. Overdue is a
-- computed state (due_date < today AND not returned), not stored,
-- except for `overdue_notified_at` which exists purely to stop the
-- same overdue alert firing again every time it's checked.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tool_loans (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- NULL requisition_id/requisition_item_id together mean this loan
    -- is a direct/manual checkout — handed out straight from the asset
    -- detail page (inventory/asset_view.php), outside the requisition
    -- flow entirely — see record_direct_checkout_loan() in
    -- includes/loans.php. It still gets a due_date and the exact same
    -- overdue tracking as a requisition-released loan; only the link
    -- back to a requisition is missing because there isn't one.
    requisition_id        INT UNSIGNED DEFAULT NULL,
    requisition_item_id   INT UNSIGNED DEFAULT NULL,
    item_id               INT UNSIGNED NOT NULL,
    -- Set when the specific physical unit released for this loan was
    -- tagged (see the QR Code Asset Tracking module, further down this
    -- file, added after tool_loans existed — hence nullable). NULL for
    -- installs/items that don't tag individual units; the loan still
    -- works exactly as before, just without unit-level detail. Always
    -- set for a direct checkout, since there's no other way to reach
    -- that flow than scanning/opening a specific tagged asset.
    asset_id              INT UNSIGNED DEFAULT NULL,
    borrower_id           INT UNSIGNED NOT NULL,
    quantity              INT UNSIGNED NOT NULL,
    borrowed_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_date              DATE NOT NULL,
    extension_days        INT DEFAULT NULL,
    extension_reason      VARCHAR(255) DEFAULT NULL,
    extension_status      ENUM('none', 'pending', 'approved', 'declined') NOT NULL DEFAULT 'none',
    extension_requested_at TIMESTAMP NULL DEFAULT NULL,
    extension_decided_at  TIMESTAMP NULL DEFAULT NULL,
    extension_decided_by  INT UNSIGNED DEFAULT NULL,
    extension_decision_note VARCHAR(255) DEFAULT NULL,
    returned_at           TIMESTAMP NULL DEFAULT NULL,
    returned_by           INT UNSIGNED DEFAULT NULL,
    overdue_notified_at   TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (requisition_id) REFERENCES requisitions(id),
    FOREIGN KEY (requisition_item_id) REFERENCES requisition_items(id),
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (borrower_id) REFERENCES users(id),
    FOREIGN KEY (returned_by) REFERENCES users(id),
    FOREIGN KEY (extension_decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ===================================================================
-- Module: QR Code Asset Tracking
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: assets
-- One row per PHYSICAL UNIT of a borrowable item — not per item type.
-- If "Impact Driver" has 3 units in stock, that's 3 rows here, each
-- with its own asset_tag (a permanent, unique QR value distinct from
-- requisitions.qr_token, which is per-TRANSACTION and gets used once).
-- An asset's tag is generated once at registration and never changes;
-- it gets scanned repeatedly over the asset's life (checkout, return,
-- transfer, audit) instead of being consumed after a single scan.
--
-- For borrow_mode 'borrow'/'choice' items, one row = one physical unit
-- (quantity always 1) — individual accountability, since who has it
-- and when it's due matters. For borrow_mode 'consume' items, one row
-- can represent a whole received LOT/batch instead (quantity = how
-- many are still in that lot) — a single tag on the box/container
-- rather than one tag per bolt, still giving a scan-to-see-history
-- trail without a print run per unit. `quantity` shrinks as the lot is
-- used (see record_asset_consumption()) until it hits 0, at which
-- point the row is retired like any other spent asset.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id           INT UNSIGNED NOT NULL,
    -- Set when this asset/lot is a specific catalog OPTION (item_variants
    -- row) rather than the item's undifferentiated stock — e.g. a filter
    -- item with several Part # options, where each option is a genuinely
    -- different physical part and can't be pooled under one tag. NULL for
    -- items that have no variant_label (the normal case) and, as a
    -- fallback, for a variant that was later renamed/removed.
    item_variant_id   INT UNSIGNED  DEFAULT NULL,
    asset_tag         VARCHAR(64)   NOT NULL UNIQUE,
    serial_number     VARCHAR(80)   DEFAULT NULL,
    -- 1 for a per-unit (borrowable) asset. For a consumable lot, the
    -- count still remaining in that lot; decremented by
    -- record_asset_consumption() as it's used, never by borrowing.
    quantity          INT UNSIGNED  NOT NULL DEFAULT 1,
    status            ENUM('available', 'checked_out', 'under_maintenance', 'missing', 'retired')
                          NOT NULL DEFAULT 'available',
    condition_note    VARCHAR(255)  DEFAULT NULL,
    -- Free-text "where is this specific unit right now" — e.g. "Stall 2
    -- — Middle", "With Juan — job site", "Tool room". Deliberately not a
    -- foreign key to stall_layers: a checked-out asset's location is
    -- wherever the borrower took it, not a shelf position, so this needs
    -- to hold either kind of value without forcing a shelf pick.
    location_note     VARCHAR(100)  DEFAULT NULL,
    -- Set while status = 'checked_out' (whether checked out through a
    -- requisition release or a manual checkout); cleared on check-in.
    current_holder_id INT UNSIGNED  DEFAULT NULL,
    acquired_at       DATE          DEFAULT NULL,
    registered_by     INT UNSIGNED  NOT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (item_variant_id) REFERENCES item_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (current_holder_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (registered_by) REFERENCES users(id),
    INDEX idx_assets_item (item_id),
    INDEX idx_assets_variant (item_variant_id),
    INDEX idx_assets_status (status)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: asset_events
-- Append-only history log, one row per scan/action against a specific
-- asset — the same pattern stock_movements and audit_logs already use
-- (single writer function, actor snapshot, never edited or deleted).
-- This is what makes scanning an asset's tag show its full life story
-- (registered -> checked out -> returned -> transferred -> ...) rather
-- than just its current status.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_events (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id              INT UNSIGNED NOT NULL,
    event_type            ENUM(
                              'registered', 'checked_out', 'checked_in',
                              'transferred', 'damage_reported',
                              'maintenance_completed', 'retired',
                              'audit_confirmed', 'audit_missing', 'consumed'
                          ) NOT NULL,
    actor_id              INT UNSIGNED DEFAULT NULL,
    actor_name_snapshot   VARCHAR(100) NOT NULL,
    -- e.g. 'requisition' + requisitions.id when a checkout/check-in came
    -- from a release/return, so an asset's history can link straight
    -- back to the requisition that moved it. NULL for manual actions
    -- (direct checkout, transfer, damage report, audit) with no
    -- requisition behind them.
    reference_type        VARCHAR(30)  DEFAULT NULL,
    reference_id          INT UNSIGNED DEFAULT NULL,
    note                  VARCHAR(255) DEFAULT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_asset_events_asset (asset_id, created_at)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Table: asset_scan_verifications
-- Proof that a specific asset tag was actually scanned (not just
-- picked from the dropdown) for a specific requisition line, before
-- release is confirmed. inventory/asset_scan_verify.php is the ONLY
-- place that ever inserts a row here, mints the token right after the
-- browser reports a successful decode; inventory/verify.php's release
-- handler then requires and consumes that exact token instead of
-- trusting the posted asset_choice[] value on its own. Short-lived
-- (see EXPIRES_MINUTES in includes/assets.php) and single-use
-- (consumed_at set the moment it's spent) so a token can't be replayed
-- against a different release later.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_scan_verifications (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requisition_item_id    INT UNSIGNED NOT NULL,
    asset_id               INT UNSIGNED NOT NULL,
    verify_token           VARCHAR(64)  NOT NULL UNIQUE,
    scanned_by             INT UNSIGNED NOT NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at             TIMESTAMP NOT NULL,
    consumed_at            TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (requisition_item_id) REFERENCES requisition_items(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (scanned_by) REFERENCES users(id),
    INDEX idx_scan_verif_token (verify_token),
    INDEX idx_scan_verif_item (requisition_item_id)
) ENGINE=InnoDB;

-- tool_loans.asset_id (declared in the tool_loans table above, since
-- assets didn't exist yet at that point in the file) links a loan to
-- the SPECIFIC physical unit handed over, not just the item type —
-- nullable, so existing loans and installs that never tag individual
-- units keep working exactly as before with no asset-level detail.
-- The FK itself can only be added here, now that `assets` exists.
-- Guarded so re-importing this file doesn't error on "duplicate key
-- name" the second time it's run.
SET @fk_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tool_loans'
      AND CONSTRAINT_NAME = 'fk_tool_loans_asset'
);
SET @add_fk_sql = IF(@fk_exists = 0,
    'ALTER TABLE tool_loans ADD CONSTRAINT fk_tool_loans_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE add_fk_stmt FROM @add_fk_sql;
EXECUTE add_fk_stmt;
DEALLOCATE PREPARE add_fk_stmt;

-- An already-running install that created tool_loans before this
-- column/table existed should run database/migration_add_assets_tables.php,
-- which adds everything the same way, guarded the same way.

-- assets.quantity — added after the assets table already shipped, so an
-- existing install needs this added on. Guarded the same way as the FK
-- above (dynamic SQL keyed off INFORMATION_SCHEMA), since plain
-- "ADD COLUMN IF NOT EXISTS" isn't available on older MySQL 5.7 installs.
SET @qty_col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'assets'
      AND COLUMN_NAME = 'quantity'
);
SET @add_qty_sql = IF(@qty_col_exists = 0,
    'ALTER TABLE assets ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number',
    'SELECT 1'
);
PREPARE add_qty_stmt FROM @add_qty_sql;
EXECUTE add_qty_stmt;
DEALLOCATE PREPARE add_qty_stmt;

-- asset_events.event_type — widen the enum to include 'consumed' for
-- consumable-lot tracking. A plain MODIFY is safe to re-run (no error
-- if 'consumed' is already in the list), so no existence guard needed.
ALTER TABLE asset_events MODIFY COLUMN event_type ENUM(
    'registered', 'checked_out', 'checked_in',
    'transferred', 'damage_reported',
    'maintenance_completed', 'retired',
    'audit_confirmed', 'audit_missing', 'consumed'
) NOT NULL;

-- ===================================================================
-- Module: System Audit Log
-- ===================================================================

-- -------------------------------------------------------------------
-- Table: audit_logs
-- One row per security-relevant or state-changing event across the
-- whole system (logins, account changes, catalog edits, stock
-- movements, requisition decisions, releases, returns). Written by
-- log_audit_event() in includes/audit.php — the single place events
-- get recorded, so admin/audit_logs.php always has the full trail.
--
-- actor_name_snapshot / actor_role_snapshot preserve who did what
-- even if that account is later edited or deactivated, the same way
-- requisition_items snapshots item names — the log must stay accurate
-- to what happened at the time, not to the account's current state.
-- actor_id is nullable and ON DELETE SET NULL only as a last resort
-- (accounts aren't hard-deleted in this system); the snapshot columns
-- are what the audit page actually displays.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id              INT UNSIGNED DEFAULT NULL,
    actor_name_snapshot   VARCHAR(100) NOT NULL,
    actor_role_snapshot   VARCHAR(30)  DEFAULT NULL,
    action                VARCHAR(60)  NOT NULL,
    entity_type           VARCHAR(40)  NOT NULL,
    entity_id             INT UNSIGNED DEFAULT NULL,
    description           TEXT NOT NULL,
    ip_address            VARCHAR(45)  DEFAULT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created_at (created_at),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_actor (actor_id)
) ENGINE=InnoDB;

-- -------------------------------------------------------------------
-- Sync borrow_mode from the legacy is_equipment/is_borrowable flags
-- for the seed data above (the seed INSERTs above only set those
-- legacy columns, so borrow_mode would otherwise sit at its 'consume'
-- default even for the seeded Equipment category/items). Safe to
-- re-run — it only ever moves a row from the default toward 'borrow'.
-- -------------------------------------------------------------------
UPDATE categories SET borrow_mode = 'borrow' WHERE is_equipment = 1 AND borrow_mode = 'consume';
UPDATE items SET borrow_mode = 'borrow' WHERE is_borrowable = 1 AND borrow_mode = 'consume';

-- -------------------------------------------------------------------
-- Seed: Fleet trucks
-- -------------------------------------------------------------------
INSERT INTO trucks (plate_number, model, status, notes) VALUES
    ('NBZ-4291', 'Isuzu Giga 10-Wheeler Wing Van', 'available', 'Regular Batangas-Manila route'),
    ('CAR-8104', 'Hino 500 Tractor Head', 'on_trip', 'Outbound to Subic depot'),
    ('RTE-5520', 'Mitsubishi Fuso Fighter 6-Wheeler', 'under_maintenance', 'Brake pads replacement and oil change'),
    ('DUA-1102', 'Isuzu Forward Dropside', 'available', 'Yard standby'),
    ('WVN-9033', 'Dongfeng Captain Heavy Truck', 'on_trip', 'Bicol express route')
ON DUPLICATE KEY UPDATE model = model;

UPDATE categories SET requires_truck = 1 WHERE name IN ('Truck Parts', 'Consumables', 'Rigging');

