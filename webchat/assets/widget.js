/* Веб-чат apidcms — виджет посетителя.
 *
 * Без зависимостей и без CDN: скрипт отдаёт сам сайт (/chat/widget).
 * Всё живёт в Shadow DOM, поэтому CSS темы не ломает виджет и наоборот.
 * Ничего не опрашивается, пока посетитель не открыл чат.
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

  var KEY_TOKEN = 'apidcms_chat_token';
  var KEY_CONFIG = 'apidcms_chat_config';
  var KEY_SEEN = 'apidcms_chat_seen';
  var POLL_MS = 3000;
  var MAX_LEN = 2000;

  var state = {
    token: null,
    lastId: 0,
    seen: {},
    open: false,
    config: null,
    polling: false,
    sending: false,
    loading: false
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
  function loadJson(key) {
    var raw = load(key);
    if (!raw) { return null; }
    try { return JSON.parse(raw); } catch (e) { return null; }
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

  var style = document.createElement('style');
  style.textContent = CSS;
  root.appendChild(style);

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
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Чат с поддержкой');
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

  function clearMessages() {
    els.body.innerHTML = '';
    state.seen = {};
    state.lastId = 0;
  }

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
    if (typeof m.id === 'number' && m.id > state.lastId) {
      state.lastId = m.id;
      store(KEY_SEEN, String(m.id));
    }
  }

  function addNotice(text) {
    if (!text) { return; }
    // Не дублируем одинаковую плашку: при обрыве связи ошибка приходит
    // на каждую попытку отправки и заваливает окно.
    var prev = els.body.lastElementChild;
    if (prev && prev.className === 'wc-notice' && prev.textContent === text) { return; }

    var note = document.createElement('div');
    note.className = 'wc-notice';
    note.textContent = text;
    els.body.appendChild(note);
  }

  /**
   * Применить настройки. Вызывается сразу при загрузке (из кеша) и после
   * ответа сервера — поэтому цвет и заголовок видны с первого кадра, а не
   * после первого сообщения.
   */
  function applyConfig(config) {
    if (!config || typeof config !== 'object') { return; }
    state.config = config;

    if (config.title) { els.title.textContent = config.title; }
    els.status.textContent = config.online ? 'на связи' : 'не в сети';
    els.status.className = 'wc-status ' + (config.online ? 'wc-online' : 'wc-offline');

    if (config.color) { host.style.setProperty('--wc-color', config.color); }
    if (config.position === 'left') {
      host.classList.add('wc-left');
    } else {
      host.classList.remove('wc-left');
    }
  }

  function refreshConfig() {
    return api('config').then(function (res) {
      if (!res || res.ok === false) { return; }
      applyConfig(res);
      store(KEY_CONFIG, JSON.stringify(res));
    }).catch(function () { /* работаем на кеше */ });
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
      store(KEY_TOKEN, res.token);
      applyConfig(res);
      return res.token;
    });
  }

  function send(text) {
    if (state.sending || !text) { return; }
    state.sending = true;
    var btn = els.form.querySelector('.wc-send');
    if (btn) { btn.disabled = true; }

    ensureThread()
      .then(function () {
        return apiPost('send', { token: state.token, text: text });
      })
      .then(function (res) {
        if (res.expired) {
          drop(KEY_TOKEN);
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
      .then(function () {
        state.sending = false;
        if (btn) { btn.disabled = false; }
      });
  }

  /**
   * Забрать сообщения. after=0 — вся история (при открытии), иначе только новые.
   */
  function poll(after) {
    if (!state.token) { return Promise.resolve(); }
    var from = (typeof after === 'number') ? after : state.lastId;

    return api('poll?token=' + encodeURIComponent(state.token) + '&after=' + from)
      .then(function (res) {
        if (!res || !res.ok) {
          if (res && res.expired) {
            state.token = null;
            drop(KEY_TOKEN);
          }
          return;
        }
        var incoming = res.messages || [];
        var unseenOut = 0;

        incoming.forEach(function (m) {
          addMessage(m);
          if (m.direction === 'out' && !state.open) { unseenOut++; }
        });

        if (unseenOut > 0) { showBadge(unseenOut); }
      })
      .catch(function () { /* тихо: следующий опрос */ });
  }

  function startPolling() {
    if (state.polling) { return; }
    state.polling = true;
    setInterval(function () {
      if (state.open) { poll(); }
    }, POLL_MS);
  }

  function showBadge(count) {
    badge.hidden = false;
    badge.textContent = count > 1 ? String(count) : '1';
  }
  function hideBadge() {
    badge.hidden = true;
  }

  function open() {
    if (state.open) { return; }
    state.open = true;
    panel.hidden = false;
    bubble.hidden = true;   // иконка скрывается, пока окно открыто
    hideBadge();

    // История и приветствие — заново, чтобы не было дублей после переоткрытия.
    clearMessages();
    addNotice((state.config && state.config.greeting) || '');
    els.body.scrollTop = 0;

    if (!state.token) { state.token = load(KEY_TOKEN); }

    refreshConfig();

    if (state.token) {
      state.loading = true;
      poll(0).then(function () { state.loading = false; });
    } else {
      ensureThread()
        .then(function () { return poll(0); })
        .catch(function (err) {
          addNotice(err.message || 'Чат недоступен.');
        });
    }

    startPolling();
    els.input.focus();
  }

  function close() {
    state.open = false;
    panel.hidden = true;
    bubble.hidden = false;
  }

  // ------------------------------------------------------------------ события

  bubble.addEventListener('click', function () {
    if (state.open) { close(); } else { open(); }
  });
  els.close.addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && state.open) { close(); }
  });

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

  // ------------------------------------------------------------------ старт

  // Кеш настроек — чтобы цвет и заголовок были верными с первого кадра,
  // а не после ответа сервера.
  var cached = loadJson(KEY_CONFIG);
  if (cached) { applyConfig(cached); }

  refreshConfig();
  mount();

  // Токен уже есть → узнать, не ответил ли оператор, пока посетитель
  // ходил по другим страницам. Один запрос на загрузку страницы.
  state.token = load(KEY_TOKEN);
  if (state.token) {
    var seenId = parseInt(load(KEY_SEEN) || '0', 10) || 0;
    api('poll?token=' + encodeURIComponent(state.token) + '&after=' + seenId)
      .then(function (res) {
        if (!res || !res.ok) {
          if (res && res.expired) { state.token = null; drop(KEY_TOKEN); }
          return;
        }
        var count = (res.messages || []).filter(function (m) { return m.direction === 'out'; }).length;
        if (count > 0) { showBadge(count); }
      })
      .catch(function () { /* молча */ });
  }
})();
