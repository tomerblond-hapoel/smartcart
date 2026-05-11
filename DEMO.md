# SmartCart — Demo Script (Live Presentation)

**Audience:** Academic reviewers. **Length:** ~10 minutes. **Goal:** showcase every major capability of both customer and business sides.

---

## Pre-demo setup (do this 5 min before)

1. **MAMP running**: Apache port 8888, MySQL port 8889.
2. **Apply migrations** (one-time):
   ```bash
   mysql -u root -P 8889 -p smartcart < sql/schema.sql       # if fresh
   mysql -u root -P 8889 -p smartcart < sql/seed.sql         # baseline data
   mysql -u root -P 8889 -p smartcart < sql/V3_migration.sql # V3 columns + tables
   mysql -u root -P 8889 -p smartcart < sql/demo_seed.sql    # Tel Aviv demo businesses
   ```
3. **Browsers**: open 3 tabs in incognito mode (so sessions don't leak):
   - Tab A — customer story
   - Tab B — business story
   - Tab C — admin story
4. **Optional**: in browser DevTools → Sensors → Set custom location to **Tel Aviv (32.08, 34.77)** so the "Use my location" demo works without your real IP.
5. **Test accounts** (all password `test1234`):
   - `customer@test.com` (David Cohen) — Tel Aviv customer
   - `business@test.com` (Sarah Levi) — owns TechStore Tel Aviv
   - `cafe@test.com` (Yossi Mor) — owns Cafe Dizengoff (demo business)
   - `admin@test.com` — admin

---

## Act 1 — The customer journey (3 min)

**Tab A** at `http://localhost:8888/smartcart/`

### Scene 1.1 — Landing & registration
- Homepage shows two CTA cards (purple = customer, orange-icon = business).
- Click **"I'm a Customer"** → register page loads in purple with role locked.
- Walk through the role-aware registration — no Step 1.
- Optionally just login as `customer@test.com` to save time.

### Scene 1.2 — Discovery with map
- Landing page (logged in) shows:
  - **Last-minute deals** with live countdown timer (the Smart LED Strip in demo seed expires in 12h)
  - **Featured groups** with progress bars
  - **Map** at bottom showing all open groups on Tel Aviv map
- Click **"📍 Find me on map"** → browser asks for location permission → blue marker appears on map.
- Zoom in on Tel Aviv — see deals clustered along Dizengoff, Ben Yehuda, Rothschild (street-level precision).

### Scene 1.3 — Browse with fuzzy location
- Go to **Browse** in top nav.
- Type **"Dizengoff"** in the city filter → results include Cafe Dizengoff + GadgetLab (both have Dizengoff in address even though city is "Tel Aviv"). **Highlight the fuzzy match.**
- Click **"📍 Use my location"** below the city filter → city auto-fills to "Tel Aviv".

### Scene 1.4 — Smart Agent (the differentiator)
- Click **Smart Agent** in top nav.
- Click **"📍 Use my location"** → blue banner appears: "Using your live location".
- Results re-sort by real-time distance from your phone.
- Click **🗺️ Map** view toggle → see all recommended deals on a map with you in the center.
- Click any marker → popup with rank, score, distance, price, "View Group →".
- Switch back to **📋 List** view → expand "How does the Smart Agent score groups?" — show the 5-factor weighting.

### Scene 1.5 — Joining a deal
- Open the Smart LED Strip deal (urgent, 5/6, 12h left).
- See the precise location on the map (Ben Yehuda 80, Tel Aviv).
- **In dev mode** (no PayPal credentials): click **Join (dev mode)** → server creates an authorized payment + group member, no real PayPal popup.
- **With real PayPal sandbox credentials**: click the PayPal button → sandbox login → approve → authorization captured.
- Notification appears in 🔔 bell icon: "You joined a group — Payment authorized".
- Banner now reads: "✓ You're in! Payment authorized — charged when group succeeds."

---

## Act 2 — The business journey (3 min)

**Tab B** at `http://localhost:8888/smartcart/pages/login.php?as=business`

### Scene 2.1 — Business login
- Login page is **orange** (business theme).
- Login as `cafe@test.com` / `test1234`.
- Redirected straight to the **business dashboard** — orange gradient header, 5-stat bar.

### Scene 2.2 — Dashboard tour
- Live Deals tab shows the **Specialty Coffee Beans** deal (4/5, almost full).
- Each deal card has progress bar, live countdown, badges, and action buttons.
- Click **"+ New Deal"** → modal opens.
  - Fill in name, description, prices.
  - **Upload an image** → drag a file → preview thumbnail appears.
  - Enter deadline using `datetime-local` picker.
  - Notice the real-time discount % preview as you type prices.
  - Cancel for now.

### Scene 2.3 — Edit business profile
- Click **⚙️ Settings** in the header → orange profile page.
- Show the **Business Info card** with precise address: `Dizengoff 240, Tel Aviv`.
- **Below the address field**: a mini map showing the business pin at exact coordinates.
- Type a new address: `Allenby 100, Tel Aviv` → autocomplete shows matching street addresses → click one → lat/lng populate, map re-centers.
- Cancel changes.

### Scene 2.4 — Orders tab (if you have time)
- Switch to **📦 Orders** tab.
- For seed data with closed groups, you'll see customer orders with shipping status dropdowns.
- Change a status from "pending" → "shipped" → enter a tracking ID.
- **Switch to Tab A (customer)**: within 30 seconds, the customer's `/pages/my-orders.php` shows a toast "Order status updated" and reloads with the new tracking info + notification.

### Scene 2.5 — Demo bottom nav (mobile)
- Resize browser to 375px width (DevTools mobile view).
- Bottom nav shows **Dashboard / New Deal / Orders / Profile** — business-specific (not customer's "Home / Search / Groups / Profile").
- Tap **New Deal** in bottom nav → modal opens directly via `?new=1` URL param.

---

## Act 3 — Admin & lifecycle (2 min)

**Tab C** at `http://localhost:8888/smartcart/pages/login.php`

### Scene 3.1 — Admin dashboard
- Login as `admin@test.com`.
- Top-level stats: users / businesses / products / groups / revenue.
- **Groups table**: scroll to a `closed` group → click **Refund** → enter reason → confirm.
- Toast: "Refunded N payment(s)".
- Switch to Tab A: customer sees notification "Refund issued — ₪X".

### Scene 3.2 — Cron & lifecycle
- In terminal:
  ```bash
  /Applications/MAMP/bin/php/php7.4.33/bin/php cron/check_groups.php
  ```
- Output: `[timestamp] expired N group(s), voided M authorization(s)`.
- Explain: this script runs every minute on the live server. Groups past their deadline that didn't fill get marked `failed` and all PayPal authorizations are voided automatically.

### Scene 3.3 — Password reset (optional)
- Logout, click **Forgot password?** on login.
- Enter `customer@test.com` → "If account exists, link sent".
- Switch to admin or peek into the DB:
  ```sql
  SELECT title, link_url FROM notifications WHERE type='password_reset' ORDER BY id DESC LIMIT 1;
  ```
- Copy the reset URL into a new tab → set new password → login with new password.

---

## Act 4 — Wrap-up talking points (1 min)

Open `/docs/SmartCart_V3_Design.md` (or just talk through):

- **Two systems, one app**: customer (purple) vs business (orange) — fully separated UX in the same codebase.
- **PayPal authorize → capture → void → refund** — full lifecycle, no money charged until success.
- **Smart Agent** — location-aware, real-time scoring; map view with current location.
- **Notifications** — every state change creates an inbox entry + best-effort email.
- **Cron** — automated group expiration with PayPal cleanup.
- **Security** — CSRF helpers, rate limiting on login (10/15min/IP), hardened session cookies.
- **Deploy-ready** — DEPLOYMENT.md ships with HTTPS-forcing `.htaccess`, production config template, manual QA checklist (TESTING.md).

---

## Reset between demos

```bash
mysql -u root -P 8889 -p smartcart < sql/schema.sql       # drops & recreates
mysql -u root -P 8889 -p smartcart < sql/seed.sql
mysql -u root -P 8889 -p smartcart < sql/V3_migration.sql
mysql -u root -P 8889 -p smartcart < sql/demo_seed.sql
```

Clear browser cookies for `localhost` to reset sessions.

---

## If something breaks mid-demo

| Symptom | Quick fix |
|---|---|
| PayPal popup hangs | Dev-mode bypass kicks in if config has placeholder credentials. Refresh and click "Join (dev mode)". |
| Map doesn't load tiles | Network issue — Leaflet uses OpenStreetMap. Refresh the page. |
| Geolocation denied | Manually enter Tel Aviv in the city filter; map still works. |
| Cron output errors | Check MySQL is on port 8889 and you ran V3_migration.sql. |
| "Database connection failed" | MAMP not running — start servers in MAMP app. |
