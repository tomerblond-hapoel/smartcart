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
