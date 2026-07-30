<?php
/**
 * Credits Plugin — init.php
 *
 * Uses universal payment hooks (payments.*):
 *   Filter:  payments.create_payment — gateways respond with payment URL
 *   Action:  payments.confirmed — payment succeeded (filters by metadata.type === 'credits')
 *   Action:  payments.return — user returned from bank page
 *
 * Own hooks:
 *   Filter:  credits.can_deduct     — default: check balance >= amount
 *   Action:  credits.balance_changed   — fired after any balance change
 *
 * Listens to:
 *   Action:  subscription.activated — bonus credits for plans with meta.credits_amount
 */

use Core\PluginManager;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Migrations ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migrationFile = $pluginDir . '/migrations/001_create.sql';
    if (!file_exists($migrationFile)) return;
    $sql = file_get_contents($migrationFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try { $db->exec($stmt); }
            catch (\Exception $e) { error_log("Credits migration error: " . $e->getMessage()); }
        }
    }

    // Schema verification: ensure all expected columns exist
    $expectedColumns = [
        'plugin_credits_user_balances' => [
            'user_id' => 'INTEGER',
            'balance' => 'INTEGER',
            'updated_at' => 'DATETIME'
        ],
        'plugin_credits_transactions' => [
            'id' => 'INTEGER',
            'user_id' => 'INTEGER',
            'amount' => 'INTEGER',
            'type' => 'TEXT',
            'description' => 'TEXT',
            'reference' => 'TEXT',
            'balance_after' => 'INTEGER',
            'created_at' => 'DATETIME'
        ]
    ];

    foreach ($expectedColumns as $table => $columns) {
        try {
            $existing = $db->query("PRAGMA table_info(\"{$table}\")")->fetchAll();
            $existingNames = array_column($existing, 'name');
            foreach ($columns as $colName => $colType) {
                if (!in_array($colName, $existingNames)) {
                    $db->exec("ALTER TABLE \"{$table}\" ADD COLUMN \"{$colName}\" {$colType} DEFAULT ''");
                    error_log("Credits plugin: added missing column {$table}.{$colName}");
                }
            }
        } catch (\Exception $e) {
            error_log("Credits plugin schema verification error for {$table}: " . $e->getMessage());
        }
    }
}, 10, 'credits');

// === Load controller ===

require_once $pluginDir . '/controllers/Service.php';

// === Twig: functions + template paths ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'credits');
    }
    $loader->addPath($pluginDir . '/views');

    $twig->addFunction(new TwigFunction('credits_balance', function () {
        if (empty($_SESSION['user_id'])) return '0 ' . \Plugins\Credits\Service::getSetting('credits_currency_name', 'кредитов');
        return \Plugins\Credits\Service::formatBalance(\Plugins\Credits\Service::getBalance((int)$_SESSION['user_id']));
    }));

    $twig->addFunction(new TwigFunction('credits_can_use', function (int $amount) {
        if (empty($_SESSION['user_id'])) return false;
        return \Plugins\Credits\Service::canDeduct((int)$_SESSION['user_id'], $amount);
    }));

    $twig->addFunction(new TwigFunction('credits_purchase_rates', function () {
        return \Plugins\Credits\Service::getPurchaseRates();
    }));

    $twig->addFunction(new TwigFunction('credits_purchase_enabled', function () {
        return (bool)\Plugins\Credits\Service::getSetting('credits_purchase_enabled', false);
    }));
}, 10, 'credits');

// === Routes ===

$pm->addAction('front.router.before', function ($path, $fc) {
    // Purchase page
    if ($path === 'credits/buy' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login?return=' . urlencode('/credits/buy'));
            exit;
        }

        if (!\Plugins\Credits\Service::getSetting('credits_purchase_enabled', false)) {
            http_response_code(404);
            echo 'Purchase disabled';
            return;
        }

        $rates = \Plugins\Credits\Service::getPurchaseRates();
        $balance = \Plugins\Credits\Service::getBalance((int)$_SESSION['user_id']);
        $currencyName = \Plugins\Credits\Service::getSetting('credits_currency_name', 'кредитов');

        // Render template
        $twig = $fc->getTwig();
        echo $twig->render('credits/buy.html.twig', [
            'rates' => $rates,
            'balance' => $balance,
            'currency_name' => $currencyName,
        ]);
        exit;
    }

    // Create payment
    if (preg_match('#^credits/buy/(\d+)$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login?return=' . urlencode('/credits/buy'));
            exit;
        }

        if (!\Plugins\Credits\Service::getSetting('credits_purchase_enabled', false)) {
            http_response_code(404);
            echo 'Purchase disabled';
            return;
        }

        $rateIndex = (int)$m[1];
        $rates = \Plugins\Credits\Service::getPurchaseRates();

        if (!isset($rates[$rateIndex])) {
            http_response_code(400);
            echo 'Invalid rate';
            return;
        }

        $rate = $rates[$rateIndex];
        $pmLocal = \Core\PluginManager::getInstance();

        $paymentData = [
            'amount' => [
                'value' => number_format((float)$rate['amount_rub'], 2, '.', ''),
                'currency' => 'RUB'
            ],
            'description' => $rate['credits'] . ' ' . \Plugins\Credits\Service::getSetting('credits_currency_name', 'кредитов'),
            'metadata' => [
                'type' => 'credits',
                'user_id' => (string)$_SESSION['user_id'],
                'rate_id' => (string)$rateIndex,
                'credits' => (string)$rate['credits'],
            ]
        ];

        $result = $pmLocal->applyFilters('payments.create_payment', null, $paymentData, $fc);

        if (is_array($result) && !empty($result['error'])) {
            error_log('Credits: payment creation failed: ' . $result['error']);
            header('Location: /credits/buy?error=payment_failed');
            exit;
        }

        if (is_array($result) && !empty($result['confirmation']['confirmation_url'])) {
            header('Location: ' . $result['confirmation']['confirmation_url']);
            exit;
        }

        header('Location: /credits/buy?error=no_payment_gateway');
        exit;
    }

    // History page
    if ($path === 'credits/history') {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login?return=' . urlencode('/credits/history'));
            exit;
        }

        $history = \Plugins\Credits\Service::getHistory((int)$_SESSION['user_id'], 100);
        $balance = \Plugins\Credits\Service::getBalance((int)$_SESSION['user_id']);
        $currencyName = \Plugins\Credits\Service::getSetting('credits_currency_name', 'кредитов');

        $twig = $fc->getTwig();
        echo $twig->render('credits/history.html.twig', [
            'history' => $history,
            'balance' => $balance,
            'currency_name' => $currencyName,
        ]);
        exit;
    }
}, 25, 'credits');

