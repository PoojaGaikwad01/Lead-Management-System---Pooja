# Funky Monkey Lead Management System

Standalone lead inbox for Funky Monkey (leads.funkymonkey.in). Leads from Meta (Lead Ads, Messenger, Instagram, WhatsApp) and agencies are assigned to branches; Branch Managers reply; Admin sends reminders and views reports.

**Stack:** Laravel 13 · PostgreSQL (Supabase) · Blade · Alpine.js · Tailwind v4 · database queue + scheduler.

## Setup

```bash
composer install
npm install && npm run build
cp .env.example .env          # set DATABASE_URL (Postgres/Supabase); APP_MODE=mock needs no Meta/AI keys
php artisan key:generate
# once per database:  CREATE SCHEMA IF NOT EXISTS lms;   (app tables live in schema "lms")
php artisan migrate --seed
php artisan serve
```

Seeded logins (password `password` — change after first login):

| Role | Email |
|---|---|
| Admin | admin@funkymonkey.in |
| Branch Manager (Andheri, Mumbai) | manager.andheri@funkymonkey.in |
| Branch Manager (Koregaon Park, Pune) | manager.koregaonpark@funkymonkey.in |
| Branch Manager (Indiranagar, Bengaluru) | manager.indiranagar@funkymonkey.in |

There is no public registration or email password reset; an admin resets passwords from **Users → Edit**.

## Configuration

All lead-system settings are in `.env` and read through `config/leads.php`:
`APP_MODE` (mock|live), `META_*`, `IG_ACCOUNT_ID`, `WA_*`, `AGENCY_NUMBERS`, `AI_*`, `FIREBASE_CREDENTIALS_PATH`, `REMINDER_AFTER_MINUTES`, `DEDUPE_DAYS`.

## Meta webhooks

- Callback URL: `https://leads.funkymonkey.in/webhooks/meta` — verify token = `META_VERIFY_TOKEN`; subscribe the Page to `leadgen` + `messages`, the Instagram account to `messages`, and the WhatsApp Business Account to `messages`.
- Webhooks are processed by the queue, so run a worker: `php artisan queue:work` (Supervisor/systemd in production).
- Mock mode: `php artisan meta:replay` replays every fixture in `tests/fixtures/meta/` (Lead Ads FB/IG, Messenger, Instagram DM, WhatsApp text/document/status, agency text/Excel). Safe to re-run.
- Live testing: `php artisan meta:simulate-webhook whatsapp_text [--url=…]` posts a fixture to the endpoint with a valid signature; `php artisan meta:send-test 91XXXXXXXXXX [message] [--template=hello_world]` sends a real WhatsApp message and prints Meta's response.
- Every webhook is logged (object type + message ids only) to `storage/logs/meta.log` (stderr on Render).

## Deploy on Render (Docker)

Render has no native PHP runtime, so the app ships as a Docker image (`Dockerfile`, `docker/`).

- New → **Web Service** → connect this repo → **Runtime: Docker**, Root Directory blank, Dockerfile Path `./Dockerfile`, Docker Command blank, **Health Check Path `/up`**.
- On every start, `docker/start.sh` caches config/routes/views, starts the queue worker + scheduler in the background (as www-data), and runs Apache on `0.0.0.0:$PORT`. It does **not** run migrations (tables already exist; run `php artisan migrate` manually when new migrations are added).
- Environment: `APP_KEY` (`php artisan key:generate --show`), `APP_URL`, `APP_MODE`, and the database as `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (don't also set `DATABASE_URL` — it would take precedence). Defaults set in the Dockerfile: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=file`, `DB_CONNECTION=pgsql`, `DB_SCHEMA=lms`, `DB_SSLMODE=require`.
- Firebase JSON: add it as a Render **Secret File** and set `FIREBASE_CREDENTIALS_PATH=/etc/secrets/<file>.json`.
- Free tier: the service sleeps after ~15 min idle (worker/scheduler pause too; Meta retries webhooks), and the disk is temporary (downloaded WhatsApp attachments are lost on restart).

## Background processes (production)

- **Queue worker** — webhooks and reminder notifications: `php artisan queue:work` (keep alive with Supervisor/systemd).
- **Scheduler** — auto-reminders every 30 min: cron `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (locally: `php artisan schedule:work`). Run once by hand: `php artisan leads:send-reminders`.

## Reminders & push notifications

- Leads assigned longer than `REMINDER_AFTER_MINUTES` without a reply remind the branch's active managers (at most once an hour). Admins can also press **Send reminder** on a pending lead.
- Every reminder appears in the in-app bell. If `FIREBASE_CREDENTIALS_PATH` points to a Firebase service-account JSON (and `APP_MODE=live`), it is also pushed via FCM to the user's registered devices.
- Register a device for the signed-in user: `POST /device-tokens` `{"token": "...", "platform": "android|ios|web"}` with the page's `X-CSRF-TOKEN`; remove with `DELETE /device-tokens`.

## Reports

**Reports** shows counts by source, branch, status and day for a date range, plus average time to reply (assignment → first reply). **Export CSV** downloads one row per lead with the same filters. Branch Managers only see their own branch.

## Tests

```bash
php artisan test
```
