# SmartCart Cron Jobs

## check_groups.php

Expires open groups whose deadline passed without filling, voiding all authorized payments.

**Run every minute** on the production server.

### Local test (MAMP)

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php /Applications/MAMP/htdocs/smartcart/cron/check_groups.php
```

Expected output:

```
[2026-05-11 14:32:00] expired 0 group(s), voided 0 authorization(s)
```

### CPanel production setup

In CPanel → **Cron Jobs**, add:

| Field | Value |
|-------|-------|
| Minute | `*` |
| Hour | `*` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |
| Command | `/usr/bin/php /home/USER/public_html/smart/cron/check_groups.php >> /home/USER/smartcart-cron.log 2>&1` |

Replace `USER` with your CPanel username. Log file path can be adjusted; redirecting both stdout and stderr lets you debug failures.

### Optional HTTP fallback

If CLI cron isn't available, the script accepts an HTTP call with a token:

```
GET /cron/check_groups.php?cron_token=YOUR_SECRET
```

Set the expected token in `config/config.php` (`CRON_TOKEN`) and add a check at the top of the script. Default behavior is CLI-only.

### What it does

1. Selects all `group_purchases` where `status='open'` and `deadline < NOW()` and `current_participants < target_participants`.
2. For each, sets `status='failed'`.
3. For each, voids all `payments` with `auth_status='authorized'` by calling PayPal's void endpoint (skipped if `PAYPAL_CLIENT_ID` is the placeholder).
4. Logs a summary to stdout.

Run is idempotent: re-running after the same minute does nothing because all qualifying groups have already moved off `'open'`.
