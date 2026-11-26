<?php
/**
 * Шифрование токенов «Моего налога».
 *
 * Токен доступа к ЛК НПД — это вход в налоговый кабинет от имени самозанятого.
 * В базе он лежит зашифрованным (AES-256-GCM), а ключ — в config/npd.php,
 * то есть ВНЕ базы: утёкший дамп cms.db (бэкап, отладка, чужие руки) сам по
 * себе доступа не даёт.
 *
 * Файл ключа не создаётся из админки: на проде config/ принадлежит admin и
 * www-data его только читает. Первый ключ ставит tools/make-key.php на сервере
 * (одна команда, как key:generate у фреймворков); в админке видно, создан ли он.
 */

namespace Plugins\Npd;

class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    /** Путь к файлу с ключом. */
    public static function keyPath(): string
    {
        return CONFIG_PATH . '/npd.php';
    }

    /**
     * Сырой ключ (32 байта) или null, если файла нет/он битый.
     */
    public static function key(): ?string
    {
        static $cache = false;

        if ($cache !== false) {
            return $cache;
        }

        $cache = null;
        $path = self::keyPath();
        if (!is_file($path)) {
            return null;
        }

        try {
            $data = require $path;
            if (!is_array($data) || empty($data['key'])) {
                return null;
            }
            $raw = base64_decode((string) $data['key'], true);
            if ($raw === false || strlen($raw) !== 32) {
                error_log('npd: ключ в ' . $path . ' повреждён (нужно 32 байта в base64)');
                return null;
            }
            $cache = $raw;
        } catch (\Throwable $e) {
            error_log('npd: не удалось прочитать ключ: ' . $e->getMessage());
            return null;
        }

        return $cache;
    }

    public static function hasKey(): bool
    {
        return self::key() !== null;
    }

    /**
     * Создать файл ключа. Нужны права записи в config/ — на проде их нет,
     * поэтому это делает CLI-скрипт, а не веб-запрос.
     *
     * @return array{ok: bool, error: string}
     */
    public static function createKeyFile(): array
    {
        $path = self::keyPath();
        if (is_file($path)) {
            return ['ok' => true, 'error' => ''];
        }

        $key = base64_encode(random_bytes(32));
        $php = "<?php\n"
            . "/**\n"
            . " * Ключ шифрования токенов плагина npd (чеки НПД).\n"
            . " *\n"
            . " * Создан автоматически. НЕ коммитить, НЕ копировать между сайтами:\n"
            . " * у каждого сайта свой ключ, иначе токен с одного сайта читается на другом.\n"
            . " * Потеря файла = повторный вход по SMS, данные не теряются.\n"
            . " */\n"
            . "return ['key' => '" . $key . "'];\n";

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => 'нет каталога ' . $dir];
        }

        if (@file_put_contents($path, $php) === false) {
            return ['ok' => false, 'error' => 'нет прав на запись в ' . $path];
        }

        @chmod($path, 0640);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Зашифровать строку. Формат: base64(nonce[12] . tag[16] . ciphertext).
     */
    public static function seal(string $plain): ?string
    {
        $key = self::key();
        if ($key === null || $plain === '') {
            return null;
        }

        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            error_log('npd: шифрование не удалось');
            return null;
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    /**
     * Расшифровать. null — если ключа нет, файл битый или подпись не сошлась
     * (тогда плагин честно просит войти заново, а не падает).
     */
    public static function open(?string $blob): ?string
    {
        $key = self::key();
        if ($key === null || $blob === null || $blob === '') {
            return null;
        }

        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) <= 28) {
            return null;
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            error_log('npd: токен не расшифровался — ключ не тот или файл ключа заменён');
            return null;
        }

        return $plain;
    }
}
