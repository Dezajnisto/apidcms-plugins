/* Веб-чат apidcms — виджет посетителя.
 *
 * Без зависимостей и без CDN: скрипт отдаёт сам сайт (/chat/widget.js).
 * Всё живёт в Shadow DOM, поэтому CSS темы не ломает виджет и наоборот.
 * Ничего не грузится и не опрашивается, пока посетитель не открыл чат.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  var base = '/chat/';
  if (script && script.src) {
    base = script.src.replace(/widget(\?.*)?$/, '');
    if (base.charAt(base.length - 1) !== '/') { base += '/'; }
  }

  // CSS подставляет сервер в ответ /chat/widget (см. FrontChatController).
  var CSS = __WC_CSS__;

  var STORAGE_KEY = 'apidcms_chat_token';
  var POLL_MS = 3000;
  var MAX_LEN = 2000;

  var state = {
    token: null,
    lastId: 0,
    seen: {},
    open: false,
    config: null,
    polling: false,
    sending: false
  };

  function store(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* приватный режим */ }
  }
  function load(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function drop(key) {
    try { window.localStorage.removeItem(key); } catch (e) { /* ignore */ }
  }

  function api(path) {
    return fetch(base + 'api/' + path, { credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }
  function apiPost(path, data) {
    return fetch(base + 'api/' + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data || {})
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
  }

  // ------------------------------------------------------------------ разметка

  var host = document.createElement('div');
  host.setAttribute('data-apidcms-chat', '');
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

  var link = document.createElement('style');
  link.textContent = CSS;
  root.appendChild(link);

  var bubble = document.createElement('button');
  bubble.type = 'button';
  bubble.className = 'wc-bubble';
  bubble.setAttribute('aria-label', 'Чат с поддержкой');
  bubble.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';

  var badge = document.createElement('span');
  badge.className = 'wc-badge';
  badge.hidden = true;
  bubble.appendChild(badge);

  var panel = document.createElement('div');
  panel.className = 'wc-panel';
  panel.hidden = true;
  panel.innerHTML =
    '<div class="wc-head">' +
      '<div class="wc-head-text"><div class="wc-title"></div><div class="wc-status"></div></div>' +
      '<button type="button" class="wc-close" aria-label="Закрыть">&times;</button>' +
    '</div>' +
    '<div class="wc-body"></div>' +
    '<form class="wc-form">' +
      '<textarea class="wc-input" rows="1" maxlength="' + MAX_LEN + '" placeholder="Напишите сообщение…"></textarea>' +
      '<button type="submit" class="wc-send" aria-label="Отправить">' +
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>' +
      '</button>' +
    '</form>';

  root.appendChild(panel);
  root.appendChild(bubble);

  var els = {
    title: panel.querySelector('.wc-title'),
    status: panel.querySelector('.wc-status'),
    body: panel.querySelector('.wc-body'),
    form: panel.querySelector('.wc-form'),
    input: panel.querySelector('.wc-input'),
    close: panel.querySelector('.wc-close')
  };

  function mount() {
    if (!document.body) { return setTimeout(mount, 50); }
    document.body.appendChild(host);
  }

  // ------------------------------------------------------------------ отрисовка

  function addMessage(m, scroll) {
    // Ответ на send и опрос приходят почти одновременно: без этого набора
    // одно и то же сообщение рисуется дважды (гонка in-flight запроса).
    if (m.id && state.seen[m.id]) {
      if (typeof m.id === 'number' && m.id > state.lastId) { state.lastId = m.id; }
      return;
    }
    if (m.id) { state.seen[m.id] = true; }

    var row = document.createElement('div');
    row.className = 'wc-msg ' + (m.direction === 'out' ? 'wc-out' : 'wc-in');
    var text = document.createElement('div');
    text.className = 'wc-text';
    text.textContent = m.body;
    row.appendChild(text);
    els.body.appendChild(row);
    if (scroll !== false) { els.body.scrollTop = els.body.scrollHeight; }
    if (typeof m.id === 'number' && m.id > state.lastId) { state.lastId = m.id; }
  }

  function addNotice(text) {
    var note = document.createElement('div');
    note.className = 'wc-notice';
    note.textContent = text;
    els.body.appendChild(note);
    els.body.scrollTop = els.body.scrollHeight;
  }

  function applyConfig(config) {
    state.config = config;
    els.title.textContent = config.title || 'Поддержка';
    els.status.textContent = config.online ? 'на связи' : 'не в сети';
    els.status.className = 'wc-status ' + (config.online ? 'wc-online' : 'wc-offline');

    if (config.color) {
      host.style.setProperty('--wc-color', config.color);
    }
    if (config.position === 'left') {
      host.classList.add('wc-left');
    }
  }

  // ------------------------------------------------------------------ действия

  function ensureThread() {
    if (state.token) {
      return Promise.resolve(state.token);
    }
    return apiPost('start', {
      page: location.href,
      ref: document.referrer || ''
    }).then(function (res) {
      if (!res.ok || !res.token) {
        throw new Error(res.error || 'не удалось начать чат');
      }
      state.token = res.token;
      store(STORAGE_KEY, res.token);
      applyConfig(res);
      if (res.greeting) { addNotice(res.greeting); }
      return res.token;
    });
  }

  function send(text) {
    if (state.sending || !text) { return; }
    state.sending = true;
    ensureThread()
      .then(function () {
        return apiPost('send', { token: state.token, text: text });
      })
      .then(function (res) {
        if (res.expired) {
          drop(STORAGE_KEY);
          state.token = null;
          return send(text);
        }
        if (!res.ok) {
          throw new Error(res.error || 'сообщение не отправлено');
        }
        if (res.message) { addMessage(res.message); }
      })
      .catch(function (err) {
        addNotice(err.message || 'Сеть недоступна. Попробуйте ещё раз.');
      })
      .then(function () { state.sending = false; });
  }

  function poll() {
    if (!state.token) { return; }
    api('poll?token=' + encodeURIComponent(state.token) + '&after=' + state.lastId)
      .then(function (res) {
        if (!res.ok) {
          if (res.expired) { state.token = null; drop(STORAGE_KEY); }
          return;
        }
        (res.messages || []).forEach(function (m) {
          addMessage(m);
          if (m.direction === 'out' && !state.open) { showBadge(); }
        });
      })
      .catch(function () { /* тихо: следующий опрос */ });
  }

  function startPolling() {
    if (state.polling) { return; }
    state.polling = true;
    poll();
    setInterval(function () {
      if (state.open) { poll(); }
    }, POLL_MS);
  }

  function showBadge() {
    badge.hidden = false;
    badge.textContent = '1';
  }
  function hideBadge() {
    badge.hidden = true;
  }

  function open() {
    state.open = true;
    panel.hidden = false;
    hideBadge();
    els.input.focus();

    if (!state.config) {
      api('config').then(function (res) {
        if (res && res.ok !== false) { applyConfig(res); }
      }).catch(function () { /* покажем дефолт */ });
    }

    if (!state.token) {
      state.token = load(STORAGE_KEY);
    }
    if (state.token) {
      // История с сервера: lastId=0 забирает всё, что было
      state.lastId = 0;
      poll();
    } else {
      ensureThread().catch(function (err) {
        addNotice(err.message || 'Чат недоступен.');
      });
    }
    startPolling();
  }

  function close() {
    state.open = false;
    panel.hidden = true;
  }

  // ------------------------------------------------------------------ события

  bubble.addEventListener('click', function () {
    if (state.open) { close(); } else { open(); }
  });
  els.close.addEventListener('click', close);

  els.form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = els.input.value.trim();
    if (!text) { return; }
    els.input.value = '';
    els.input.style.height = 'auto';
    send(text);
  });

  els.input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      els.form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });

  els.input.addEventListener('input', function () {
    els.input.style.height = 'auto';
    els.input.style.height = Math.min(els.input.scrollHeight, 120) + 'px';
  });

  mount();
})();
