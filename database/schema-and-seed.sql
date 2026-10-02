-- Inventory & Order Management System
-- Schema + seed data (Slice 1: Auth, User mgmt, Master Data)
-- Runs automatically on first MySQL container start (docker-entrypoint-initdb.d)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------
-- users
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Admin', 'Sales', 'WarehouseStaff') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- warehouses
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_warehouses_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- categories
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    UNIQUE KEY uq_categories_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- products
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    category_id INT NOT NULL,
    unit VARCHAR(30) NOT NULL,
    cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    sell_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    reorder_point INT NOT NULL DEFAULT 0,
    image VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category (category_id),
    KEY idx_products_name (name),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT chk_products_cost_price CHECK (cost_price >= 0),
    CONSTRAINT chk_products_sell_price CHECK (sell_price >= 0),
    CONSTRAINT chk_products_reorder_point CHECK (reorder_point >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- product_stocks (per-warehouse quantity; ProductStock is derived from
-- StockLedger once PO/SO flows exist - slice 1 seeds it directly)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_warehouse (product_id, warehouse_id),
    KEY idx_stocks_warehouse (warehouse_id),
    CONSTRAINT fk_stocks_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_stocks_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT chk_stocks_quantity CHECK (quantity >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- suppliers
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- customers
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- purchase_orders / purchase_order_items
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    status ENUM('Draft', 'Ordered', 'PartiallyReceived', 'Received', 'Cancelled') NOT NULL DEFAULT 'Draft',
    order_date DATE NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_po_supplier (supplier_id),
    KEY idx_po_warehouse (warehouse_id),
    KEY idx_po_status (status),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_po_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty_ordered INT NOT NULL,
    qty_received INT NOT NULL DEFAULT 0,
    cost_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    KEY idx_poi_po (purchase_order_id),
    KEY idx_poi_product (product_id),
    CONSTRAINT fk_poi_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
    CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_poi_qty_ordered CHECK (qty_ordered > 0),
    CONSTRAINT chk_poi_qty_received CHECK (qty_received >= 0 AND qty_received <= qty_ordered),
    CONSTRAINT chk_poi_cost_price CHECK (cost_price >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- sales_orders / sales_order_items
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    status ENUM('Draft', 'PendingApproval', 'Approved', 'Fulfilled', 'Cancelled') NOT NULL DEFAULT 'Draft',
    order_date DATE NOT NULL,
    created_by INT NOT NULL,
    approved_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_so_customer (customer_id),
    KEY idx_so_warehouse (warehouse_id),
    KEY idx_so_status (status),
    KEY idx_so_created_by (created_by),
    CONSTRAINT fk_so_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_so_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_so_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_so_approved_by FOREIGN KEY (approved_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS sales_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    sell_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    KEY idx_soi_so (sales_order_id),
    KEY idx_soi_product (product_id),
    CONSTRAINT fk_soi_so FOREIGN KEY (sales_order_id) REFERENCES sales_orders (id),
    CONSTRAINT fk_soi_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_soi_qty CHECK (qty > 0),
    CONSTRAINT chk_soi_sell_price CHECK (sell_price >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- -----------------------------------------------------------------
-- stock_ledger (single source of truth for every stock movement)
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    movement_type ENUM('Receipt', 'Issue', 'Adjustment') NOT NULL,
    quantity INT NOT NULL,
    reference_type ENUM('PurchaseOrder', 'SalesOrder', 'Adjustment') NOT NULL,
    reference_id INT NULL,
    performed_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ledger_product (product_id),
    KEY idx_ledger_warehouse (warehouse_id),
    KEY idx_ledger_reference (reference_type, reference_id),
    CONSTRAINT fk_ledger_product FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT fk_ledger_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
    CONSTRAINT fk_ledger_performed_by FOREIGN KEY (performed_by) REFERENCES users (id),
    CONSTRAINT chk_ledger_quantity CHECK (quantity > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ===================================================================
-- Seed data
-- ===================================================================

-- Demo accounts - login accepts either the email or the username, all
-- sharing the password 12345678 (hashed below with PHP's
-- password_hash/PASSWORD_DEFAULT):
--   admin@neuronworks.test    / admin    / 12345678
--   sales1@neuronworks.test   / sales1   / 12345678
--   sales2@neuronworks.test   / sales2   / 12345678
--   gudang1@neuronworks.test  / gudang1  / 12345678
--   gudang2@neuronworks.test  / gudang2  / 12345678
INSERT INTO users (name, username, email, password_hash, role, is_active) VALUES
    ('Admin Utama', 'admin', 'admin@neuronworks.test', '$2y$10$qGcYOUiZDnHEd6hRX27ZUuaJbb5Zs0bUDi6PTCPdW8iV5FDgxsgsS', 'Admin', 1),
    ('Sari Sales', 'sales1', 'sales1@neuronworks.test', '$2y$10$qGcYOUiZDnHEd6hRX27ZUuaJbb5Zs0bUDi6PTCPdW8iV5FDgxsgsS', 'Sales', 1),
    ('Budi Sales', 'sales2', 'sales2@neuronworks.test', '$2y$10$qGcYOUiZDnHEd6hRX27ZUuaJbb5Zs0bUDi6PTCPdW8iV5FDgxsgsS', 'Sales', 1),
    ('Wawan Gudang', 'gudang1', 'gudang1@neuronworks.test', '$2y$10$qGcYOUiZDnHEd6hRX27ZUuaJbb5Zs0bUDi6PTCPdW8iV5FDgxsgsS', 'WarehouseStaff', 1),
    ('Tono Gudang', 'gudang2', 'gudang2@neuronworks.test', '$2y$10$qGcYOUiZDnHEd6hRX27ZUuaJbb5Zs0bUDi6PTCPdW8iV5FDgxsgsS', 'WarehouseStaff', 1);

INSERT INTO warehouses (name, location, is_active) VALUES
    ('Gudang Jakarta', 'Jl. Industri Raya No. 1, Jakarta', 1),
    ('Gudang Surabaya', 'Jl. Rungkut Industri No. 12, Surabaya', 1);

INSERT INTO categories (name, description) VALUES
    ('Elektronik', 'Perangkat dan aksesoris elektronik'),
    ('Alat Tulis', 'Perlengkapan tulis dan kantor'),
    ('Kebutuhan Kantor', 'Perlengkapan operasional kantor'),
    ('Bahan Baku', 'Material mentah untuk produksi'),
    ('Peralatan', 'Peralatan dan perkakas kerja');

-- Every product gets a placeholder photo (LoremFlickr - real stock photos
-- pulled by keyword, ?lock=<n> pins each product to one fixed image instead
-- of a new random photo per request) so the product gallery/storefront view
-- has something to show.
--
-- Kept in the original SKU-0001..0016 order/IDs (1-16) - purchase_order_items
-- and sales_order_items below reference these products by literal id, so
-- this insert order must stay exactly as it was.
INSERT INTO products (sku, name, category_id, unit, cost_price, sell_price, reorder_point, is_active, image) VALUES
    ('SKU-0001', 'Mouse Wireless', 1, 'pcs', 45000, 75000, 20, 1, '/uploads/seed/SKU-0001.jpg'),
    ('SKU-0002', 'Keyboard Mechanical', 1, 'pcs', 250000, 375000, 10, 1, '/uploads/seed/SKU-0002.jpg'),
    ('SKU-0003', 'Kabel HDMI 2m', 1, 'pcs', 25000, 45000, 30, 1, '/uploads/seed/SKU-0003.jpg'),
    ('SKU-0004', 'Charger USB-C 30W', 1, 'pcs', 60000, 95000, 15, 1, '/uploads/seed/SKU-0004.jpg'),
    ('SKU-0005', 'Pulpen Gel Hitam', 2, 'pcs', 2500, 4000, 100, 1, '/uploads/seed/SKU-0005.jpg'),
    ('SKU-0006', 'Buku Tulis 58 Lembar', 2, 'pcs', 3000, 5000, 80, 1, '/uploads/seed/SKU-0006.jpg'),
    ('SKU-0007', 'Spidol Whiteboard', 2, 'pcs', 4000, 7000, 40, 1, '/uploads/seed/SKU-0007.jpg'),
    ('SKU-0008', 'Map Plastik A4', 3, 'pcs', 1500, 2500, 60, 1, '/uploads/seed/SKU-0008.jpg'),
    ('SKU-0009', 'Kertas HVS A4 (rim)', 3, 'rim', 45000, 58000, 25, 1, '/uploads/seed/SKU-0009.jpg'),
    ('SKU-0010', 'Stapler Sedang', 3, 'pcs', 12000, 18000, 20, 1, '/uploads/seed/SKU-0010.jpg'),
    ('SKU-0011', 'Plat Besi 1mm', 4, 'lembar', 85000, 110000, 15, 1, '/uploads/seed/SKU-0011.jpg'),
    ('SKU-0012', 'Kawat Las 1kg', 4, 'kg', 35000, 52000, 25, 1, '/uploads/seed/SKU-0012.jpg'),
    ('SKU-0013', 'Cat Dasar 1L', 4, 'liter', 40000, 60000, 20, 1, '/uploads/seed/SKU-0013.jpg'),
    ('SKU-0014', 'Obeng Set 6pc', 5, 'set', 55000, 85000, 10, 1, '/uploads/seed/SKU-0014.jpg'),
    ('SKU-0015', 'Bor Tangan Listrik', 5, 'pcs', 350000, 495000, 5, 1, '/uploads/seed/SKU-0015.jpg'),
    ('SKU-0016', 'Meteran 5m', 5, 'pcs', 20000, 32000, 15, 1, '/uploads/seed/SKU-0016.jpg');

-- Extra products so every category has 11 total (matches the gallery's
-- "Muat Lebih Banyak" batches of 5 - 11 needs two clicks to reveal all).
-- Appended after the original 16 so their ids (17-55) stay a clean, stable
-- continuation instead of interleaving with the referenced ids above.
INSERT INTO products (sku, name, category_id, unit, cost_price, sell_price, reorder_point, is_active, image) VALUES
    ('SKU-0017', 'Monitor LED 24 Inch', 1, 'pcs', 1200000, 1550000, 8, 1, '/uploads/seed/SKU-0017.jpg'),
    ('SKU-0018', 'Webcam HD 1080p', 1, 'pcs', 180000, 250000, 15, 1, '/uploads/seed/SKU-0018.jpg'),
    ('SKU-0019', 'Speaker Bluetooth', 1, 'pcs', 150000, 220000, 12, 1, '/uploads/seed/SKU-0019.jpg'),
    ('SKU-0020', 'Flashdisk 32GB', 1, 'pcs', 40000, 65000, 40, 1, '/uploads/seed/SKU-0020.jpg'),
    ('SKU-0021', 'Hard Disk External 1TB', 1, 'pcs', 550000, 720000, 10, 1, '/uploads/seed/SKU-0021.jpg'),
    ('SKU-0022', 'Router WiFi', 1, 'pcs', 220000, 310000, 10, 1, '/uploads/seed/SKU-0022.jpg'),
    ('SKU-0023', 'Power Bank 10000mAh', 1, 'pcs', 95000, 145000, 20, 1, '/uploads/seed/SKU-0023.jpg'),
    ('SKU-0024', 'Pensil 2B', 2, 'pcs', 1500, 2500, 100, 1, '/uploads/seed/SKU-0024.jpg'),
    ('SKU-0025', 'Penghapus Karet', 2, 'pcs', 1000, 2000, 100, 1, '/uploads/seed/SKU-0025.jpg'),
    ('SKU-0026', 'Penggaris 30cm', 2, 'pcs', 2000, 3500, 60, 1, '/uploads/seed/SKU-0026.jpg'),
    ('SKU-0027', 'Correction Tape', 2, 'pcs', 4500, 7000, 50, 1, '/uploads/seed/SKU-0027.jpg'),
    ('SKU-0028', 'Stabilo Highlighter', 2, 'pcs', 3500, 6000, 60, 1, '/uploads/seed/SKU-0028.jpg'),
    ('SKU-0029', 'Lem Stick', 2, 'pcs', 3000, 5000, 70, 1, '/uploads/seed/SKU-0029.jpg'),
    ('SKU-0030', 'Gunting Kertas', 2, 'pcs', 8000, 13000, 30, 1, '/uploads/seed/SKU-0030.jpg'),
    ('SKU-0031', 'Amplop Coklat', 2, 'pcs', 500, 1000, 200, 1, '/uploads/seed/SKU-0031.jpg'),
    ('SKU-0032', 'Isi Staples No 10', 3, 'box', 3000, 5000, 60, 1, '/uploads/seed/SKU-0032.jpg'),
    ('SKU-0033', 'Klip Kertas', 3, 'box', 2500, 4000, 60, 1, '/uploads/seed/SKU-0033.jpg'),
    ('SKU-0034', 'Post It Note', 3, 'pcs', 6000, 9500, 50, 1, '/uploads/seed/SKU-0034.jpg'),
    ('SKU-0035', 'Tinta Printer Hitam', 3, 'pcs', 65000, 90000, 15, 1, '/uploads/seed/SKU-0035.jpg'),
    ('SKU-0036', 'Kalkulator Meja', 3, 'pcs', 45000, 68000, 12, 1, '/uploads/seed/SKU-0036.jpg'),
    ('SKU-0037', 'Ordner Arsip', 3, 'pcs', 18000, 27000, 25, 1, '/uploads/seed/SKU-0037.jpg'),
    ('SKU-0038', 'Kartu Nama Kotak', 3, 'box', 15000, 25000, 20, 1, '/uploads/seed/SKU-0038.jpg'),
    ('SKU-0039', 'Paper Clip Besar', 3, 'box', 3500, 5500, 40, 1, '/uploads/seed/SKU-0039.jpg'),
    ('SKU-0040', 'Pipa PVC 1 Inch', 4, 'batang', 25000, 38000, 30, 1, '/uploads/seed/SKU-0040.jpg'),
    ('SKU-0041', 'Baut Mur Set', 4, 'set', 15000, 24000, 40, 1, '/uploads/seed/SKU-0041.jpg'),
    ('SKU-0042', 'Kayu Lapis 9mm', 4, 'lembar', 95000, 125000, 15, 1, '/uploads/seed/SKU-0042.jpg'),
    ('SKU-0043', 'Lem Kayu', 4, 'pcs', 12000, 19000, 30, 1, '/uploads/seed/SKU-0043.jpg'),
    ('SKU-0044', 'Amplas Kasar', 4, 'lembar', 3000, 5000, 60, 1, '/uploads/seed/SKU-0044.jpg'),
    ('SKU-0045', 'Solder Timah', 4, 'roll', 45000, 65000, 15, 1, '/uploads/seed/SKU-0045.jpg'),
    ('SKU-0046', 'Karet Gasket', 4, 'pcs', 8000, 13000, 30, 1, '/uploads/seed/SKU-0046.jpg'),
    ('SKU-0047', 'Semen Putih 1kg', 4, 'kg', 6000, 10000, 40, 1, '/uploads/seed/SKU-0047.jpg'),
    ('SKU-0048', 'Tang Kombinasi', 5, 'pcs', 35000, 52000, 20, 1, '/uploads/seed/SKU-0048.jpg'),
    ('SKU-0049', 'Palu Karet', 5, 'pcs', 25000, 38000, 20, 1, '/uploads/seed/SKU-0049.jpg'),
    ('SKU-0050', 'Gergaji Besi', 5, 'pcs', 30000, 45000, 15, 1, '/uploads/seed/SKU-0050.jpg'),
    ('SKU-0051', 'Kunci Pas Set', 5, 'set', 120000, 165000, 10, 1, '/uploads/seed/SKU-0051.jpg'),
    ('SKU-0052', 'Tang Potong', 5, 'pcs', 28000, 42000, 20, 1, '/uploads/seed/SKU-0052.jpg'),
    ('SKU-0053', 'Waterpass 60cm', 5, 'pcs', 45000, 68000, 12, 1, '/uploads/seed/SKU-0053.jpg'),
    ('SKU-0054', 'Cutter Besar', 5, 'pcs', 12000, 19000, 30, 1, '/uploads/seed/SKU-0054.jpg'),
    ('SKU-0055', 'Helm Safety', 5, 'pcs', 55000, 80000, 15, 1, '/uploads/seed/SKU-0055.jpg');

-- Stock per warehouse; some rows deliberately below reorder_point to
-- exercise the low-stock indicator in the product listing.
INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES
    (1, 1, 15), (1, 2, 10),
    (2, 1, 6),  (2, 2, 5),
    (3, 1, 40), (3, 2, 20),
    (4, 1, 5),  (4, 2, 4),
    (5, 1, 120),(5, 2, 60),
    (6, 1, 90), (6, 2, 40),
    (7, 1, 25), (7, 2, 20),
    (8, 1, 70), (8, 2, 30),
    (9, 1, 15), (9, 2, 8),
    (10,1, 12), (10,2, 10),
    (11,1, 8),  (11,2, 5),
    (12,1, 15), (12,2, 12),
    (13,1, 25), (13,2, 10),
    (14,1, 6),  (14,2, 3),
    (15,1, 2),  (15,2, 1),
    (16,1, 18), (16,2, 10),
    (17,1, 6),  (17,2, 4),
    (18,1, 20), (18,2, 12),
    (19,1, 18), (19,2, 10),
    (20,1, 60), (20,2, 30),
    (21,1, 8),  (21,2, 5),
    (22,1, 14), (22,2, 8),
    (23,1, 25), (23,2, 15),
    (24,1, 150),(24,2, 80),
    (25,1, 140),(25,2, 70),
    (26,1, 55), (26,2, 30),
    (27,1, 45), (27,2, 20),
    (28,1, 65), (28,2, 35),
    (29,1, 60), (29,2, 30),
    (30,1, 25), (30,2, 12),
    (31,1, 300),(31,2, 150),
    (32,1, 55), (32,2, 25),
    (33,1, 50), (33,2, 25),
    (34,1, 45), (34,2, 20),
    (35,1, 12), (35,2, 8),
    (36,1, 10), (36,2, 6),
    (37,1, 22), (37,2, 12),
    (38,1, 18), (38,2, 10),
    (39,1, 35), (39,2, 18),
    (40,1, 25), (40,2, 15),
    (41,1, 35), (41,2, 20),
    (42,1, 12), (42,2, 8),
    (43,1, 25), (43,2, 15),
    (44,1, 50), (44,2, 25),
    (45,1, 12), (45,2, 8),
    (46,1, 25), (46,2, 15),
    (47,1, 35), (47,2, 20),
    (48,1, 18), (48,2, 10),
    (49,1, 16), (49,2, 8),
    (50,1, 12), (50,2, 6),
    (51,1, 8),  (51,2, 5),
    (52,1, 16), (52,2, 8),
    (53,1, 10), (53,2, 6),
    (54,1, 25), (54,2, 12),
    (55,1, 12), (55,2, 8);

-- Opening-balance ledger rows so StockLedger stays the source of truth even
-- for the initial seeded quantities above (BR-01: "ledger adalah kebenaran").
INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by)
SELECT product_id, warehouse_id, 'Receipt', quantity, 'Adjustment', NULL, 1
FROM product_stocks
WHERE quantity > 0;

INSERT INTO suppliers (name, contact, address, is_active) VALUES
    ('CV Sumber Elektronik', '021-5551234', 'Jl. Mangga Dua No. 10, Jakarta', 1),
    ('PT Alat Tulis Nusantara', '021-5555678', 'Jl. Kebon Jeruk No. 5, Jakarta', 1),
    ('UD Bahan Baku Sejahtera', '031-7778899', 'Jl. Industri No. 20, Surabaya', 1);

INSERT INTO customers (name, contact, address, is_active) VALUES
    ('Toko Maju Jaya', '0812-1111-2222', 'Jl. Pasar Baru No. 3, Jakarta', 1),
    ('CV Berkah Abadi', '0813-3333-4444', 'Jl. Diponegoro No. 8, Bandung', 1),
    ('Koperasi Sejahtera', '031-9990001', 'Jl. Basuki Rahmat No. 15, Surabaya', 1);

-- -----------------------------------------------------------------
-- Purchase Orders (demo: mix of Ordered/PartiallyReceived/Received/Cancelled)
-- user ids: 1=Admin, 4=Wawan Gudang, 5=Tono Gudang
-- -----------------------------------------------------------------
INSERT INTO purchase_orders (id, supplier_id, warehouse_id, status, order_date, created_by) VALUES
    (1, 1, 1, 'Ordered', '2026-08-20', 4),
    (2, 2, 2, 'Ordered', '2026-08-25', 1),
    (3, 1, 1, 'PartiallyReceived', '2026-08-10', 4),
    (4, 3, 2, 'Received', '2026-07-15', 5),
    (5, 2, 1, 'Cancelled', '2026-08-01', 1),
    (6, 3, 2, 'Received', '2026-07-28', 4);

INSERT INTO purchase_order_items (purchase_order_id, product_id, qty_ordered, qty_received, cost_price) VALUES
    (1, 1, 20, 0, 45000),
    (1, 2, 10, 0, 250000),
    (2, 5, 100, 0, 2500),
    (2, 6, 80, 0, 3000),
    (3, 3, 30, 15, 25000),
    (4, 11, 10, 10, 85000),
    (5, 7, 20, 0, 4000),
    (6, 14, 5, 5, 55000),
    (6, 15, 5, 5, 350000);

-- Ledger entries + stock increments for the lines already received above
-- (PO 3 partial, PO 4 and PO 6 full) - kept consistent with product_stocks.
INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by) VALUES
    (3, 1, 'Receipt', 15, 'PurchaseOrder', 3, 4),
    (11, 2, 'Receipt', 10, 'PurchaseOrder', 4, 5),
    (14, 2, 'Receipt', 5, 'PurchaseOrder', 6, 4),
    (15, 2, 'Receipt', 5, 'PurchaseOrder', 6, 4);

UPDATE product_stocks SET quantity = quantity + 15 WHERE product_id = 3 AND warehouse_id = 1;
UPDATE product_stocks SET quantity = quantity + 10 WHERE product_id = 11 AND warehouse_id = 2;
UPDATE product_stocks SET quantity = quantity + 5 WHERE product_id = 14 AND warehouse_id = 2;
UPDATE product_stocks SET quantity = quantity + 5 WHERE product_id = 15 AND warehouse_id = 2;

-- -----------------------------------------------------------------
-- Sales Orders (demo: mix of Draft/PendingApproval/Approved/Fulfilled/Cancelled)
-- user ids: 1=Admin, 2=Sari Sales, 3=Budi Sales
-- SO4's item deliberately exceeds available stock in warehouse 1 (product 15
-- only has 2 on hand there) so the goods-issue rejection path can be demoed.
-- -----------------------------------------------------------------
INSERT INTO sales_orders (id, customer_id, warehouse_id, status, order_date, created_by, approved_by) VALUES
    (1, 1, 1, 'Draft', '2026-09-01', 2, NULL),
    (2, 2, 1, 'PendingApproval', '2026-08-28', 3, NULL),
    (3, 1, 2, 'Approved', '2026-08-20', 2, 1),
    (4, 3, 1, 'Approved', '2026-08-15', 3, 1),
    (5, 2, 1, 'Cancelled', '2026-08-05', 2, NULL),
    (6, 1, 1, 'Fulfilled', '2026-07-20', 3, 1),
    (7, 3, 2, 'Fulfilled', '2026-07-10', 2, 1);

INSERT INTO sales_order_items (sales_order_id, product_id, qty, sell_price) VALUES
    (1, 1, 3, 75000),
    (2, 5, 20, 4000),
    (2, 6, 10, 5000),
    (3, 8, 5, 2500),
    (4, 15, 5, 495000),
    (5, 4, 3, 95000),
    (6, 9, 5, 58000),
    (7, 12, 4, 52000),
    (7, 13, 3, 60000);

-- Ledger entries + stock decrements for the already-fulfilled SOs above
-- (SO 6 and SO 7) - kept consistent with product_stocks.
INSERT INTO stock_ledger (product_id, warehouse_id, movement_type, quantity, reference_type, reference_id, performed_by) VALUES
    (9, 1, 'Issue', 5, 'SalesOrder', 6, 1),
    (12, 2, 'Issue', 4, 'SalesOrder', 7, 1),
    (13, 2, 'Issue', 3, 'SalesOrder', 7, 1);

UPDATE product_stocks SET quantity = quantity - 5 WHERE product_id = 9 AND warehouse_id = 1;
UPDATE product_stocks SET quantity = quantity - 4 WHERE product_id = 12 AND warehouse_id = 2;
UPDATE product_stocks SET quantity = quantity - 3 WHERE product_id = 13 AND warehouse_id = 2;
