<?php
/**
 * Публичные маршруты веб-чата: скрипт виджета, его API и приём вебхуков.
 *
 * Всё живёт под /chat/ и перехватывается хуком front.router.before, поэтому
 * ни шаблонов темы, ни правок nginx не нужно.
 */

namespace Plugins\Webchat;

class FrontChatController
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const RATE_MESSAGES = 60;         // сообщений
    private const RATE_MESSAGES_WINDOW = 600; // за 10 минут с одного IP
    private const RATE_THREADS = 10;          // новых обращений
    private const RATE_THREADS_WINDOW = 3600;

    private static ?Chat $chat = null;

    /**
     * Точка входа из init.php. Возвращает true, если запрос обработан.
     */
    public static function handle(string $path): bool
    {
        $path = trim($path, '/');

        if ($path === 'chat/widget') {
            // Адрес без расширения — сознательно. nginx отдаёт *.js/*.css статикой
            // (location ~* \.(css|js)$ → try_files), и PHP-маршрут с таким
            // именем до index.php не доходит. Здесь же работает обычный
            // location / → index.php, а MIME мы ставим сами.
            self::widgetScript();
            return true;
        }

        if (strpos($path, 'chat/api/') !== 0) {
            return false;
        }

        $action = substr($path, strlen('chat/api/'));

        try {
            $chat = self::chat();
        } catch (\Throwable $e) {
            self::json(['ok' => false, 'error' => 'чат недоступен'], 503);
            return true;
        }

        switch ($action) {
            case 'config':
                self::json(['ok' => true] + $chat->settings()->widgetConfig());
                return true;

            case 'start':
                self::start($chat);
                return true;

            case 'send':
                self::send($chat);
                return true;

            case 'poll':
                self::poll($chat);
                return true;

            case 'hook/max':
                self::webhook($chat, 'max');
                return true;

            case 'hook/telegram':
            case 'hook/tg': // короткий алиас: на него подписывались ранние сборки
                self::webhook($chat, 'telegram');
                return true;
        }

        self::json(['ok' => false, 'error' => 'неизвестное действие'], 404);
        return true;
    }

    // ---------------------------------------------------------------- действия

    private static function start(Chat $chat): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'error' => 'только POST'], 405);
            return;
        }

        $ip = self::clientIp();
        if (!self::rateOk('threads', $ip, self::RATE_THREADS, self::RATE_THREADS_WINDOW)) {
            self::json(['ok' => false, 'error' => 'слишком много обращений, попробуйте позже'], 429);
            return;
        }

        $input = self::input();
        $thread = $chat->createThread([
            'site' => $_SERVER['HTTP_HOST'] ?? '',
            'page_url' => self::cleanLine((string) ($input['page'] ?? ''), 500),
            'referrer' => self::cleanLine((string) ($input['ref'] ?? ''), 500),
            'user_agent' => self::cleanLine((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
            'ip_hash' => self::ipHash($ip),
            'visitor_name' => self::cleanLine((string) ($input['name'] ?? ''), 120),
            'visitor_contact' => self::cleanLine((string) ($input['contact'] ?? ''), 190),
        ]);

        if (!$thread) {
            self::json(['ok' => false, 'error' => 'не удалось создать обращение'], 500);
            return;
        }

        self::json([
            'ok' => true,
            'token' => $thread['token'],
            'thread' => (int) $thread['id'],
        ] + $chat->settings()->widgetConfig());
    }

    private static function send(Chat $chat): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'error' => 'только POST'], 405);
            return;
        }

        $input = self::input();
        $thread = $chat->findThreadByToken((string) ($input['token'] ?? ''));
        if (!$thread) {
            self::json(['ok' => false, 'error' => 'обращение не найдено', 'expired' => true], 404);
            return;
        }

        $text = self::cleanText((string) ($input['text'] ?? ''));
        if ($text === '') {
            self::json(['ok' => false, 'error' => 'пустое сообщение'], 400);
            return;
        }
        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_MESSAGE_LENGTH);
        }

        if (!self::rateOk('messages', self::clientIp(), self::RATE_MESSAGES, self::RATE_MESSAGES_WINDOW)) {
            self::json(['ok' => false, 'error' => 'слишком часто, подождите минуту'], 429);
            return;
        }

        $result = $chat->addVisitorMessage($thread, $text);

        self::json([
            'ok' => true,
            'message' => [
                'id' => $result['message']['id'],
                'direction' => 'in',
                'body' => $result['message']['body'],
                'created_at' => $result['message']['created_at'],
            ],
            'delivered' => $result['delivered'],
        ]);
    }

    private static function poll(Chat $chat): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $after = (int) ($_GET['after'] ?? 0);

        $thread = $chat->findThreadByToken($token);
        if (!$thread) {
            self::json(['ok' => false, 'error' => 'обращение не найдено', 'expired' => true], 404);
            return;
        }

        $messages = [];
        foreach ($chat->messages((int) $thread['id'], $after) as $row) {
            $messages[] = [
                'id' => (int) $row['id'],
                'direction' => $row['direction'],
                'body' => $row['body'],
                'author' => $row['author'],
                'created_at' => $row['created_at'],
            ];
        }

        self::json([
            'ok' => true,
            'messages' => $messages,
            'status' => $thread['status'],
        ]);
    }

    private static function webhook(Chat $chat, string $channelName): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'error' => 'только POST'], 405);
            return;
        }

        $secret = $chat->webhookSecret();
        if ($secret === '') {
            self::json(['ok' => false, 'error' => 'секрет вебхука не задан'], 500);
            return;
        }

        // Telegram присылает секрет заголовком, MAX — своим.
        $given = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']
            ?? $_SERVER['HTTP_X_MAX_BOT_API_SECRET']
            ?? '');
        if ($given === '' || !hash_equals($secret, $given)) {
            self::json(['ok' => false, 'error' => 'неверный секрет'], 403);
            return;
        }

        $raw = file_get_contents('php://input');
        $update = json_decode((string) $raw, true);
        if (!is_array($update)) {
            self::json(['ok' => false, 'error' => 'ожидался JSON'], 400);
            return;
        }

        try {
            $chat->handleUpdate($update, $channelName);
        } catch (\Throwable $e) {
            // Всегда отвечаем 200: иначе мессенджер повторяет событие 10 раз,
            // а ошибка всё равно наша, а не его.
            error_log('webchat: ошибка обработки вебхука — ' . $e->getMessage());
        }

        self::json(['ok' => true]);
    }

    // ---------------------------------------------------------------- помощники

    private static function chat(): Chat
    {
        if (self::$chat === null) {
            self::$chat = new Chat();
        }
        return self::$chat;
    }

    private static function asset(string $file, string $contentType): void
    {
        $path = dirname(__DIR__) . '/assets/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }

        $etag = '"' . md5((string) filemtime($path) . filesize($path)) . '"';
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=3600');
        header('ETag: ' . $etag);

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /**
     * Скрипт виджета одним файлом: CSS подставляется в него на лету.
     *
     * Такой ответ кешируется браузером по ETag, но не требует ни отдельного
     * запроса за стилями, ни правил в nginx для расширений .js/.css.
     */
    private static function widgetScript(): void
    {
        $dir = dirname(__DIR__) . '/assets/';
        $jsFile = $dir . 'widget.js';
        $cssFile = $dir . 'widget.css';

        if (!is_file($jsFile)) {
            http_response_code(404);
            exit;
        }

        $stamp = (string) @filemtime($jsFile) . '|' . (string) @filemtime($cssFile);
        $etag = '"' . md5($stamp) . '"';

        header('Content-Type: application/javascript; charset=utf-8');
        // Версия приходит в URL (?v=), поэтому длинный кеш безопасен.
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);

        // exit, а не return: если отдать управление обратно во FrontController,
        // он нарисует 404 и перетрёт статус. Браузер тогда видит 404 с пустым
        // телом и не грузит скрипт — виджет «пропадает» через раз.
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        $js = (string) file_get_contents($jsFile);
        $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
        $js = str_replace('__WC_CSS__', (string) json_encode($css), $js);

        header('Content-Length: ' . strlen($js));
        echo $js;
        exit;
    }

    private static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        // INVALID_UTF8_SUBSTITUTE: битая кодировка в настройке (например,
        // вставленный из чужой программы текст) не должна превращать весь
        // ответ в пустоту — json_encode без флага вернул бы false.
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private static function input(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    /** Многострочный текст: убираем управляющие символы, схлопываем пустые строки. */
    private static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        return trim($text);
    }

    /** Однострочное значение: без переводов строк и тегов. */
    private static function cleanLine(string $value, int $limit): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/\s{2,}/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $limit);
    }

    private static function clientIp(): string
    {
        // За nginx реальный адрес приходит в X-Real-IP / X-Forwarded-For.
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = (string) ($_SERVER[$key] ?? '');
            if ($value !== '') {
                $parts = explode(',', $value);
                return trim($parts[0]);
            }
        }
        return '0.0.0.0';
    }

    private static function ipHash(string $ip): string
    {
        // Сам адрес не храним: для антиспама хватает необратимого хеша,
        // а персональных данных в базе становится меньше.
        return substr(hash('sha256', $ip . '|webchat'), 0, 32);
    }

    /**
     * Простой лимит на файлах: окно + счётчик. Без Redis и без блокировок —
     * при нашем объёме обращений достаточно.
     */
    private static function rateOk(string $bucket, string $ip, int $limit, int $window): bool
    {
        $dir = STORAGE_PATH . '/cache/webchat';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return true; // нечем считать — не блокируем посетителя
        }

        $file = $dir . '/' . $bucket . '-' . substr(hash('sha256', $ip), 0, 16) . '.json';
        $now = time();
        $data = ['start' => $now, 'count' => 0];

        if (is_file($file)) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                $data = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
            }
        }

        if ($now - $data['start'] > $window) {
            $data = ['start' => $now, 'count' => 0];
        }

        $data['count']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $data['count'] <= $limit;
    }
}
