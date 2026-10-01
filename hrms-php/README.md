# WorkNest — PHP / MySQL edition

WorkNest is a multi-tenant HRMS covering employees, attendance with geofenced check-in, leave, payroll and statutory deductions, recruitment, onboarding, performance, expenses, documents and letters, assets, helpdesk, exit, reports and audit. This edition is plain **PHP 8.2+, MySQL/MariaDB, HTML/CSS and vanilla JS**. Node, npm, Composer and cron are not needed at runtime, so it runs on **Hostinger shared hosting** as is.

It is a feature-for-feature port of the original Next.js app. URLs, roles, permissions and workflows are unchanged. Security fixes and bug fixes are listed in [What changed vs. the Next.js app](#what-changed-vs-the-nextjs-app).

---

## 1. Requirements

| Item | Needed |
|---|---|
| PHP | **8.2 or newer** (tested on 8.2 and 8.5) |
| PHP extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `dom`, `openssl`, `json` (all enabled by default on Hostinger) |
| Database | MySQL 8.0+ or MariaDB 10.6+ (InnoDB, utf8mb4) |
| Web server | Apache or LiteSpeed with `.htaccess` and `mod_rewrite` (Hostinger default) |
| HTTPS | Strongly recommended. Free SSL is included in hPanel. |

All third-party code is bundled in `app/vendor/`: PHPMailer 7.1.1, tFPDF 1.33 with DejaVu fonts, and Chart.js (served locally). Nothing is loaded from a CDN.

## 2. What's in the package

`dist/worknest-hostinger.zip` (build it with `python dev/build_package.py`) contains:

```
public_html/          ← the ONLY web-visible folder (index.php, .htaccess, assets/)
app/                  ← application code (private)
storage/              ← uploads, sessions, logs, cache (private, must be writable)
database/hrms.sql            ← full schema (43 tables), no data
database/hrms_demo_data.sql  ← OPTIONAL demo companies/users
.env.example          ← configuration template
README.md
```

The package never contains `.env`, `dev/` (tests and build tools) or `database/tools/`.

## 3. Deploying on Hostinger

### Step 1: Create the database
1. In hPanel, go to **Databases → MySQL Databases**. Create a database and a user, and give the user **all privileges** on the database.
2. Note the database name, user and password. On Hostinger the name and user look like `u123456789_hrms`.

### Step 2: Import the schema
1. Open **phpMyAdmin**, select the new database and go to **Import**.
2. Upload `database/hrms.sql` and click **Go**. This creates the 43 tables. The file contains no `CREATE DATABASE` statement and no data.
3. *(Optional, demo or trial only)* Import `database/hrms_demo_data.sql` the same way. Every demo account shares one well-known password, so **never import the demo data on a live site.**

### Step 3: Upload the files
Choose one layout.

**A. Recommended layout:** code sits outside the web root. In hPanel → **File Manager**, open the folder that *contains* `public_html` (usually `domains/yourdomain.com/`) and upload the zip there. Extract it so you end up with:
```
domains/yourdomain.com/
├── app/
├── storage/
├── database/
├── .env              ← you create this in step 4
└── public_html/      ← index.php, .htaccess, assets/
```
`public_html/index.php` finds `../app/bootstrap.php` automatically.

**B. Fallback layout:** use this when you can only write inside `public_html`. Extract **everything** into `public_html/`, so `app/`, `storage/`, `database/` and `.env` sit next to `index.php`. The app detects this layout automatically. The `.htaccess` files block web access to every private folder and dotfile, and only `index.php` can execute. This layout is tested, but A is still preferred.

> When `public_html` already contains Hostinger's `default.php`, delete it.

### Step 4: Create `.env`
Copy `.env.example` to `.env` in the same folder as `app/`, then edit it:

```ini
APP_ENV=production          # never "local" on a live site
APP_DEBUG=false
APP_URL=https://hr.yourdomain.com   # no trailing slash; used in reset emails
APP_TIMEZONE=Asia/Kolkata

DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456789_hrms
DB_USER=u123456789_hrms
DB_PASSWORD=your-db-password

MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_ENCRYPTION=ssl
MAIL_USERNAME=no-reply@yourdomain.com
MAIL_PASSWORD=mailbox-password
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
MAIL_FROM_NAME="WorkNest"

SETUP_KEY=paste-a-long-random-string-here   # needed once, for /install
```

Secrets live **only** in `.env`. None are hard-coded in the source.

### Step 5: Permissions
- `storage/` and its subfolders `cache`, `logs`, `sessions` and `uploads` must be writable by PHP. Hostinger's default **755** for folders and **644** for files works.
- `.env` should be **600** or **640**.

### Step 6: Enable SSL
1. In hPanel → **Security → SSL**, install the free certificate.
2. Turn on **Force HTTPS**. Alternatively, uncomment the three "Force HTTPS" lines in `public_html/.htaccess`.

Over HTTPS the session cookie is marked `Secure` and HSTS is sent automatically.

### Step 7: Create the first Super Admin
There is **no default administrator or password**. Pick one method:

- **In the browser:** visit `https://yourdomain/install` and enter the `SETUP_KEY` from `.env`, your email and a password of 12 or more characters. The page then locks itself permanently and returns 404. Afterwards, **delete the `SETUP_KEY` line** from `.env`.
- **Over SSH:** run `php app/cli/create_admin.php you@yourdomain.com`. It prompts for the password. No `SETUP_KEY` is needed.

Next, sign in. Go to **Companies → New**, create the first company and give the temporary password to its Company Admin. Temporary passwords are random, shown exactly once, and never stored in plain text or sent by email.

### Step 8: Email (password reset)
Create a mailbox in hPanel → **Emails** and put its address and password in the `MAIL_*` settings. Then use **Forgot password** on the login page to check delivery. If mail fails, the reason is logged in `storage/logs/`.

### Step 9: PHP settings (if needed)
`.htaccess` sets `upload_max_filesize=12M`, `post_max_size=16M` and `display_errors=Off`. If your plan ignores these values, set them in hPanel → **Advanced → PHP Configuration**. Select PHP **8.2 or newer** there too.

## 4. Upgrading and backups
- **Back up the database:** use hPanel → Databases → **Backups**, or phpMyAdmin → Export (SQL).
- **Back up files:** `storage/uploads/` (all employee documents, logos and letters) and `.env`. The rest can be re-uploaded from the package.
- **Upgrading code:** replace `app/` and `public_html/`. Never overwrite `storage/` or `.env`. Schema changes, if any, ship as separate SQL files. Back up before importing them.

## 5. Security notes
- **Private files:** code, uploads and config are outside the web root, or blocked by `.htaccess` in the fallback layout. Uploaded files can only be downloaded through `/files/{id}` after a permission check. They are stored under random names in per-company folders, with PHP execution disabled.
- **Uploads:** checked by extension, real MIME type and file structure, and scanned for embedded PHP.
- **Request protection:** every form and JSON request is CSRF-protected. Output is HTML-escaped everywhere. The Content-Security-Policy allows scripts from this site only, with no inline JS. Other headers sent: `X-Frame-Options`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`.
- **Sessions:** HttpOnly, SameSite=Lax, and Secure over HTTPS. The ID is regenerated at login. A login lasts up to 7 days. The user is re-checked on every request, so deactivation, role changes and password changes apply immediately.
- **Logins:** rate-limited to 8 attempts per 15 minutes per IP and email. Password-reset requests are also rate-limited. Reset tokens are stored as SHA-256 hashes, and messages never reveal whether an email exists.
- **Multi-tenancy:** every query is scoped to the signed-in user's company. A `companyId` sent by the browser is never trusted.
- **Errors:** production shows a generic error page with a reference code. Details go to `storage/logs/app-YYYY-MM-DD.log`. SQL, paths and stack traces are never shown.
- **Audit log:** every create, approval, status change and settings change is recorded.

## 6. Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page or **500** | Check `storage/logs/app-*.log`. Usually wrong `DB_*` values in `.env`, or `storage/` isn't writable. |
| "Database not ready" on `/install` | The schema isn't imported, or the `DB_*` values are wrong. |
| `/install` says "Setup key required" | Add `SETUP_KEY=` followed by 16+ random characters to `.env`. |
| Every page except `/` returns 404 | `mod_rewrite` or `.htaccess` is missing. Make sure `public_html/.htaccess` was uploaded; hidden files can be hidden in File Manager. |
| "Your session expired" after submitting a form | The browser blocked cookies, or `APP_URL` / HTTPS is inconsistent. Use one canonical domain with HTTPS. |
| Uploads fail | The file is over 10 MB, the type isn't allowed, or `storage/uploads` isn't writable. |
| Reset emails don't arrive | Check the `MAIL_*` settings: port 465 needs `ssl`, port 587 needs `tls`. The log shows the SMTP error. |
| Times look wrong | Set `APP_TIMEZONE`. Dates are stored as wall-clock time in that zone. |
| `₹` missing in PDFs | `storage/cache/` must be writable, because fonts are cached there on first use. |

## 7. What changed vs. the Next.js app
All features, URLs, roles and workflows are preserved. These are the deliberate differences.

**Security fixes**
- **A1:** uploads moved out of the public folder into private, per-company storage.
- **A2:** files are downloaded only through permission-checked `/files/{id}` URLs.
- **A3:** XSS in the letter preview fixed. All output is escaped.
- **A4:** helpdesk tickets were readable across companies. Now scoped to the company.
- **A5:** open redirect after login fixed.
- **A6:** CSRF protection on every form and API call.
- **A7:** hardened sessions. Users are re-validated on every request, and a password change ends other sessions.
- Reset tokens are hashed. Login and reset messages don't reveal whether an account exists. CSV exports are protected against spreadsheet-formula injection.
- **No default admin.** Setup uses `/install` with a `SETUP_KEY`, or the CLI. The demo-credentials page returns 404 in production.

**Bug fixes**
- **Leave:**
  - **B1:** leave approval runs in one transaction.
  - **B5:** the leave type must belong to the employee's company.
  - **B3:** leave balances are created automatically for paid leave types, without duplicates.
  - The first day of leave was charged as LOP because UTC and IST dates were mixed.
- **Payroll and settlements:**
  - **B2:** a payroll run is all-or-nothing.
  - The default salary date was one day early.
  - Full & Final could be generated before HR sign-off and all clearances. Asset clearance now requires every asset to be returned.
- **Attendance and assets:**
  - A repeated check-in overwrote the original time.
  - Assets could be assigned twice.
- **Approvals and access:**
  - Finance could approve its own expense claim.
  - HR could preview letters for another company's employee.
  - Audit filter lists showed other companies' action names.
- **Reports and settings:**
  - The payroll chart showed the *first* six runs instead of the latest six, and the run count stopped at 6.
  - Non-numeric policy values crashed the settings page.
- **Display:** the CSS cascade bug that made the check-in card white-on-white.

**Kept as in the original (documented)**
- A Manager can download the company-wide employees CSV.
- Payroll Admin has no Helpdesk module, even though Payroll-category tickets are routed to that queue. You can grant it under **Roles & permissions**.
- Payroll Admin can't open the statutory settings page.
- Department headcount counts every employee linked to a department, including those who have exited.

## 8. Development (not deployed)
`dev/` contains the Docker test stack (Apache + PHP 8.2 and MariaDB 11.4) and 13 test suites covering every phase:

- infrastructure, auth/RBAC and layout
- each module
- security checks against a production fallback-layout container
- a fresh-install end-to-end journey

The suites have 926 checks, all passing. See `dev/README.md`.

- **Rebuild CSS and icons:** `dev/build_assets.sh`
- **Build the package:** `python dev/build_package.py`
- **Regenerate the SQL:** `database/tools/` regenerates it from the original Prisma schema. It is read-only against `prisma/dev.db`.
