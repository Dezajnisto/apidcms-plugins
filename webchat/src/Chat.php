<?php
/**
 * Ядро веб-чата: обращения, сообщения, доставка в канал.
 *
 * Одно обращение = один диалог посетителя. Посетитель знает только свой token
 * (случайные 32 hex в localStorage), оператор видит обращение в мессенджере.
 * Хранение — в БД сайта (SQLite), поэтому история остаётся у владельца сайта.
 */

namespace Plugins\Webchat;

use Plugins\Webchat\Channels\ChannelInterface;
use Plugins\Webchat\Channels\MaxChannel;
use Plugins\Webchat\Channels\TelegramChannel;

class Chat
{
    private \Core\Database $db;
    private Settings $settings;
    private ?ChannelInterface $channel = null;

    /**
     * Почему resolveThread() ничего не нашёл:
     *   empty  — привязать не к чему (в чате ещё нет обращений);
     *   foreign — сообщение относится к другому сайту на том же боте.
     * Нужен, чтобы не спамить подсказками в общий чат.
     */
    private string $resolveReason = 'empty';

    public function __construct(?\Core\Database $db = null, ?Settings $settings = null)
    {
        if ($db === null) {
            $pm = \Core\PluginManager::getInstance();
            $db = $pm->getDatabase();
            if (!$db) {
                throw new \RuntimeException('webchat: нет соединения с БД');
            }
        }
        $this->db = $db;
        $this->settings = $settings ?? new Settings();
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /** Канал доставки или null, если он не настроен. */
    public function channel(): ?ChannelInterface
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $channel = $this->settings->channel();
        $api = (string) $this->settings->get('api_base', '');
        $this->channel = $channel === 'telegram'
            ? new TelegramChannel((string) $this->settings->get('tg_token'), (string) $this->settings->get('tg_chat_id'), $api)
            : new MaxChannel(
                (string) $this->settings->get('max_token'),
                (string) $this->settings->get('max_chat_id'),
                $api,
                (string) $this->settings->get('max_read_chat_id', '')
            );

        return $this->channel;
    }

    /**
     * Бот обслуживает несколько сайтов? Результат кешируем на 10 минут:
     * проверка — это запрос к Bot API, а нужно это на каждом обращении.
     */
    public function sharedBot(): bool
    {
        $cached = (string) $this->settings->get('shared_bot', '');
        $at = (int) $this->settings->get('shared_bot_at', 0);
        if ($cached !== '' && $at > 0 && (time() - $at) < 600) {
            return $cached === '1';
        }

        $channel = $this->channel();
        $shared = $channel !== null && $channel->isShared();

        $this->settings->save('shared_bot', $shared ? '1' : '0');
        $this->settings->save('shared_bot_at', (string) time());

        return $shared;
    }

    /**
     * Метка сайта в номере обращения: example.com → example.
     *
     * В общем боте номера сайтов совпадают (у каждого свой #1), поэтому в
     * общем режиме в мессенджер уходит «#example-3», а не «#3».
     */
    public static function siteTag(string $host): string
    {
        $host = strtolower(trim($host));
        $host = (string) preg_replace('#^www\.#', '', $host);
        $label = explode('.', $host)[0] ?? '';
        $label = (string) preg_replace('/[^a-z0-9-]/', '', $label);

        return $label !== '' ? $label : 'site';
    }

