-- ╔══════════════════════════════════════════════════════════════════╗
-- ║  SmartCart — One-shot CPanel install                             ║
-- ║  Run this ONCE in phpMyAdmin → Import on a fresh DB.             ║
-- ║  Combines: schema.sql + seed.sql + V3_migration.sql + demo_seed  ║
-- ║  Safe to re-run on partial installs (V3 + demo are idempotent).  ║
-- ╚══════════════════════════════════════════════════════════════════╝

-- ════════ schema.sql ════════
-- SmartCart Database Schema
-- Project: Academic Accelerator 20262X64
-- Team: Gaya Kishon, Rotem Maor, Noa Tider, Tomer Blond
-- Stack: MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`                 VARCHAR(255) NOT NULL,
    `password_hash`         VARCHAR(255) NOT NULL,
    `full_name`             VARCHAR(150) NOT NULL,
    `role`                  ENUM('customer','business','admin') NOT NULL DEFAULT 'customer',
    `phone`                 VARCHAR(30)  DEFAULT NULL,
    `address`               VARCHAR(255) DEFAULT NULL,
    `city`                  VARCHAR(100) DEFAULT NULL,
    `lat`                   DECIMAL(10,8) DEFAULT NULL,
    `lng`                   DECIMAL(11,8) DEFAULT NULL,
    `preferred_categories`  JSON          DEFAULT NULL,
    `onboarding_complete`   TINYINT(1)   NOT NULL DEFAULT 0,
    `is_active`             TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. BUSINESSES
-- ============================================================
CREATE TABLE IF NOT EXISTS `businesses` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT UNSIGNED NOT NULL,
    `business_name` VARCHAR(200) NOT NULL,
    `description`   TEXT         DEFAULT NULL,
    `address`       VARCHAR(255) DEFAULT NULL,
    `city`          VARCHAR(100) DEFAULT NULL,
    `lat`           DECIMAL(10,8) DEFAULT NULL,
    `lng`           DECIMAL(11,8) DEFAULT NULL,
    `logo_url`      VARCHAR(500) DEFAULT NULL,
    `phone`         VARCHAR(30)  DEFAULT NULL,
    `category`      ENUM(
                        'electronics','home','fashion','food',
                        'sports','beauty','toys','books','automotive','other'
                    ) NOT NULL DEFAULT 'other',
    `status`        ENUM('active','suspended') NOT NULL DEFAULT 'active',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_businesses_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_businesses_user` (`user_id`),
    KEY `idx_businesses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `products` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `business_id`     INT UNSIGNED NOT NULL,
    `name`            VARCHAR(300) NOT NULL,
    `description`     TEXT         DEFAULT NULL,
    `price_ils`       DECIMAL(10,2) NOT NULL,
    `group_price_ils` DECIMAL(10,2) NOT NULL,
    `category`        ENUM(
                          'electronics','home','fashion','food',
                          'sports','beauty','toys','books','automotive','other'
                      ) NOT NULL DEFAULT 'other',
    `min_participants` INT UNSIGNED NOT NULL DEFAULT 2,
    `image_url`       VARCHAR(500) DEFAULT NULL,
    `city`            VARCHAR(100) DEFAULT NULL,
    `lat`             DECIMAL(10,8) DEFAULT NULL,
    `lng`             DECIMAL(11,8) DEFAULT NULL,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_products_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    KEY `idx_products_business` (`business_id`),
    KEY `idx_products_category` (`category`),
    KEY `idx_products_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. GROUP PURCHASES