// === Filter: credits.can_deduct (default implementation) ===

$pm->addFilter('credits.can_deduct', function ($current, int $userId, int $amount) {
    // Only provide default if no previous filter returned false
    if ($current === false) return false;
    $balance = \Plugins\Credits\Service::getBalance($userId);
    return $balance >= $amount;
}, 100, 'credits');

// === Action: payments.confirmed (filter by metadata.type === 'credits') ===

$pm->addAction('payments.confirmed', function ($payment) {
    $metadata = $payment['metadata'] ?? [];

    // Only handle credits payments
    if (($metadata['type'] ?? '') !== 'credits') return;

    $userId = (int)($metadata['user_id'] ?? 0);
    $credits = (int)($metadata['credits'] ?? 0);
    $rateId = $metadata['rate_id'] ?? '';

    if ($userId <= 0 || $credits <= 0) return;

    try {
        \Plugins\Credits\Service::add(
            $userId,
            $credits,
            'purchase',
            'Покупка ' . $credits . ' ' . \Plugins\Credits\Service::getSetting('credits_currency_name', 'кредитов'),
            'purchase:' . ($payment['id'] ?? $rateId)
        );
    } catch (\Exception $e) {
        error_log('Credits: payment.confirmed failed: ' . $e->getMessage());
    }
}, 10, 'credits');

// === Action: payments.return (redirect to credits history) ===
// Only handle if credits purchase is enabled; subscription handler (priority 10) gets first crack

$pm->addAction('payments.return', function ($fc) {
    // Only redirect for credits purchases
    if (!\Plugins\Credits\Service::getSetting('credits_purchase_enabled', false)) {
        return; // let other handlers (e.g. subscription) process
    }
    if (empty($_SESSION['user_id'])) {
        header('Location: /');
        exit;
    }
    header('Location: /credits/history');
    exit;
}, 15, 'credits');

// === Action: subscription.activated (bonus credits) ===

$pm->addAction('subscription.activated', function ($subscription, $fc) {
    $userId = (int)($subscription['user_id'] ?? 0);
    if ($userId <= 0) return;

    // Get plan to check for bonus credits
    try {
        $db = \Plugins\Credits\Service::getDb();
        $plan = $db->query(
            "SELECT * FROM plugin_subscription_plans WHERE id = ?",
            [$subscription['plan_id']]
        )->fetch();

        if (!$plan) return;

        // Check meta.credits_amount in features or description JSON
        $features = json_decode($plan['features'] ?? '[]', true);
        $bonusCredits = 0;

        // Look for credits_amount in features array (e.g. {"credits_amount": 50})
        if (is_array($features)) {
            foreach ($features as $feature) {
                if (is_array($feature) && isset($feature['credits_amount'])) {
                    $bonusCredits = (int)$feature['credits_amount'];
                    break;
                }
            }
        }

        if ($bonusCredits <= 0) return;

        \Plugins\Credits\Service::add(
            $userId,
            $bonusCredits,
            'bonus',
            'Бонус по подписке ' . ($plan['name'] ?? $plan['slug'] ?? ''),
            'subscription:' . ($plan['slug'] ?? $plan['id'])
        );
    } catch (\Exception $e) {
        error_log('Credits: subscription.activated bonus failed: ' . $e->getMessage());
    }
}, 10, 'credits');

// === Admin menu ===

$pm->addFilter('admin.menu', function ($menuItems) {
    $menuItems[] = [
        'title' => '💰 Балансы',
        'url' => '/admin/table/plugin_credits_user_balances',
        'section' => 'credits',
        'order' => 91
    ];
    $menuItems[] = [
        'title' => '📒 Транзакции',
        'url' => '/admin/table/plugin_credits_transactions',
        'section' => 'credits',
        'order' => 92
    ];
    return $menuItems;
}, 10, 'credits');
