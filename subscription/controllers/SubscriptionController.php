<?php
/**
 * Subscription Plugin — Controller
 *
 * Handles: /catalog-content/{slug}, /catalog-copy/{slug}, /subscribe/{plan}
 *
 * Hook API for payment gateways:
 *   subscription.create_payment  (filter) — called by subscribe(), gateways respond
 *   subscription.check_payment   (filter) — called by onPaymentReturn(), gateways respond
 *   subscription.payment.confirmed (action) — gateways call on success
 *   subscription.payment.canceled  (action) — gateways call on cancel
 *   subscription.payment.return    (action) — gateways call on return from bank
 */

namespace Plugins\Subscription;

class Controller
{
    /**
     * Get catalog content via AJAX
     */
    public static function getContent($fc)
    {
        header('Content-Type: application/json');

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
        $slug = end($parts);

        if (empty($slug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Slug required']);
            return;
        }

        if (!self::isSubscriber($fc)) {
            http_response_code(403);
            echo json_encode(['error' => 'Subscription required']);
            return;
        }

        $db = self::getDb($fc);
        $item = $db->query(
            "SELECT content FROM catalog WHERE slug = ? AND status = 'active'",
            [$slug]
        )->fetch();

        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        echo json_encode(['content' => $item['content']]);
    }

    /**
     * Increment copy count for catalog item
     */
    public static function countCopy($fc)
    {
        header('Content-Type: application/json');

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
        $slug = end($parts);

        if (empty($slug)) {
            http_response_code(400);
            echo json_encode(['error' => 'Slug required']);
            return;
        }

        try {
            $db = self::getDb($fc);
            $db->query(
                "UPDATE catalog SET copies_count = copies_count + 1 WHERE slug = ? AND status = 'active'",
                [$slug]
            );
            $item = $db->query("SELECT copies_count FROM catalog WHERE slug = ?", [$slug])->fetch();
            echo json_encode(['ok' => true, 'copies_count' => $item ? (int)$item['copies_count'] : 0]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Internal error']);
        }
    }

    /**
     * Subscribe to a plan
     */
    public static function subscribe($fc)
    {
        if (empty($_SESSION['user_id'])) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/subscribe';
            header('Location: /login?return=' . urlencode($uri));
            exit;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
        $planSlug = end($parts);

        $db = self::getDb($fc);
        $plan = $db->query(
            "SELECT * FROM plugin_subscription_plans WHERE slug = ? AND is_active = 1",
            [$planSlug]
        )->fetch();

        if (!$plan) {
            http_response_code(404);
            echo "Plan not found";
            return;
        }

        // Free plan — activate immediately
        if ((float)$plan['price'] == 0) {
            $existing = $db->query(
                "SELECT id FROM plugin_subscription_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > datetime('now')",
                [$_SESSION['user_id']]
            )->fetch();

            if ($existing) {
                header('Location: /profile');
                exit;
            }

            // trial_once check: one-time free/trial plans
            if (!empty($plan['trial_once'])) {
                $hadTrial = $db->query(
                    "SELECT id FROM plugin_subscription_subscriptions WHERE user_id = ? AND plan_id = ? AND status IN ('active','expired') LIMIT 1",
                    [$_SESSION['user_id'], $plan['id']]
                )->fetch();
                if ($hadTrial) {
                    header('Location: /subscribe?error=trial_used');
                    exit;
                }
            }

            self::activateFreeSubscription($db, $_SESSION['user_id'], $plan);
            header('Location: /profile?subscribed=1');
            exit;
        }

        // Paid plan — check existing
        $existing = $db->query(
            "SELECT id FROM plugin_subscription_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > datetime('now')",
            [$_SESSION['user_id']]
        )->fetch();

        if ($existing) {
            header('Location: /profile');
            exit;
        }

        // Create pending subscription
        $db->query(
            "INSERT INTO plugin_subscription_subscriptions (user_id, plan_id, status, payment_provider, amount, created_at) VALUES (?, ?, 'pending', 'yookassa', ?, datetime('now'))",
            [$_SESSION['user_id'], $plan['id'], $plan['price']]
        );
        $subscriptionId = $db->query("SELECT last_insert_rowid()")->fetchColumn();

        // Ask all registered gateways to create a payment
        $pm = \Core\PluginManager::getInstance();

        $paymentData = [
            'amount' => [
                'value' => number_format((float)$plan['price'], 2, '.', ''),
                'currency' => 'RUB'
            ],
            'description' => $plan['name'] . ' - ' . self::formatDuration($plan['duration_days']),
            'metadata' => [
                'type' => 'subscription',
                'user_id' => (string)$_SESSION['user_id'],
                'plan_slug' => $plan['slug'],
                'subscription_id' => (string)$subscriptionId
            ]
        ];

        $result = $pm->applyFilters('payments.create_payment', null, $paymentData, $fc);

        if (is_array($result) && !empty($result['error'])) {
            error_log('Subscription: payment creation failed: ' . $result['error']);
            header('Location: /profile?error=payment_failed');
            exit;
        }

        if (is_array($result) && !empty($result['id'])) {
            $db->query(
                "UPDATE plugin_subscription_subscriptions SET payment_id = ? WHERE id = ?",
                [$result['id'], $subscriptionId]
            );

            if (!empty($result['confirmation']['confirmation_url'])) {
                header('Location: ' . $result['confirmation']['confirmation_url']);
                exit;
            }
        }

        // No gateway responded
        header('Location: /profile?error=no_payment_gateway');
        exit;
    }

    /**
     * Called by gateways on successful payment (webhook or return)
     */
    public static function onPaymentConfirmed($payment, $fc): void
    {
        $paymentId = $payment['id'] ?? '';
        $metadata = $payment['metadata'] ?? [];
        $userId = $metadata['user_id'] ?? null;

        if (!$paymentId || !$userId) {
            return;
        }

        $db = self::getDb($fc);
        $subscription = $db->query(
            "SELECT us.*, sp.duration_days FROM plugin_subscription_subscriptions us
             JOIN plugin_subscription_plans sp ON us.plan_id = sp.id
             WHERE us.payment_id = ? AND us.user_id = ? AND us.status = 'pending'
             ORDER BY us.id DESC LIMIT 1",
            [$paymentId, $userId]
        )->fetch();

        if ($subscription) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$subscription['duration_days']} days"));
            $db->query(
                "UPDATE plugin_subscription_subscriptions SET status = 'active', started_at = datetime('now'), expires_at = ? WHERE id = ?",
                [$expiresAt, $subscription['id']]
            );

            // Reload subscription with updated data
            $activated = $db->query(
                "SELECT us.*, sp.slug as plan_slug, sp.name as plan_name, sp.features as plan_features
                 FROM plugin_subscription_subscriptions us JOIN plugin_subscription_plans sp ON us.plan_id = sp.id
                 WHERE us.id = ?",
                [$subscription['id']]
            )->fetch();

            if ($activated) {
                $pm = \Core\PluginManager::getInstance();
                $pm->doAction('subscription.activated', $activated, $fc);
            }
        }
    }

