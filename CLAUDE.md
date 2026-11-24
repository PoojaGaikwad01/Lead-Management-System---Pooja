# Funky Monkey Lead Management System

Standalone Laravel 13 + PostgreSQL (Supabase) + Blade + Alpine + Tailwind v4 app (leads.funkymonkey.in). Built in phases 1–6; build in order, don't refactor earlier phases unless required.

## Database
- PostgreSQL on Supabase (session pooler, SSL `require`). Connection string only in `.env` `DATABASE_URL` (password URL-encoded, `@` → `%40`); never hardcode or print it. Don't run `php artisan db:show` (it prints the URL incl. password).
- All app tables live in schema `lms` (`DB_SCHEMA`, pgsql `search_path`). `public` holds unrelated tables (users, leads, lead_activities) — never touch them, never `migrate:fresh` without checking the search_path.
- Postgres specifics: use `whereLike()` for case-insensitive search (ILIKE); cast id filters to int; route ids are constrained to digits/UUIDs (AppServiceProvider).
- Deploy: Render (Docker runtime). `Dockerfile` (php:8.3-apache + composer → node:20 assets → runtime), `docker/start.sh` (config/route/view cache, queue + scheduler as www-data in background, Apache on 0.0.0.0:$PORT; NO migrations), `docker/apache.conf`. Prod uses SESSION_DRIVER=cookie (keep session data tiny; reply drafts >500 chars aren't flashed), CACHE_STORE=file, LOG_CHANNEL=stderr. Proxies trusted + https forced in production; APP_URL falls back to RENDER_EXTERNAL_URL.
- PHP needs `extension=pdo_pgsql` (enabled in the local winget php.ini; backup at php.ini.bak-before-pgsql). Tests stay on in-memory SQLite (forced in phpunit.xml).

## Local toolchain (Windows)
- PHP 8.3 (winget, not on PATH): `/c/Users/DELL2/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.NTS.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe`
- Composer: `~/bin/composer` (wrapper around `~/bin/composer.phar`)
- In Bash: `export PATH="<php dir>:$HOME/bin:$PATH"` then `php artisan ...`, `composer ...`
- Tests: `php artisan test` (SQLite in-memory, Vite disabled in `tests/TestCase.php`)

## Conventions
- Prefer Laravel built-ins; add packages only if essential. No Laravel Boost.
- All lead-system settings live in `config/leads.php` (APP_MODE, Meta/WA keys, DEDUPE_DAYS, …). Never call `env()` outside config.
- Everything must work with `APP_MODE=mock` (no real Meta/WA/FCM/AI calls).
- Roles: `App\Enums\Role` (admin|branch_manager). Middleware aliases: `role:admin`, `active`.
- Branch scoping: models with `branch_id` use `App\Models\Concerns\BelongsToBranch` → `Model::visibleTo($user)`.
- Layout: `<x-layouts.app title="...">` with optional `<x-slot:actions>`; CSS helpers `.btn .btn-primary .btn-secondary .btn-danger .input .label .card .badge .table` in `resources/css/app.css`.
- Inline `$request->validate()` in controllers (no FormRequest classes).
- Blade gotcha: never mix inline `@php($x = …)` with `@php … @endphp` blocks in one file (the compiler pairs them wrongly). Use block form.

## Leads (Phase 2+)
- Enums `LeadSource` (label/short/color/channel/isLeadAd) and `LeadStatus` (label/color). Lead Ads have `channel() === null` → no chat thread.
- `Lead.phone` mutator normalizes via `App\Support\Phone::normalize()` (+91…); unrecognised input kept raw for review.
- `Lead.contact_id` = chat sender id (PSID / IG id / wa_id) — used for replies and to dedupe chats without a phone.
- `Lead.last_activity_at` = inbox sort key; `Message` `created` event bumps it. `Message` has no `updated_at`.
- Authorization: `LeadPolicy::view` (admin or same branch); lists use `Lead::visibleTo($user)->filter($request->only(...))`.
- Inbox: `InboxController` index/show share one view (`inbox/index` + `inbox/_thread`); layout `:flush="true"` for full-height panes.
- `LeadSeeder` runs only when APP_MODE=mock and the leads table is empty.

## Meta ingestion (Phase 3+)
- `routes/webhooks.php` is loaded outside the web group (no session/CSRF). `MetaWebhookController` verifies `X-Hub-Signature-256` (skipped only in mock with no secret) and queues `ProcessMetaWebhook` → `MetaWebhookProcessor`.
- ALL lead/message creation goes through `App\Services\LeadIngestor` (`findDuplicate`, `ingestChat`, `ingestLeadAd`, `store`). Idempotent via `external_message_id` / `(source, external_id)`; dedupe = same phone (or same source+contact_id) with `last_activity_at` within DEDUPE_DAYS. `store()` only fills blank fields and reopens closed leads.
- Lead-ad duplicates become a message with `channel = 'lead_ad'`, `external_message_id = 'leadgen:{id}'`. Agency messages use `channel = 'agency'` (they come from the agency, not the customer, so never open the 24h window).
- `App\Services\Meta\MetaClient`: Graph GETs + WhatsApp media download. Mock mode reads `tests/fixtures/meta/graph/{id}.json` and `tests/fixtures/meta/media/{filename}`. Attachments: private disk path (`media/whatsapp/{media_id}/{file}`) or Meta CDN URL; served via `attachments.show` (policy-checked).
- WhatsApp phones: always `'+'.$wa_id` before normalizing. Agency = sender in `config('leads.agency_numbers')` → `App\Services\Agency\AgencyParser::handle()`.
- Fixtures: `tests/fixtures/meta/*.json` with `__NOW__` / `__NOW_MS__` tokens; `php artisan meta:replay [names] [--queue]` (mock only).
- Live-test tools: `meta:simulate-webhook {fixture} [--url]` (signed HTTP POST), `meta:send-test {to} [message] [--template=]` (always real Cloud API). Log channel `meta` (`LOG_META_PATH`, default storage/logs/meta.log): ids/object only, never content.

## Agency parsing (Phase 4+)
- `AgencyParser` → `SpreadsheetLeadReader` (xlsx/xls/csv/ods via phpoffice/phpspreadsheet; header row found in first 10 rows) or `TextLeadExtractor` (regex `PHONE`, labels Name:/City:). One lead + message copy per candidate, ids `{wamid}#n`; nothing usable → one needs_review lead with id `{wamid}`. Whole message in one DB transaction.
- Status: phone present and unambiguous → new; else needs_review. Candidates with a phone are deduped via `LeadIngestor::findDuplicate`.
- AI: `Services\Agency\Ai\AiLeadExtractor` interface, bound in `AppServiceProvider` (only `NullAiLeadExtractor` exists; AI_PROVIDER=none). Called only for text when rules found nothing or something ambiguous.
- Admin fixes leads via `PATCH leads/{lead}` (`LeadController@update`); saving a needs_review lead with a valid phone → new (or assigned if it has a branch).
- Excel fixture `tests/fixtures/meta/media/agency-leads.xlsx` is binary; regenerate with PhpSpreadsheet if rows need changing.

## Assign / reply (Phase 5+)
- Message channels: `whatsapp|messenger|instagram` (customer chat), `lead_ad` (dup form summary), `agency` (agency forward), `note` (system note e.g. "Marked reply sent", rendered as a pill). Only `Lead::CHAT_CHANNELS` inbound messages open the 24h window.
- `Lead::replyWindow()` → channel/recipient (WA = phone digits, Messenger/IG = contact_id)/open/closes_at. `Lead::assignTo()` (resets `replied_at`), `Lead::markReplied()` (keeps first `replied_at`).
- `leads.replied_at` = first reply since latest assignment → Phase 6 "time to reply" = replied_at − assigned_at.
- `MetaClient::sendText()` (mock returns `mock.out.{uuid}`). `LeadPolicy::reply` = can view AND lead has a branch. Needs-review leads can't be assigned (bulk skips them).

## Reminders / reports (Phase 6)
- `ReminderService::remind()` → one `reminders` row per active manager of the lead's branch + `LeadReminder` notification (queued; `database` always, `FcmChannel` when `FcmClient::configured()` and the user has device tokens). Only status `assigned`.
- `leads:send-reminders` scheduled every 30 min in `routes/console.php` (assigned > REMINDER_AFTER_MINUTES, no reminder in the last hour).
- `FcmClient`: HTTP v1, JWT signed with openssl (no SDK), access token cached as `fcm.access_token`; mock mode only logs. Unregistered tokens are deleted.
- Bell in the layout (`notifications.*` routes, polls unread count every 60s). Device tokens: `POST/DELETE /device-tokens` (session + CSRF).
- `ReportController`: filters from/to (lead created_at, default last 30 days), source, branch (admin); totals/by source/branch/status/day computed in PHP; CSV via `streamDownload` with BOM + formula-injection guard.
- Windows PHP can't `openssl_pkey_new()` without a config file — tests pass a minimal temp cnf.

## Google Ads lead forms
- Source: `LeadSource::GoogleLeadAd` (`google_lead_ad`). It's a Lead Ad: `channel()` is null and there's no chat thread. `LeadSource::leadAds()` lists Meta + Google. No migration was needed (`source` is a string column).
- Webhook: `POST webhooks/google-leads` (`routes/webhooks.php`) → `GoogleLeadWebhookController`.
  - Checks `google_key` in the body with `hash_equals` against `leads.google.lead_key` → 401. An empty key is allowed only when not live, same rule as the Meta signature.
  - Processed synchronously (the body has the whole lead) by `Services\Google\GoogleLeadProcessor` → `LeadIngestor::ingestLeadAd()`. Same idempotency and phone dedupe as Meta; a duplicate becomes a `lead_ad` message `leadgen:{lead_id}`.
  - Responds `200 {}`.
  - CORS is on for this one route (`config/cors.php` paths) so browser-based senders (Google Ads "Send test data", online testers) don't fail with "Failed to fetch". No other route gets CORS.
- Parsing:
  - `GoogleLeadProcessor::decode()` uses `JSON_BIGINT_AS_STRING`.
  - `attributes()` reads `user_column_data` by `column_id`. The webhook has no names, so it sets `campaign_name` = "Google campaign {id}", `form_name` = "Google form {id}", `ad_name` = "Ad group {id}".
  - `google_key` is stripped from `raw_payload`.
- `Lead::formAnswers()` returns Meta `field_data` or Google `user_column_data` rows (used by `inbox/_thread`). `Lead::isTest()` = Google `is_test`.
- Demo:
  - `SampleGoogleLead::payload()` builds Google-format bodies (`lead_id` "sample-…"). `SampleGoogleLead::clear()` deletes only those.
  - Commands: `google:send-sample [--count --test --key --url]` (HTTP POST) and `google:clear-samples`.
  - Admin buttons in the inbox (`GoogleDemoController`, `leads.google.demo_tools` = `GOOGLE_DEMO_TOOLS`, default mock only). The browser POSTs the sample body to the webhook itself; a server-to-self request would deadlock `php artisan serve`.
- Inbox live updates: `resources/js/inbox-live.js` polls `inbox.updates?since={id}` every 5s (visibleTo + filters). On new leads it re-renders the `[data-live-region]` parts from the current URL, highlights them with `.lead-new` and shows a toast (textContent only).
- `LeadSeeder` adds 5 Google leads (`mock-google-1..5`, #5 is a test lead) after the original 30, whose rotation excludes Google so it stays unchanged.

## Simulation mode (current stage)
- Real Meta / WhatsApp / Google details aren't available yet, so Render and local run `APP_MODE=mock`. Simulated content is Idyllic Services (IT services); Funky Monkey content comes later.
- `App\Services\Simulation\LeadSimulator::run($type)` builds a fresh webhook body in the platform's exact format and runs `ProcessMetaWebhook::dispatchSync()` (same pipeline as real data). Types: `LeadSimulator::TYPES` (fb/ig lead form, messenger, ig_dm, whatsapp, agency_text, agency_excel). Google uses `SampleGoogleLead` via the browser.
- Graph objects (lead answers, form names, profile names) and media (generated .xlsx) are put in the cache under `MetaClient::MOCK_GRAPH_KEY` / `MOCK_MEDIA_KEY`; `MetaClient` reads them first in mock mode, then the fixtures.
- All simulated ids start with `sim-` (media dirs `sim_media_…`). `LeadSimulator::clear()` deletes those plus `sample-` Google leads. Agency simulation uses the first `AGENCY_NUMBERS` entry, else `+919900000001` for that request only.
- Admin menu "Simulate a lead" in `inbox/index` (`simulate.store` / `simulate.clear`, 404 in live mode). Tests: `tests/Feature/SimulationTest.php`.
