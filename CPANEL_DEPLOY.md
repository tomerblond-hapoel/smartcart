# SmartCart — CPanel Deployment Walkthrough

> מדריך מלא להעלאת SmartCart ל-CPanel ב-`noati2.mtacloud.co.il`. צעד אחר צעד, עם כל הקליקים ב-CPanel UI.

**יעד:** `https://noati2.mtacloud.co.il/smart` (או תת-תיקייה אחרת).
**זמן ביצוע משוער:** 30–45 דקות לפעם הראשונה, 5 דקות לעדכון.

---

## 🚀 Quick start — אם כבר העלית קבצים ויש 500

קיבלת 500 כי `config/config.php` לא הועלה (gitignored) ו/או DB לא הוקם. תיקון מהיר:

1. **CPanel → MySQL Databases:**
   - צור DB בשם `smartcart` → השם המלא: `noati2_smartcart`
   - צור user בשם `smartcart` עם Password Generator → שמור את הסיסמה
   - הוסף את ה-user ל-DB עם **ALL PRIVILEGES**

2. **CPanel → File Manager → `public_html/smart/config/`:**
   - Copy של `config.production.php.example` → שנה את השם להעתק ל-`config.php`
   - Edit את `config.php`, החלף את `CHANGE_ME` בסיסמה שיצרת
   - שמור (Save)

3. **CPanel → phpMyAdmin → בחר `noati2_smartcart` → Import:**
   - העלה את `sql/cpanel_install.sql` (קובץ אחד — מכיל הכל) → Go
   - **אם זה נכשל** עם שגיאת DELIMITER: חזור ולעשה Import של 4 הקבצים בנפרד לפי סעיף D.

4. **רענן** את `https://noati2.mtacloud.co.il/smart/` — צריך לעלות בסגול.

עדיין 500? CPanel → Metrics → **Error Log** → תשלח אליי את 10 השורות האחרונות.

---

## A. מה צריך לפני שמתחילים?

