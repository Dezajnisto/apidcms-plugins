<?php
/**
 * Subscription Plugin — init.php
 *
 * Hook API for payment gateways:
 *   Filter:  subscription.create_payment — gateways respond with payment URL
 *   Action:  subscription.payment.confirmed — gateways call on success
 *   Action:  subscription.payment.canceled  — gateways call on cancel
 *   Action:  subscription.payment.return    — gateways call on return from bank
 */

use Core\PluginManager;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Migrations ===

$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    try {
        $cols = $db->query("PRAGMA table_info(subscription_plans)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (is_array($cols) && in_array('duration_months', $cols) && !in_array('duration_days', $cols)) {
            $db->exec("ALTER TABLE subscription_plans ADD COLUMN duration_days INTEGER NOT NULL DEFAULT 30");
            $db->exec("UPDATE subscription_plans SET duration_days = ROUND(duration_months * 30)");
            $db->exec("ALTER TABLE subscription_plans DROP COLUMN duration_months");
        }
    } catch (\Exception $e) {
        error_log("Subscription migration v1.2.2 error: " . $e->getMessage());
    }

    // Migration v1.3.1: add trial_once column
    try {
        $cols = $db->query("PRAGMA table_info(subscription_plans)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (is_array($cols) && !in_array('trial_once', $cols)) {
            $db->exec("ALTER TABLE subscription_plans ADD COLUMN trial_once INTEGER DEFAULT 0");
        }
    } catch (\Exception $e) {
        error_log("Subscription migration v1.3.1 error: " . $e->getMessage());
    }

    $migrationFile = $pluginDir . '/migrations/001_create.sql';
    if (!file_exists($migrationFile)) return;
    $sql = file_get_contents($migrationFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try { $db->exec($stmt); }
            catch (\Exception $e) { error_log("Subscription migration error: " . $e->getMessage()); }
        }
    }
}, 10, 'subscription');

// === Load controller ===

require_once $pluginDir . '/controllers/SubscriptionController.php';

// === Twig: functions + template paths ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'subscription');
    }
    $loader->addPath($pluginDir . '/views');

    $twig->addFunction(new TwigFunction('is_subscriber', function () use ($fc) {
        return \Plugins\Subscription\Controller::isSubscriber($fc);
    }));

    $twig->addFunction(new TwigFunction('current_subscription', function () use ($fc) {
        return \Plugins\Subscription\Controller::currentSubscription($fc);
    }));

    $twig->addFunction(new TwigFunction('format_duration', function ($months) {
        return \Plugins\Subscription\Controller::formatDuration($months);
    }));

    $twig->addFunction(new TwigFunction('get_plans', function () use ($fc) {
        return \Plugins\Subscription\Controller::getPlans($fc);
    }));
}, 10, 'subscription');

// === Routes ===

$pm->addAction('front.router.before', function ($path, $fc) {
    if (preg_match('#^catalog-content/(.+)$#', $path, $m)) {
        \Plugins\Subscription\Controller::getContent($fc);
        exit;
    }

    if (preg_match('#^catalog-copy/(.+)$#', $path, $m)) {
        \Plugins\Subscription\Controller::countCopy($fc);
        exit;
    }

    if (preg_match('#^subscribe/(.+)$#', $path, $m)) {
        \Plugins\Subscription\Controller::subscribe($fc);
        exit;
    }
}, 25, 'subscription');

// === Hook API handlers (called by gateways) ===

$pm->addAction('subscription.payment.confirmed', function ($payment, $fc) {
    \Plugins\Subscription\Controller::onPaymentConfirmed($payment, $fc);
}, 10, 'subscription');

$pm->addAction('subscription.payment.canceled', function ($payment, $fc) {
    \Plugins\Subscription\Controller::onPaymentCanceled($payment, $fc);
}, 10, 'subscription');

$pm->addAction('subscription.payment.return', function ($fc) {
    \Plugins\Subscription\Controller::onPaymentReturn($fc);
}, 10, 'subscription');

// === Admin menu ===

$pm->addFilter('admin.menu', function ($menuItems) {
    $menuItems[] = [
        'title' => '📋 Подписки',
        'url' => '/admin/table/user_subscriptions',
        'section' => 'subscriptions',
        'order' => 90
    ];
    return $menuItems;
}, 10, 'subscription');
