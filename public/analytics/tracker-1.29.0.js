(function () {
  'use strict';

  if (window.__smmAnalyticsLoaded || navigator.doNotTrack === '1') return;
  window.__smmAnalyticsLoaded = true;

  var script = document.currentScript;
  var endpoint = (script && script.dataset.endpoint) || 'https://core.smm.plus/api/analytics/collect';
  var siteId = (script && script.dataset.site) || 'smm-plus';
  var requestedUserState = (script && script.dataset.userState) || 'guest';
  var userState = ['guest', 'authenticated', 'internal'].indexOf(requestedUserState) !== -1
    ? requestedUserState
    : 'guest';
  var queue = [];
  var flushTimer = null;
  var maxScroll = 0;
  var activeMs = 0;
  var lastTick = Date.now();
  var vitalValues = {};
  var vitalsSent = false;

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 3 | 8)).toString(16);
    });
  }

  function storageId(storage, key) {
    try {
      var value = storage.getItem(key);
      if (!value) {
        value = uuid();
        storage.setItem(key, value);
      }
      return value;
    } catch (_) {
      return uuid();
    }
  }

  var visitorId = storageId(window.localStorage, 'smm_analytics_visitor');
  var sessionId = storageId(window.sessionStorage, 'smm_analytics_session');

  function clean(value, limit) {
    if (value === null || value === undefined) return null;
    return String(value).replace(/\s+/g, ' ').trim().slice(0, limit || 255) || null;
  }

  function safePath(value) {
    var path = String(value || '/').split('?')[0].split('#')[0];
    var redactRemainder = false;
    var segments = path.split('/').map(function (segment) {
      if (!segment) return segment;
      if (redactRemainder) return ':redacted';
      if (/^(resetpassword|confirmemail|2fa)$/i.test(segment)) {
        redactRemainder = true;
        return segment.toLowerCase();
      }
      if (/^\d{4,}$/.test(segment) || /^[0-9a-f]{8}-[0-9a-f-]{27,}$/i.test(segment) || segment.length > 48) {
        return ':id';
      }
      return segment;
    }).join('/');

    return clean(path.charAt(0) === '/' ? segments : '/' + segments, 500) || '/';
  }

  function language() {
    var value = (document.documentElement.lang || '').toLowerCase().split('-')[0];
    if (value) return value.slice(0, 12);
    var match = location.pathname.match(/^\/(ru|tr|bp|ko|ar|es|th|vi|fr|zh|de|id|it|ja|pl|uk|fa)(?:\/|$)/i);
    return match ? match[1].toLowerCase() : 'en';
  }

  function campaignValue(value, limit) {
    var result = clean(value, limit);
    if (!result || /@/.test(result) || /(?:^|\D)\+?\d[\d\s().-]{7,}\d(?:\D|$)/.test(result)) return null;
    return result;
  }

  function pageType() {
    var path = location.pathname.toLowerCase();
    if (/404|not found/i.test(document.title) || document.body && document.body.querySelector('.page-title') && document.body.querySelector('.page-title').textContent.trim() === '404') return '404';
    if (path.indexOf('/blog/') !== -1) return 'blog_post';
    if (/\/blog\/?$/.test(path)) return 'blog_index';
    if (path.indexOf('free-') !== -1 || path.indexOf('/free-services') !== -1) return 'free_service';
    if (path.indexOf('/services') !== -1 || path.indexOf('telegram-') !== -1) return 'service';
    if (path.indexOf('/signup') !== -1) return 'signup';
    if (path === '/' || /^\/[a-z]{2}\/?$/.test(path)) return 'home';
    return 'other';
  }

  function traffic() {
    var params = new URLSearchParams(location.search);
    var campaign = campaignValue(params.get('utm_campaign'), 255);
    var source = campaignValue(params.get('utm_source'), 100);
    var medium = campaignValue(params.get('utm_medium'), 100);
    var referrerHost = null;

    if (document.referrer) {
      try { referrerHost = new URL(document.referrer).hostname.replace(/^www\./, ''); } catch (_) {}
    }

    if (!source) {
      if (!referrerHost || referrerHost === location.hostname.replace(/^www\./, '')) {
        source = 'direct';
        medium = medium || 'none';
      } else if (/google\.|bing\.|yahoo\.|duckduckgo\.|yandex\./i.test(referrerHost)) {
        source = referrerHost.split('.')[0];
        medium = medium || 'organic';
      } else if (/t\.me|telegram|facebook|instagram|twitter|x\.com|youtube|linkedin/i.test(referrerHost)) {
        source = referrerHost;
        medium = medium || 'social';
      } else {
        source = referrerHost;
        medium = medium || 'referral';
      }
    }

    return { source: source, medium: medium, campaign: campaign, referrer_host: referrerHost };
  }

  function sessionAttribution() {
    try {
      var stored = sessionStorage.getItem('smm_analytics_attribution');
      if (stored) return JSON.parse(stored);
      var value = traffic();
      sessionStorage.setItem('smm_analytics_attribution', JSON.stringify(value));
      return value;
    } catch (_) {
      return traffic();
    }
  }

  var attribution = sessionAttribution();
  var isLandingPage = false;
  try {
    isLandingPage = sessionStorage.getItem('smm_analytics_has_pageview') !== '1';
    sessionStorage.setItem('smm_analytics_has_pageview', '1');
  } catch (_) {
    isLandingPage = true;
  }

  function baseEvent(name) {
    return {
      event_id: uuid(),
      visitor_id: visitorId,
      session_id: sessionId,
      event_name: name,
      page_path: safePath(location.pathname),
      page_title: userState === 'guest' ? clean(document.title, 255) : null,
      page_type: pageType(),
      language: language(),
      referrer_host: attribution.referrer_host,
      source: attribution.source,
      medium: attribution.medium,
      campaign: attribution.campaign,
      device_type: innerWidth < 768 ? 'mobile' : (innerWidth < 1024 ? 'tablet' : 'desktop'),
      user_state: userState,
      viewport_width: Math.min(65535, Math.max(0, innerWidth || 0)),
      occurred_at: new Date().toISOString()
    };
  }

  function track(name, data, immediate) {
    var event = baseEvent(name);
    data = data || {};
    Object.keys(data).forEach(function (key) {
      if (data[key] !== undefined) event[key] = data[key];
    });
    queue.push(event);

    if (immediate || queue.length >= 10) flush(immediate);
    else if (!flushTimer) flushTimer = setTimeout(flush, 3000);
  }

  function flush(useBeacon) {
    if (flushTimer) clearTimeout(flushTimer);
    flushTimer = null;
    if (!queue.length) return;

    var events = queue.splice(0, 25);
    var body = JSON.stringify({ site_id: siteId, events: events });

    if (useBeacon && navigator.sendBeacon) {
      var sent = navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain;charset=UTF-8' }));
      if (sent) return;
    }

    fetch(endpoint, {
      method: 'POST',
      mode: 'cors',
      credentials: 'omit',
      keepalive: true,
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body: body
    }).catch(function () {});
  }

  function updateActiveTime() {
    var now = Date.now();
    if (document.visibilityState === 'visible' && (!document.hasFocus || document.hasFocus())) {
      activeMs += Math.min(2000, now - lastTick);
    }
    lastTick = now;
  }

  function updateScroll() {
    var root = document.documentElement;
    var total = Math.max(root.scrollHeight, document.body ? document.body.scrollHeight : 0) - innerHeight;
    var depth = total <= 0 ? 100 : Math.round((scrollY / total) * 100);
    maxScroll = Math.max(maxScroll, Math.min(100, Math.max(0, depth)));
  }

  function clickTarget(element) {
    // Never capture arbitrary visible text: authenticated pages can render names, balances,
    // ticket subjects, or other personal data inside a clicked element.
    return clean(element.getAttribute('data-analytics-label') || element.id || element.tagName, 160);
  }

  function classifyConversion(element, href, label) {
    var value = ((href || '') + ' ' + (label || '')).toLowerCase();
    if (/signup|sign up|register|ثبت.?نام/.test(value)) return 'signup_click';
    if (/free-service|free service|free trial/.test(value)) return 'free_service_click';
    if (/neworder|new-order|order now|buy now|شروع سفارش/.test(value)) return 'order_start';
    if (/login|log in|sign in|ورود/.test(value)) return 'login_click';
    return null;
  }

  document.addEventListener('click', function (event) {
    var element = event.target.closest && event.target.closest('a,button,[data-analytics-event]');
    if (!element) return;

    var href = element.href || element.getAttribute('data-href') || '';
    var label = clickTarget(element);
    var conversion = element.getAttribute('data-analytics-conversion') || classifyConversion(element, href, label);
    if (conversion) track('conversion', { target: clean(conversion, 500), metadata: { label: label } });

    if (element.matches('[data-analytics-event]')) {
      track('conversion', { target: clean(element.getAttribute('data-analytics-event'), 500), metadata: { label: label } });
      return;
    }

    if (!href) return;
    try {
      var url = new URL(href, location.href);
      if (url.origin === location.origin) track('internal_click', { target: safePath(url.pathname), metadata: { label: label } });
      else track('outbound_click', { target: clean(url.hostname, 255), metadata: { label: label } });
    } catch (_) {}
  }, { passive: true });

  window.addEventListener('error', function (event) {
    var errorPath = 'inline';
    try { if (event.filename) errorPath = new URL(event.filename, location.href).pathname; } catch (_) {}
    track('js_error', {
      target: clean(errorPath, 500),
      // Error messages can echo user input or tokens. The asset path and line are enough to
      // group failures without sending the message itself.
      metadata: { line: event.lineno || null }
    });
  });

  function observeVitals() {
    if (!window.PerformanceObserver) return;
    try {
      new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (entries.length) vitalValues.LCP = entries[entries.length - 1].startTime;
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (_) {}

    try {
      vitalValues.CLS = 0;
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (!entry.hadRecentInput) vitalValues.CLS += entry.value;
        });
      }).observe({ type: 'layout-shift', buffered: true });
    } catch (_) {}

    try {
      vitalValues.INP = 0;
      new PerformanceObserver(function (list) {
        list.getEntries().forEach(function (entry) {
          if (entry.interactionId && entry.duration > vitalValues.INP) vitalValues.INP = entry.duration;
        });
      }).observe({ type: 'event', buffered: true, durationThreshold: 40 });
    } catch (_) {}

    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    if (nav) vitalValues.TTFB = nav.responseStart;
    var paints = performance.getEntriesByType && performance.getEntriesByType('paint');
    if (paints) paints.forEach(function (entry) { if (entry.name === 'first-contentful-paint') vitalValues.FCP = entry.startTime; });
  }

  function sendVitals() {
    if (vitalsSent) return;
    vitalsSent = true;
    Object.keys(vitalValues).forEach(function (name) {
      var value = vitalValues[name];
      if (typeof value === 'number' && isFinite(value) && value >= 0) {
        track('web_vital', { target: name, metric_value: Math.round(value * 10000) / 10000 });
      }
    });
  }

  track('page_view', { is_landing: isLandingPage });
  observeVitals();
  updateScroll();
  setInterval(updateActiveTime, 1000);
  addEventListener('scroll', updateScroll, { passive: true });
  setTimeout(sendVitals, 10000);

  addEventListener('pagehide', function () {
    updateActiveTime();
    sendVitals();
    track('engagement', { duration_ms: Math.round(activeMs), scroll_depth: maxScroll }, true);
    flush(true);
  });

  window.smmAnalytics = {
    track: function (name, data) { track(name, data || {}); },
    conversion: function (name, data) {
      data = data || {};
      data.target = clean(name, 500);
      track('conversion', data);
    },
    video: function (name, percent) {
      track('video', { target: clean(name, 500), metric_value: Math.max(0, Math.min(100, Number(percent) || 0)) });
    }
  };
})();
