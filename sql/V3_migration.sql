-- SmartCart V3 Migration
-- Run once after deploying V3 code:
--   mysql -u root -P 8889 -p smartcart < sql/V3_migration.sql

-- 1. PayPal authorize/void state on payments
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

-- 2. Deposit status on group_members (5% hold lifecycle)
ALTER TABLE group_members
    ADD COLUMN deposit_status ENUM('none','held','captured','voided','forfeited','refunded') DEFAULT 'none' AFTER status;

-- 3. In-app notifications inbox
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

-- 4. Password reset tokens
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

-- 5. Rate-limit table
CREATE TABLE IF NOT EXISTS rate_limits (
    `key`      VARCHAR(120) NOT NULL PRIMARY KEY,
    `count`    INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
