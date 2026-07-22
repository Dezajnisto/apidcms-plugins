<?php
/**
 * YooKassa Plugin — init.php
 *
 * Implements subscription hook API:
 *   Filter subscription.create_payment — creates YooKassa payment
 *   Filter subscription.check_payment  — checks YooKassa payment status
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

    // Return from bank — delegate to subscription
    if ($path === 'yookassa/return') {
        $pm = \Core\PluginManager::getInstance();
        $pm->doAction('subscription.payment.return', $fc);
        exit;
    }
}, 25, 'yookassa');

// === Implement subscription hook API ===

// subscription.create_payment — respond with YooKassa payment
$pm->addFilter('subscription.create_payment', function ($result, $paymentData, $fc) {
    // Skip if another gateway already responded
    if ($result !== null) {
        return $result;
    }

    try {
        $config = \Plugins\YooKassa\Service::getConfig();
        if (empty($config['shop_id']) || empty($config['secret_key'])) {
            return ['error' => 'YooKassa not configured'];
        }

        // Add return_url
        $paymentData['return_url'] = \Plugins\YooKassa\Service::getBaseUrl() . '/yookassa/return';

        return \Plugins\YooKassa\Service::createPayment($paymentData, $config);
    } catch (\Exception $e) {
        error_log('YooKassa: payment creation failed: ' . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}, 10, 'yookassa');

// subscription.check_payment — respond with YooKassa payment status
$pm->addFilter('subscription.check_payment', function ($result, $paymentId, $fc) {
    // Skip if another gateway already responded
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