| מה | איפה משיגים |
|---|---|
| חשבון CPanel פעיל | סופק לך — `noati2.mtacloud.co.il` |
| סיסמת CPanel | סופקה לך (בנפרד מסיסמת ה-FTP) |
| גישת SSH (אופציונלי, לcron CLI) | בקש מהאחסון אם לא פעילה |
| חשבון PayPal Developer | https://developer.paypal.com (חינם) — צריך Sandbox Client ID + Secret |
| תעודת HTTPS | בדרך כלל אוטומטית ב-CPanel (Let's Encrypt) |

---

## B. שלב 1 — יצירת מסד נתונים ב-CPanel (5 דק׳)

1. כניסה ל-CPanel: `https://noati2.mtacloud.co.il:2083` (או הקישור שקיבלת).
2. תחת **Databases**, לחץ **MySQL Databases**.
3. **Create New Database**:
   - שם: `smartcart` (הסיומת `noati2_` תתווסף אוטומטית → השם המלא: `noati2_smartcart`).
   - לחץ **Create Database**.
4. **Create New User**:
   - שם: `smartcart` (יהפוך ל-`noati2_smartcart`).
   - סיסמה: השתמש ב-**Password Generator** ושמור אותה בצד — תזדקק לה ב-config.
   - לחץ **Create User**.
5. **Add User to Database**:
   - בחר את המשתמש שיצרת ואת ה-database.
   - הענק **ALL PRIVILEGES**.
   - לחץ **Make Changes**.

**רשום לעצמך:**
- DB_NAME = `noati2_smartcart`
- DB_USER = `noati2_smartcart`
- DB_PASS = הסיסמה שגנרטת
- DB_HOST = `localhost`
- DB_PORT = `3306` (ברירת מחדל ב-CPanel — לא 8889 כמו ב-MAMP!)

---

## C. שלב 2 — העלאת קוד (5–10 דק׳)

### אפשרות 1 — Git (מומלץ)

אם הקוד שלך ב-GitHub/GitLab:

1. ב-CPanel → **Git™ Version Control** → **Create**.
2. **Clone URL**: כתובת ה-repo (HTTPS עם token או SSH).
3. **Repository Path**: `/home/noati2/public_html/smart`.
4. **Repository Name**: `smartcart`.
5. לחץ **Create**.

לעדכונים עתידיים: SSH או File Manager → `cd public_html/smart && git pull`.

### אפשרות 2 — File Manager / FTP

1. ב-CPanel → **File Manager** → נווט ל-`public_html/`.
2. צור תיקייה חדשה: `smart`.
3. הכנס לתיקייה.
4. לחץ **Upload** → גרור את כל תוכן `smartcart/` (ללא תיקיית האב).

**חשוב:** ודא ש-`.htaccess` הועלה (קבצים שמתחילים בנקודה לפעמים מוסתרים — סמן "Show hidden files" ב-File Manager Preferences).

### אל תעלה את הקבצים האלה:
- `config/config.php` (יש לי placeholder שלך → תיצור חדש בייצור)
- `logs/` (יווצר אוטומטית בריצה ראשונה)
- `uploads/products/*` ו-`uploads/logos/*` (חוץ מ-`.gitkeep` ו-`.htaccess`)
- `.git/` (אם העלית ידנית)

---

## D. שלב 3 — יבוא סכמת DB (5–10 דק׳)

> ✨ **הדרך המהירה:** הרץ Import אחד עם `sql/cpanel_install.sql` שכבר מכיל schema + seed + V3 + demo בסדר הנכון. אם זה עובד, דלג לסעיף E.
>
> אם זה נכשל (לעיתים phpMyAdmin לא תומך ב-`DELIMITER //` להליכים מאוחסנים), השתמש בארבעת הקבצים הנפרדים למטה.

> ⚠️ **חשוב:** אם אתה מייבא את הקבצים בנפרד, יש לייבא בסדר הזה ולוודא שכל קובץ הסתיים בהצלחה. אם V3_migration.sql נכשל באמצע, חלק מהעמודות/טבלאות יחסרו ו-join לקבוצה יחזיר "Failed to join". ראה סעיף ה-Verification בסוף.

### אפשרות 1 — phpMyAdmin (קל)

1. ב-CPanel → **phpMyAdmin** → בחר את DB `noati2_smartcart` בצד שמאל.
2. לחץ **Import** בראש העמוד.
3. **Choose File**: העלה את `sql/schema.sql` → לחץ **Go**. ודא הודעת הצלחה.
4. חזור על אותו דבר עם, **בסדר הזה**:
   - `sql/seed.sql` (test users + sample data)
   - `sql/V3_migration.sql` (V3 columns + tables — אם נכשל, ראה Verification)
   - `sql/demo_seed.sql` (optional — Tel Aviv demo businesses; ניתן להריץ שוב בבטחה)

### אפשרות 2 — SSH (מהיר יותר)

```bash
ssh noati2@noati2.mtacloud.co.il
cd public_html/smart
mysql -u noati2_smartcart -p noati2_smartcart < sql/schema.sql
mysql -u noati2_smartcart -p noati2_smartcart < sql/seed.sql
mysql -u noati2_smartcart -p noati2_smartcart < sql/V3_migration.sql
mysql -u noati2_smartcart -p noati2_smartcart < sql/demo_seed.sql
```

### Verification — לוודא שכל V3 הוחל

הרץ ב-phpMyAdmin (SQL tab) או ב-SSH — שלוש השאילתות הבאות חייבות להחזיר תוצאות:

```sql
-- 1. payments צריך 9 עמודות חדשות
SHOW COLUMNS FROM payments LIKE 'auth_status';
-- צפוי: שורה אחת עם enum('none','authorized','captured','voided','refunded','failed')

-- 2. group_members צריך עמודת deposit_status
SHOW COLUMNS FROM group_members LIKE 'deposit_status';
-- צפוי: שורה אחת

-- 3. 3 טבלאות חדשות
SHOW TABLES LIKE 'notifications';      -- צריך להחזיר notifications
SHOW TABLES LIKE 'password_reset_tokens';
SHOW TABLES LIKE 'rate_limits';
```

אם אחת מהן ריקה → V3 לא הוחל מלא. הרץ שוב את `sql/V3_migration.sql` (הוא בנוי עם `IF NOT EXISTS` לטבלאות, אבל ה-ALTERים על payments/group_members יכשלו אם העמודות כבר קיימות — זה בסדר, פשוט תוסיף ידנית רק את העמודות החסרות בעזרת `ALTER TABLE ... ADD COLUMN`).

---

## E. שלב 4 — יצירת config.php ייצור (5 דק׳)

1. ב-File Manager → `public_html/smart/config/`.
2. **Copy** את `config.production.php.example` → שמור כ-`config.php` באותה תיקייה.
3. לחץ **Edit** על `config.php` ומלא:

```php
<?php
// SmartCart — Production config (DO NOT COMMIT)

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'noati2_smartcart');
define('DB_USER', 'noati2_smartcart');
define('DB_PASS', 'YOUR_GENERATED_PASSWORD');

define('APP_ENV',  'production');
define('APP_URL',  'https://noati2.mtacloud.co.il/smart');
define('APP_NAME', 'SmartCart');

// PayPal — אופציונלי לדמו אקדמי!
// אם תשאיר את הערכים הראשוניים שמתחילים ב-'your_paypal_…', המערכת תעבור אוטומטית ל-Dev Mode:
//   • הצטרפות לקבוצה תיצור רשומת תשלום סינתטית (auth_status='authorized', auth_id='DEV-AUTH-…')
//   • לא יוצג popup של PayPal, פשוט כפתור "Join Group" שעובד מיידית
//   • Capture / Void / Refund כולם פועלים על הרשומות הסינתטיות — מתאים מצוין להדגמה
// אם תרצה PayPal אמיתי: צור חשבון Developer (חינם) ב-https://developer.paypal.com
//   Sandbox: https://api-m.sandbox.paypal.com   |   Live: https://api-m.paypal.com
define('PAYPAL_CLIENT_ID',  'your_paypal_sandbox_client_id');   // ← השאר כך לדמו
define('PAYPAL_SECRET',     'your_paypal_sandbox_secret');      // ← השאר כך לדמו
define('PAYPAL_BASE_URL',   'https://api-m.sandbox.paypal.com');
define('PAYPAL_CURRENCY',   'ILS');

define('GMAPS_API_KEY', '');

define('ILS_TO_USD_RATE', 3.7);
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);

define('MAIL_FROM',      'noreply@noati2.mtacloud.co.il');
define('MAIL_FROM_NAME', 'SmartCart');
```

4. שמור (Save).
5. **חשוב:** הגדר הרשאות 640 על `config.php` (קליק ימני → Change Permissions → 640).

---

## F. שלב 5 — הרשאות תיקיות (3 דק׳)

ב-File Manager או SSH, ודא ההרשאות הבאות:

| נתיב | הרשאה | למה |
|---|---|---|
| `public_html/smart/` | 755 | תיקיית בסיס |
| `public_html/smart/config/` | 755 | קונפיגורציה |
| `public_html/smart/config/config.php` | **640** | רק ה-web server יקרא |
| `public_html/smart/uploads/` | **755** | ניתן לכתיבה ע"י web server |
| `public_html/smart/uploads/products/` | 755 | תמונות מוצרים |
| `public_html/smart/uploads/logos/` | 755 | לוגואים |
| `public_html/smart/logs/` | 755 | יווצר אוטומטית |

ב-SSH:
```bash
cd public_html/smart
chmod 755 . config uploads uploads/products uploads/logos
chmod 640 config/config.php
mkdir -p logs && chmod 755 logs
```

---

## G. שלב 6 — בדיקה ראשונית (5 דק׳)

פתח בדפדפן: **https://noati2.mtacloud.co.il/smart/**

צפוי לראות:
- ✅ דף הבית של SmartCart בסגול.
- ✅ HTTPS פעיל (תיתקלו ב-redirect אוטומטי מ-http).
- ✅ אין שגיאות 500.

אם רואה **500 Internal Server Error**:
- File Manager → `public_html/smart/.htaccess` → ודא ש-Apache תומך ב-mod_rewrite (כל hosts מודרניים).
- CPanel → **Error Log** (תחת **Metrics**) → בדוק את ההודעה האחרונה.
- Likely candidates:
  - `config.php` חסר או עם syntax error.
  - שגיאת DB credentials (בדוק את הסיסמה).
  - הרשאת קובץ שגויה.

---

## H. שלב 7 — Cron Job (3 דק׳)

זה מה שגורם לקבוצות שפג תוקפן לסיים את עצמן ו-void את ה-PayPal authorizations.

1. ב-CPanel → תחת **Advanced**, לחץ **Cron Jobs**.
2. תחת **Common Settings**, בחר **"Once per minute"** — או הגדר ידנית:
   - Minute: `*`
   - Hour: `*`
   - Day: `*`
   - Month: `*`
   - Weekday: `*`
3. **Command**:
   ```
   /usr/bin/php /home/noati2/public_html/smart/cron/check_groups.php >> /home/noati2/smartcart-cron.log 2>&1
   ```
   (החלף `noati2` בשם המשתמש שלך אם שונה.)
4. לחץ **Add New Cron Job**.

**אימות:** המתן 2–3 דקות, ואז:
- File Manager → `/home/noati2/smartcart-cron.log` → אמור להראות שורה דומה ל:
  `[2026-05-11 14:31:00] expired 0 group(s), voided 0 authorization(s)`

---

## I. שלב 8 — תעודת SSL / HTTPS (אם לא מוגדר)

CPanel מודרני מתקין Let's Encrypt אוטומטית, אבל לוודא:

1. ב-CPanel → **SSL/TLS Status** (תחת **Security**).
2. אם רואה ✓ ירוק ליד `noati2.mtacloud.co.il` — מצוין.
3. אם לא: לחץ **Run AutoSSL** (או פנה לתמיכה של האחסון).

ה-`.htaccess` כבר מאלץ HTTPS — אחרי שיש תעודה, גישה ל-`http://...` תקפוץ אוטומטית ל-`https://...`.

---

## J. שלב 9 — Smoke Test בייצור (10 דק׳)

הרצה ידנית של 8 הצעדים הבאים מאמתת ש-V3 פעיל לחלוטין:

1. ☐ פתח `https://noati2.mtacloud.co.il/smart/` — דף בית בסגול.
2. ☐ לחץ "I'm a Business" → דף הרשמה כתום, תפקיד נעול.
3. ☐ הירשם עם אימייל חדש → מועבר ל-dashboard כתום.
4. ☐ צור Deal חדש עם תמונה (העלה מקומית) → התמונה מופיעה.
5. ☐ Logout, התחבר כ-`customer@test.com` (אם השתמשת ב-seed.sql).
6. ☐ Smart Agent → "📍 Use my location" → דורש הרשאת location → מציג מפה עם דילים סביבך.
7. ☐ הצטרף לקבוצה:
   - **Dev mode (PayPal לא מוגדר):** לחץ "Join Group" → מצטרף מיידית → notification ב-🔔.
   - **PayPal Sandbox:** לוחץ על כפתור PayPal → login עם `sb-buyer@personal.example.com` → אישור → notification ב-🔔.
   בשני המקרים: בטבלת `payments` תיווצר רשומה עם `auth_status='authorized'`.
8. ☐ Login as `admin@test.com` → בדוק שאתה רואה את כל ה-stats ושכפתורי Refund/Fail פעילים.
9. ☐ בדוק תזמון: deal עם פחות מ-24h → הקאונטר אמור להראות שעות (`12h left`), לא ימים.

אם כל ה-9 עובדים: **הפרויקט deploy ✓**.

---

## K. עדכונים עתידיים (5 דק׳)

לאחר deploy ראשוני, עדכון V3.1, V4 וכו':

### אם הקוד ב-Git:
```bash
ssh noati2@noati2.mtacloud.co.il
cd public_html/smart
git pull origin main
# אם יש מיגרציה חדשה:
mysql -u noati2_smartcart -p noati2_smartcart < sql/V4_migration.sql
```

### אם File Manager:
1. הורד את הקבצים החדשים מ-`smartcart/` המקומי.
2. החלף את הקבצים ב-CPanel (drag & drop).
3. אל תחליף את `config/config.php`.
4. אם יש מיגרציה חדשה — phpMyAdmin → Import.

---

## L. בעיות נפוצות

| בעיה | פתרון |
|---|---|
| `500 Internal Server Error` בכל הדפים | בדוק `config.php` — syntax error או חסר סוגריים. השווה ל-`config.production.php.example`. |
| Apache `RewriteEngine` not allowed | בקש מהאחסון לאפשר `AllowOverride All` ב-Apache vhost. |
| תמונות לא נשמרות | `uploads/` chmod 755. ב-PHP errors בדוק `error_log`. |
| הצטרפות לקבוצה מחזירה `"Failed to join"` | V3 migration הוחל חלקית. הרץ את שאילתות ה-Verification בסעיף D ו-ADD COLUMN ידנית לכל מה שחסר. |
| PayPal "client authentication failed" | אם השתמשת ב-LIVE keys: ודא שאתה משתמש ב-`https://api-m.paypal.com`. ב-Sandbox: `api-m.sandbox.paypal.com`. (אם השארת placeholder — דע מצב Dev פעיל אוטומטית, לא צריך PayPal.) |
| Cron לא רץ | בדוק את ה-log path נכון. נסה להריץ ידנית: `php /home/noati2/public_html/smart/cron/check_groups.php`. |
| `Class 'PDO' not found` | בקש מהאחסון להפעיל PHP PDO extension (חיוני). |
| נכנס ל-`/smart/` ורואה `Index of /smart` | חסר `.htaccess` או חסרה הוראת `DirectoryIndex`. ודא ש-`pages/index.php` קיים והנתיב הראשי בקובץ `.htaccess` נכון. |
| Maps לא נטענים | OpenStreetMap דורש HTTPS בייצור. ודא שהאתר נטען ב-HTTPS. |
| Geolocation לא מתבקש | דורש HTTPS — לא יעבוד ב-`http://`. |
| חיפוש "Tel Aviv" לא מחזיר תוצאות | זה תוקן ב-V3 — ודא ש-`api/products.php` ו-`pages/browse.php` הם הגרסה החדשה (יש בהם `preg_split` על העיר ל-tokens). |
| הקאונטר מציג "1 days left" לדיל של 12 שעות | זה תוקן ב-V3 — ודא ש-`includes/functions.php`'s `countdown_label()` מחזיר `"12h left"` ולא `ceil(diff/86400)`. |
| Map זוום על גלילת עכבר חוטף את הדף | התנהגות חדשה: זוום מופעל **רק כשמרחפים מעל המפה** (250ms). ודא ש-`assets/js/maps.js` עדכני. |
| אימיילים לא יוצאים | `mail()` של PHP זמין רק על שרתים מסוימים. ב-V4 נשדרג ל-PHPMailer + SMTP. |

---

## M. רשימת תיוג סופית לפני המסירה

- [ ] DB מיובא: schema + seed + V3_migration + demo_seed
- [ ] **3 שאילתות ה-Verification בסעיף D עברו** (auth_status, deposit_status, 3 טבלאות חדשות)
- [ ] `config.php` מוגדר עם DB credentials נכונים
- [ ] HTTPS פעיל (ירוק ב-SSL/TLS Status)
- [ ] Cron job מוגדר ורץ (אימות עם log)
- [ ] `chmod 640 config/config.php`
- [ ] `uploads/` ניתן לכתיבה
- [ ] PayPal — או credentials אמיתיים, או placeholder (Dev mode פעיל אוטומטית)
- [ ] Smoke test (9 צעדים מ-J) עבר
- [ ] ה-3 חשבונות הבדיקה (`customer@`, `business@`, `admin@`) עובדים
- [ ] לפחות deal פתוח אחד עם תמונה
- [ ] התראות מופיעות ב-bell icon
- [ ] חיפוש fuzzy עובד: "Tel Aviv" / "Dizengoff" / "Ben Yehuda" כל אחד מחזיר תוצאות
- [ ] קאונטר מציג שעות לדילים <24h (`12h left`, לא `1 day`)
- [ ] Map: גלילת עכבר על המפה מזיימת — מחוץ למפה גולל את הדף
- [ ] DEMO.md נסקר — אתה יודע איך להעביר את הדמו ב-10 דקות

**הצלחה!** 🚀
