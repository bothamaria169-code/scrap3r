# scrap3r — setup and operations

A ZoomInfo lead → outreach → appointment pipeline. Two halves:

- **Collector** (Python) — pulls ZoomInfo records through Bright Data's Dataset API.
  Forked from [luminati-io/zoominfo-scraper](https://github.com/luminati-io/zoominfo-scraper),
  kept as the `upstream` remote.
- **Web app** (PHP 8.2 + MariaDB on XAMPP) — lead database, SMTP emailer with
  sequenced campaigns, and appointment booking.

---

## Quick start (Windows)

Two wrappers in the project root work from any directory, from cmd,
PowerShell, or a double-click:

| Command | Does |
|---------|------|
| `scrape.bat` | Scrapes every URL in `urls.txt`, waits for queued jobs, imports into the app |
| `scrape.bat my_list.txt` | Same, with another URL list |
| `scrape.bat --snapshot sd_xxx` | Resumes a job that timed out |
| `worker.bat --dry-run` | Shows which campaign emails are due, sends nothing |
| `worker.bat` | Sends due campaign emails (schedule this) |

Timezone is set by `APP_TZ` in `.env` (currently `Africa/Johannesburg`). It
drives send windows, booking slots and every timestamp, so it must match where
your reps work.

---

## Sending from Vumani's mail server

Settings are pre-filled for **aubrey@vumanibiz.co.za** on `mail.vumanibiz.co.za`,
port **587**, **STARTTLS** (probed: 587, 465 and 25 all answer with a valid
certificate and accept LOGIN/PLAIN). Only the **password** is left to enter,
on the Settings page. Then use **Send a test** there.

**Fix SPF before sending campaigns.** The live record is

    v=spf1 include:touchbasepro.email ~all

which authorises only TouchBasePro. Mail from this app leaves through
`lnxsvrweb06.hostserv.co.za` (41.185.114.15), which that record does not cover,
so receivers see an SPF failure and lean towards spam. In the DNS panel for
vumanibiz.co.za change the TXT record to

    v=spf1 include:touchbasepro.email ip4:41.185.114.15 ~all

Then send a test to a Gmail address, open it, choose **Show original**, and
confirm **SPF: PASS** and **DKIM: PASS** (a DKIM key is already published under
the `default` selector). If SPF still fails, the header shows the IP that
actually sent it; put that IP in the record instead.

## Templates, attachments and one-off sends

- **Templates** has **Preview** (exactly what a lead sees) and **Send test**
  (to your own inbox, marked [TEST], never logged as outreach).
- Any template can carry **one attachment** (PDF, Word, Excel or image,
  under 8 MB). Files live in `storage/attachments/`, which is not reachable
  from the web.
- `{{signature}}` inserts the signature from Settings; `{{sender_email}}` is
  your reply address, for "reply" buttons (`mailto:{{sender_email}}`).
- On a lead's page, **Send an email now** sends one template immediately.
  That is how the brochure goes out when someone replies that they are
  interested.

The Vumani workshop set: *Workshop 1 - Invitation* (no attachment), *2 -
Follow-up* (+3 days), *3 - Last note* (+5 days), wired into the draft campaign
**Corporate Comms Workshop - 1-2 Oct 2026**, plus *Workshop - Brochure &
registration*, which carries the PDF and is sent by hand from the lead page.

---

## Finding contacts: Hunter.io

The ZoomInfo feed yields companies, not people. Contacts come from Hunter.io.

1. Sign up at hunter.io (free tier available) and copy your key from **API** in
   the Hunter dashboard.
2. Paste it on the **Settings** page. Don't put it in chat or email.
3. Use **Find contacts**: search a company domain (`standardbank.co.za`) or
   name, optionally filtered by department, seniority or job title. Tick the
   people you want and import them. The page shows your remaining credits.

On any lead page you can also **Find email** for a named person, or **Verify**
an address before sending.

| Action | Hunter credits |
|--------|----------------|
| Company search | 1 per 10 addresses returned; empty results free |
| Find one person's email | 1 if found, 0 if not |
| Verify an address | 1 verification credit |
| Checking your balance, importing results | Free |

Addresses that fail verification are blocked from campaigns and never sent to,
because every bounce hurts your sending domain's reputation. If Hunter reports
that a person has asked for their data to be deleted, that address is added to
the suppression list automatically.

---

## Read this first: what the data actually contains

The Bright Data ZoomInfo dataset returns **41 fields**, but in live testing:

| URL type | Result |
|----------|--------|
| `/p/` person pages | Record returns, but only `url`, `id`, `description` and `timestamp` are populated. Everything else is `null`. |
| `/c/` company pages | Consistently blocked: `rate_limit — Crawler error: Navigation failed: too many requests`. |

**Contact fields (`email_formats`, `phone_number`, `top_contacts`, `social_media`)
came back null on every record collected.** The scraper is therefore a *targeting*
source, not a contact source. Email addresses must come from a CSV import or be
entered by hand. The app is built around that constraint and will not let you
enrol a lead that has no address.

Whether company pages start working is a Bright Data / ZoomInfo matter, not a
code one. The collector handles those rows correctly either way — see
*Blocked rows* below.

---

## Layout

| Path | Purpose |
|------|---------|
| `public/` | The **only** web-reachable directory (`.htaccess` denies the rest) |
| `src/` | Application classes, PSR-style autoloaded |
| `views/` | Templates |
| `bin/` | CLI workers — sequence runner, user admin |
| `db/` | `schema.sql` plus numbered migrations |
| `config/bootstrap.php` | Env loading, autoloader, error handling |
| `zoominfo_scraper_api_codes/` | The Python collector |
| `output/` | Scraper JSON output (gitignored) |
| `.env` | Tokens, DB and SMTP credentials (gitignored, web-denied) |

---

## First run

1. **Database** — already created. To rebuild from scratch:

   ```bash
   C:/xampp/mysql/bin/mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS scrap3r CHARACTER SET utf8mb4"
   C:/xampp/mysql/bin/mysql.exe -u root scrap3r < db/schema.sql
   C:/xampp/mysql/bin/mysql.exe -u root scrap3r < db/migration_001_rich_fields.sql
   C:/xampp/mysql/bin/mysql.exe -u root scrap3r < db/migration_002_hunter.sql
   C:/xampp/mysql/bin/mysql.exe -u root scrap3r < db/migration_003_attachments.sql
   ```

2. **Create the admin account** — open <http://localhost/scrap3r/public/install.php>.
   The page disables itself once a user exists.

3. **Configure SMTP** — Settings page. Host, port, security, username, password,
   plus the From identity and the postal address used in the footer. The
   **Send a test** button opens a real SMTP session and shows the full transcript,
   which is the fastest way to diagnose a refused login.

4. **Add reps**:

   ```bash
   C:/xampp/php/php.exe bin/add_user.php "Jane Doe" jane@example.com --role=rep --tz=Africa/Johannesburg
   C:/xampp/php/php.exe bin/set_availability.php 2 "1-5:09:00-17:00"
   ```

   The password is prompted for, never passed as an argument, so it stays out of
   shell history.

5. **Schedule the sequence worker** — nothing sends without it. Windows Task
   Scheduler, every 10–15 minutes:

   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\scrap3r\bin\run_sequences.php
   ```

   Dry run first: `C:/xampp/php/php.exe bin/run_sequences.php --dry-run`

---

## Daily use

**Import** (`import.php`) — three routes:
- Run the scraper on pasted URLs (targeting data only)
- Upload a CSV (**this is where emails come from**) — needs an `email` column;
  optional `name`, `title`, `company`, `phone`, `source_url`, `description`.
  Rows matching an existing profile URL or address update that lead rather than
  duplicating it, and a CSV email is never overwritten by a later scraper import.
- Import an existing `output/*.json` file

**Campaigns** — a campaign is an ordered list of steps, each pointing at a
template with a delay in hours. Step 1 fires as soon as a lead is enrolled.
Per-campaign throttle, send window and weekend skipping; a global daily cap
lives in Settings.

**Booking** — every outreach email carries a `{{booking_link}}` unique to that
lead. The lead picks from the rep's real free/busy slots. Booking is
transactional, so two people cannot take the same slot. Reps can also enter
appointments by hand from the lead page.

**A lead that books or unsubscribes is dropped from its sequence automatically.**

---

## Compliance behaviour (deliberate, don't strip it)

- Every send checks the suppression list first, keyed by email — one opt-out
  blocks every duplicate record of the same person.
- If a template body has no opt-out URL, a footer with one is appended along
  with your company name and postal address.
- `List-Unsubscribe` and `List-Unsubscribe-Post` headers are set, so Gmail and
  Outlook show a native unsubscribe control. One-click opt-out (RFC 8058) is
  honoured without a confirmation step.
- Throttling, send windows and the daily cap are on by default.

---

## The collector

```bash
.venv/Scripts/python.exe zoominfo_scraper_api_codes/zoominfo_scraper.py -f urls.txt
```

Bright Data's synchronous `/scrape` endpoint frequently cannot finish in time and
returns a **snapshot id** instead of data. The collector detects this, polls the
progress endpoint, and downloads the result when ready. A person-page scrape took
about 90 seconds this way.

If it times out it prints a resume command:

```bash
.venv/Scripts/python.exe zoominfo_scraper_api_codes/zoominfo_scraper.py --snapshot sd_xxxx -o output/data.json
```

Useful flags: `--include-errors` (shows *why* a URL returned nothing),
`--poll-timeout`, `--poll-interval`, `--batch-size`, `--limit`, `--notify`.

**Blocked rows.** When ZoomInfo blocks a URL, Bright Data returns a row carrying
`error` and `error_code` instead of data. The importer counts these separately as
*blocked*, records the reason against the import, and creates no lead. Empty
placeholder rows are counted as *skipped*. Neither becomes a junk lead.

Every scraped record is stored whole in `leads.raw_json`, so a schema change on
Bright Data's side never silently loses data.

---

## Security notes

- Only `public/` is served. The root `.htaccess` denies everything else —
  verified: `.env`, `src/`, `config/`, `db/`, `bin/` and the venv all return 403.
- Sessions are HttpOnly + SameSite=Lax, with id regeneration on login.
- All POSTs require a CSRF token; a forged POST gets a 403 and writes nothing.
- Passwords use `password_hash()` defaults.
- Every query is a prepared statement with `EMULATE_PREPARES` off.
- Public pages (booking, unsubscribe) authenticate by a 256-bit random token
  scoped to a single purpose; a booking token will not resolve as an unsubscribe
  token.

**If this ever moves off localhost:** put it behind HTTPS, change the MySQL root
password and give the app its own limited DB user, and set `APP_ENV` to something
other than `local` so stack traces stop rendering.

---

## Tests

There is no test framework here. Two scratch suites were used during the build
(79 assertions, all passing) covering import idempotency, description parsing,
merge fields, enrolment rules, suppression, footer injection, slot generation,
double-booking, token scoping, throttling and the send window. They live in the
session scratchpad rather than the repo — worth porting to PHPUnit if this grows.
