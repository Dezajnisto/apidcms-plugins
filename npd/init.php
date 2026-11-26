<?php
/**
 * Плагин npd — чеки самозанятого (НПД).
 *
 * Что делает: после оплаты регистрирует доход в сервисе ФНС «Мой налог»
 * (lknpd.nalog.ru) и отдаёт покупателю ссылку на чек. ЮKassa этого для
 * самозанятых не делает — по 422-ФЗ чек обязан сформировать сам продавец.
 *
 * Хуки:
 *   db.migrate             — таблица журнала чеков, ключ шифрования, секреты
 *   twig.init              — шаблоны плагина (админка и фронт)
 *   front.router.before    — /receipts (чеки в ЛК), /npd/income (API), /npd/tick (cron)
 *   admin.routes           — /admin/npd: журнал, вход по SMS, повтор, отмена
 *   admin.menu             — пункт меню со значком проблем
 *   admin.twig.init        — шаблоны админки
 *   admin.current_section  — подсветка пункта меню
 *   payments.confirmed     — постановка чека в очередь (любой платёжный шлюз)
 *
 * Наружу (для ботов и ручных продаж):
 *   POST /npd/income  — HMAC-подписанный запрос, синхронно возвращает ссылку
 */

use Core\PluginManager;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Автозагрузка классов плагина ===

spl_autoload_register(function ($class) use ($pluginDir) {
    $prefix = 'Plugins\\Npd\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    foreach ([$pluginDir . '/src/', $pluginDir . '/controllers/'] as $base) {
        $file = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// === Миграции ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migration = $pluginDir . '/migrations/001_init.sql';
    if (is_file($migration)) {
        $sql = (string) file_get_contents($migration);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            try {
                $db->exec($statement);
            } catch (\Throwable $e) {
                error_log('npd: миграция не применилась — ' . $e->getMessage());
            }
        }
    }

    try {
        $settings = new \Plugins\Npd\Settings();

        // Ключ шифрования токенов. На проде config/ недоступен для записи —
        // тогда его ставит tools/make-key.php, а админка показывает команду.
        if (!\Plugins\Npd\Crypto::hasKey()) {
            $created = \Plugins\Npd\Crypto::createKeyFile();
            if (!$created['ok']) {
                error_log('npd: ключ шифрования не создан — ' . $created['error']);
            }
        }

        // Секреты нужны до первого платежа: иначе подпись API и cron-ключ
        // пришлось бы показывать как «пусто» и просить придумать вручную.
        $settings->apiSecret();
        $settings->tickKey();
        $settings->deviceId();
    } catch (\Throwable $e) {
        error_log('npd: инициализация настроек — ' . $e->getMessage());
    }
}, 10, 'npd');

// === Twig: пути к шаблонам ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'npd');
    }

    // FrontController держит Twig приватно — забираем ссылку здесь, чтобы
    // страница чеков рендерилась в теме сайта (см. src/Render.php).
    \Plugins\Npd\Render::bind($fc, $twig);
}, 10, 'npd');

// === Публичные маршруты ===

