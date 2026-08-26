# SMM Plus SEO — Sitemap Sync & Control Panel (Laravel backend)

Fetches `smm.plus`'s sitemap, categorizes every URL by shape (home/blog/landing/static/utility),
stores it in MySQL/MariaDB, and republishes a categorized sitemap index with hreflang alternates
linking locale variants together. Runs on a dedicated subdomain (`seo.smm.plus`), independent of
the main site's codebase (no access to it is required or used).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env: DB_* credentials, SOURCE_SITEMAP_URL, APP_URL (public base URL of this subdomain)
php artisan migrate
php artisan admin:seed   # creates/updates the panel admin user from ADMIN_EMAIL / ADMIN_PASSWORD
```

## Required server cron entry

Laravel's scheduler needs exactly one system cron entry; everything else (the sitemap sync
interval) is configured from the panel/API at runtime, not by editing crontabs:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`sitemap:sync` is scheduled to run every 15 minutes but internally checks whether the
admin-configured interval (`Setting: sync_interval_hours`, default from `DEFAULT_SYNC_INTERVAL_HOURS`)
has actually elapsed before doing any work — so changing the interval from the panel's
`PUT /api/settings` endpoint takes effect on the next tick, no redeploy needed.

## API

All `/api/*` routes except `/api/auth/login` require `Authorization: Bearer <token>` (token
issued by login, stored hashed against the admin user — no separate auth package needed).

- `POST /api/auth/login` — `{ email, password }` → `{ accessToken }`
- `GET/PUT /api/settings` — sync interval hours, source sitemap URL
- `POST /api/sync/run`, `GET /api/sync/runs`, `GET /api/sync/status`
- `GET /api/urls` — filter by `patternType`, `lang`, `hidden`, `active`, `search`
- `POST /api/urls` — add a manual URL not present in the source sitemap
- `PATCH /api/urls/{id}` — toggle `isHidden`, override `patternType`, set `priority`/`changefreq`
- `PATCH /api/urls/bulk-visibility` — hide/show by `ids`, `patternType`, or `lang` in one call
- `DELETE /api/urls/{id}` — manually-added or inactive URLs only

Public, unauthenticated (what Google actually fetches):

- `GET /sitemap_index.xml`
- `GET /sitemap-pages.xml`, `/sitemap-blog.xml`, `/sitemap-landing.xml`, `/sitemap-other.xml`
- `POST /api/analytics/collect` — origin-checked, rate-limited first-party analytics beacon

## First-party SEO analytics

Load `/analytics/tracker.js` from the shared website layout. It records page views, sessions,
active engagement, scroll depth, landing attribution, internal/outbound clicks, key conversions,
404 visits, browser errors and Core Web Vitals without storing raw IP addresses. The Filament
**Analytics → Website Statistics** page provides time/language/device/audience filters and
actionable SEO tables. The layout supplies only `guest`, `authenticated`, or opt-in `internal`
status—never a user ID, email, or account attribute. Raw events are pruned after 180 days.

Paid orders and refunds use a separate trusted endpoint: `POST /api/analytics/purchases`. The
ordering backend sends an `X-SMM-Timestamp` Unix timestamp and an `X-SMM-Signature` equal to
`sha256=` plus `HMAC-SHA256(timestamp + "." + exact_raw_json_body, ANALYTICS_PURCHASE_WEBHOOK_SECRET)`.
The JSON contract is:

```json
{
  "site_id": "smm-plus",
  "event_id": "a-new-uuid-for-every-order-update",
  "order_id": "ORD-1001",
  "status": "paid",
  "gross_amount": "24.50",
  "refunded_amount": "0",
  "currency": "USD",
  "visitor_id": "optional-tracker-uuid",
  "session_id": "optional-tracker-uuid",
  "paid_at": "2026-08-26T12:00:00Z",
  "updated_at": "2026-08-26T12:00:00Z"
}
```

Valid statuses are `paid`, `partially_refunded`, `refunded`, and `cancelled`. A fresh `event_id`
is required for each update, while `order_id` stays stable. Replays are permanently deduplicated,
older updates cannot undo newer refunds, and currency totals are never combined. The anonymous
tracker IDs are available through `window.smmAnalytics.context()` so checkout can attach them to
the server-side order; the amount and status must always come from the trusted order database.

The collection endpoint treats all browser telemetry as untrusted: it enforces an exact CORS
origin allowlist, a strict event schema, request and event budgets, payload-size limits, trusted
Cloudflare proxy ranges, and server-side sensitive-value redaction. The tracker supports SHA-384
Subresource Integrity; never put an API secret in the public script because it would not be secret.

## Notes

- Utility/app-only routes (`signup`, `resetpassword`, `user-levels`) are classified `UTILITY`
  and hidden by default since they carry no SEO value — toggle back on from the panel if needed.
- URLs manually recategorized or added from the panel (`is_manual`) are never overwritten by
  the next sync; everything else is re-classified from the source sitemap's URL shape each run.