-- ============================================================
CREATE TABLE IF NOT EXISTS `group_purchases` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id`          INT UNSIGNED NOT NULL,
    `creator_id`          INT UNSIGNED NOT NULL,
    `target_participants` INT UNSIGNED NOT NULL,
    `current_participants` INT UNSIGNED NOT NULL DEFAULT 0,
    `deadline`            DATETIME NOT NULL,
    `status`              ENUM('open','closed','failed') NOT NULL DEFAULT 'open',
    `city`                VARCHAR(100) DEFAULT NULL,
    `lat`                 DECIMAL(10,8) DEFAULT NULL,
    `lng`                 DECIMAL(11,8) DEFAULT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_gp_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gp_creator` FOREIGN KEY (`creator_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_gp_product` (`product_id`),
    KEY `idx_gp_creator` (`creator_id`),
    KEY `idx_gp_status` (`status`),
    KEY `idx_gp_deadline` (`deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. GROUP MEMBERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `group_members` (
    `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`  INT UNSIGNED NOT NULL,
    `user_id`   INT UNSIGNED NOT NULL,
    `status`    ENUM('joined','paid','cancelled') NOT NULL DEFAULT 'joined',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_gm_group` FOREIGN KEY (`group_id`) REFERENCES `group_purchases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_gm_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_group_member` (`group_id`, `user_id`),
    KEY `idx_gm_user` (`user_id`),
    KEY `idx_gm_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. GROUP MESSAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS `group_messages` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`     INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `message_text` TEXT NOT NULL,
    `sent_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_msg_group` FOREIGN KEY (`group_id`) REFERENCES `group_purchases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_msg_group` (`group_id`),
    KEY `idx_msg_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. PAYMENTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`             INT UNSIGNED NOT NULL,
    `user_id`              INT UNSIGNED NOT NULL,
    `amount_ils`           DECIMAL(10,2) NOT NULL,
    `status`               ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    `paypal_transaction_id` VARCHAR(255) DEFAULT NULL,
    `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pay_group` FOREIGN KEY (`group_id`) REFERENCES `group_purchases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pay_user`  FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_pay_group` (`group_id`),
    KEY `idx_pay_user` (`user_id`),
    KEY `idx_pay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. ORDERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `orders` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id`         INT UNSIGNED NOT NULL,
    `payment_id`       INT UNSIGNED NOT NULL,
    `user_id`          INT UNSIGNED NOT NULL,
    `product_id`       INT UNSIGNED NOT NULL,
    `amount_paid`      DECIMAL(10,2) NOT NULL,
    `shipping_status`  ENUM('pending','processing','shipped','delivered') NOT NULL DEFAULT 'pending',
    `shipping_address` VARCHAR(500) DEFAULT NULL,
    `tracking_id`      VARCHAR(100) DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ord_group`   FOREIGN KEY (`group_id`)   REFERENCES `group_purchases`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ord_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ord_user`    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ord_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    KEY `idx_ord_group` (`group_id`),
    KEY `idx_ord_user` (`user_id`),
    KEY `idx_ord_shipping` (`shipping_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════ seed.sql ════════
-- SmartCart — Seed Data (Israeli Market)
-- Test accounts: customer@test.com, business@test.com, admin@test.com
-- All passwords: test1234 (bcrypt hash below)
-- Real Israeli GPS coordinates used throughout

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────
-- USERS (password hash = bcrypt of 'test1234')
-- ─────────────────────────────────────────────────────────
INSERT INTO users (email, password_hash, full_name, role, phone, city, lat, lng, preferred_categories, onboarding_complete) VALUES
('customer@test.com', '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'David Cohen', 'customer', '052-1234567', 'Tel Aviv', 32.0853, 34.7818, '["electronics","sports","food"]', 1),
('business@test.com', '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Sarah Levi', 'business', '03-9876543', 'Tel Aviv', 32.0853, 34.7818, '[]', 1),
('admin@test.com',    '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Admin User', 'admin', '02-1111111', 'Jerusalem', 31.7683, 35.2137, '[]', 1),
('maya@test.com',    '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Maya Ben-David', 'customer', '054-2345678', 'Haifa', 32.7940, 34.9896, '["fashion","beauty","home"]', 1),
('oren@test.com',    '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Oren Shapiro', 'customer', '050-3456789', 'Jerusalem', 31.7683, 35.2137, '["books","electronics"]', 1),
('noa@test.com',     '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Noa Mizrahi', 'business', '09-8765432', 'Herzliya', 32.1663, 34.8439, '[]', 1),
('tamar@test.com',   '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Tamar Katz', 'customer', '053-4567890', 'Beer Sheva', 31.2520, 34.7915, '["food","sports","toys"]', 1),
('rami@test.com',    '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Rami Peretz', 'business', '04-7654321', 'Haifa', 32.7940, 34.9896, '[]', 1);

-- ─────────────────────────────────────────────────────────
-- BUSINESSES
-- ─────────────────────────────────────────────────────────
INSERT INTO businesses (user_id, business_name, description, address, city, lat, lng, phone, category, status) VALUES
(2, 'TechStore Tel Aviv', 'Leading electronics retailer in Tel Aviv. Latest tech at group prices.', 'Dizengoff 120, Tel Aviv', 'Tel Aviv', 32.0818, 34.7738, '03-9876543', 'electronics', 'active'),
(6, 'HomeStyle Herzliya', 'Premium home decor and appliances for the modern Israeli home.', 'HaAtzmaut 45, Herzliya', 'Herzliya', 32.1663, 34.8439, '09-7654321', 'home', 'active'),
(8, 'SportZone Haifa', 'Your one-stop shop for all sports equipment and activewear.', 'HaNassi 78, Haifa', 'Haifa', 32.8200, 34.9990, '04-8765432', 'sports', 'active'),
(2, 'FreshBox Jerusalem', 'Farm-fresh produce and specialty foods delivered to your group.', 'Mahane Yehuda 5, Jerusalem', 'Jerusalem', 31.7844, 35.2132, '02-9876543', 'food', 'active'),
(6, 'BookCorner Ramat Gan', 'Hebrew and English books, textbooks, and educational materials.', 'Bialik 23, Ramat Gan', 'Ramat Gan', 32.0684, 34.8248, '03-6543210', 'books', 'active'),
(8, 'GlowUp Netanya', 'Professional beauty products and skincare at wholesale prices.', 'Herzl 56, Netanya', 'Netanya', 32.3215, 34.8533, '09-5432109', 'beauty', 'active'),
(2, 'FashionHub Beer Sheva', 'Contemporary fashion for the whole family. Israeli and international brands.', 'Rager 89, Beer Sheva', 'Beer Sheva', 31.2460, 34.7960, '08-9012345', 'fashion', 'active');

-- ─────────────────────────────────────────────────────────
-- PRODUCTS (ILS prices, Israeli market)
-- ─────────────────────────────────────────────────────────
INSERT INTO products (business_id, name, description, price_ils, group_price_ils, category, min_participants, image_url, city, lat, lng, status) VALUES
-- TechStore Tel Aviv
(1, 'Sony WH-1000XM5 Wireless Headphones', 'Industry-leading noise cancellation with Bluetooth 5.2. 30-hour battery life, 3-minute quick charge gives 3 hours of playback. Premium audio quality for music and calls.', 1499.00, 899.00, 'electronics', 3, '/assets/images/products/sony-wh-1000xm5-headphones.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
(1, 'Samsung Galaxy Watch 6', 'Smartwatch with Super AMOLED display and advanced health tracking. Built-in GPS, heart rate monitor, sleep tracking, and 40-hour battery life. Compatible with Android and iOS.', 1200.00, 750.00, 'electronics', 4, '/assets/images/products/samsung-galaxy-watch-6.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
(1, 'JBL Charge 5 Bluetooth Speaker', 'Portable waterproof speaker (IP67-rated) with powerful stereo sound and deep bass. 20-hour battery life, Bluetooth 5.1, doubles as a power bank to charge your devices.', 899.00, 540.00, 'electronics', 5, '/assets/images/products/jbl-charge-5-speaker.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
-- HomeStyle Herzliya
(2, 'Dyson V12 Detect Slim Vacuum', 'Cordless stick vacuum with laser dust detection technology that reveals hidden dust. Up to 60 minutes of runtime, auto-adjusting suction, whole-machine HEPA filtration.', 2400.00, 1290.00, 'home', 4, '/assets/images/products/dyson-v12-vacuum.jpg', 'Herzliya', 32.1663, 34.8439, 'active'),
(2, 'Instant Pot Duo 7-in-1 Electric Pressure Cooker', '7-in-1 multi-cooker: pressure cooker, slow cooker, rice cooker, steamer, sauté pan, yogurt maker, and food warmer. 6-quart capacity, saves up to 70% cooking time.', 599.00, 370.00, 'home', 3, '/assets/images/products/instant-pot-duo-7-in-1.jpg', 'Herzliya', 32.1663, 34.8439, 'active'),
-- SportZone Haifa
(3, 'Nike Air Max 270 Running Shoes', 'Everyday running shoes featuring the tallest Max Air heel unit for all-day comfort. Breathable mesh upper, responsive foam cushioning, available in multiple colors and sizes.', 599.00, 390.00, 'sports', 5, '/assets/images/products/nike-air-max-270-shoes.jpg', 'Haifa', 32.8200, 34.9990, 'active'),
(3, 'Premium Yoga Mat Set', 'Complete yoga set for all levels: 6mm thick non-slip mat with alignment lines, two foam blocks for stability, and an adjustable stretch strap. Lightweight and easy to carry.', 299.00, 210.00, 'sports', 4, '/assets/images/products/premium-yoga-mat-set.jpg', 'Haifa', 32.8200, 34.9990, 'active'),
-- FreshBox Jerusalem
(4, 'Organic Coffee Sampler Box', '6 single-origin organic coffee varieties from Ethiopia, Colombia, Brazil, Guatemala, Peru, and Kenya. 250g per bag, freshly roasted and vacuum-sealed for peak flavor.', 199.00, 149.00, 'food', 8, '/assets/images/products/organic-coffee-sampler-box.jpg', 'Jerusalem', 31.7844, 35.2132, 'active'),
-- BookCorner Ramat Gan
(5, 'Complete Python Programming Course (Book)', 'Comprehensive Python guide for beginners to advanced developers. 600+ pages covering core syntax, data structures, OOP, web scraping, data analysis, and 10 real-world projects.', 199.00, 119.00, 'books', 6, '/assets/images/products/python-programming-book.jpg', 'Ramat Gan', 32.0684, 34.8248, 'active'),
-- GlowUp Netanya
(6, 'Natural Skincare Discovery Set', 'Complete natural skincare routine in one box: hydrating face cream, vitamin C brightening serum, nourishing body lotion, and gentle natural soap. Paraben-free, dermatologist tested.', 299.00, 200.00, 'beauty', 5, '/assets/images/products/natural-skincare-set.jpg', 'Netanya', 32.3215, 34.8533, 'active'),
-- FashionHub Beer Sheva
(7, 'Handmade Leather Messenger Bag', 'Genuine full-grain leather messenger bag handcrafted by artisans. Padded laptop compartment fits up to 15 inches, multiple organizer pockets, adjustable shoulder strap. Available in 3 colors.', 599.00, 430.00, 'fashion', 6, '/assets/images/products/leather-messenger-bag.jpg', 'Beer Sheva', 31.2460, 34.7960, 'active');

-- ─────────────────────────────────────────────────────────
-- GROUP PURCHASES (various fill levels, all Israeli cities)
-- ─────────────────────────────────────────────────────────
INSERT INTO group_purchases (product_id, creator_id, target_participants, current_participants, deadline, status, city, lat, lng) VALUES
-- Sony Headphones — 3/5 filled (60%)
(1, 1, 5, 3, DATE_ADD(NOW(), INTERVAL 5 DAY), 'open', 'Tel Aviv', 32.0818, 34.7738),
-- Samsung Watch — 2/4 filled (50%)
(2, 4, 4, 2, DATE_ADD(NOW(), INTERVAL 8 DAY), 'open', 'Tel Aviv', 32.0818, 34.7738),
-- Dyson Vacuum — 5/8 filled (62%)
(4, 6, 8, 5, DATE_ADD(NOW(), INTERVAL 3 DAY), 'open', 'Herzliya', 32.1663, 34.8439),
-- KitchenAid — 1/3 filled (33%)
(5, 1, 3, 1, DATE_ADD(NOW(), INTERVAL 12 DAY), 'open', 'Herzliya', 32.1663, 34.8439),
-- Nike shoes — 7/10 filled (70%)
(6, 7, 10, 7, DATE_ADD(NOW(), INTERVAL 2 DAY), 'open', 'Haifa', 32.8200, 34.9990),
-- Tennis racket — 4/4 filled (100%) — CLOSED
(7, 8, 4, 4, DATE_ADD(NOW(), INTERVAL -1 DAY), 'closed', 'Haifa', 32.8200, 34.9990),
-- Organic veggies — 12/20 filled (60%)
(8, 5, 20, 12, DATE_ADD(NOW(), INTERVAL 6 DAY), 'open', 'Jerusalem', 31.7844, 35.2132),
-- Math books — 4/6 filled (67%)
(9, 1, 6, 4, DATE_ADD(NOW(), INTERVAL 10 DAY), 'open', 'Ramat Gan', 32.0684, 34.8248),
-- Skincare kit — 1/5 filled (20%)
(10, 4, 5, 1, DATE_ADD(NOW(), INTERVAL 15 DAY), 'open', 'Netanya', 32.3215, 34.8533),
-- Zara jacket — 5/6 filled (83%)
(11, 7, 6, 5, DATE_ADD(NOW(), INTERVAL 1 DAY), 'open', 'Beer Sheva', 31.2460, 34.7960),
-- PowerBank — 2/5 filled (40%)
(3, 1, 5, 2, DATE_ADD(NOW(), INTERVAL 7 DAY), 'open', 'Tel Aviv', 32.0818, 34.7738);

-- ─────────────────────────────────────────────────────────
-- GROUP MEMBERS (sample memberships)
-- ─────────────────────────────────────────────────────────
INSERT INTO group_members (group_id, user_id, status) VALUES
-- Group 1 (Sony): David, Maya, Oren joined
(1, 1, 'joined'), (1, 4, 'joined'), (1, 5, 'joined'),
-- Group 2 (Samsung): David, Maya joined
(2, 1, 'joined'), (2, 4, 'joined'),
-- Group 3 (Dyson): 5 members
(3, 6, 'joined'), (3, 1, 'joined'), (3, 4, 'joined'), (3, 5, 'joined'), (3, 7, 'joined'),
-- Group 4 (KitchenAid): David
(4, 1, 'joined'),
-- Group 5 (Nike): 7 members
(5, 7, 'joined'), (5, 1, 'joined'), (5, 4, 'joined'), (5, 5, 'joined'), (5, 2, 'joined'), (5, 6, 'joined'), (5, 8, 'joined'),
-- Group 6 (Tennis - CLOSED): 4 members, 2 paid
(6, 8, 'paid'), (6, 1, 'paid'), (6, 4, 'joined'), (6, 7, 'joined'),
-- Group 7 (Veggies): 12 members
(7, 5, 'joined'), (7, 1, 'joined'), (7, 4, 'joined'), (7, 7, 'joined'), (7, 2, 'joined'), (7, 6, 'joined'),
-- Group 10 (Zara): 5 members
(10, 7, 'joined'), (10, 4, 'joined'), (10, 1, 'joined'), (10, 6, 'joined'), (10, 2, 'joined'),
-- Group 11 (PowerBank): 2 members
(11, 1, 'joined'), (11, 5, 'joined');

-- ─────────────────────────────────────────────────────────
-- PAYMENTS (for closed group 6 - Tennis racket)
-- ─────────────────────────────────────────────────────────
INSERT INTO payments (group_id, user_id, amount_ils, status, paypal_transaction_id) VALUES
(6, 8, 599.00, 'completed', 'PAYPAL-SANDBOX-TXN-001'),
(6, 1, 599.00, 'completed', 'PAYPAL-SANDBOX-TXN-002');

-- ─────────────────────────────────────────────────────────
-- ORDERS (for completed payments)
-- ─────────────────────────────────────────────────────────
INSERT INTO orders (group_id, payment_id, user_id, product_id, amount_paid, shipping_status, shipping_address, tracking_id) VALUES
(6, 1, 8, 7, 599.00, 'shipped',    'HaNassi 78, Haifa', 'IL-TRACK-001-HFA'),
(6, 2, 1, 7, 599.00, 'processing', 'Dizengoff 120, Tel Aviv', NULL);

-- ─────────────────────────────────────────────────────────
-- SAMPLE CHAT MESSAGES
-- ─────────────────────────────────────────────────────────
INSERT INTO group_messages (group_id, user_id, message_text) VALUES
(1, 1, 'היי לכולם! מי נוסף לקבוצה?'),
(1, 4, 'נכנסתי, ממתינה לאישור :)'),
(1, 5, 'מצוין! נחכה לעוד שניים ונסגור.'),
(5, 7, 'הנעליים שלנו בדרך! עוד 3 מקומות ונסגור'),
(5, 4, 'שלחתי לחברים, אולי הם יצטרפו');

SET FOREIGN_KEY_CHECKS = 1;

-- ════════ V3_migration.sql ════════
DELIMITER //
DROP PROCEDURE IF EXISTS smartcart_v3_install //
CREATE PROCEDURE smartcart_v3_install()
BEGIN
    -- payments: 9 new columns
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='paypal_auth_id') THEN
        ALTER TABLE payments
            ADD COLUMN paypal_auth_id      VARCHAR(60)  DEFAULT NULL AFTER paypal_transaction_id,
            ADD COLUMN auth_status         ENUM('none','authorized','captured','voided','refunded','failed') DEFAULT 'none' AFTER status,
            ADD COLUMN auth_amount_ils     DECIMAL(10,2) DEFAULT NULL AFTER auth_status,
            ADD COLUMN authorized_at       DATETIME DEFAULT NULL,
            ADD COLUMN captured_at         DATETIME DEFAULT NULL,
            ADD COLUMN voided_at           DATETIME DEFAULT NULL,
            ADD COLUMN refunded_at         DATETIME DEFAULT NULL,
            ADD COLUMN refund_id           VARCHAR(60) DEFAULT NULL,
            ADD COLUMN last_error          VARCHAR(500) DEFAULT NULL;
    END IF;

    -- group_members: deposit_status
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='group_members' AND column_name='deposit_status') THEN
        ALTER TABLE group_members
            ADD COLUMN deposit_status ENUM('none','held','captured','voided','forfeited','refunded') DEFAULT 'none' AFTER status;
    END IF;
END //
DELIMITER ;

CALL smartcart_v3_install();
DROP PROCEDURE smartcart_v3_install;

-- 3 new tables (these were always idempotent)
CREATE TABLE IF NOT EXISTS notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    type         VARCHAR(64) NOT NULL,
    title        VARCHAR(200) NOT NULL,
    body         TEXT,
    link_url     VARCHAR(500) DEFAULT NULL,
    payload_json JSON DEFAULT NULL,
    read_at      DATETIME DEFAULT NULL,
    sent_email   TINYINT(1) DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_unread (user_id, read_at),
    KEY idx_created    (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    `key`      VARCHAR(120) NOT NULL PRIMARY KEY,
    `count`    INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════ demo_seed.sql ════════
-- SmartCart — Demo seed (extends existing seed.sql with precise addresses for a demo session)
-- Run after schema.sql and seed.sql. Run with:
--   mysql -u root -P 8889 -p smartcart < sql/demo_seed.sql
--
-- What this adds:
--   • Precise street-level addresses for existing businesses (e.g. "Dizengoff 120, Tel Aviv")
--   • 3 new Tel Aviv businesses on Dizengoff / Ben Yehuda / Rothschild — close together for the map demo
--   • Each new business has a deal in a different state (open, almost-full, closed-with-orders, failed)
--   • A V3-ready group with PayPal "authorized" state for showing the lifecycle

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────
-- Ensure existing businesses have precise addresses + lat/lng
-- These are real Tel Aviv coordinates so the maps look authentic.
-- ─────────────────────────────────────────────────────────
UPDATE businesses SET address='Dizengoff 120, Tel Aviv', lat=32.07861, lng=34.77498 WHERE business_name='TechStore Tel Aviv';
UPDATE businesses SET address='HaAtzmaut 45, Herzliya', lat=32.16596, lng=34.84385 WHERE business_name='HomeStyle Herzliya';
UPDATE businesses SET address='HaNassi 78, Haifa', lat=32.81891, lng=34.99857 WHERE business_name='SportZone Haifa';

-- ─────────────────────────────────────────────────────────
-- Add 3 demo business users (passwords: test1234) — bcrypt hash matches seed.sql
-- ─────────────────────────────────────────────────────────
INSERT IGNORE INTO users (email, password_hash, full_name, role, phone, city, lat, lng, preferred_categories, onboarding_complete) VALUES
('cafe@test.com',   '$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Yossi Mor',  'business', '03-1112233', 'Tel Aviv', 32.0820, 34.7710, '[]', 1),
('boutique@test.com','$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Liat Vardi', 'business', '03-2223344', 'Tel Aviv', 32.0780, 34.7720, '[]', 1),
('gadgets@test.com','$2y$10$xvsztotL5DSSzegycVHwveSJN.gFi3Eanh6/IB0xJpuqajJZoiChO', 'Doron Bar',  'business', '03-3334455', 'Tel Aviv', 32.0640, 34.7740, '[]', 1);

-- Capture their user IDs by email and create businesses (only if user has no business yet)
INSERT INTO businesses (user_id, business_name, description, address, city, lat, lng, phone, category, status)
SELECT u.id, 'Cafe Dizengoff', 'Artisan coffee and pastry — discover specialty roasts at group prices.',
       'Dizengoff 240, Tel Aviv', 'Tel Aviv', 32.08495, 34.77417, '03-1112233', 'food', 'active'
FROM users u WHERE u.email='cafe@test.com'
  AND NOT EXISTS (SELECT 1 FROM businesses b WHERE b.user_id = u.id);

INSERT INTO businesses (user_id, business_name, description, address, city, lat, lng, phone, category, status)
SELECT u.id, 'Rothschild Boutique', 'Independent designer fashion on Rothschild Boulevard.',
       'Rothschild 45, Tel Aviv', 'Tel Aviv', 32.06463, 34.77467, '03-2223344', 'fashion', 'active'
FROM users u WHERE u.email='boutique@test.com'
  AND NOT EXISTS (SELECT 1 FROM businesses b WHERE b.user_id = u.id);

INSERT INTO businesses (user_id, business_name, description, address, city, lat, lng, phone, category, status)
SELECT u.id, 'GadgetLab', 'Smart home gear and electronics — the gadget hub of Tel Aviv.',
       'Ben Yehuda 80, Tel Aviv', 'Tel Aviv', 32.08105, 34.77076, '03-3334455', 'electronics', 'active'
FROM users u WHERE u.email='gadgets@test.com'
  AND NOT EXISTS (SELECT 1 FROM businesses b WHERE b.user_id = u.id);

-- ─────────────────────────────────────────────────────────
-- Demo products (one per new business)
-- ─────────────────────────────────────────────────────────
INSERT INTO products (business_id, name, description, price_ils, group_price_ils, category, min_participants, city, lat, lng, status)
SELECT b.id, 'Specialty Coffee Beans 1kg', '1kg bag of single-origin Ethiopian beans, roasted in Tel Aviv every Monday.',
       180, 120, 'food', 5, b.city, b.lat, b.lng, 'active'
FROM businesses b
WHERE b.business_name='Cafe Dizengoff'
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.business_id=b.id AND p.name='Specialty Coffee Beans 1kg');

INSERT INTO products (business_id, name, description, price_ils, group_price_ils, category, min_participants, city, lat, lng, status)
SELECT b.id, 'Designer Linen Shirt', 'Hand-stitched linen shirt in 4 colors. Local Israeli design.',
       450, 290, 'fashion', 4, b.city, b.lat, b.lng, 'active'
FROM businesses b
WHERE b.business_name='Rothschild Boutique'
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.business_id=b.id AND p.name='Designer Linen Shirt');

INSERT INTO products (business_id, name, description, price_ils, group_price_ils, category, min_participants, city, lat, lng, status)
SELECT b.id, 'Smart LED Strip 5m', 'WiFi-controlled RGB LED strip, syncs with music and Alexa.',
       260, 165, 'electronics', 6, b.city, b.lat, b.lng, 'active'
FROM businesses b
WHERE b.business_name='GadgetLab'
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.business_id=b.id AND p.name='Smart LED Strip 5m');

-- ─────────────────────────────────────────────────────────
-- Demo group purchases — 4 states for the demo
-- ─────────────────────────────────────────────────────────
-- 1. Cafe Dizengoff coffee — almost-full (4/5 in 2 days)
INSERT INTO group_purchases (product_id, creator_id, target_participants, current_participants, deadline, status, city, lat, lng)
SELECT p.id, b.user_id, 5, 4, DATE_ADD(NOW(), INTERVAL 2 DAY), 'open', p.city, p.lat, p.lng
FROM products p JOIN businesses b ON b.id=p.business_id
WHERE p.name='Specialty Coffee Beans 1kg'
  AND NOT EXISTS (SELECT 1 FROM group_purchases gp WHERE gp.product_id=p.id AND gp.status='open');

-- 2. Designer shirt — just started (1/4, plenty of time)
INSERT INTO group_purchases (product_id, creator_id, target_participants, current_participants, deadline, status, city, lat, lng)
SELECT p.id, b.user_id, 4, 1, DATE_ADD(NOW(), INTERVAL 14 DAY), 'open', p.city, p.lat, p.lng
FROM products p JOIN businesses b ON b.id=p.business_id
WHERE p.name='Designer Linen Shirt'
  AND NOT EXISTS (SELECT 1 FROM group_purchases gp WHERE gp.product_id=p.id AND gp.status='open');

-- 3. Smart LED — urgent (5/6, ends in 12 hours!) — great for countdown demo
INSERT INTO group_purchases (product_id, creator_id, target_participants, current_participants, deadline, status, city, lat, lng)
SELECT p.id, b.user_id, 6, 5, DATE_ADD(NOW(), INTERVAL 12 HOUR), 'open', p.city, p.lat, p.lng
FROM products p JOIN businesses b ON b.id=p.business_id
WHERE p.name='Smart LED Strip 5m'
  AND NOT EXISTS (SELECT 1 FROM group_purchases gp WHERE gp.product_id=p.id AND gp.status='open');

-- ─────────────────────────────────────────────────────────
-- Backfill: add the businesses' creators as group members (so groups aren't empty)
-- ─────────────────────────────────────────────────────────
-- For each open group, add a few member rows so participant counts match
-- (uses real test customer IDs from seed.sql)
INSERT IGNORE INTO group_members (group_id, user_id, status, deposit_status)
SELECT gp.id, u.id, 'joined', 'held'
FROM group_purchases gp
JOIN products p ON p.id = gp.product_id
JOIN users u ON u.email IN ('customer@test.com','maya@test.com','oren@test.com','tamar@test.com')
WHERE p.name IN ('Specialty Coffee Beans 1kg','Smart LED Strip 5m')
  AND NOT EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id=gp.id AND gm.user_id=u.id);

SET FOREIGN_KEY_CHECKS = 1;

-- Verify the result
SELECT b.business_name, b.address, b.lat, b.lng, COUNT(gp.id) AS deals
FROM businesses b
LEFT JOIN products p ON p.business_id = b.id
LEFT JOIN group_purchases gp ON gp.product_id = p.id AND gp.status='open'
GROUP BY b.id
ORDER BY b.business_name;