$pm->addAction('front.router.before', function ($path, $fc) use ($pluginDir) {
    $path = trim((string) $path, '/');
    if ($path === '') {
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    }

    // API для ботов и ручных продаж.
    if ($path === 'npd/income') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit;
        }
        \Plugins\Npd\Api::handle($fc);
        exit;
    }

    // Тик из cron: ключ в админке, подпись не нужна (только запуск очереди).
    if ($path === 'npd/tick') {
        $settings = new \Plugins\Npd\Settings();
        $key = (string) ($_GET['key'] ?? '');
        if ($key === '' || !hash_equals($settings->tickKey(), $key)) {
            http_response_code(403);
            echo 'forbidden';
            exit;
        }
        $db = PluginManager::getInstance()->getDatabase();
        if (!$db) {
            http_response_code(500);
            echo 'no database';
            exit;
        }
        $issuer = new \Plugins\Npd\Issuer($settings, new \Plugins\Npd\Ledger($db), new \Plugins\Npd\Nalog($settings));
        $result = $issuer->run(20);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Чеки покупателя в личном кабинете сайта.
    if ($path === 'receipts') {
        // На сайте без личных кабинетов странице некуда вести: плагин account
        // не установлен, /login отдаст 404. Лучше честный 404 сразу, чем
        // редирект в пустоту.
        if (!npd_account_plugin()) {
            http_response_code(404);
            echo 'Чеки доступны в личном кабинете — на этом сайте его нет';
            exit;
        }

        if (empty($_SESSION['user_id'])) {
            header('Location: /login?return=' . urlencode('/receipts'));
            exit;
        }

        $db = PluginManager::getInstance()->getDatabase();
        $rows = $db ? (new \Plugins\Npd\Ledger($db))->forUser((int) $_SESSION['user_id'], 100) : [];

        \Plugins\Npd\Render::page('@npd/receipts.html.twig', [
            'title' => 'Мои чеки',
            'receipts' => $rows,
        ]);
        exit;
    }

    // Ленивый тик: страховка на случай, когда cron не настроен или опаздывает.
    // Только GET, не чаще раза в 2 минуты, и уже после отправки страницы.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && strpos($path, 'npd/') !== 0) {
        $settings = new \Plugins\Npd\Settings();
        if ($settings->bool('enabled', false)) {
            $last = (string) $settings->get('last_tick', '');
            if ($last === '' || strtotime($last) < time() - 120) {
                $settings->save('last_tick', gmdate('c'));
                register_shutdown_function(function () use ($settings) {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    try {
                        $db = \Core\PluginManager::getInstance()->getDatabase();
                        if (!$db) {
                            return;
                        }
                        $issuer = new \Plugins\Npd\Issuer(
                            $settings,
                            new \Plugins\Npd\Ledger($db),
                            new \Plugins\Npd\Nalog($settings)
                        );
                        $issuer->run(5);
                    } catch (\Throwable $e) {
                        error_log('npd: ленивый тик — ' . $e->getMessage());
                    }
                });
            }
        }
    }
}, 15, 'npd');

// === Оплата подтверждена: поставить чек в очередь ===

$pm->addAction('payments.confirmed', function ($payment, $fc) {
    try {
        if (!is_array($payment)) {
            return;
        }

        $db = PluginManager::getInstance()->getDatabase();
        if (!$db) {
            return;
        }

        $paymentId = (string) ($payment['id'] ?? '');
        $amount = (string) ($payment['amount']['value'] ?? '');
        if ($paymentId === '' || !is_numeric($amount) || (float) $amount <= 0) {
            return;
        }

        $settings = new \Plugins\Npd\Settings();
        $ledger = new \Plugins\Npd\Ledger($db);

        $meta = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        $userId = (int) ($meta['user_id'] ?? 0);

        $email = trim((string) ($meta['email'] ?? ''));
        if ($email === '') {
            $email = $ledger->emailForUser($userId);
        }

        $ref = trim((string) ($meta['type'] ?? ''));
        foreach (['plan_slug', 'rate_id', 'order_id', 'ref'] as $key) {
            if (!empty($meta[$key])) {
                $ref .= ($ref !== '' ? ':' : '') . (string) $meta[$key];
            }
        }

        $isTestShop = npd_is_test_shop();

        $id = $ledger->enqueue([
            'source' => 'payment',
            'payment_id' => $paymentId,
            'ref' => $ref,
            'user_id' => $userId,
            'amount' => $amount,
            'description' => (string) ($payment['description'] ?? ''),
            'client_email' => $email,
            'operation_time' => npd_payment_time((string) ($payment['created_at'] ?? '')),
            'income_type' => (string) $settings->get('default_income_type', 'FROM_INDIVIDUAL'),
            'payment_type' => (string) $settings->get('payment_type', 'CASH'),
            'status' => $isTestShop ? \Plugins\Npd\Ledger::STATUS_SKIPPED : \Plugins\Npd\Ledger::STATUS_PENDING,
            'last_error' => $isTestShop ? 'тестовый магазин ЮKassa — чек в ФНС не нужен' : '',
        ]);

        if ($id === null) {
            return; // этот платёж уже в журнале
        }

        if ($isTestShop || !$settings->bool('enabled', false)) {
            return;
        }

        // Отвечаем ЮKassa сразу и только потом идём в ФНС: регистрация дохода
        // иногда занимает секунды, а повторный вебхук нам не нужен.
        http_response_code(200);
        echo 'OK';
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $issuer = new \Plugins\Npd\Issuer($settings, $ledger, new \Plugins\Npd\Nalog($settings));
        $row = $ledger->byId($id);
        if ($row) {
            $issuer->issue($row);
        }
    } catch (\Throwable $e) {
        error_log('npd: платёж не попал в журнал чеков — ' . $e->getMessage());
    }
}, 10, 'npd');

