<?php
/**
 * Печатает адреса, которые плагин считает документами сайта.
 * Нужен для проверки: каждый такой адрес должен реально открываться.
 *
 *   php plugins/seo/tools/urls.php /var/www/storage/database/cms.db [limit]
 */

$dbPath = $argv[1] ?? '';
$limit = (int) ($argv[2] ?? 0);

if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "нет базы: {$dbPath}\n");
    exit(1);
}

define('FRONT_ACCESS', true);
require '/var/www/www/core/src/Core/Database.php';
require '/var/www/www/core/src/Core/I18n.php';

$base = dirname(__DIR__) . '/src';
foreach (['Settings', 'Meta', 'Analyzer', 'Store', 'Inventory'] as $class) {
    require $base . '/' . $class . '.php';
}

$db = new Core\Database([
    'path' => dirname($dbPath) . '/',
    'file' => basename($dbPath),
    'full_path' => $dbPath,
]);

$docs = (new Plugins\Seo\Inventory($db))->all(false);
if ($limit > 0 && count($docs) > $limit) {
    $step = (int) ceil(count($docs) / $limit);
    $docs = array_values(array_filter($docs, fn($i) => $i % $step === 0));
}

foreach ($docs as $doc) {
    echo $doc['url'], "\n";
}
