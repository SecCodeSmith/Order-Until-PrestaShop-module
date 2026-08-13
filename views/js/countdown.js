/**
 * SCM Order Until — client-side delivery countdown.
 *
 * The BROWSER calls the FastAPI service directly (or the module's PHP proxy in
 * "server" mode), so PrestaShop does no per-request work. It then renders a
 * green "Available, order by HH:MM, delivery {when}" box with a live countdown.
 *
 * All day/date wording comes from window.scmou.labels, which PrestaShop fills in
 * with the CURRENT page language's translations (see Scmorderuntil::phraseLabels).
 * This is what lets a multi-language shop render each language correctly — the JS
 * itself is language-agnostic.
 *
 * window.scmou = {
 *   callMode, endpoint, apiBase, ajaxUrl, cutoff, offset, locale, refresh,
 *   templates:{open,closed}, countdownLabel, apiKey?,
 *   labels:{ today, tomorrow, days, weekdays[7], defaultOpen, defaultClosed }
 * }
 */
(function () {
  'use strict';

  var CFG = window.scmou || {};

  // Language-neutral fallbacks; PrestaShop overrides these per page language.
  var L = merge({
    today: 'today',
    tomorrow: 'tomorrow',
    days: 'd',
    weekdays: ['on Sunday', 'on Monday', 'on Tuesday', 'on Wednesday',
      'on Thursday', 'on Friday', 'on Saturday'],
    defaultOpen: 'Available, order by {cutoff}, delivery {when}*',
    defaultClosed: 'Available, order before {shipwhen} {cutoff}*'
  }, CFG.labels || {});

  function merge(base, over) {
    for (var k in over) {
      if (Object.prototype.hasOwnProperty.call(over, k)
        && over[k] !== null && over[k] !== undefined && over[k] !== '') {
        base[k] = over[k];
      }
    }
    return base;
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function parseDate(s) {
    var p = String(s).split('-');
    return new Date(+p[0], +p[1] - 1, +p[2]);
  }

  function formatRemaining(ms) {
    var total = Math.max(0, Math.floor(ms / 1000));
    var days = Math.floor(total / 86400);
    var h = Math.floor((total % 86400) / 3600);
    var m = Math.floor((total % 3600) / 60);
    var s = total % 60;
    var hms = pad(h) + ':' + pad(m) + ':' + pad(s);
    return days > 0 ? days + (L.days || 'd') + ' ' + hms : hms;
  }

  // A human phrase for a date relative to "today": today / tomorrow / weekday.
  // allowToday distinguishes the ship day (can be today) from the delivery day.
  function dayPhrase(est, dateStr, allowToday) {
    if (!dateStr) { return ''; }
    var diff = Math.round(
      (parseDate(dateStr) - parseDate(est.today)) / 86400000
    );
    if (allowToday && diff <= 0) {
      return L.today;
    }
    if (diff <= 1) {
      return L.tomorrow;
    }
    return (L.weekdays || [])[parseDate(dateStr).getDay()] || '';
  }

  function whenPhrase(est) {
    return dayPhrase(est, est.delivery_date, false);
  }

  function shipWhenPhrase(est) {
    return dayPhrase(est, est.ship_date, true);
  }

  function ddmm(s) {
    var p = String(s).split('-');
    return p[2] + '.' + p[1];
  }

  function buildText(est) {
    var open = est.order_before_cutoff_available;
    var tpl = (open
      ? (CFG.templates && CFG.templates.open)
      : (CFG.templates && CFG.templates.closed))
      || (open ? L.defaultOpen : L.defaultClosed) || '';
    return tpl
      .replace(/\{cutoff\}/g, est.cutoff_time || '')
      .replace(/\{shipwhen\}/g, shipWhenPhrase(est))
      .replace(/\{when\}/g, whenPhrase(est))
      .replace(/\{delivery\}/g, ddmm(est.delivery_date));
  }

  function localeOf(el) {
    return el.getAttribute('data-locale') || CFG.locale || 'en';
  }

  function buildUrl(el) {
    if (CFG.callMode === 'server') {
      return CFG.ajaxUrl;
    }
    var base = (CFG.apiBase || '').replace(/\/+$/, '');
    var q = ['locale=' + encodeURIComponent(localeOf(el))];
    var cutoff = el.getAttribute('data-cutoff') || CFG.cutoff;
    var offset = el.getAttribute('data-offset') || CFG.offset;
    if (cutoff) { q.push('cutoff=' + encodeURIComponent(cutoff)); }
    if (offset) { q.push('delivery_offset=' + encodeURIComponent(offset)); }
    return base + CFG.endpoint + '?' + q.join('&');
  }

  function fetchEstimate(el, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', buildUrl(el), true);
    xhr.setRequestHeader('Accept', 'application/json');
    if (CFG.callMode !== 'server' && CFG.apiKey) {
      xhr.setRequestHeader('X-API-Key', CFG.apiKey);
    }
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) { return; }
      if (xhr.status >= 200 && xhr.status < 300) {
        try { cb(JSON.parse(xhr.responseText)); return; } catch (e) { /* noop */ }
      }
      cb(null);
    };
    xhr.send();
  }

  function updateBadge(el, badge) {
    var deadline = Date.parse(el.getAttribute('data-deadline'));
    if (isNaN(deadline)) { return true; }
    var remaining = deadline - Date.now();
    var label = CFG.countdownLabel || '';
    badge.textContent = (label ? label + ' ' : '') + formatRemaining(remaining);
    return remaining <= 0;
  }

  function render(el, est) {
    if (!est) { return; } // fail silent: leave the box hidden
    var available = !!est.order_before_cutoff_available;

    el.setAttribute('data-deadline', est.countdown_deadline || '');
    el.classList.remove('scmou-loading');
    el.classList.toggle('scmou-open', available);
    el.classList.toggle('scmou-closed', !available);
    el.removeAttribute('hidden');

    var textEl = el.querySelector('[data-text]');
    if (textEl) { textEl.textContent = buildText(est); }

    // Always show the countdown: open -> today's cutoff; closed -> next deadline.
    var badge = el.querySelector('[data-countdown]');
    if (badge) {
      badge.removeAttribute('hidden');
      updateBadge(el, badge);
    }
  }

  function start(el) {
    fetchEstimate(el, function (est) {
      render(el, est);
      if (!est) { return; }
      setInterval(function () {
        var badge = el.querySelector('[data-countdown]');
        var expired;
        if (badge) {
          expired = updateBadge(el, badge);
        } else {
          var dl = Date.parse(el.getAttribute('data-deadline'));
          expired = !isNaN(dl) && (dl - Date.now()) <= 0;
        }
        // When the deadline passes, refetch so the widget rolls to the next
        // order window (open<->closed and the new deadline) without a reload.
        if (expired && CFG.refresh && !el.__scmouBusy) {
          el.__scmouBusy = true;
          fetchEstimate(el, function (fresh) {
            el.__scmouBusy = false;
            if (fresh) { render(el, fresh); }
          });
        }
      }, 1000);
    });
  }

  // Initialise every not-yet-processed widget. Safe to call repeatedly.
  function scan() {
    var widgets = document.querySelectorAll('[data-scmou]:not([data-scmou-init])');
    Array.prototype.forEach.call(widgets, function (el) {
      el.setAttribute('data-scmou-init', '1');
      start(el);
    });
  }

  // Catch widgets injected AFTER load (product quickview modal, AJAX lists, etc.).
  function observe() {
    if (!window.MutationObserver || !document.body) { return; }
    var pending = false;
    var mo = new MutationObserver(function () {
      if (pending) { return; }
      pending = true;
      setTimeout(function () { pending = false; scan(); }, 50);
    });
    mo.observe(document.body, { childList: true, subtree: true });
  }

  function boot() {
    scan();
    observe();
    if (window.prestashop && typeof window.prestashop.on === 'function') {
      ['updatedProduct', 'clickQuickView', 'updateProductList',
        'updatedProductCombination', 'updatedProductList'].forEach(function (ev) {
        try { window.prestashop.on(ev, scan); } catch (e) { /* noop */ }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
