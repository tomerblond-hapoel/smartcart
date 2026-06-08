-- SmartCart V5 Migration — Payment Deadline
-- Run ONCE after deploying V5 code.
--
-- Adds payment_deadline to group_purchases so cron can expire groups
-- whose members didn't pay within 24 hours of the group filling up.

SET NAMES utf8mb4;

ALTER TABLE `group_purchases`
  ADD COLUMN `payment_deadline` DATETIME NULL DEFAULT NULL
  AFTER `deadline`;

-- Index for the cron query that looks for expired payment windows
ALTER TABLE `group_purchases`
  ADD KEY `idx_gp_payment_deadline` (`payment_deadline`);
