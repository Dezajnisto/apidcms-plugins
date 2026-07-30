<?php
/**
 * Favorites Plugin - Controller
 * Universal favorites/bookmarks for any entity type.
 */

namespace Plugins\Favorites;

class Controller
{
    public static function toggle(int $userId)
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $entityType = trim($input['entity_type'] ?? '');
        $entitySlug = trim($input['entity_slug'] ?? '');

        if (empty($entityType) || empty($entitySlug)) {
            http_response_code(400);
            echo json_encode(['error' => 'entity_type and entity_slug required']);
            return;
        }

        $db = self::getDb();
        $existing = $db->query(
            "SELECT id FROM plugin_favorites_user_favorites WHERE user_id = ? AND entity_type = ? AND entity_slug = ?",
            [$userId, $entityType, $entitySlug]
        )->fetch();

        if ($existing) {
            $db->query("DELETE FROM plugin_favorites_user_favorites WHERE id = ?", [$existing['id']]);
            echo json_encode(['ok' => true, 'favorited' => false]);
        } else {
            $limit = (int) self::getSetting('max_favorites', 50);
            $count = $db->query("SELECT COUNT(*) as cnt FROM plugin_favorites_user_favorites WHERE user_id = ?", [$userId])->fetch();
            if ($count['cnt'] >= $limit) {
                http_response_code(400);
                echo json_encode(['error' => "Max {$limit} favorites reached"]);
                return;
            }
            $db->query(
                "INSERT INTO plugin_favorites_user_favorites (user_id, entity_type, entity_slug) VALUES (?, ?, ?)",
                [$userId, $entityType, $entitySlug]
            );
            echo json_encode(['ok' => true, 'favorited' => true]);
        }
    }

    public static function list(int $userId)
    {
        $db = self::getDb();
        $rows = $db->query(
            "SELECT entity_type, entity_slug, created_at FROM plugin_favorites_user_favorites WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        )->fetchAll() ?: [];
        echo json_encode($rows);
    }

    public static function getUserFavorites(int $userId): array
    {
        $db = self::getDb();
        return $db->query(
            "SELECT entity_type, entity_slug, created_at FROM plugin_favorites_user_favorites WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        )->fetchAll() ?: [];
    }

    public static function isFavorited(int $userId, string $entityType, string $entitySlug): bool
    {
        $db = self::getDb();
        $row = $db->query(
            "SELECT 1 FROM plugin_favorites_user_favorites WHERE user_id = ? AND entity_type = ? AND entity_slug = ? LIMIT 1",
            [$userId, $entityType, $entitySlug]
        )->fetch();
        return !empty($row);
    }

    public static function getCount(int $userId): int
    {
        $db = self::getDb();
        $row = $db->query("SELECT COUNT(*) as cnt FROM plugin_favorites_user_favorites WHERE user_id = ?", [$userId])->fetch();
        return (int) ($row['cnt'] ?? 0);
    }

    private static function getDb()
    {
        static $db = null;
        if ($db === null) {
            if (!defined('FRONT_ACCESS')) {
                define('FRONT_ACCESS', true);
            }
            $config = require __DIR__ . '/../../front/config/config.php';
            $db = new \Core\Database($config['database']);
        }
        return $db;
    }

    public static function getSetting(string $key, $default = null)
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $cfg = $pm->getPlugin('favorites');
            if (!empty($cfg['settings'])) {
                foreach ($cfg['settings'] as $s) {
                    if (($s['key'] ?? '') === $key) {
                        return $s['value'] ?? $default;
                    }
                }
            }
        } catch (\Exception $e) {}
        return $default;
    }
}
