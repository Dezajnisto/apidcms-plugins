<?php
/**
 * Настройки плагина npd.
 *
 * Объявленные в plugin.json ключи уже подставлены PluginManager'ом в манифест,
 * но внутренние значения (токены ФНС, device id, секрет входящего API, метка
 * последнего тика) в манифесте не описаны — их читаем из plugin_settings.
 * Источник правды один: таблица plugin_settings.
 */

namespace Plugins\Npd;

class Settings
{
    private const PLUGIN = 'npd';

    /** @var array<string,mixed> значения из манифеста (schema + defaults + overlay) */
    private array $values = [];

    /** @var array<string,string>|null все значения из plugin_settings */
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
        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public function save(string $key, string $value): void
    {
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return;
            }
            (new \Core\PluginSettings($db))->set(self::PLUGIN, $key, $value);
            if ($this->stored !== null) {
                $this->stored[$key] = $value;
            }
        } catch (\Throwable $e) {
            error_log('npd: не удалось сохранить настройку ' . $key . ': ' . $e->getMessage());
        }
    }

    public function forget(string $key): void
    {
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return;
            }
            (new \Core\PluginSettings($db))->delete(self::PLUGIN, $key);
            if ($this->stored !== null) {
                unset($this->stored[$key]);
            }
        } catch (\Throwable $e) {
            error_log('npd: не удалось удалить настройку ' . $key . ': ' . $e->getMessage());
        }
    }

    /** Постоянный идентификатор устройства: ФНС привязывает к нему сессию. */
    public function deviceId(): string
    {
        $id = (string) $this->get('device_id', '');
        if ($id === '') {
            $id = self::uuid();
            $this->save('device_id', $id);
        }
        return $id;
    }

    /** Секрет подписи входящего API (/npd/income). Создаётся при первой миграции. */
    public function apiSecret(): string
    {
        $secret = (string) $this->get('api_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
            $this->save('api_secret', $secret);
        }
        return $secret;
    }

    /** Ключ для ручного запуска тика из cron. */
    public function tickKey(): string
    {
        $key = (string) $this->get('tick_key', '');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            $this->save('tick_key', $key);
        }
        return $key;
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
            $db = \Core\PluginManager::getInstance()->getDatabase();
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
