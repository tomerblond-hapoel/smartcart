# SmartCart V3 — Manual QA Checklist

Run this end-to-end before each release. Use the 3 test accounts:
- `customer@test.com` / `test1234`
- `business@test.com` / `test1234`
- `admin@test.com` / `test1234`

---

## Tier 0 — Smoke (5 min, run on every deploy)

- [ ] `/` loads, marketing landing in **purple** with two CTA cards.
- [ ] Click "I'm a Business" → register page is **orange** with role locked.
- [ ] Click "I'm a Customer" → register page is **purple**.
- [ ] Login as `business@test.com` → redirected to **business dashboard** in orange.
- [ ] Login as `customer@test.com` → redirected to **customer home** in purple.
- [ ] Login as `admin@test.com` → redirected to **admin dashboard**.
- [ ] Logout works; flash shows; redirected to login.

## Tier 1 — Auth & registration

- [ ] Register a new customer → auto-login, lands on `/pages/index.php`, welcome notification appears in bell.
- [ ] Register a new business → auto-login, lands on `/pages/business/dashboard.php`, welcome notification.
- [ ] Try to register with existing email → `Email already registered` error.
- [ ] Password < 8 chars → validation error.
- [ ] Forgot password → submit → `forgot_password_sent` flash → notification row appears in DB (logs).
- [ ] Open the reset link from notification → set new password → login succeeds with new password.
- [ ] Reset token reuse → second attempt fails with "Token is invalid or has expired".
- [ ] 11 login attempts in 15 min from same IP → 11th returns 429 "Too many login attempts".

## Tier 2 — Customer flow (PayPal)

> Requires real PayPal sandbox credentials in `config.php`. With placeholders, the dev-mode bypass triggers.

- [ ] As customer, open a deal in `/pages/group.php?id=N`.
- [ ] Click PayPal button → popup → approve sandbox account.
- [ ] After approval: status becomes "joined", payment row created with `auth_status='authorized'`, `group_members.deposit_status='held'`.
- [ ] Toast: "Joined! Payment authorized (you'll be charged when group succeeds)."
- [ ] Notification appears in bell: "You joined a group".
- [ ] Refresh: card shows "✓ You're in! Payment authorized — charged when group succeeds."

## Tier 3 — Auto-close / capture

- [ ] As another customer, join the same group bringing it to target.
- [ ] All authorized payments transition to `auth_status='captured'`, `status='completed'`.
- [ ] Orders rows created for each member; `group_members.status='paid'`.
- [ ] All members get notification "Group reached target — payment captured".
- [ ] Card on `/pages/group.php` shows "✓ Payment captured — order created".

## Tier 4 — Expiration / void

- [ ] Create a deal with deadline 2 minutes in the future, target 3 participants.
- [ ] Join with 1 customer (don't fill target).
- [ ] Wait 3 minutes; run cron manually: `php cron/check_groups.php`.
- [ ] Output: "expired 1 group(s), voided 1 authorization(s)".
- [ ] `payments.auth_status='voided'`, `group_members.deposit_status='voided'`.
- [ ] Member sees notification "Group cancelled — authorization released".

## Tier 5 — Admin refund

- [ ] Login as admin.
- [ ] On admin dashboard groups table, find a `closed` group.
- [ ] Click "Refund" → enter reason → confirm.
- [ ] Toast: "Refunded N payment(s)".
- [ ] `payments.auth_status='refunded'`, `refund_id` populated.
- [ ] Customer notification: "Refund issued — ₪X".

## Tier 6 — Order tracking

- [ ] As business, change order shipping_status: pending → processing → shipped (add tracking_id) → delivered.
- [ ] As customer, on `/pages/my-orders.php`, leave tab open ≥ 30 seconds after business changes status.
- [ ] Toast appears: "Order status updated — refreshing…", page reloads.
- [ ] Customer gets notification "Order shipped — [product]".

## Tier 7 — Business deal management

- [ ] As business, click "New Deal" → modal opens.
- [ ] Upload an image file (jpg, < 5MB) → preview appears, URL populates.
- [ ] Try uploading a 6MB file → error "File too large".
- [ ] Try uploading a `.txt` file with `.jpg` extension → error "File is not a valid image".
- [ ] Submit deal with valid data → success, group_purchases row + product row created.
- [ ] Edit existing deal → modal pre-populates, save → updates.

## Tier 8 — Navigation & themes

- [ ] As business, top nav does NOT show: Browse, Smart Agent, My Groups, My Orders.
- [ ] As business on mobile (375px), bottom nav: Dashboard / New Deal / Orders / Profile.
- [ ] As customer on mobile, bottom nav: Home / Search / Groups / Profile.
- [ ] Click bell → notifications page; click any unread → marks read + navigates if link.
- [ ] Click "Mark all read" → all marked, badge clears.

## Tier 9 — i18n

- [ ] Switch language to Hebrew (`?lang=he`) → all visible text translates.
- [ ] Business bottom nav in Hebrew: לוח בקרה / דיל חדש / הזמנות / פרופיל.
- [ ] No mixed English/Hebrew strings on customer pages (acceptable: untranslated `Test` data).
- [ ] Direction flips to RTL for Hebrew.

## Tier 10 — Mobile responsive

- [ ] Open DevTools → 375px viewport.
- [ ] No horizontal scrollbars on: index, group.php, my-orders, my-groups, profile, business/dashboard, business/profile, notifications.
- [ ] Stats bars collapse to 2 cols on mobile.

## Tier 11 — Security

- [ ] Session cookie in DevTools: `HttpOnly=true, SameSite=Lax`. (Secure flag only set in production.)
- [ ] Direct access to `https://APP_URL/config/config.php` → 403 (htaccess).
- [ ] Direct access to `https://APP_URL/uploads/products/test.php` (if you can plant one) → not executed.
- [ ] `https://APP_URL/.git/config` → 403.

## Tier 12 — Pages syntax & no errors

- [ ] `for f in $(find . -name "*.php"); do php -l "$f"; done | grep -v "^No syntax"` → no output.
- [ ] No PHP warnings/notices in error log after smoke test.

---

## Pre-submission checklist

- [ ] All Tier 0-6 pass.
- [ ] Tier 7-12 sampled.
- [ ] No `config.php` committed (`.gitignore` verified).
- [ ] DEPLOYMENT.md followed end-to-end on a staging URL.
- [ ] V3_Design.md is up to date and lists all known deviations from spec.
