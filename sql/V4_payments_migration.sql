-- SmartCart V4 Migration — Hosted Payment Provider
-- Run ONCE after deploying V4 code.
--
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- Note: MySQL 5.7 does not support IF NOT EXISTS on ADD COLUMN — run on a
--       fresh deployment only, or wrap each ALTER in a stored procedure guard.

SET NAMES utf8mb4;

-- ── 1. Extend group_purchases.status ──────────────────────────────────────────
--      ready_for_payment : group is full, payment links have been generated
--      order_ready       : all members paid, ready for fulfilment
ALTER TABLE `group_purchases`
  MODIFY COLUMN `status`
    ENUM('open','closed','failed','ready_for_payment','order_ready')
    NOT NULL DEFAULT 'open';

-- ── 2. Extend the payments table ──────────────────────────────────────────────

-- 2a. product_id — denormalised shortcut so we don't need to join group_purchases
--     every time we render a payment; nullable for backward-compat with old rows.
ALTER TABLE `payments`
  ADD COLUMN `product_id` INT UNSIGNED DEFAULT NULL AFTER `group_id`;

-- 2b. currency
ALTER TABLE `payments`
  ADD COLUMN `currency` VARCHAR(3) NOT NULL DEFAULT 'ILS' AFTER `amount_ils`;

-- 2c. Hosted-payment-page columns
ALTER TABLE `payments`
  ADD COLUMN `provider`                VARCHAR(50)   DEFAULT NULL,
  ADD COLUMN `provider_transaction_id` VARCHAR(255)  DEFAULT NULL,
  ADD COLUMN `payment_url`             VARCHAR(1000) DEFAULT NULL,
  ADD COLUMN `paid_at`                 DATETIME      DEFAULT NULL;

-- 2d. Migrate legacy 'completed' → 'paid' before extending the ENUM
--     (safe to run even if no rows have status='completed')
UPDATE `payments` SET `status` = 'paid' WHERE `status` = 'completed';

-- 2e. Extend status ENUM  ('completed' kept so any un-migrated rows still valid)
ALTER TABLE `payments`
  MODIFY COLUMN `status`
    ENUM('pending','completed','paid','failed','expired')
    NOT NULL DEFAULT 'pending';

-- 2f. Indexes for provider lookups (webhook matching) and product joins
ALTER TABLE `payments`
  ADD KEY `idx_pay_provider_tx` (`provider_transaction_id`),
  ADD KEY `idx_pay_product`     (`product_id`);

-- 2g. FK so provider rows still reference valid products (nullable, SET NULL on delete)
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_product`
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL;
