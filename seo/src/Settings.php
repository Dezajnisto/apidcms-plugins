<?php
/**
 * Настройки плагина SEO.
 *
 * Значения объявлены в plugin.json (схема + значения по умолчанию), хранятся
 * в plugin_settings, PluginManager подставляет их в манифест до первого
 * обращения. Источник правды — таблица, манифест — только схема.
 */

namespace Plugins\Seo;

class Settings
{
    private const PLUGIN = 'seo';

    /** @var array<string,mixed> */
    private array $values = [];

    /** @var array<string,string>|null */
    private ?array $stored = null;

    public function __construct()
    {
        $manifest = [];
        try {
            $manifest = \Core\PluginManager::getInstance()->getPlugin(self::PLUGIN) ?? [];
        } catch (\Throwable $e) {
            $manifest = [];
        }

        foreach ($manifest['settings'] ?? [] as $setting) {
            if (!is_array($setting) || !isset($setting['key'])) {
                continue;
            }
            $this->values[(string) $setting['key']] = $setting['value'] ?? ($setting['default'] ?? '');
        }
    }

    /** @return mixed */
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

    /** @return array<string,string> */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $this->stored = [];
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return $this->stored;
            }
            $rows = $db->query(
                "SELECT setting_key, setting_value FROM plugin_settings WHERE plugin = ?",
                [self::PLUGIN]
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $this->stored[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // plugin_settings ещё нет — работаем на значениях по умолчанию
        }

        return $this->stored;
    }
}
