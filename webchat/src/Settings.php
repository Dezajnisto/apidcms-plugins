<?php
/**
 * Настройки плагина webchat.
 *
 * Значения живут в plugin_settings. Объявленные в plugin.json ключи уже
 * подставлены PluginManager'ом в манифест, но внутренние (секрет вебхука,
 * последнее активное обращение) в манифесте не описаны — их читаем из БД.
 * Поэтому источник правды один: таблица plugin_settings, а манифест — только
 * схема и значения по умолчанию.
 */

namespace Plugins\Webchat;

class Settings
{
    private const PLUGIN = 'webchat';

    /** @var array<string,mixed> значения из манифеста (schema + defaults + overlay) */
    private array $values = [];

    /** @var array<string,string>|null все значения из plugin_settings */
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

    /** Выбранный канал: 'max' или 'telegram'. */
    public function channel(): string
    {
        return (string) $this->get('channel', 'max') === 'telegram' ? 'telegram' : 'max';
    }

    /** Настроен ли выбранный канал (есть токен и ID чата). */
    public function channelReady(): bool
    {
        return $this->channel() === 'telegram'
            ? ($this->get('tg_token') !== '' && $this->get('tg_chat_id') !== '')
            : ($this->get('max_token') !== '' && $this->get('max_chat_id') !== '');
    }

    public function save(string $key, string $value): void
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $db = $pm->getDatabase();
            if (!$db) {
                return;
            }
            (new \Core\PluginSettings($db))->set(self::PLUGIN, $key, $value);
            if ($this->stored !== null) {
                $this->stored[$key] = $value;
            }
        } catch (\Throwable $e) {
            error_log('webchat: не удалось сохранить настройку ' . $key . ': ' . $e->getMessage());
        }
    }

    /** Публичная (безопасная) часть настроек для виджета. */
    public function widgetConfig(): array
    {
        return [
            'title' => (string) $this->get('title', 'Поддержка'),
            'greeting' => (string) $this->get('greeting', ''),
            'offline' => (string) $this->get('offline_text', ''),
            'color' => (string) $this->get('color', '#2563eb'),
            'position' => (string) $this->get('position', 'right'),
            'online' => $this->bool('online', true),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $this->stored = [];
        try {
            $pm = \Core\PluginManager::getInstance();
            $db = $pm->getDatabase();
            if (!$db) {
                return $this->stored;
            }
            $rows = $db->query(
                'SELECT setting_key, setting_value FROM plugin_settings WHERE plugin = ?',
                [self::PLUGIN]
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows ?: [] as $row) {
                $this->stored[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable $e) {
            // Таблицы ещё нет (первый запрос до миграции) — работаем на дефолтах.
        }

        return $this->stored;
    }
}
