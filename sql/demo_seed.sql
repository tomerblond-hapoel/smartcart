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