// === Админка: маршруты ===

$pm->addAction('admin.routes', function ($app) {
    $app->addRoute('/npd', 'Plugins\\Npd\\AdminNpdController', 'index');
    $app->addRoute('/npd/auth/start', 'Plugins\\Npd\\AdminNpdController', 'authStart');
    $app->addRoute('/npd/auth/verify', 'Plugins\\Npd\\AdminNpdController', 'authVerify');
    $app->addRoute('/npd/check', 'Plugins\\Npd\\AdminNpdController', 'check');
    $app->addRoute('/npd/run', 'Plugins\\Npd\\AdminNpdController', 'run');
    $app->addRoute('/npd/retry/{id}', 'Plugins\\Npd\\AdminNpdController', 'retry');
    $app->addRoute('/npd/cancel/{id}', 'Plugins\\Npd\\AdminNpdController', 'cancel');
    $app->addRoute('/npd/logout', 'Plugins\\Npd\\AdminNpdController', 'logout');
}, 10, 'npd');

// === Админка: пункт меню ===

$pm->addFilter('admin.menu', function ($items) {
    $badge = 0;
    try {
        $db = PluginManager::getInstance()->getDatabase();
        if ($db) {
            $badge = (new \Plugins\Npd\Ledger($db))->problemCount();
        }
    } catch (\Throwable $e) {
        $badge = 0;
    }

    $items[] = [
        'title' => 'Чеки НПД',
        'url' => '/admin/npd',
        'icon' => 'fas fa-receipt',
        'section' => 'npd',
        'badge' => $badge,
        'order' => 65,
    ];

    return $items;
}, 10, 'npd');

// === Админка: шаблоны и подсветка меню ===

$pm->addAction('admin.twig.init', function ($controller, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'npd');
    }
}, 10, 'npd');

$pm->addFilter('admin.current_section', function ($section, $path) {
    if (strpos((string) $path, '/npd') !== false) {
        return 'npd';
    }
    return $section;
}, 10, 'npd');

// === Вспомогательное ===

/** Установлен и включён плагин account (личный кабинет). */
function npd_account_plugin(): bool
{
    try {
        $plugin = PluginManager::getInstance()->getPlugin('account');
        return !empty($plugin['enabled']);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Тестовый магазин ЮKassa: чеки в ФНС по тестовым платежам не нужны.
 * Признак — тестовый секретный ключ шлюза (начинается с test_).
 */
function npd_is_test_shop(): bool
{
    try {
        $plugin = PluginManager::getInstance()->getPlugin('yookassa');
        foreach ($plugin['settings'] ?? [] as $setting) {
            if (($setting['key'] ?? '') === 'yookassa_secret_key') {
                return strpos((string) ($setting['value'] ?? ''), 'test_') === 0;
            }
        }
    } catch (\Throwable $e) {
        // Шлюза нет — значит и тестового магазина нет.
    }
    return false;
}

/** Время операции в формате ФНС (+03:00). */
function npd_payment_time(string $createdAt): string
{
    if ($createdAt !== '') {
        try {
            return \Plugins\Npd\Nalog::localIso(new \DateTimeImmutable($createdAt));
        } catch (\Throwable $e) {
            // ниже — текущее время
        }
    }
    return \Plugins\Npd\Nalog::localIso();
}
