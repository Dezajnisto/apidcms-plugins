<?php
/**
 * Служебные адреса сообщества: отправка комментария и загрузка картинки.
 *
 * Отдельно от Forum, потому что это не страницы форума: комментарий уходит
 * с любой страницы сайта, а картинку грузит форма в любом месте.
 */

namespace Plugins\Community;

class Endpoint
{
    /** @return bool обработан ли запрос */
    public static function handle(string $path): bool
    {
        $path = trim($path, '/');

        if ($path === 'community/reply') {
            (new Comments())->reply();
            return true;
        }

        if ($path === 'community/upload') {
            self::upload();
            return true;
        }

        // Адреса без расширения — так же, как у виджета чата: nginx отдаёт
        // *.css и *.js статикой, и PHP-маршрут с таким именем до index.php
        // не доходит. MIME ставим сами.
        if ($path === 'community/css') {
            self::asset('community.css', 'text/css; charset=utf-8');
            return true;
        }

        if ($path === 'community/js') {
            self::asset('community.js', 'application/javascript; charset=utf-8');
            return true;
        }

        return false;
    }

    private static function asset(string $file, string $mime): void
    {
        $full = dirname(__DIR__) . '/assets/' . $file;

        if (!is_file($full)) {
            http_response_code(404);
            exit;
        }

        $etag = '"' . md5((string) filemtime($full) . filesize($full)) . '"';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit; // exit, а не return: иначе FrontController перетрёт статус на 404
        }

        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit;
    }

    private static function upload(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::json(['ok' => false, 'error' => 'Только POST'], 405);
        }

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            self::json(['ok' => false, 'error' => 'Форма устарела, обновите страницу'], 419);
        }

        $store = new Store();
        $guard = new Guard($store);
        $channel = $store->commentsChannel();
        $deny = $channel ? $guard->denyReason($channel) : 'Комментарии не настроены';
        if ($deny !== '') {
            self::json(['ok' => false, 'error' => $deny], 403);
        }

        // не больше 10 картинок за 10 минут с одной сессии
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $recent = $_SESSION['community_uploads'] ?? [];
        $recent = array_values(array_filter($recent, static fn($t) => $t > time() - 600));
        if (count($recent) >= 10) {
            self::json(['ok' => false, 'error' => 'Слишком много картинок подряд, подождите.'], 429);
        }

        $result = (new Upload())->save($_FILES['image'] ?? []);

        if (!$result['ok']) {
            self::json(['ok' => false, 'error' => $result['error'] ?? 'Не удалось загрузить'], 400);
        }

        $recent[] = time();
        $_SESSION['community_uploads'] = $recent;

        self::json(['ok' => true, 'url' => $result['url']]);
    }

    private static function json(array $data, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
