# Deploying scrap3r to Plesk (leads.vumanibiz.co.za)

The app goes on its **own subdomain**, so the company website at
www.vumanibiz.co.za is not touched. It uses **SQLite**: the whole database is
one file, `storage/database.sqlite`, so there is no database to create in Plesk.

The upload package already contains your accounts, the Vumani templates, the
brochure, sender settings and the draft campaign.

---

## 1. Create the subdomain

Plesk → **Websites & Domains** → **Add Subdomain**

- Subdomain name: `leads` → `leads.vumanibiz.co.za`
- **Document root: `leads.vumanibiz.co.za/public`** ← must end in `/public`

  Only `public/` may be reachable from the web. The database, `.env`, code and
  brochure all live beside it, out of reach. This is the single most important
  setting.

Plesk creates the DNS record automatically when the domain's DNS is managed by
Plesk. If DNS is managed elsewhere, add an **A record** `leads` → `41.185.114.15`.

## 2. PHP version

Subdomain → **PHP Settings** (or *Hosting Settings*):

- **PHP 8.2 or 8.3** (the main site runs 7.4; that is separate and unaffected).
  The app needs 8.1 or newer.
- Leave the default extensions on. It needs `pdo_sqlite`, `curl`, `openssl`,
  `mbstring` and `fileinfo`, which Plesk's PHP builds include.

## 3. HTTPS

Subdomain → **SSL/TLS Certificates** → **Install** a free Let's Encrypt
certificate for `leads.vumanibiz.co.za`, and turn on **redirect HTTP to HTTPS**.
Booking and unsubscribe links in emails point here, so they must be https.

## 4. Upload

1. Plesk → **Files** → open the `leads.vumanibiz.co.za` folder.
2. **Delete what Plesk put in `public/`** when it created the subdomain
   (`index.html`, and `css`, `img`, `favicon.ico` if present). A leftover
   `index.html` would show the Plesk placeholder page instead of the app.
3. Upload `scrap3r-vumani-deploy.zip` into the `leads.vumanibiz.co.za` folder
   and choose **Extract Files**. You should end up with:

   ```
   leads.vumanibiz.co.za/
     .env            bin/   config/   db/   public/   src/   storage/   views/ ...
   ```

4. Make sure **`storage/`** is writable. Files uploaded through Plesk belong to
   your subscription user, which is also the user PHP runs as, so the default
   permissions normally work. If you see "unable to open database file", set
   `storage/` to 755 (folder) and `storage/database.sqlite` to 644.

## 5. First login

Open **https://leads.vumanibiz.co.za** and sign in with an existing account
(Helen or Trevor). Then in **Settings**:

- Enter **Aubrey's mailbox password** and save.
- Fill in the **postal address** (it appears in the email footer).
- **Send a test** to yourself. Also open **Templates → Workshop - Brochure →
  Send test** to check the brochure arrives.

## 6. Schedule the sender (campaigns don't send without this)

Plesk → **Websites & Domains** → **Scheduled Tasks** → **Add Task**

- Task type: **Run a PHP script**
- Script path: `leads.vumanibiz.co.za/bin/run_sequences.php`
- PHP version: the same as step 2 (8.2 / 8.3)
- Run: **Cron style** `*/10 * * * *` (every 10 minutes)
- Notify: **on errors only**

Click **Run Now** once. The output should end in `processed=0 sent=0 ...`.

## 7. DNS for deliverability (do before the first campaign)

Plesk → **DNS Settings** for vumanibiz.co.za → edit the TXT record starting
`v=spf1` to:

```
v=spf1 include:touchbasepro.email ip4:41.185.114.15 ~all
```

Keep `include:touchbasepro.email`, which your existing mailers use. Then send a
test to a Gmail address, open it, choose **Show original**, and check for
**SPF: PASS** and **DKIM: PASS**.

---

## Settings file (`.env`)

Already filled in for this subdomain:

```
APP_URL=https://leads.vumanibiz.co.za
APP_ENV=production
APP_TZ=Africa/Johannesburg
DB_DRIVER=sqlite
DB_PATH=storage/database.sqlite
```

If you pick a different subdomain, change `APP_URL` in Plesk's file editor.
Every link in every email is built from it.

## Backups

Everything that matters is two things:

- `storage/database.sqlite`: leads, templates, campaigns, settings, history
- `storage/attachments/`: uploaded attachments such as the brochure

Download them regularly, or include the subscription in Plesk's Backup Manager.

## Updating the app later

Upload the changed code folders (`src/`, `public/`, `views/`, `bin/`,
`config/`, `db/`). **Never overwrite `storage/` or `.env`**, which hold the live
data and settings.

## What doesn't run on the server

The **ZoomInfo scraper** needs Python with extra packages, which shared Plesk
hosting normally doesn't provide. The Import page says so and disables the
button. Find contacts (Hunter), CSV upload and importing a scraper JSON file
all work online. The scraper can still be run on a PC, and its JSON uploaded.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Plesk placeholder page | Delete `public/index.html` (step 4.2) |
| "500 Internal Server Error" | Check the PHP version is 8.1+ (step 2); details are in `storage/logs/php-errors.log` |
| "unable to open database file" | `storage/` must be writable by PHP (step 4.4) |
| Login page with no styling | Document root is not `/public` (step 1) |
| Emails not sending | Settings → Send a test shows the exact SMTP reply; check the Scheduled Task ran |
| Emails in spam | SPF record (step 7) |