    /**
     * Called by gateways on canceled payment
     */
    public static function onPaymentCanceled($payment, $fc): void
    {
        $paymentId = $payment['id'] ?? '';
        if ($paymentId) {
            $db = self::getDb($fc);
            $db->query(
                "UPDATE plugin_subscription_subscriptions SET status = 'canceled' WHERE payment_id = ? AND status = 'pending'",
                [$paymentId]
            );
        }
    }

    /**
     * Called by gateways on return from bank page.
     * Subscription handles the entire return flow: check status, activate, redirect.
     */
    public static function onPaymentReturn($fc): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        $db = self::getDb($fc);

        // Already activated (webhook was faster)?
        $activeSub = self::currentSubscription($fc);
        if ($activeSub) {
            header('Location: /profile?subscribed=1');
            exit;
        }

        // Find pending subscription
        $subscription = $db->query(
            "SELECT id, payment_id FROM plugin_subscription_subscriptions
             WHERE user_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [$_SESSION['user_id']]
        )->fetch();

        if (!$subscription) {
            // Not a subscription return — let other handlers (e.g. credits) process it
            return;
        }

        // Ask gateways to check payment status
        if ($subscription['payment_id']) {
            $pm = \Core\PluginManager::getInstance();
            $payment = $pm->applyFilters('payments.check_payment', null, $subscription['payment_id'], $fc);

            if (is_array($payment) && isset($payment['status'])) {
                if ($payment['status'] === 'succeeded') {
                    self::onPaymentConfirmed($payment, $fc);
                    header('Location: /profile?subscribed=1');
                    exit;
                } elseif ($payment['status'] === 'canceled') {
                    self::onPaymentCanceled($payment, $fc);
                    header('Location: /profile?error=payment_canceled');
                    exit;
                }
            }
        }

