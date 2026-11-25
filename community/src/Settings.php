<?php
/**
 * Настройки плагина «Сообщество».
 *
 * Как в webchat: манифест даёт схему и значения по умолчанию, plugin_settings —
 * то, что владелец сайта поменял в админке. Внутренние ключи (секрет CSRF,
 * служебные отметки) в манифесте не описаны и читаются только из БД.
 */

namespace Plugins\Community;

class Settings
{
    private const PLUGIN = 'community';

    /** @var array<string,mixed> */
    private array $values = [];

    /** @var array<string,string>|null */
    private ?array $stored = null;

    private array $manifest = [];

    public function __construct()
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $this->manifest = $pm->getPlugin(self::PLUGIN) ?? [];
        } catch (\Throwable $e) {
            $this->manifest = [];
        }

        foreach ($this->manifest['settings'] ?? [] as $setting) {
            if (!is_array($setting) || !isset($setting['key'])) {
                continue;
            }
            $this->values[(string) $setting['key']] = $setting['value'] ?? ($setting['default'] ?? '');
        }
    }

    public function get(string $key, $default = '')
    {
        $stored = $this->stored();
        if (isset($stored[$key]) && $stored[$key] !== '') {
            return $stored[$key];
        }

        $value = $this->values[$key] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, null);
        if ($value === null) {
            return $default;
        }
        return !in_array($value, ['', '0', 'false', false], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, null);
        return ($value === null || $value === '') ? $default : (int) $value;
    }

    /** Адрес форума: настройка без ведущего слеша, по умолчанию «forum». */
    public function forumPrefix(): string
    {
        $prefix = trim((string) $this->get('forum_prefix', 'forum'), '/');
        return $prefix === '' ? 'forum' : $prefix;
    }

    /** Список путей, где комментарии не показываются. */
    public function excludedPaths(): array
    {
        $raw = (string) $this->get('comments_exclude', '');
        $paths = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $paths[] = '/' . trim($line, '/');
            }
        }
        return $paths;
    }

    public function stopWords(): array
    {
        $raw = (string) $this->get('stop_words', '');
        $words = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim(mb_strtolower($line));
            if ($line !== '') {
                $words[] = $line;
            }
        }
        return $words;
    }

    /** @return array<string,string> */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $this->stored = [];
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if ($db) {
                $settings = new \Core\PluginSettings($db);
                $this->stored = $settings->all(self::PLUGIN);
            }
        } catch (\Throwable $e) {
            $this->stored = [];
        }

        return $this->stored;
    }

    /** Секрет для CSRF-токенов: создаётся один раз, живёт в plugin_settings. */
    public function csrfSecret(): string
    {
        $stored = $this->stored();
        if (!empty($stored['csrf_secret'])) {
            return (string) $stored['csrf_secret'];
        }

        $secret = bin2hex(random_bytes(32));
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if ($db) {
                (new \Core\PluginSettings($db))->set(self::PLUGIN, 'csrf_secret', $secret);
            }
        } catch (\Throwable $e) {
            // без БД токен всё равно сгенерируется, просто не сохранится
        }

        $this->stored['csrf_secret'] = $secret;
        return $secret;
    }
}