    /** Метка ЭТОГО сайта (по домену, на который пришёл запрос). */
    private function ownTag(): string
    {
        return self::siteTag((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    public function channelReady(): bool
    {
        $channel = $this->channel();
        return $channel !== null && $channel->isReady();
    }

    // ---------------------------------------------------------------- обращения

    /**
     * Новое обращение. Возвращает строку обращения (включая token для виджета).
     */
    public function createThread(array $data): array
    {
        $token = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO plugin_webchat_threads
                (token, channel, site, page_url, referrer, user_agent, ip_hash, visitor_name, visitor_contact, status, unread_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 0, ?, ?)",
            [
                $token,
                (string) ($data['channel'] ?? $this->settings->channel()),
                mb_substr((string) ($data['site'] ?? ''), 0, 190),
                mb_substr((string) ($data['page_url'] ?? ''), 0, 500),
                mb_substr((string) ($data['referrer'] ?? ''), 0, 500),
                mb_substr((string) ($data['user_agent'] ?? ''), 0, 300),
                (string) ($data['ip_hash'] ?? ''),
                mb_substr((string) ($data['visitor_name'] ?? ''), 0, 120),
                mb_substr((string) ($data['visitor_contact'] ?? ''), 0, 190),
                $now,
                $now,
            ]
        );

        $thread = $this->findThreadByToken($token);
        return $thread ?? [];
    }

    public function findThreadByToken(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $row = $this->db->query("SELECT * FROM plugin_webchat_threads WHERE token = ?", [$token])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getThread(int $id): ?array
    {
        $row = $this->db->query("SELECT * FROM plugin_webchat_threads WHERE id = ?", [$id])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Последнее активное обращение — для ответов без явной привязки (MAX). */
    public function lastThreadId(): int
    {
        $value = (string) $this->settings->get('last_thread', '0');
        return (int) $value;
    }

    /**
     * @return array<int,array>
     */
    public function listThreads(string $status = '', string $search = '', int $limit = 100): array
    {
        $sql = "SELECT t.*,
                    (SELECT COUNT(*) FROM plugin_webchat_messages m WHERE m.thread_id = t.id) AS message_count,
                    (SELECT body FROM plugin_webchat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM plugin_webchat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_at
                FROM plugin_webchat_threads t";
        $params = [];
        $where = [];

        if ($status === 'open' || $status === 'closed') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(t.visitor_name LIKE ? OR t.visitor_contact LIKE ? OR t.page_url LIKE ? OR t.id = ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = (int) $search;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY COALESCE(last_at, t.created_at) DESC LIMIT ' . max(1, min(500, $limit));

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function unreadCount(): int
    {
        try {
            $row = $this->db->query("SELECT COUNT(*) AS c FROM plugin_webchat_threads WHERE unread_admin > 0 AND status = 'open'")->fetch(\PDO::FETCH_ASSOC);
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ---------------------------------------------------------------- сообщения

    /**
     * @return array<int,array>
     */
    public function messages(int $threadId, int $afterId = 0): array
    {
        return $this->db->query(
            "SELECT id, direction, body, author, created_at
               FROM plugin_webchat_messages
              WHERE thread_id = ? AND id > ?
              ORDER BY id ASC",
            [$threadId, $afterId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Сообщение посетителя: сохраняем и отправляем оператору в канал.
     *
     * @return array{message:array,delivered:bool,error:string}
     */
    public function addVisitorMessage(array $thread, string $text): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            "INSERT INTO plugin_webchat_messages (thread_id, direction, body, author, created_at)
             VALUES (?, 'in', ?, 'visitor', ?)",
            [(int) $thread['id'], $text, $now]
        );
        $messageId = (int) $this->db->getConnection()->lastInsertId();

        $this->db->query(
            "UPDATE plugin_webchat_threads
                SET unread_admin = unread_admin + 1, updated_at = ?, status = CASE WHEN status = 'closed' THEN 'open' ELSE status END
              WHERE id = ?",
            [$now, (int) $thread['id']]
        );

        $message = [
            'id' => $messageId,
            'thread_id' => (int) $thread['id'],
            'direction' => 'in',
            'body' => $text,
            'author' => 'visitor',
            'created_at' => $now,
        ];

        $delivery = $this->notifyOperator($thread, $message);

        return ['message' => $message, 'delivered' => $delivery['ok'], 'error' => $delivery['error']];
    }

    /**
     * Ответ оператора (из мессенджера или из админки).
     */
    public function addOperatorMessage(int $threadId, string $text, string $author = 'оператор'): array
    {
        $thread = $this->getThread($threadId);
        if (!$thread) {
            return ['ok' => false, 'error' => 'обращение не найдено'];
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO plugin_webchat_messages (thread_id, direction, body, author, created_at)
             VALUES (?, 'out', ?, ?, ?)",
            [$threadId, $text, mb_substr($author, 0, 120), $now]
        );

        $this->db->query(
            "UPDATE plugin_webchat_threads SET unread_admin = 0, updated_at = ? WHERE id = ?",
            [$now, $threadId]
        );
        $this->settings->save('last_thread', (string) $threadId);

        return ['ok' => true, 'error' => '', 'id' => (int) $this->db->getConnection()->lastInsertId()];
    }

    public function markRead(int $threadId): void
    {
        $this->db->query("UPDATE plugin_webchat_threads SET unread_admin = 0 WHERE id = ?", [$threadId]);
    }

    public function setStatus(int $threadId, string $status): void
    {
        $status = $status === 'closed' ? 'closed' : 'open';
        $this->db->query(
            "UPDATE plugin_webchat_threads SET status = ?, updated_at = ? WHERE id = ?",
            [$status, date('Y-m-d H:i:s'), $threadId]
        );
    }

    public function deleteThread(int $threadId): void
    {
        $this->db->query("DELETE FROM plugin_webchat_messages WHERE thread_id = ?", [$threadId]);
        $this->db->query("DELETE FROM plugin_webchat_threads WHERE id = ?", [$threadId]);
    }

    // ---------------------------------------------------------------- доставка

    /**
     * Отправить обращение оператору в канал.
     *
     * @return array{ok:bool,error:string}
     */
    public function notifyOperator(array $thread, array $message): array
    {
        $channel = $this->channel();
        if (!$channel || !$channel->isReady()) {
            return ['ok' => false, 'error' => 'канал не настроен'];
        }

        // Ветка (тема в Telegram) создаётся один раз — на первом сообщении.
        if ((string) ($thread['external_ref'] ?? '') === '') {
            $ref = $channel->openThread($thread);
            if ($ref !== '') {
                $this->db->query("UPDATE plugin_webchat_threads SET external_ref = ? WHERE id = ?", [$ref, (int) $thread['id']]);
                $thread['external_ref'] = $ref;
            }
        }

        $text = $this->formatForOperator($thread, $message);
        $result = $channel->send($thread, $text);
        if ($result['mid'] !== '') {
            $this->db->query(
                "UPDATE plugin_webchat_messages SET external_mid = ? WHERE id = ?",
                [$result['mid'], (int) $message['id']]
            );
        }

        if (!$result['ok']) {
            error_log('webchat: доставка оператору не удалась — ' . $result['error']);
        }

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    /** Текст обращения для мессенджера: шапка с номером, страницей и текстом. */
    public function formatForOperator(array $thread, array $message): string
    {
        $id = (int) ($thread['id'] ?? 0);
        $name = trim((string) ($thread['visitor_name'] ?? ''));
        $page = (string) ($thread['page_url'] ?? '');

        // Общий бот на несколько сайтов: без метки номер «#3» не говорит,
        // чьё это обращение — на каждом сайте свой третий.
        $number = $this->sharedBot()
            ? self::siteTag((string) ($thread['site'] ?? '')) . '-' . $id
            : (string) $id;

        $header = '#' . $number . ' · ' . ($name !== '' ? $name : 'Посетитель');
        if ($page !== '') {
            $header .= "\n" . mb_substr($page, 0, 200);
        }

        $body = (string) ($message['body'] ?? '');
        return $header . "\n———\n" . $body;
    }

    /**
     * Входящее событие из мессенджера → сообщение оператора в нужное обращение.
     *
     * @return array{ok:bool,thread_id:int,error:string}
     */
    public function handleUpdate(array $update, string $channelName): array
    {
        $channel = $this->channel();
        if (!$channel || $channel->name() !== $channelName) {
            return ['ok' => false, 'thread_id' => 0, 'error' => 'канал выключен'];
        }

        // MAX: бот добавлен в чат — запоминаем chat_id, чтобы не искать вручную.
        if (($update['update_type'] ?? '') === 'bot_added') {
            $chatId = (string) ($update['chat_id'] ?? '');
            if ($chatId !== '' && (string) $this->settings->get('max_chat_id') === '') {
                $this->settings->save('max_chat_id', $chatId);
            }
            return ['ok' => true, 'thread_id' => 0, 'error' => ''];
        }

        $parsed = $channel->parseUpdate($update);
        if (!$parsed || !empty($parsed['from_bot'])) {
            return ['ok' => false, 'thread_id' => 0, 'error' => 'не сообщение оператора'];
        }

        // В диалоге с ботом chat_id ≠ user_id, а для чтения истории нужен
        // именно chat_id. Узнаём его из любого события и запоминаем.
        $chatId = (string) ($parsed['chat_id'] ?? '');
        if ($channelName === 'max' && $chatId !== '' && (string) $this->settings->get('max_read_chat_id', '') !== $chatId) {
            $this->settings->save('max_read_chat_id', $chatId);
            $this->channel = null; // пересоздать канал с новым chat_id
        }

        // MAX: оператор написал боту в личку — запоминаем, куда слать
        // обращения. Иначе владельцу пришлось бы искать свой user_id руками.
        if ($channelName === 'max'
            && empty($parsed['from_bot'])
            && ($parsed['chat_type'] ?? '') === 'dialog'
            && (string) $this->settings->get('max_chat_id') === ''
            && (string) ($parsed['sender_id'] ?? '') !== ''
        ) {
            $this->settings->save('max_chat_id', (string) $parsed['sender_id']);
        }

        $shared = $this->sharedBot();
        $thread = $this->resolveThread($parsed, $shared);
        if (!$thread) {
            // В общем режиме сообщение часто адресовано другому сайту: его
            // разберёт тот сайт, у которого нашёлся mid. Подсказку в чат
            // тогда НЕ пишем — иначе на одно действие оператора прилетит по
            // подсказке от каждого сайта.
            if ($shared && $this->resolveReason !== 'empty') {
                return ['ok' => false, 'thread_id' => 0, 'error' => 'обращение другого сайта'];
            }

            // Молча терять сообщение оператора нельзя: он думает, что ответил.
            // Подсказываем, как привязать ответ, прямо в мессенджер.
            $channel->send(
                ['id' => 0, 'external_ref' => ''],
                $shared
                    ? 'Не понял, к какому обращению это относится. Ответьте на сообщение бота цитатой или начните с номера: #' . $this->ownTag() . '-12 ваш ответ'
                    : 'Не понял, к какому обращению это относится — обращений ещё нет. '
                        . 'Ответьте на сообщение бота (цитатой) или начните текст с номера: #12 ваш ответ.'
            );
            return ['ok' => false, 'thread_id' => 0, 'error' => 'обращение не найдено'];
        }

        $text = $this->stripThreadPrefix((string) $parsed['text']);
        if ($text === '') {
            return ['ok' => false, 'thread_id' => (int) $thread['id'], 'error' => 'пустой текст'];
        }

        $author = trim((string) ($parsed['author'] ?? '')) ?: 'оператор';
        $this->addOperatorMessage((int) $thread['id'], $text, $author);

        return ['ok' => true, 'thread_id' => (int) $thread['id'], 'error' => ''];
    }

    /**
     * Обращение по номеру из текста. В общем боте номер несёт метку сайта
     * («#example-12»), в обычном — просто «#12». Метка обязана совпасть с
     * доменом этого сайта, иначе номер относится к соседу.
     */
    private function threadByNumber(string $text, bool $shared): ?array
    {
        // Номер ищем в любом месте текста, а не только в начале: оператор
        // пишет «Да, это example-5» — и это тоже должно адресовать ответ.
        // Берём первое совпадение, у которого нашлось обращение: в общем боте
        // в тексте может быть упомянут и чужой сайт.
        if ($shared) {
            $own = preg_quote($this->ownTag(), '/');
            if (!preg_match_all('/#?(' . $own . ')-(\d{1,9})\b/ui', $text, $all, PREG_SET_ORDER)) {
                return null;
            }
            foreach ($all as $m) {
                $thread = $this->getThread((int) $m[2]);
                if ($thread) {
                    return $thread;
                }
            }
            return null;
        }

        if (preg_match_all('/#(\d{1,9})\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $thread = $this->getThread((int) $m[1]);
                if ($thread) {
                    return $thread;
                }
            }
        }

        return null;
    }

    /** Найти обращение по ответу, теме или префиксу «#12» / «#example-12». */
    private function resolveThread(array $parsed, bool $shared = false): ?array
    {
        $this->resolveReason = 'empty';

        // 1. Ответ (quote) на сообщение бота: mid → сообщение → обращение.
        //    Самый точный способ и единственный, который не зависит от того,
        //    сколько сайтов сидит на боте.
        $replyMid = (string) ($parsed['reply_mid'] ?? '');
        if ($replyMid !== '') {
            $row = $this->db->query(
                "SELECT thread_id FROM plugin_webchat_messages WHERE external_mid = ? LIMIT 1",
                [$replyMid]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['thread_id'])) {
                $thread = $this->getThread((int) $row['thread_id']);
                if ($thread) {
                    return $thread;
                }
            }
            // Цитата есть, а mid у нас нет — это сообщение другого сайта.
            $this->resolveReason = 'foreign';
        }

        $text = (string) ($parsed['text'] ?? '');

        // 1.5. Текст цитаты: в нём есть номер обращения («#example-12 · Иван»).
        //      Второй независимый путь — работает даже когда mid не совпал,
        //      а в общем боте ещё и отсекает чужие сайты по метке.
        $quoted = (string) ($parsed['reply_text'] ?? '');
        if ($quoted !== '') {
            $thread = $this->threadByNumber($quoted, $shared);
            if ($thread) {
                return $thread;
            }
            // Цитата есть, а номер в ней чужой. Дальше искать нельзя: резервный
            // путь «последнее сообщение бота» вернул бы СВОЁ обращение, и
            // ответ соседу утёк бы нам. Выходим сразу.
            if ($shared) {
                $this->resolveReason = 'foreign';
                return null;
            }
        }

        // 2. Явный номер в тексте. В общем режиме номер несёт метку сайта:
        //    «#example-12» — иначе «#3» совпадает у всех сайтов.
        $thread = $this->threadByNumber($text, $shared);
        if ($thread) {
            return $thread;
        }

        // Номер в тексте есть, но не наш (или обращение удалено). В общем боте
        // это значит, что оператор адресовал ответ соседу — молчим и выходим,
        // иначе резервный путь отправит ответ не туда.
        if ($shared && preg_match('/#?[a-z][a-z0-9-]*-\d{1,9}\b/ui', $text)) {
            $this->resolveReason = 'foreign';
            return null;
        }
        if (!$shared && preg_match('/#\d{1,9}\b/', $text)) {
            $this->resolveReason = 'foreign';
            return null;
        }

        // 3. Тема Telegram: id темы = внешняя ссылка обращения.
        $topicId = (string) ($parsed['topic_id'] ?? '');
        if ($topicId !== '') {
            $row = $this->db->query(
                "SELECT * FROM plugin_webchat_threads WHERE external_ref = ? ORDER BY id DESC LIMIT 1",
                [$topicId]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        // 4. Общий бот: оператор ответил без цитаты и без номера — адресат
        //    тот, чьё сообщение бота в чате последнее. Читаем чат и ищем mid
        //    этого сообщения у себя: найдётся ровно у одного сайта, и никто
        //    не получит чужой ответ.
        if ($shared) {
            $channel = $this->channel();
            $lastMid = $channel !== null ? $channel->lastBotMid() : '';
            if ($lastMid === '') {
                return null; // подсказка в чат: привязать не к чему
            }

            $row = $this->db->query(
                "SELECT thread_id FROM plugin_webchat_messages WHERE external_mid = ? LIMIT 1",
                [$lastMid]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['thread_id'])) {
                $thread = $this->getThread((int) $row['thread_id']);
                if ($thread) {
                    return $thread;
                }
            }

            $this->resolveReason = 'foreign';
            return null;
        }

        // 5. Последнее активное обращение — обычный сценарий для одного
        // оператора. Берём именно последнее ОБНОВЛЁННОЕ, а не запомненное
        // в настройках: настройка пишется только при ответе из админки, и
        // после первого же нового обращения ответ из мессенджера терялся.
        $row = $this->db->query(
            "SELECT * FROM plugin_webchat_threads WHERE status = 'open' ORDER BY updated_at DESC, id DESC LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        // 5. Совсем крайний случай — самое свежее обращение любого статуса.
        $lastId = $this->lastThreadId();
        if ($lastId > 0) {
            $thread = $this->getThread($lastId);
            if ($thread) {
                return $thread;
            }
        }

        return null;
    }

    private function stripThreadPrefix(string $text): string
    {
        // «#12 », «#example-12 » — номер обращения не должен попасть в текст.
        $text = preg_replace('/^#[a-z0-9-]*\d+\s*/ui', '', $text);
        return trim((string) $text);
    }

    // ---------------------------------------------------------------- вебхук

    /** Секрет вебхука: генерируется один раз, живёт в plugin_settings. */
    public function webhookSecret(): string
    {
        $secret = (string) $this->settings->get('webhook_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            $this->settings->save('webhook_secret', $secret);
        }
        return $secret;
    }

    public function webhookUrl(string $channelName): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/chat/api/hook/' . $channelName;
    }
}
