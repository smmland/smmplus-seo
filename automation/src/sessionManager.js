'use strict';

const { chromium } = require('playwright');
const crypto = require('crypto');

// Lifecycle: STARTING -> (CHECKING_CAPTCHA ->) [AWAITING_CAPTCHA ->] SUBMITTING
//            -> LOGIN_FAILED | TWO_FACTOR_REQUIRED | (NAVIGATING -> BLOG_PAGE_READY) | ERROR
const STATUS = {
  STARTING: 'starting',
  CHECKING_CAPTCHA: 'checking_captcha',
  AWAITING_CAPTCHA: 'awaiting_captcha',
  SUBMITTING: 'submitting',
  LOGIN_FAILED: 'login_failed',
  TWO_FACTOR_REQUIRED: 'two_factor_required',
  NAVIGATING: 'navigating',
  BLOG_PAGE_READY: 'blog_page_ready',
  ERROR: 'error',
};

const SESSION_TTL_MS = 10 * 60 * 1000;
const VIEWPORT = { width: 1280, height: 800 };

// hCaptcha's accessibility titles are stable across sites since they come from hCaptcha's own
// script, not the integrating page - safer to key off these than any smmplus-specific markup.
const CHECKBOX_IFRAME_TITLE = 'Widget containing checkbox for hCaptcha security challenge';
const CHALLENGE_IFRAME_TITLE = 'hCaptcha challenge';

class Session {
  constructor(id) {
    this.id = id;
    this.status = STATUS.STARTING;
    this.message = null;
    this.error = null;
    this.frame = null;
    this.viewport = VIEWPORT;
    this.context = null;
    this.page = null;
    this.cdp = null;
    this.screencastOn = false;
    this.createdAt = Date.now();
    this.expireTimer = null;
  }

  toJSON() {
    return {
      id: this.id,
      status: this.status,
      message: this.message,
      error: this.error,
      hasFrame: !!this.frame,
      viewport: this.viewport,
    };
  }
}

class SessionManager {
  constructor() {
    /** @type {Map<string, Session>} */
    this.sessions = new Map();
    this.browserPromise = null;
  }

  async getBrowser() {
    if (!this.browserPromise) {
      this.browserPromise = chromium.launch({
        headless: true,
        executablePath: process.env.CHROMIUM_EXECUTABLE_PATH || undefined,
        args: [
          '--disable-blink-features=AutomationControlled',
          '--no-sandbox',
        ],
      });
    }
    return this.browserPromise;
  }

  get(id) {
    return this.sessions.get(id) || null;
  }

  remove(id) {
    const session = this.sessions.get(id);
    if (!session) return;
    this._clearExpireTimer(session);
    this.sessions.delete(id);
    this._teardown(session).catch(() => {});
  }

  _clearExpireTimer(session) {
    if (session.expireTimer) {
      clearTimeout(session.expireTimer);
      session.expireTimer = null;
    }
  }

  _scheduleExpiry(session) {
    this._clearExpireTimer(session);
    session.expireTimer = setTimeout(() => this.remove(session.id), SESSION_TTL_MS);
  }

  async _teardown(session) {
    await this._stopScreencast(session);
    if (session.context) {
      await session.context.close().catch(() => {});
    }
  }

  async startLogin({ panelUrl, username, password }) {
    const id = crypto.randomBytes(12).toString('hex');
    const session = new Session(id);
    this.sessions.set(id, session);
    this._scheduleExpiry(session);

    this._runLoginFlow(session, { panelUrl, username, password }).catch((err) => {
      session.status = STATUS.ERROR;
      session.error = err && err.message ? err.message : String(err);
    });

    return session;
  }

