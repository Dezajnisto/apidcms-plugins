<?php
/**
 * Плагин «Корзина» — init.php
 */

use Core\PluginManager;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Миграции ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migrationFile = $pluginDir . '/migrations/001_create_cart.sql';
    if (!file_exists($migrationFile)) return;
    $sql = file_get_contents($migrationFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try { $db->exec($stmt); }
            catch (\Exception $e) { error_log("Cart migration error: " . $e->getMessage()); }
        }


    // Schema verification: ensure all expected columns exist
    \$expectedColumns = [
        'plugin_cart_items' => [
            'id' => 'INTEGER',
            'user_id' => 'INTEGER',
            'session_id' => 'TEXT',
            'product_table' => 'TEXT',
            'product_id' => 'INTEGER',
            'quantity' => 'INTEGER',
            'added_at' => 'DATETIME'
        ]
    ];

    foreach (\$expectedColumns as \$table => \$columns) {
        try {
            \$existing = \$db->query("PRAGMA table_info(\"{\$table}\")")->fetchAll();
            \$existingNames = array_column(\$existing, 'name');
            foreach (\$columns as \$colName => \$colType) {
                if (!in_array(\$colName, \$existingNames)) {
                    \$db->exec("ALTER TABLE \"{\$table}\" ADD COLUMN \"{\$colName}\" {\$colType} DEFAULT ''");
                    error_log("Cart plugin: added missing column {\$table}.{\$colName}");
                }
            }
        } catch (\Exception \$e) {
            error_log("Cart plugin schema verification error for {\$table}: " . \$e->getMessage());
        }
    }

        }
    }
}, 10, 'cart');

// === Twig-функции ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'cart');
    }

    $twig->addFunction(new TwigFunction('cart_count', function () {
        $cart = $_SESSION['cart'] ?? [];
        return array_sum(array_column($cart, 'quantity'));
    }));

    $twig->addFunction(new TwigFunction('cart_total', function () {
        $cart = $_SESSION['cart'] ?? [];
        return array_sum(array_map(function($item) {
            return ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }, $cart));
    }));
}, 10, 'cart');

// === AJAX-эндпоинты ===

$pm->addAction('front.router.before', function ($path, $fc) use ($pluginDir) {
    if (!in_array($path, ['cart/add', 'cart/remove', 'cart/update', 'cart'])) return;

    require_once $pluginDir . '/controllers/CartController.php';

    if (session_status() === PHP_SESSION_NONE) session_start();

    // Генерируем или получаем session_id для гостевой корзины
    if (empty($_SESSION['cart_session_id'])) {
        $_SESSION['cart_session_id'] = bin2hex(random_bytes(16));
    }

    switch ($path) {
        case 'cart/add':
            header('Content-Type: application/json');
            echo json_encode(\Plugins\Cart\Controller::add($fc));
            exit;
        case 'cart/remove':
            header('Content-Type: application/json');
            echo json_encode(\Plugins\Cart\Controller::remove($fc));
            exit;
        case 'cart/update':
            header('Content-Type: application/json');
            echo json_encode(\Plugins\Cart\Controller::update($fc));
            exit;
        case 'cart':
            \Plugins\Cart\Controller::show($fc);
            exit;
    }
}, 25, 'cart');
