# SmartCart Deployment Guide (CPanel)

Target: `noati2.mtacloud.co.il/smart`. Steps for first-time deploy and updates.

---

## 1. Upload code

Via Git push (preferred) or File Manager:

```bash
# From your laptop, push to your CPanel-linked remote:
git push production main
```

Or via FTP/File Manager: upload the entire `smartcart/` folder to `~/public_html/smart/`.

## 2. Configure

```bash
cd ~/public_html/smart/config
cp config.production.php.example config.php
nano config.php   # fill in real DB credentials, PayPal LIVE keys, APP_URL
chmod 640 config.php   # readable by web server, not world
```

## 3. Database

Create database in CPanel → "MySQL Databases", then import:

```bash
mysql -u noati2_smartcart -p noati2_smartcart < ~/public_html/smart/sql/schema.sql
mysql -u noati2_smartcart -p noati2_smartcart < ~/public_html/smart/sql/seed.sql       # optional test data
mysql -u noati2_smartcart -p noati2_smartcart < ~/public_html/smart/sql/V3_migration.sql
```

If updating an existing V1/V2 deployment, only run `V3_migration.sql`.

## 4. Permissions

```bash
chmod 755 ~/public_html/smart
chmod 755 ~/public_html/smart/uploads ~/public_html/smart/uploads/products ~/public_html/smart/uploads/logos
chmod 755 ~/public_html/smart/logs    # created on demand by paypal.php / notifications.php
chmod 640 ~/public_html/smart/config/config.php
```

## 5. Cron job

CPanel → "Cron Jobs". Add:

| Field | Value |
|-------|-------|
| Common settings | "Once per minute" (or set manually: `*` `*` `*` `*` `*`) |
| Command | `/usr/bin/php /home/USERNAME/public_html/smart/cron/check_groups.php >> /home/USERNAME/smartcart-cron.log 2>&1` |

Replace `USERNAME` with your CPanel username. Verify after 2 minutes by tailing the log.

## 6. PayPal Live setup

1. Log in to developer.paypal.com → switch to "Live" → create REST API credentials.
2. Paste Client ID / Secret into `config.php`.
3. In PayPal sandbox, run a quick test: register → join group → see authorization in PayPal dashboard.

## 7. Smoke test (5 minutes)

- `https://noati2.mtacloud.co.il/smart/` loads — purple theme, sees marketing CTAs.
- Register a customer → lands on home in purple.
- Logout, register a business → lands on dashboard in orange.
- Login `business@test.com` → orange dashboard, can create deal.
- Login `customer@test.com` → can join, PayPal popup, authorization captured.
- Group reaches target → captures fire → orders created → notifications appear.
- Admin login → can refund a closed group.

## 8. Updates

For V3+ updates after initial deploy:

```bash
cd ~/public_html/smart
git pull origin main
mysql -u noati2_smartcart -p noati2_smartcart < sql/VX_migration.sql   # if there's a new migration
# Verify cron is still running
```

## Rollback

```bash
git log --oneline -10
git checkout PREVIOUS_COMMIT_SHA
# Restore DB from CPanel backup if schema changed
```

## Logs

- PayPal: `logs/paypal.log` (created on first call)
- Notifications: `logs/notify.log` (created on first failure only)
- Cron: wherever you redirected (`smartcart-cron.log`)
- PHP errors: CPanel "Error Logs"

## Known limitations

- PayPal flow uses the AUTHORIZE-then-CAPTURE pattern (full amount, not 5%). See `docs/SmartCart_V3_Design.md` § Payment Flow for the rationale.
- Email dispatch uses PHP `mail()` — fine for low volume on shared hosting, no SMTP setup. For higher reliability, swap in PHPMailer + SMTP.
- File uploads are filesystem-based (`uploads/`). No CDN/optimization in V3.
- Chat and order status are polling-based (15s and 30s respectively). No WebSocket.
