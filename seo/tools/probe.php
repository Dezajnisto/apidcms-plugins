<?php
/**
 * Проверка поиска документа на реальной базе сайта — без CMS.
 *
 * Запуск (в контейнере стенда, база примонтирована):
 *   php plugins/seo/tools/probe.php /var/www/storage/database/cms.db /
 */

$dbPath = $argv[1] ?? '';
$path = $argv[2] ?? '/';

if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "нет базы: {$dbPath}\n");
    exit(1);
}

$core = $argv[3] ?? '';
$candidates = array_filter([
    $core,
    '/var/www/www/core/src/Core/Database.php',
    '/var/www/core/src/Core/Database.php',
    dirname(__DIR__, 3) . '/www/core/src/Core/Database.php',
]);
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $core = $candidate;
        break;
    }
}
if (!is_file((string) $core)) {
    fwrite(STDERR, "не нашёл ядро (передайте путём 3-м аргументом)\n");
    exit(1);
}
// Ядро требует явного контекста доступа к базе (защита от прямого вызова).
define('FRONT_ACCESS', true);
require $core;

// I18n — рядом с Database в том же ядре.
$i18n = dirname($core) . '/I18n.php';
if (is_file($i18n)) {
    require $i18n;
}

$base = dirname(__DIR__) . '/src';
require $base . '/Settings.php';
require $base . '/Meta.php';
require $base . '/Analyzer.php';
require $base . '/Store.php';
require $base . '/Inventory.php';

$db = new Core\Database([
    'path' => dirname($dbPath) . '/',
    'file' => basename($dbPath),
    'full_path' => $dbPath,
]);

$inv = new Plugins\Seo\Inventory($db);
$doc = $inv->byPath($path);

if ($doc === null) {
    echo "ДОКУМЕНТ НЕ НАЙДЕН для {$path}\n";
    exit(2);
}

echo "path:    {$path}\n";
echo "key:     {$doc['key']}\n";
echo "type:    {$doc['type']}\n";
echo "url:     {$doc['url']}\n";
echo "title:   {$doc['title']}\n";
echo "words:   " . count(Plugins\Seo\Analyzer::words(Plugins\Seo\Analyzer::plain((string) $doc['content']))) . "\n";

$e = Plugins\Seo\Meta::effective($doc, [], new Plugins\Seo\Settings(), [
    'title' => 'Титул темы',
    'description' => 'Описание темы',
]);
echo "canon:   {$e['canonical']}\n";
echo "og:title {$e['og_title']}\n";
echo "robots:  {$e['robots']}\n";
exit(0);
