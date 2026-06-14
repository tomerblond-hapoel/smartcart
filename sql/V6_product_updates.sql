-- SmartCart — V6 Product Data Update
-- Fixes all 13 product records: English names, English descriptions,
-- correct categories, and new clean image paths under /assets/images/products/.
--
-- Run after schema.sql and seed.sql (or on the live DB via CPanel phpMyAdmin).
-- Safe to re-run — uses UPDATE WHERE id = X.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────
-- Electronics (TechStore Tel Aviv)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Sony WH-1000XM5 Wireless Headphones',
    description = 'Industry-leading noise cancellation with Bluetooth 5.2. 30-hour battery life, 3-minute quick charge gives 3 hours of playback. Premium audio quality for music and calls.',
    category    = 'electronics',
    image_url   = '/assets/images/products/sony-wh-1000xm5-headphones.jpg'
WHERE id = 1;

UPDATE products SET
    name        = 'Samsung Galaxy Watch 6',
    description = 'Smartwatch with Super AMOLED display and advanced health tracking. Built-in GPS, heart rate monitor, sleep tracking, and 40-hour battery life. Compatible with Android and iOS.',
    category    = 'electronics',
    image_url   = '/assets/images/products/samsung-galaxy-watch-6.jpg'
WHERE id = 2;

UPDATE products SET
    name        = 'JBL Charge 5 Bluetooth Speaker',
    description = 'Portable waterproof speaker (IP67-rated) with powerful stereo sound and deep bass. 20-hour battery life, Bluetooth 5.1, doubles as a power bank to charge your devices.',
    category    = 'electronics',
    image_url   = '/assets/images/products/jbl-charge-5-speaker.jpg'
WHERE id = 3;

-- ─────────────────────────────────────────────────────────
-- Home (HomeStyle Herzliya)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Dyson V12 Detect Slim Vacuum',
    description = 'Cordless stick vacuum with laser dust detection technology that reveals hidden dust. Up to 60 minutes of runtime, auto-adjusting suction, whole-machine HEPA filtration.',
    category    = 'home',
    image_url   = '/assets/images/products/dyson-v12-vacuum.jpg'
WHERE id = 4;

UPDATE products SET
    name        = 'Instant Pot Duo 7-in-1 Electric Pressure Cooker',
    description = '7-in-1 multi-cooker: pressure cooker, slow cooker, rice cooker, steamer, sauté pan, yogurt maker, and food warmer. 6-quart capacity, saves up to 70% cooking time.',
    category    = 'home',
    image_url   = '/assets/images/products/instant-pot-duo-7-in-1.jpg'
WHERE id = 5;

-- ─────────────────────────────────────────────────────────
-- Sports (SportZone Haifa)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Nike Air Max 270 Running Shoes',
    description = 'Everyday running shoes featuring the tallest Max Air heel unit for all-day comfort. Breathable mesh upper, responsive foam cushioning, available in multiple colors and sizes.',
    category    = 'sports',
    image_url   = '/assets/images/products/nike-air-max-270-shoes.jpg'
WHERE id = 6;

UPDATE products SET
    name        = 'Premium Yoga Mat Set',
    description = 'Complete yoga set for all levels: 6mm thick non-slip mat with alignment lines, two foam blocks for stability, and an adjustable stretch strap. Lightweight and easy to carry.',
    category    = 'sports',
    image_url   = '/assets/images/products/premium-yoga-mat-set.jpg'
WHERE id = 7;

-- ─────────────────────────────────────────────────────────
-- Food (FreshBox Jerusalem)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Organic Coffee Sampler Box',
    description = '6 single-origin organic coffee varieties from Ethiopia, Colombia, Brazil, Guatemala, Peru, and Kenya. 250g per bag, freshly roasted and vacuum-sealed for peak flavor.',
    category    = 'food',
    image_url   = '/assets/images/products/organic-coffee-sampler-box.jpg'
WHERE id = 8;

-- ─────────────────────────────────────────────────────────
-- Books (BookCorner Ramat Gan)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Complete Python Programming Course (Book)',
    description = 'Comprehensive Python guide for beginners to advanced developers. 600+ pages covering core syntax, data structures, OOP, web scraping, data analysis, and 10 real-world projects.',
    category    = 'books',
    image_url   = '/assets/images/products/python-programming-book.jpg'
WHERE id = 9;

-- ─────────────────────────────────────────────────────────
-- Beauty (GlowUp Netanya)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Natural Skincare Discovery Set',
    description = 'Complete natural skincare routine in one box: hydrating face cream, vitamin C brightening serum, nourishing body lotion, and gentle natural soap. Paraben-free, dermatologist tested.',
    category    = 'beauty',
    image_url   = '/assets/images/products/natural-skincare-set.jpg'
WHERE id = 10;

-- ─────────────────────────────────────────────────────────
-- Fashion (FashionHub Beer Sheva)
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Handmade Leather Messenger Bag',
    description = 'Genuine full-grain leather messenger bag handcrafted by artisans. Padded laptop compartment fits up to 15 inches, multiple organizer pockets, adjustable shoulder strap. Available in 3 colors.',
    category    = 'fashion',
    image_url   = '/assets/images/products/leather-messenger-bag.jpg'
WHERE id = 11;

-- ─────────────────────────────────────────────────────────
-- Products added directly to live DB (may not exist in fresh install)
-- These UPDATE statements are safe to run — they are no-ops if the row doesn't exist.
-- ─────────────────────────────────────────────────────────

UPDATE products SET
    name        = 'Apple iPhone 12',
    description = '6.1-inch Super Retina XDR display powered by the A14 Bionic chip. Dual 12MP camera system with Night Mode and 4K video, 5G connectivity, MagSafe compatible. Available in 64GB, 128GB, 256GB.',
    category    = 'electronics',
    image_url   = '/assets/images/products/apple-iphone-12.jpg'
WHERE id = 18;

UPDATE products SET
    name        = 'Apple iPad 11 A16',
    description = '11-inch Liquid Retina display with ProMotion technology and A16 Bionic chip. All-day battery life (up to 10 hours), USB-C, compatible with Apple Pencil (2nd gen) and Magic Keyboard.',
    category    = 'electronics',
    image_url   = '/assets/images/products/apple-ipad-11-a16.jpg'
WHERE id = 21;