  async forwardInput(id, event) {
    const session = this.get(id);
    if (!session || !session.cdp) return { ok: false, reason: 'no_active_session' };

    const { width, height } = session.viewport;
    const x = Math.max(0, Math.min(width, Math.round((event.xPct ?? 0) * width)));
    const y = Math.max(0, Math.min(height, Math.round((event.yPct ?? 0) * height)));

    try {
      switch (event.type) {
        case 'mousemove':
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
          break;
        case 'mousedown':
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
          break;
        case 'mouseup':
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
          break;
        case 'click':
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y, button: 'none' });
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
          await session.cdp.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
          break;
        case 'keydown':
          await session.cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: event.key, text: event.key && event.key.length === 1 ? event.key : undefined });
          break;
        case 'keyup':
          await session.cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: event.key });
          break;
        default:
          return { ok: false, reason: 'unknown_event_type' };
      }
    } catch (err) {
      return { ok: false, reason: err.message };
    }

    return { ok: true };
  }

  async _runLoginFlow(session, { panelUrl, username, password }) {
    const browser = await this.getBrowser();
    const context = await browser.newContext({ viewport: session.viewport });
    session.context = context;

    // Best-effort stealth: reduces (but does not guarantee removal of) the odds hCaptcha
    // always forces a challenge purely because it detected an automated browser.
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    });

    const page = await context.newPage();
    session.page = page;

    const baseUrl = panelUrl.replace(/\/+$/, '');

    await page.goto(`${baseUrl}/admin`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[name="login"]', { timeout: 20000 });

    await page.fill('input[name="login"]', username);
    await page.fill('input[name="password"]', password);

    session.status = STATUS.CHECKING_CAPTCHA;
    await this._handleCaptchaIfPresent(session);

    if (session.status === STATUS.ERROR) return;

    session.status = STATUS.SUBMITTING;
    await page.click('button[type="submit"]');

    const outcome = await this._waitForLoginOutcome(page);

    if (outcome === 'two_factor') {
      session.status = STATUS.TWO_FACTOR_REQUIRED;
      session.message = 'Two-factor authentication is required for this account. Manual 2FA handling is not implemented yet.';
      return;
    }

    if (outcome === 'failed') {
      session.status = STATUS.LOGIN_FAILED;
      session.message = 'Incorrect username or password.';
      return;
    }

    if (outcome !== 'success') {
      session.status = STATUS.ERROR;
      session.error = 'Login did not resolve to a known state in time.';
      return;
    }

    session.status = STATUS.NAVIGATING;
    await page.goto(`${baseUrl}/admin/appearance/blog`, { waitUntil: 'domcontentloaded' });

    await this._waitForBlogPage(page);

    session.status = STATUS.BLOG_PAGE_READY;
    session.message = 'وارد صفحه بلاگ شدید';
  }

  // Locator#isVisible() reports current state only - it does not poll - so a brief manual
  // retry loop is needed to catch visibility that flips true a moment after the check starts
  // (e.g. the challenge iframe unhiding itself after the checkbox click's postMessage round-trip).
  async _pollVisible(locator, timeoutMs, intervalMs = 250) {
    const deadline = Date.now() + timeoutMs;
    do {
      if (await locator.isVisible().catch(() => false)) return true;
      await new Promise((r) => setTimeout(r, intervalMs));
    } while (Date.now() < deadline);
    return false;
  }

  async _handleCaptchaIfPresent(session) {
    const { page } = session;

    const checkboxIframe = page.frameLocator(`iframe[title="${CHECKBOX_IFRAME_TITLE}"]`);
    const hasCaptcha = await this._pollVisible(
      page.locator(`iframe[title="${CHECKBOX_IFRAME_TITLE}"]`).first(),
      4000,
    );

    if (!hasCaptcha) {
      // No captcha challenge rendered for this attempt at all.
      return;
    }

    try {
      await checkboxIframe.locator('#checkbox').click({ timeout: 5000 });
    } catch (err) {
      session.status = STATUS.ERROR;
      session.error = `Could not click the hCaptcha checkbox: ${err.message}`;
      return;
    }

    // Give hCaptcha a moment to either auto-pass or pop the interactive challenge.
    const challengeAppeared = await this._pollVisible(
      page.locator(`iframe[title="${CHALLENGE_IFRAME_TITLE}"]`).first(),
      4000,
    );

    if (!challengeAppeared) {
      return;
    }

    session.status = STATUS.AWAITING_CAPTCHA;
    await this._startScreencast(session);
    await this._waitForCaptchaResolution(session);
    await this._stopScreencast(session);
  }

  async _waitForCaptchaResolution(session) {
    const { page } = session;
    const deadline = Date.now() + 5 * 60 * 1000;

    while (Date.now() < deadline) {
      if (!this.sessions.has(session.id)) return; // session was removed/cancelled

      const stillVisible = await page
        .locator(`iframe[title="${CHALLENGE_IFRAME_TITLE}"]`)
        .first()
        .isVisible()
        .catch(() => false);

      if (!stillVisible) return; // solved (or dismissed) - challenge overlay closed

      await page.waitForTimeout(1000);
    }

    session.status = STATUS.ERROR;
    session.error = 'Timed out waiting for the captcha challenge to be solved.';
  }

  async _waitForLoginOutcome(page) {
    const result = await Promise.race([
      page
        .waitForSelector('input[name="login"]', { state: 'detached', timeout: 20000 })
        .then(() => 'left_login_form')
        .catch(() => null),
      page
        .getByText(/incorrect username or password/i)
        .waitFor({ timeout: 20000 })
        .then(() => 'failed')
        .catch(() => null),
      page
        .getByText(/enter 6-digit code/i)
        .waitFor({ timeout: 20000 })
        .then(() => 'two_factor')
        .catch(() => null),
    ]);

    if (result === 'left_login_form') return 'success';
    return result;
  }

  async _waitForBlogPage(page) {
    await Promise.race([
      page.getByRole('button', { name: /add new post/i }).waitFor({ timeout: 20000 }),
      page.waitForLoadState('networkidle', { timeout: 20000 }),
    ]).catch(() => {});
  }

  async _startScreencast(session) {
    if (session.screencastOn) return;

    const cdp = await session.context.newCDPSession(session.page);
    session.cdp = cdp;

    cdp.on('Page.screencastFrame', async (evt) => {
      session.frame = Buffer.from(evt.data, 'base64');
      try {
        await cdp.send('Page.screencastFrameAck', { sessionId: evt.sessionId });
      } catch (_) {
        // session may already be gone
      }
    });

    await cdp.send('Page.startScreencast', {
      format: 'jpeg',
      quality: 60,
      maxWidth: session.viewport.width,
      maxHeight: session.viewport.height,
    });

    session.screencastOn = true;
  }

  async _stopScreencast(session) {
    if (!session.screencastOn || !session.cdp) return;
    await session.cdp.send('Page.stopScreencast').catch(() => {});
    session.screencastOn = false;
  }
}

module.exports = { SessionManager, STATUS };
