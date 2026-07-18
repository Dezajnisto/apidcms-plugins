<?php
/**
 * Subscription Plugin — init.php
 *
 * Registers hooks: db.migrate, twig.init, front.router.before, admin.menu
 * Twig functions: is_subscriber(), current_subscription()
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
            catch (\Exception $e) { error_log("Subscription migration error: " . $e->getMessage()); }
        }
    }
}, 10, 'subscription');

// === Twig: functions + template paths ===

$pm->addAction('twig.init', function ($fc, $twig) use ($pluginDir) {
    // Add plugin views path
    $loader = $twig->getLoader();
    if ($loader instanceof \Twig\Loader\FilesystemLoader) {
        $loader->addPath($pluginDir . '/views', 'subscription');
    }

    // Load controller for Twig functions
    require_once $pluginDir . '/controllers/SubscriptionController.php';

    // is_subscriber()
    $twig->addFunction(new TwigFunction('is_subscriber', function () use ($fc) {
        return \Plugins\Subscription\Controller::isSubscriber($fc);
    }));

    // current_subscription()
    $twig->addFunction(new TwigFunction('current_subscription', function () use ($fc) {
        return \Plugins\Subscription\Controller::currentSubscription($fc);
    }));

    // Demo mode flag (from plugin settings in plugin.json)
    $twig->addFunction(new TwigFunction('subscription_demo', function () use ($fc) {
        try {
            $pm = \Core\PluginManager::getInstance();
            $config = $pm->getPlugin('subscription');
            if (!empty($config['settings'])) {
                foreach ($config['settings'] as $setting) {
                    if ($setting['key'] === 'demo_mode') {
                        return !empty($setting['value']);
                    }
                }
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }));

    // get_plans() - all active subscription plans
    $twig->addFunction(new TwigFunction('get_plans', function () use ($fc) {
        return \Plugins\Subscription\Controller::getPlans($fc);
    }));
}, 10, 'subscription');

// === Routes: AJAX endpoints ===

$pm->addAction('front.router.before', function ($path, $fc) use ($pluginDir) {
    // /catalog-content/{slug} - AJAX content endpoint
    if (preg_match('#^catalog-content/(.+)$#', $path, $m)) {
        require_once $pluginDir . '/controllers/SubscriptionController.php';
        \Plugins\Subscription\Controller::getContent($fc);
        exit;
    }

    // /catalog-copy/{slug} - increment copy count
    if (preg_match('#^catalog-copy/(.+)$#', $path, $m)) {
        require_once $pluginDir . '/controllers/SubscriptionController.php';
        \Plugins\Subscription\Controller::countCopy($fc);
        exit;
    }

    // /subscribe/{plan} - subscription flow
    if (preg_match('#^subscribe/(.+)$#', $path, $m)) {
        require_once $pluginDir . '/controllers/SubscriptionController.php';
        \Plugins\Subscription\Controller::subscribe($fc);
        exit;
    }
}, 25, 'subscription');

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
