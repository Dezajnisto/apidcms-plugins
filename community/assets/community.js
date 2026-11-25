/**
 * Сообщество: мелочи, которые без JS неудобны, но без них страница работает.
 *
 *  1. Загрузка картинки: файл уходит на /community/upload, в текст вставляется
 *     markdown-ссылка. Без JS поле просто не показывается.
 *  2. «Ответить» на сообщение: подставляет номер в форму и ставит курсор.
 *  3. Кнопка отправки блокируется, чтобы не отправить дважды.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function csrfFrom(form) {
        var input = form.querySelector('input[name="_csrf"]');
        return input ? input.value : '';
    }

    function statusEl(form, text, isError) {
        var el = form.querySelector('.wcc-upload-status');
        if (!el) { return; }
        el.textContent = text || '';
        el.hidden = !text;
        el.classList.toggle('wcc-upload-status--error', !!isError);
    }

    ready(function () {
        // --- 1. картинки ---------------------------------------------------
        document.querySelectorAll('.wcc-form[data-upload]').forEach(function (form) {
            var endpoint = form.getAttribute('data-upload');
            var input = form.querySelector('input[type="file"]');
            var textarea = form.querySelector('textarea[name="content"]');
            if (!endpoint || !input || !textarea) { return; }

            var maxMb = parseInt(form.getAttribute('data-max') || '5', 10);

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) { return; }

                if (file.size > maxMb * 1024 * 1024) {
                    statusEl(form, 'Файл больше ' + maxMb + ' МБ', true);
                    input.value = '';
                    return;
                }

                var data = new FormData();
                data.append('image', file);
                data.append('_csrf', csrfFrom(form));

                statusEl(form, 'Загружаем…', false);

                fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Ошибка сервера' }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            statusEl(form, res.error || 'Не удалось загрузить', true);
                            return;
                        }

                        var markdown = '![](' + res.url + ')';
                        var at = textarea.selectionStart || textarea.value.length;
                        textarea.value = textarea.value.slice(0, at) + markdown + textarea.value.slice(at);
                        textarea.focus();
                        textarea.selectionStart = textarea.selectionEnd = at + markdown.length;
                        statusEl(form, 'Картинка добавлена', false);
                    })
                    .catch(function () {
                        statusEl(form, 'Не удалось загрузить картинку', true);
                    })
                    .finally(function () {
                        input.value = '';
                    });
            });
        });

        // --- 2. ответ на сообщение ----------------------------------------
        var parentField = document.getElementById('wcc-parent-id');
        var replyLink = document.querySelectorAll('a[href*="reply_to="]');

        replyLink.forEach(function (link) {
            link.addEventListener('click', function (event) {
                var match = /reply_to=(\d+)/.exec(link.getAttribute('href') || '');
                if (!match || !parentField) { return; }

                event.preventDefault();
                parentField.value = match[1];

                var textarea = document.querySelector('.wcc-reply textarea[name="content"]');
                if (textarea) {
                    textarea.focus();
                    textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    textarea.placeholder = 'Ответ на сообщение #' + match[1] + ' — напишите здесь';
                }
            });
        });

        // если страницу открыли по ссылке ?reply_to=N — подставить номер сразу
        if (parentField) {
            var fromUrl = /reply_to=(\d+)/.exec(window.location.search);
            if (fromUrl) { parentField.value = fromUrl[1]; }
        }

        // --- 3. защита от двойной отправки --------------------------------
        document.querySelectorAll('.wcc-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (!button) { return; }
                button.disabled = true;
                setTimeout(function () { button.disabled = false; }, 4000);
            });
        });
    });
})();
