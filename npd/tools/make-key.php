<?php
/**
 * Создать файл ключа шифрования токенов (config/npd.php).
 *
 * Запускать на сервере один раз после установки плагина:
 *
 *     php plugins/npd/tools/make-key.php
 *
 * Из админки файл не создаётся: на проде config/ принадлежит admin, а php-fpm
 * работает от www-data и писать туда не может — и это правильно.
 *
 * Ключ у каждого сайта свой. Потеря файла не теряет данные: просто придётся
 * ещё раз войти в «Мой налог» по SMS.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// plugins/npd/tools/make-key.php → public_html/
$root = dirname(__DIR__, 3);

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', $root . '/config');
}

require_once dirname(__DIR__) . '/src/Crypto.php';

$path = \Plugins\Npd\Crypto::keyPath();

if (is_file($path)) {
    echo "Ключ уже есть: {$path}\n";
    echo "Ничего не менял. Если нужно пересоздать — сначала удалите файл (сессия ФНС после этого отвалится).\n";
    exit(0);
}

$result = \Plugins\Npd\Crypto::createKeyFile();

if (!$result['ok']) {
    fwrite(STDERR, "Не получилось создать ключ: {$result['error']}\n");
    fwrite(STDERR, "Создайте файл вручную:\n\n");
    fwrite(STDERR, "  mkdir -p " . dirname($path) . "\n");
    fwrite(STDERR, "  php -r 'echo \"<?php return [\\\"key\\\" => \\\"\" . base64_encode(random_bytes(32)) . \"\\\"];\\n\";' > {$path}\n");
    fwrite(STDERR, "  chmod 640 {$path}\n");
    exit(1);
}

echo "Ключ создан: {$path}\n";
echo "В git он не попадает. Теперь можно войти в «Мой налог» по SMS в админке: Плагины → Чеки НПД.\n";
exit(0);
