# Плагин «Сообщество» — форум, комментарии, сообщества

Проект: плагин для apidcms. Один плагин `community`, один набор таблиц,
три режима работы. Версия-ориентир: ядро 2.8.0.

## 1. Откуда идея и что именно копируем

Референс — `rainlab/forum-plugin` для OctoberCMS. Главная находка там не
«форум», а приём с `embed_code`.

Тема (`topic`) в форуме — обычная запись с полем `embed_code`. Если это поле
пустое, тема живёт в форуме как тема. Если заполнено — тема привязана
к произвольной странице сайта, и создаётся она лениво, при первом рендере
страницы:

```php
// RainLab: models/Topic.php
public function scopeForEmbed($query, $channel, $code) {
    return $query->where('embed_code', $code)->where('channel_id', $channel->id);
}

public static function createForEmbed($code, $channel, $subject = null) {
    if (!$topic = self::forEmbed($channel, $code)->first()) {
        $topic = new self;
        $topic->subject = $subject;      // заголовок страницы
        $topic->embed_code = $code;      // идентификатор страницы
        $topic->channel = $channel;      // канал «Комментарии»
        $topic->save();
    }
    return $topic;
}
```

Из этого следует всё остальное: комментарии — это просто посты в теме,
у которой есть `embed_code`. Модерация, ответы, цитирование, подписка на
ответы, пагинация — общий код с форумом. Админке не нужно отдельной сущности
«комментарий».

Что берём из RainLab как есть (структура таблиц, проверенная практикой):

| Таблица | Роль |
|---|---|
| `channels` | разделы: `slug`, `title`, `parent_id` (дерево), `is_hidden`, `is_moderated`, счётчики |
| `topics` | темы: `slug`, `subject`, `channel_id`, `embed_code`, `is_sticky`, `is_locked`, `count_posts`, `count_views`, `last_post_at` |
| `posts` | сообщения: `topic_id`, `member_id`, `content`, `content_html`, `count_links` |
| `topic_followers` | кто подписан на ответы в теме |

Что меняем под apidcms:

- нет October-моделей → обычный SQL через `Core\Database`;
- нет компонентов → маршруты плагина + Twig-шаблоны, которые наследуют
  `base.html.twig` темы сайта (проверено: у всех 8 сайтов есть `{% block content %}`);
- нет `RainLab.User` → пользователи из плагина `account` (`plugin_account_users`);
- нет `Akismet` → свои фильтры (см. §6);
- добавляем режим «сообщество», которого в RainLab нет.

## 2. Три режима — один канал

Ключевая сущность — **канал**. У канала есть `mode`:

| `mode` | Что это | Кто создаёт темы | Где видно |
|---|---|---|---|
| `forum` | обычный раздел форума | пользователи | `/forum/<slug>` |
| `comments` | служебный канал для страниц сайта | автоматически, из страниц | под контентом страницы |
| `community` | сообщество: закрытый или по заявке раздел | участники | `/community/<slug>` |

Канал `comments` в списке форума не показывается (`is_hidden = 1`).
Остальное — общий код: тема, посты, модерация, подписки.

Так «каждая страница = тема» получается без отдельной подсистемы: страница
рендерится → плагин ищет тему по `embed_code` → нет темы, создаёт →
под контентом выводит посты этой темы и форму ответа.

Идентификаторы страниц (`embed_code`):

- `page:<id>` — статическая страница из таблицы `pages`;
- `nav:<url>` — страница из `navigation` (у неё может не быть записи в `pages`);
- `item:<table>:<id>` — динамический элемент (пост блога, товар каталога) — фаза 2.

## 3. Схема БД

Префикс `plugin_community_`, миграция `migrations/001_init.sql`.

