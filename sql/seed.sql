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
(1, 'Sony WH-1000XM5 אוזניות אלחוטיות', 'אוזניות בלוטות׳ מסדרת XM5 עם ביטול רעש מוביל בתעשייה. 30 שעות סוללה, טעינה מהירה.', 1499.00, 899.00, 'electronics', 3, '/assets/images/Sony WH-1000XM5 Wireless Headphones.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
(1, 'Samsung Galaxy Watch 6', 'שעון חכם עם מסך Super AMOLED, מעקב בריאות מתקדם, GPS מובנה.', 1200.00, 750.00, 'electronics', 4, '/assets/images/Smart Home Starter Kit.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
(1, 'JBL Charge 5 רמקול בלוטות׳', 'רמקול נייד עמיד במים עם סאונד עוצמתי. 20 שעות סוללה, Bluetooth 5.1.', 899.00, 540.00, 'electronics', 5, '/assets/images/JBL.jpg', 'Tel Aviv', 32.0818, 34.7738, 'active'),
-- HomeStyle Herzliya
(2, 'Dyson V12 שואב אבק אלחוטי', 'שואב אבק אלחוטי עם טכנולוגיית זיהוי אוטומטי, 60 דקות פעולה.', 2400.00, 1290.00, 'home', 4, '/assets/images/Dyson V12 Detect Slim Vacuum.jpg', 'Herzliya', 32.1663, 34.8439, 'active'),
(2, 'Instant Pot Duo 7-in-1', 'סיר לחץ חשמלי רב-תכליתי. 7 פונקציות: לחץ, איטי, אורז, אדים, מוקפץ, יוגורט, חם.', 599.00, 370.00, 'home', 3, '/assets/images/Instant Pot Duo 7-in-1.jpg', 'Herzliya', 32.1663, 34.8439, 'active'),
-- SportZone Haifa
(3, 'Nike Air Max 270 נעלי ספורט', 'נעלי ריצה בעיצוב אייר מקס. נוח לשימוש יומיומי ואימונים.', 599.00, 390.00, 'sports', 5, '/assets/images/Nike Air Max 270 Running Shoes.jpg', 'Haifa', 32.8200, 34.9990, 'active'),
(3, 'סט מזרן יוגה פרמיום', 'סט מזרן יוגה מקצועי עם קוביות ורצועה. עובי 6mm, נגד החלקה.', 299.00, 210.00, 'sports', 4, '/assets/images/Premium Yoga Mat Set.jpg', 'Haifa', 32.8200, 34.9990, 'active'),
-- FreshBox Jerusalem
(4, 'Organic Coffee Sampler Box', 'מארז קפה אורגני 6 זנים שונים מרחבי העולם. 250 גרם כל אחד, קלייה טרייה.', 199.00, 149.00, 'food', 8, '/assets/images/Organic Coffee Sampler Box.jpg', 'Jerusalem', 31.7844, 35.2132, 'active'),
-- BookCorner Ramat Gan
(5, 'Complete Python Programming Course', 'קורס פייתון מלא לשולחן העבודה. מתאים למתחילים ומתקדמים. 600+ עמודים.', 199.00, 119.00, 'books', 6, '/assets/images/Complete Python Programming Course (Book).jpg', 'Ramat Gan', 32.0684, 34.8248, 'active'),
-- GlowUp Netanya
(6, 'Natural Skincare Discovery Set', 'ערכת טיפוח טבעי מלאה: קרם פנים, סרום, תחליב גוף וסבון. ללא פרבנים.', 299.00, 200.00, 'beauty', 5, '/assets/images/Natural Skincare Discovery Set.jpg', 'Netanya', 32.3215, 34.8533, 'active'),
-- FashionHub Beer Sheva
(7, 'Handmade Leather Messenger Bag', 'תיק עור בעבודת יד. עיצוב קלאסי, מתאים ללפטופ עד 15 אינץ׳. 3 צבעים.', 599.00, 430.00, 'fashion', 6, '/assets/images/Handmade Leather Messenger Bag.jpg', 'Beer Sheva', 31.2460, 34.7960, 'active');

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
