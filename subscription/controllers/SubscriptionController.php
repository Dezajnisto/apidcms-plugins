<?php
/**
 * Subscription Plugin — Controller
 *
 * Handles: /catalog-content/{slug}, /subscribe/{plan}
 */

namespace Plugins\Subscription;

class Controller
{
    /**
     * Get catalog content via AJAX
     * GET /catalog-content/{slug}?demo=1
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

        // Demo mode check
        $isDemo = !empty($_GET['demo']);

        if (!$isDemo && !self::isSubscriber($fc)) {
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

        echo json_encode([
            'content' => $item['content'],
            'demo' => $isDemo
        ]);
    }

    /**
     * Subscribe to a plan
     * GET/POST /subscribe/{plan}
     */
    public static function subscribe($fc)
    {
        // Must be logged in
        if (empty($_SESSION['user_id'])) {
            // Redirect to login with return URL
            $uri = $_SERVER['REQUEST_URI'] ?? '/subscribe';
            header('Location: /login?return=' . urlencode($uri));
            exit;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
        $planSlug = end($parts);

        $db = self::getDb($fc);

        // Find plan
        $plan = $db->query(
            "SELECT * FROM subscription_plans WHERE slug = ? AND is_active = 1",
            [$planSlug]
        )->fetch();

        if (!$plan) {
            http_response_code(404);
            echo "Plan not found";
            return;
        }

        // For now (pre-YooKassa), activate immediately in demo mode
        $demoMode = $fc->getSetting('subscription_demo_mode');
        if ($demoMode || true) {
            // Check if already has active subscription
            $existing = $db->query(
                "SELECT id FROM user_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > datetime('now')",
                [$_SESSION['user_id']]
            )->fetch();

            if ($existing) {
                // Already subscribed - redirect to profile
                header('Location: /profile');
                exit;
            }

            // Create subscription
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$plan['duration_months']} months"));
            $db->query(
                "INSERT INTO user_subscriptions (user_id, plan_id, status, amount, started_at, expires_at, payment_id) VALUES (?, ?, 'active', ?, datetime('now'), ?, 'demo_' || datetime('now'))",
                [$_SESSION['user_id'], $plan['id'], $plan['price'], $expiresAt]
            );

            header('Location: /profile?subscribed=1');
            exit;
        }

        // Real payment flow (YooKassa) - placeholder
        header('Location: /profile?error=payment_not_configured');
        exit;
    }

    /**
     * Check if current user is an active subscriber
     */
    public static function isSubscriber($fc = null)
    {
        if (empty($_SESSION['user_id'])) return false;

        try {
            $db = self::getDb($fc);
            $sub = $db->query(
                "SELECT id FROM user_subscriptions WHERE user_id = ? AND status = 'active' AND expires_at > datetime('now') LIMIT 1",
                [$_SESSION['user_id']]
            )->fetch();
            return !empty($sub);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current subscription info
     */
    public static function currentSubscription($fc = null)
    {
        if (empty($_SESSION['user_id'])) return null;

        try {
            $db = self::getDb($fc);
            return $db->query(
                "SELECT us.*, sp.name as plan_name, sp.slug as plan_slug, sp.duration_months
                 FROM user_subscriptions us
                 JOIN subscription_plans sp ON us.plan_id = sp.id
                 WHERE us.user_id = ? AND us.status = 'active' AND us.expires_at > datetime('now')
                 ORDER BY us.expires_at DESC LIMIT 1",
                [$_SESSION['user_id']]
            )->fetch();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all plans
     */
    public static function getPlans($fc = null)
    {
        try {
            $db = self::getDb($fc);
            return $db->query(
                "SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order"
            )->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get DB from FrontController
     */
    private static function getDb($fc = null)
    {
        static $db = null;
        if ($db === null) {
            // Database constructor checks for FRONT_ACCESS or ADMIN_ACCESS
            if (!defined('FRONT_ACCESS')) {
                define('FRONT_ACCESS', true);
            }
            $config = require __DIR__ . '/../../../front/config/config.php';
            $db = new \Core\Database($config['database']);
        }
        return $db;
    }
}
