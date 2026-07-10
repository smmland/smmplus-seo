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

## Notes

- Utility/app-only routes (`signup`, `resetpassword`, `user-levels`) are classified `UTILITY`
  and hidden by default since they carry no SEO value — toggle back on from the panel if needed.
- URLs manually recategorized or added from the panel (`is_manual`) are never overwritten by
  the next sync; everything else is re-classified from the source sitemap's URL shape each run.
