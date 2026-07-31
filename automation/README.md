# Admin panel login automation

Companion service for the Laravel app's Translation section. The smmplus/smmto admin panel is a
React SPA with no documented login API, and its login form occasionally requires solving an
hCaptcha (observed reliably after a first wrong-password attempt). This service drives a real
Chromium instance with Playwright to:

1. Fill in the username/password on `{panelUrl}/admin`.
2. If an hCaptcha widget is rendered, click its checkbox automatically.
3. If that click resolves the captcha immediately, continue straight to submit.
4. If it instead pops the interactive picture challenge, stop and expose a live screencast (a
   polled JPEG endpoint) plus an input-forwarding endpoint, so a human can solve the challenge
   through the Laravel admin UI. It resumes automatically once the challenge overlay closes.
5. Submit, confirm login succeeded (vs. wrong credentials or a 2FA prompt), then navigate to
   `{panelUrl}/admin/appearance/blog` and confirm the page actually loaded.

This has to be a separate Node/Playwright process because a real browser (JS execution, iframes,
CDP screencasting) can't be done from PHP, and most shared PHP hosting has neither Node nor a
Chromium binary available. Run this on any small host that does (a cheap VPS is enough) and point
the Laravel app's automation settings at its URL.

## Running

```bash
cp .env.example .env   # set AUTOMATION_TOKEN to a real secret
npm install
npm start
```

## API (all routes require `Authorization: Bearer <AUTOMATION_TOKEN>`)

- `POST /sessions` — `{ panelUrl, username, password }` → starts a login attempt, returns the
  session (id + status).
- `GET /sessions/:id` — poll current `status`/`message`/`error`.
- `GET /sessions/:id/frame.jpg` — latest screencast frame (only populated while
  `status = awaiting_captcha`); `204` if none yet.
- `POST /sessions/:id/input` — forward a UI event while solving the captcha:
  `{ type: 'mousemove'|'mousedown'|'mouseup'|'click'|'keydown'|'keyup', xPct, yPct, key }`
  (`xPct`/`yPct` are 0–1, relative to the session's viewport).
- `DELETE /sessions/:id` — cancel and close the browser context early.

## Status values

`starting` → `checking_captcha` → [`awaiting_captcha` →] `submitting` → one of
`login_failed` / `two_factor_required` / `error`, or on success `navigating` → `blog_page_ready`.

`two_factor_required` is detected but not yet handled automatically — it's surfaced so the caller
can decide what to do next.