        // Still pending
        header('Location: /profile?pending=1');
        exit;
    }

    /**
     * Activate free subscription immediately
     */
    private static function activateFreeSubscription($db, $userId, $plan)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$plan['duration_days']} days"));
        $db->query(
            "INSERT INTO plugin_subscription_subscriptions (user_id, plan_id, status, amount, started_at, expires_at, payment_id) VALUES (?, ?, 'active', ?, datetime('now'), ?, 'free_' || datetime('now'))",
            [$userId, $plan['id'], $plan['price'], $expiresAt]
        );

        // Fire subscription.activated for free plans too (bonus credits, etc.)
        $subId = $db->query("SELECT last_insert_rowid()")->fetchColumn();
        $activated = $db->query(
            "SELECT us.*, sp.slug as plan_slug, sp.name as plan_name, sp.features as plan_features
             FROM plugin_subscription_subscriptions us JOIN plugin_subscription_plans sp ON us.plan_id = sp.id
             WHERE us.id = ?",
            [$subId]
        )->fetch();

        if ($activated) {
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('subscription.activated', $activated, null);
        }
    }

    /**
     * Format duration for display
     */
    public static function formatDuration($days)
    {
        $d = (int)$days;
        if ($d <= 0) $d = 1;
        if ($d < 60) {
            return $d . ' ' . self::plural($d, ['день', 'дня', 'дней']);
        }
        $months = round($d / 30);
        return $months . ' ' . self::plural($months, ['месяц', 'месяца', 'месяцев']);
    }

    private static function plural($n, $forms)
    {
        $n = abs((int)$n) % 100;
        if ($n >= 11 && $n <= 19) return $forms[2];
        $n = $n % 10;
        if ($n == 1) return $forms[0];
        if ($n >= 2 && $n <= 4) return $forms[1];
        return $forms[2];
    }

    public static function isSubscriber($fc = null)
    {
        if (empty($_SESSION['user_id'])) return false;
        try {
            $db = self::getDb($fc);
            $sub = $db->query(
                "SELECT id FROM plugin_subscription_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > datetime('now') LIMIT 1",
                [$_SESSION['user_id']]
            )->fetch();
            return !empty($sub);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function currentSubscription($fc = null)
    {
        if (empty($_SESSION['user_id'])) return null;
        try {
            $db = self::getDb($fc);
            return $db->query(
                "SELECT us.*, sp.name as plan_name, sp.slug as plan_slug, sp.duration_days
                 FROM plugin_subscription_subscriptions us
                 JOIN plugin_subscription_plans sp ON us.plan_id = sp.id
                 WHERE us.user_id = ? AND us.status = 'active' AND us.expires_at > datetime('now')
                 ORDER BY us.expires_at DESC LIMIT 1",
                [$_SESSION['user_id']]
            )->fetch();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function getPlans($fc = null)
    {
        try {
            $db = self::getDb($fc);
            return $db->query(
                "SELECT * FROM plugin_subscription_plans WHERE is_active = 1 ORDER BY sort_order"
            )->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function getDb($fc = null)
    {
        static $db = null;
        if ($db === null) {
            if (!defined('FRONT_ACCESS')) {
                define('FRONT_ACCESS', true);
            }
            $config = require __DIR__ . '/../../../front/config/config.php';
            $db = new \Core\Database($config['database']);
        }
        return $db;
    }
}
