<?php
/**
 * Credits Plugin — Service
 *
 * Atomic balance operations on immutable ledger.
 * All writes are inside SQLite transactions.
 *
 * Public API:
 *   Service::getBalance($userId)
 *   Service::canDeduct($userId, $amount)
 *   Service::add($userId, $amount, $type, $desc, $ref)
 *   Service::deduct($userId, $amount, $type, $desc, $ref)
 *   Service::getHistory($userId, $limit)
 *   Service::formatBalance($amount)
 */

namespace Plugins\Credits;

class Service
{
    /**
     * Get current balance for a user
     */
    public static function getBalance(int $userId): int
    {
        if ($userId <= 0) return 0;
        try {
            $db = self::getDb();
            $row = $db->query(
                "SELECT balance FROM plugin_credits_user_balances WHERE user_id = ?",
                [$userId]
            )->fetch();
            return $row ? (int)$row['balance'] : 0;
        } catch (\Exception $e) {
            error_log('Credits: getBalance error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if user can afford amount (respects credits.can_deduct filter)
     */
    public static function canDeduct(int $userId, int $amount): bool
    {
        if ($userId <= 0 || $amount <= 0) return false;
        try {
            $balance = self::getBalance($userId);
            $default = ($balance >= $amount);

            $pm = \Core\PluginManager::getInstance();
            return $pm->applyFilters('credits.can_deduct', $default, $userId, $amount);
        } catch (\Exception $e) {
            error_log('Credits: canDeduct error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add credits (atomic). Returns new balance.
     *
     * @param int    $userId
     * @param int    $amount  Positive integer
     * @param string $type    purchase|bonus|refund|manual
     * @param string $desc    Human-readable description
     * @param string $ref     Reference string (e.g. 'subscription:light')
     * @return int  New balance
     * @throws \Exception
     */
    public static function add(int $userId, int $amount, string $type = 'manual', string $desc = '', string $ref = ''): int
    {
        if ($userId <= 0 || $amount <= 0) {
            throw new \Exception('Credits: invalid add() parameters');
        }

        $db = self::getDb();
        $db->beginTransaction();
        try {
            // Ensure balance row exists
            $db->query(
                "INSERT OR IGNORE INTO plugin_credits_user_balances (user_id, balance) VALUES (?, 0)",
                [$userId]
            );

            // Atomic update
            $db->query(
                "UPDATE plugin_credits_user_balances SET balance = balance + ?, updated_at = datetime('now') WHERE user_id = ?",
                [$amount, $userId]
            );

            // Read new balance
            $newBalance = (int)$db->query(
                "SELECT balance FROM plugin_credits_user_balances WHERE user_id = ?",
                [$userId]
            )->fetchColumn();

            // Write ledger
            $db->query(
                "INSERT INTO plugin_credits_transactions (user_id, amount, type, description, reference, balance_after) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $amount, $type, $desc, $ref, $newBalance]
            );

            $db->commit();

            // Fire hook
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('credits.balance_changed', $userId, $newBalance, $amount, $type, $desc);

            return $newBalance;
        } catch (\Exception $e) {
            $db->rollback();
            error_log('Credits: add() failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deduct credits (atomic). Throws if insufficient.
     *
     * @param int    $userId
     * @param int    $amount  Positive integer (will be stored as negative)
     * @param string $type    deduction|refund
     * @param string $desc    Human-readable description
     * @param string $ref     Reference string (e.g. 'generation:prompt_42')
     * @return int  New balance
     * @throws \Exception
     */
    public static function deduct(int $userId, int $amount, string $type = 'deduction', string $desc = '', string $ref = ''): int
    {
        if ($userId <= 0 || $amount <= 0) {
            throw new \Exception('Credits: invalid deduct() parameters');
        }

        if (!self::canDeduct($userId, $amount)) {
            throw new \Exception('Credits: insufficient balance');
        }

        $db = self::getDb();
        $db->beginTransaction();
        try {
            // Atomic update (subtract)
            $db->query(
                "UPDATE plugin_credits_user_balances SET balance = balance - ?, updated_at = datetime('now') WHERE user_id = ? AND balance >= ?",
                [$amount, $userId, $amount]
            );

            // Read new balance
            $newBalance = (int)$db->query(
                "SELECT balance FROM plugin_credits_user_balances WHERE user_id = ?",
                [$userId]
            )->fetchColumn();

            // Write ledger (negative amount)
            $negAmount = -$amount;
            $db->query(
                "INSERT INTO plugin_credits_transactions (user_id, amount, type, description, reference, balance_after) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $negAmount, $type, $desc, $ref, $newBalance]
            );

            $db->commit();

            // Fire hook
            $pm = \Core\PluginManager::getInstance();
            $pm->doAction('credits.balance_changed', $userId, $newBalance, $negAmount, $type, $desc);

            return $newBalance;
        } catch (\Exception $e) {
            $db->rollback();
            error_log('Credits: deduct() failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get transaction history for a user
     */
    public static function getHistory(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) return [];
        try {
            $db = self::getDb();
            return $db->query(
                "SELECT * FROM plugin_credits_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
                [$userId, $limit]
            )->fetchAll();
        } catch (\Exception $e) {
            error_log('Credits: getHistory error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Format balance with currency name
     */
    public static function formatBalance(int $amount): string
    {
        $currencyName = self::getSetting('credits_currency_name', 'кредитов');
        return $amount . ' ' . $currencyName;
    }

    /**
     * Get a plugin setting value
     */
    public static function getSetting(string $key, $default = null)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $cfg = $pm->getPlugin('credits');
            if (!empty($cfg['settings'])) {
                foreach ($cfg['settings'] as $s) {
                    if ($s['key'] === $key) {
                        return $s['value'] ?? $default;
                    }
                }
            }
        } catch (\Exception $e) {}
        return $default;
    }

    /**
     * Get purchase rates from settings
     */
    public static function getPurchaseRates(): array
    {
        $raw = self::getSetting('credits_purchase_rates', '[]');
        $rates = json_decode($raw, true);
        return is_array($rates) ? $rates : [];
    }

    /**
     * Get DB connection
     */
    public static function getDb()
    {
        if (!defined('FRONT_ACCESS')) {
            define('FRONT_ACCESS', true);
        }
        $config = require __DIR__ . '/../../../config/front.php';
        $coreDefaults = require __DIR__ . '/../../../www/core/config/front.php';
        $config = array_replace_recursive($coreDefaults, $config);
        return new \Core\Database($config['database']);
    }
}