```sql
CREATE TABLE plugin_community_channels (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id     INTEGER DEFAULT 0,
    slug          TEXT NOT NULL UNIQUE,
    title         TEXT NOT NULL,
    description   TEXT DEFAULT '',
    mode          TEXT NOT NULL DEFAULT 'forum',   -- forum | comments | community
    is_hidden     INTEGER DEFAULT 0,
    is_private    INTEGER DEFAULT 0,               -- читать только участникам
    is_moderated  INTEGER DEFAULT 0,               -- новые посты на проверку
    requires_join INTEGER DEFAULT 0,
    sort_order    INTEGER DEFAULT 0,
    count_topics  INTEGER DEFAULT 0,
    count_posts   INTEGER DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE plugin_community_topics (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id    INTEGER NOT NULL,
    slug          TEXT NOT NULL,
    subject       TEXT NOT NULL DEFAULT '',
    embed_code    TEXT DEFAULT NULL,               -- не NULL => тема-комментарии
    start_user_id INTEGER DEFAULT 0,
    is_sticky     INTEGER DEFAULT 0,
    is_locked     INTEGER DEFAULT 0,
    is_pending    INTEGER DEFAULT 0,
    count_posts   INTEGER DEFAULT 0,
    count_views   INTEGER DEFAULT 0,
    last_post_id  INTEGER DEFAULT 0,
    last_post_at  DATETIME DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (channel_id, slug)
);
CREATE INDEX idx_wc_topics_embed ON plugin_community_topics(embed_code);
CREATE INDEX idx_wc_topics_list  ON plugin_community_topics(channel_id, is_sticky DESC, last_post_at DESC);

CREATE TABLE plugin_community_posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id      INTEGER NOT NULL,
    user_id       INTEGER DEFAULT 0,               -- 0 = гость
    guest_name    TEXT DEFAULT '',
    guest_email   TEXT DEFAULT '',
    parent_id     INTEGER DEFAULT 0,               -- ответ на пост
    content       TEXT NOT NULL DEFAULT '',        -- markdown, как ввёл автор
    content_html  TEXT NOT NULL DEFAULT '',        -- отрендерено и почищено
    status        TEXT NOT NULL DEFAULT 'published', -- published | pending | spam | deleted
    count_links   INTEGER DEFAULT 0,
    ip_hash       TEXT DEFAULT '',                 -- sha256(ip + соль сайта), как в webchat
    edit_user_id  INTEGER DEFAULT 0,
    edited_at     DATETIME DEFAULT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_wc_posts_topic  ON plugin_community_posts(topic_id, created_at);
CREATE INDEX idx_wc_posts_status ON plugin_community_posts(status);

CREATE TABLE plugin_community_followers (
    topic_id   INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    notify     TEXT NOT NULL DEFAULT 'email',      -- email | none
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (topic_id, user_id)
);

-- фаза 2: участники сообществ
CREATE TABLE plugin_community_members (
    channel_id INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    role       TEXT NOT NULL DEFAULT 'member',     -- member | moderator
    status     TEXT NOT NULL DEFAULT 'active',     -- active | pending
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id)
);
```

Счётчики (`count_topics`, `count_posts`, `last_post_*`) — денормализация,
обновляются при публикации/удалении поста. Нужны, чтобы список каналов
и тем рисовался одним запросом.

## 4. Маршруты

Все маршруты плагина обрабатываются в `front.router.before` и **обязательно
завершаются `exit`** (иначе FrontController перетирает ответ — грабля из webchat).

| Метод | Путь | Что |
|---|---|---|
| GET | `/forum` | список каналов |
| GET | `/forum/<channel>` | темы канала, пагинация |
| GET | `/forum/<channel>/new` | форма новой темы |
| POST | `/forum/<channel>/new` | создание темы |
| GET | `/forum/<channel>/<topic>` | тема + посты |
| POST | `/forum/<channel>/<topic>/reply` | ответ |
| POST | `/community/reply` | ответ из блока комментариев на странице |
| GET | `/forum/rss.xml` | RSS последних тем (фаза 2) |
| GET | `/community/<slug>` | сообщество (фаза 2) |
| POST | `/community/<slug>/join` | вступить (фаза 2) |

Префикс — настройка плагина (`/forum` по умолчанию).

