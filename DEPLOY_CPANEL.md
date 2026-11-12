# Deploying scrap3r on cPanel (scrap3r.swiftfusion.co.za) — MySQL

The app uses the cPanel MySQL database `swiftfusionco_scrap3r`. It **creates
its own tables** the first time it connects, so there is nothing to import.

`.env` (database password, install key) is **not in Git** because the repo is
public. You create it on the server in step 5.

---

## 1. Database (cPanel → MySQL® Databases)

1. **Create New Database**: `scrap3r` → becomes `swiftfusionco_scrap3r`.
2. **Add New User**: `scrap3r` → becomes `swiftfusionco_scrap3r`,
   with the database password you were given.
3. **Add User To Database**: user `swiftfusionco_scrap3r` → database
   `swiftfusionco_scrap3r` → tick **ALL PRIVILEGES** → Make Changes.

(If the database/user already exist, just make sure step 3 is done.)

## 2. Subdomain (cPanel → Domains → Create A New Domain)

- Domain: `scrap3r.swiftfusion.co.za`
- Untick **Share document root**
- **Document Root: `scrap3r.swiftfusion.co.za/public`** ← must end in `/public`

## 3. PHP (cPanel → MultiPHP Manager)

Tick `scrap3r.swiftfusion.co.za` → **PHP 8.2 or 8.3** → Apply.
Extensions needed (Select PHP Version, if shown): `pdo_mysql`, `mysqli`,
`curl`, `mbstring`, `fileinfo`, `gd`, `openssl`.

## 4. Upload the code

1. On GitHub: **Code → Download ZIP** (or zip your local checkout).
2. cPanel → **File Manager** → Settings → tick **Show Hidden Files** → Save.
3. Open `scrap3r.swiftfusion.co.za/`, delete anything cPanel put in `public/`
   (`index.html`, `cgi-bin`).
4. Upload the zip there → right-click → **Extract**. GitHub zips contain a
   top folder (`scrap3r-main/`): open it, **Select All → Move** to
   `/scrap3r.swiftfusion.co.za`, then delete the empty folder and the zip.
   You should now see `bin/ config/ db/ public/ src/ storage/ views/ index.php`
   directly inside `scrap3r.swiftfusion.co.za/`.
5. `storage` → Change Permissions → **755**.

## 5. Create `.env`

In `scrap3r.swiftfusion.co.za/` → **+ File** → name `.env` → Edit, paste the
contents of `.env.cpanel.example` and set `DB_PASS` and `INSTALL_KEY`
(any long random value). Save.

## 6. HTTPS

cPanel → **SSL/TLS Status** → `scrap3r.swiftfusion.co.za` → **Run AutoSSL**.

## 7. Create the admin (one time)

Open `https://scrap3r.swiftfusion.co.za/install.php?key=<INSTALL_KEY>` →
company, name, email, password. The tables are created on this first visit;
the page then locks itself.

## 8. Mailbox

Sign in → **Settings** → SMTP (host, port 587, STARTTLS, user, password),
From address → Save → **Send a test**.

## 9. Cron (campaigns don't send without it)

cPanel → **Cron Jobs** → Once per ten minutes (`*/10 * * * *`):

```
/usr/local/bin/php /home/USER/scrap3r.swiftfusion.co.za/bin/run_sequences.php >/dev/null 2>&1
```

Replace `USER` with your cPanel username (likely `swiftfusionco`). On
multi-PHP hosts use e.g. `/opt/cpanel/ea-php83/root/usr/bin/php`.

---

## Updating later

Upload the new code over the old (keep `.env`). The database is untouched.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "The app could not open its database" | Check `.env` DB_NAME / DB_USER / DB_PASS, and that the user has ALL PRIVILEGES (step 1.3) |
| "Install key required" | Use the full link with `?key=` matching `.env` |
| 500 error | PHP version (step 3); details in `storage/logs/php-errors.log` |
| cPanel default page | Delete `public/index.html` |
| No styling | Document root must end in `/public` |
