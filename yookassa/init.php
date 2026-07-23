<?php
/**
 * YooKassa Plugin — init.php
 *
 * Implements universal payment hooks:
 *   Filter payments.create_payment — creates YooKassa payment (any plugin)
 *   Filter payments.check_payment  — checks YooKassa payment status
 *
 * Dispatches on webhook:
 *   Action payments.confirmed — payment succeeded (plugins filter by metadata.type)
 *   Action payments.canceled  — payment canceled
 *   Action payments.return    — user returned from bank page
 *
 * Routes: POST /yookassa/webhook, GET /yookassa/return
 */

use Core\PluginManager;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

require_once $pluginDir . '/controllers/Service.php';

// === Routes ===

$pm->addAction('front.router.before', function ($path, $fc) {
    // Webhook endpoint
    if ($path === 'yookassa/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        \Plugins\YooKassa\Service::handleWebhook($fc);
        exit;
    }

    // Return from bank — let payment consumers handle it
    if ($path === 'yookassa/return') {
        $pm = \Core\PluginManager::getInstance();
        $pm->doAction('payments.return', $fc);
        exit;
    }
}, 25, 'yookassa');

// === Universal payment hooks ===

// payments.create_payment — single entry point for all payment consumers
$pm->addFilter('payments.create_payment', function ($result, $paymentData, $fc) {
    // Skip if another gateway already responded
    if ($result !== null) {
        return $result;
    }

    try {
        $config = \Plugins\YooKassa\Service::getConfig();
        if (empty($config['shop_id']) || empty($config['secret_key'])) {
            return ['error' => 'YooKassa not configured'];
        }

        // Add default return_url if caller didn't provide one
        if (empty($paymentData['return_url'])) {
            $paymentData['return_url'] = \Plugins\YooKassa\Service::getBaseUrl() . '/yookassa/return';
        }

        return \Plugins\YooKassa\Service::createPayment($paymentData, $config);
    } catch (\Exception $e) {
        error_log('YooKassa: payment creation failed: ' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}, 10, 'yookassa');

// payments.check_payment — single entry point for payment status checks
$pm->addFilter('payments.check_payment', function ($result, $paymentId, $fc) {
    if ($result !== null) {
        return $result;
    }

    try {
        $config = \Plugins\YooKassa\Service::getConfig();
        if (empty($config['shop_id'])) {
            return null;
        }
        return \Plugins\YooKassa\Service::getPayment($paymentId, $config);
    } catch (\Exception $e) {
        error_log('YooKassa: payment check failed: ' . $e->getMessage());
        return null;
    }
}, 10, 'yookassa');