Шаблоны: `views/forum/channels.html.twig`, `channel.html.twig`, `topic.html.twig`,
`new_topic.html.twig`, `partials/post.html.twig`, `partials/form.html.twig`,
`comments.html.twig`. Все наследуют `base.html.twig` темы:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ topic.subject }} — {{ site_title }}{% endblock %}
{% block content %}
  ... список постов, пагинация, форма ...
{% endblock %}
```

Стили — один `assets/community.css`, подключается через `front.output`
(или `{% block scripts %}` темы). Никаких CDN и фреймворков: у webchat это
себя оправдало.

## 5. Встраивание комментариев в страницу

Хук `front.render` (данные до рендера). Если в `$data` есть `page` (страница)
или `post` (пост блога) и включены комментарии:

1. вычислить `embed_code` (`page:<id>` / `nav:<url>` / `item:<table>:<id>`);
2. найти или создать тему в канале `comments`;
3. отрендерить `comments.html.twig` и **дописать HTML к `$data['page']['content']`**.

Почему дописываем к контенту, а не вставляем в конец страницы через
`front.output`: контент печатается в `{% block content %}` темы, то есть
комментарии окажутся под текстом статьи, а не после футера. Работает на всех
темах, где печатается `page.content` (проверено на 8 сайтах), и не требует
править шаблоны.

Для тем, которые контент не печатают, есть запасной вариант — Twig-функция
`community_comments(page)`; вставляется в шаблон вручную.

Настройки встраивания (плагин, раздел «Комментарии»):

- `comments_enabled` — включить на сайте;
- `comments_channel` — канал, куда падают темы (по умолчанию `comments`);
- `comments_exclude` — список URL, где не показывать (по строке);
- `comments_guests` — разрешить гостям (с модерацией);
- `comments_per_page` — сколько показывать (по умолчанию 30, дальше «показать ещё»).

## 6. Права, антиспам, модерация

Роли:

| Кто | Читать | Писать | Модерировать |
|---|---|---|---|
| гость | публичные каналы | если `comments_guests`, всегда на проверку | нет |
| пользователь (`account`) | публичные + сообщества, где состоит | да | нет |
| модератор канала | всё в канале | да | закрепить, закрыть, удалить, одобрить |
| админ | всё | да | всё, включая каналы |

Проверки при отправке (по порядку, дёшево → дорого):

1. CSRF-токен формы (HMAC от сессии и времени, TTL 2 часа) — в ядре его нет, делаем свой;
2. honeypot-поле + минимальное время заполнения (3 с);
3. лимит по `ip_hash`: 5 сообщений / 10 мин, 3 новые темы / час;
4. длина: тема 3–150 символов, пост 2–20000; ссылок больше 2 → `pending`;
5. стоп-слова из настройки → `spam`;
6. первый пост нового пользователя в модерируемом канале → `pending`;
7. Markdown через `Parsedown::setSafeMode(true)` (как в ядре) + обрезка `javascript:`.

Ответы: `status = 'pending'` показывается автору с плашкой «на проверке»,
остальным — нет. `deleted` не удаляется физически (нужно для разбора жалоб).

Модерация в админке:

- `/admin/community/moderation` — очередь `pending`, кнопки «Опубликовать»,
  «Спам», «Удалить», ссылка на тему;
- generic-таблицы ядра (`/admin/table/plugin_community_channels` и т.д.)
  с `field_meta`/`table_meta` — названия полей по-русски;
- пункт меню «💬 Сообщество» (`admin.menu`, `order` 75).

## 7. Уведомления

Фаза 1: email.

- чекбокс «уведомлять об ответах» в форме ответа → запись в `followers`;
- при новом посте — письмо подписчикам темы (кроме автора), через
  `Core\EmailSender`, шаблон простой текстовый;
- письма шлются после ответа клиенту (не задерживаем), по одному на адресата,
  с заголовком `List-Unsubscribe`.

Фаза 2: то же в MAX/Telegram через `webchat` (общий транспорт уже есть),
дайджест раз в сутки вместо письма на каждый пост.

## 8. Что нужно от ядра

Всё уже есть в 2.8.0 — новых хуков не требуется:

- `front.router.before` — маршруты (с `exit`);
- `front.render` — встраивание комментариев и данных для шаблонов;
- `front.output` — подключение CSS/JS;
- `twig.init` — путь к шаблонам плагина, функции `community_comments()`, `is_logged_in()` уже есть от `account`;
- `admin.routes`, `admin.menu`, `admin.twig.init` — админка;
- `db.migrate` — таблицы;
- `PluginManager::getDatabase()`, `PluginSettings` — доступ к БД и настройкам.

Зависимость: `account` (пользователи). Если его нет — плагин работает
в режиме «только гости», писать может кто угодно с модерацией.

## 9. Этапы

**Фаза 1 — форум + комментарии (ядро задачи).**
Таблицы, каналы/темы/посты, страницы форума, встраивание комментариев
в страницы, форма ответа (POST → redirect, без JS), Markdown, модерация,
антиспам, админка, email-подписка. Проверка на стенде `dezajno.localhost:8080`,
затем на одном сайте прода.

**Фаза 2 — сообщества.** Приватные каналы, вступление/заявка, роли
модераторов, лента сообщества, участники.

**Фаза 3 — удобства.** Лайки, жалобы, вложения (картинки), поиск, RSS,
уведомления в MAX, «показать ещё» без перезагрузки, аватары.

## 10. Открытые вопросы (нужны ответы до кода)

1. **Гости.** Разрешать писать без регистрации? Предлагаю: в комментариях —
   да (имя обязательно, email не публикуется, всё на проверку), в форуме —
   только зарегистрированные.
2. **Сообщества.** Понимаю как «закрытый раздел со своими участниками и
   модераторами». Или это лента постов как в VK-сообществах?
3. **Вложения.** Картинки в постах нужны в фазе 1 или можно позже?
4. **Email.** Есть ли на сайтах рабочий SMTP (иначе подписка не нужна в фазе 1)?
5. **Первый сайт.** Где включаем первым: `bajto.ru` или витрина ядра
   `apidcms.dezajno.ru`?
