<?php
/**
 * Самопроверка анализатора без CMS и без базы.
 *
 * Запуск (из корня сайта, где плагин уже лежит):
 *   php plugins/seo/tools/selftest.php
 *
 * Нужен, чтобы правка чек-листа не сломала оценки молча: анализатор — чистая
 * логика, ему хватает файлов src/Analyzer.php, src/Meta.php, src/Settings.php.
 * Код возврата 1 — есть провал.
 */

$src = dirname(__DIR__) . '/src';
require $src . '/Settings.php';
require $src . '/Meta.php';
require $src . '/Analyzer.php';

use Plugins\Seo\Analyzer;
use Plugins\Seo\Settings;

$failed = 0;
$passed = 0;

function ok(string $name, bool $condition, string $details = ''): void
{
    global $failed, $passed;
    if ($condition) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
}

/** @return array<string, array<string,mixed>> id => check */
function byId(array $checks): array
{
    $map = [];
    foreach ($checks as $check) {
        $map[$check['id']] = $check;
    }
    return $map;
}

$settings = new Settings();
$analyzer = new Analyzer($settings);

// Готовим счетчики дублей: два документа с одинаковым заголовком
$analyzer->setSiteCounts(
    ['одинаковый заголовок страницы для теста' => 2],
    []
);

// Текст для «хорошей» страницы: фраза встречается 3 раза на ~300 слов —
// плотность около 4%, то есть в норме, а не переспам.
$longText = '<h1>Как выбрать промт</h1><p>'
    . 'Промт для нейросети задаёт роль и формат ответа. '
    . str_repeat('Текст про оформление, проверку и правки результата. ', 34)
    . 'Промт для нейросети экономит время на переписывании. '
    . str_repeat('Ещё немного текста про примеры и разбор ошибок. ', 8)
    . 'Промт для нейросети стоит проверить на одном примере перед работой.'
    . '</p><h2>Разбор</h2>';

$good = [
    'key' => 'page:pages:1',
    'type' => 'page',
    'table' => 'pages',
    'id' => 1,
    'url' => '/kak-vybrat-prompt',
    'title' => 'Как выбрать промт для нейросети: пошаговый разбор',
    'slug' => 'kak-vybrat-prompt',
    'description' => 'Пошаговый разбор: как составить промт для нейросети, чтобы получить предсказуемый результат с первого раза.',
    'image' => '/storage/uploads/cover.jpg',
    'content' => $longText,
    'updated_at' => '2026-11-20 10:00:00',
];

$row = [
    'title' => '',
    'description' => '',
    'focus_keyword' => 'промт для нейросети',
    'canonical' => '',
    'robots' => '',
    'og_title' => '',
    'og_description' => '',
    'og_image' => '',
];

echo "Анализатор: хорошая страница\n";
$result = $analyzer->analyze($good, $row);
$checks = byId($result['checks']);
ok('оценка высокая', $result['score'] >= 70, 'score=' . $result['score']);
ok('H1 найден', $checks['h1_count']['status'] === 'ok');
ok('описание задано', $checks['description_present']['status'] === 'ok');
ok('фраза в заголовке', $checks['keyword_in_title']['status'] === 'ok');
ok('фраза в тексте', $checks['keyword_in_content']['status'] === 'ok');
ok('плотность в норме', $checks['keyword_density']['status'] === 'ok', $checks['keyword_density']['message']);
ok('картинка для соцсетей', $checks['og_image']['status'] === 'ok');
ok('адрес латиницей', $checks['slug_quality']['status'] === 'ok');

echo "Анализатор: пустая страница\n";
$empty = $good;
$empty['content'] = '';
$empty['description'] = '';
$emptyResult = $analyzer->analyze($empty, []);
$emptyChecks = byId($emptyResult['checks']);
ok('оценка ниже', $emptyResult['score'] < $result['score'], $emptyResult['score'] . ' < ' . $result['score']);
ok('описание не задано — fail', $emptyChecks['description_present']['status'] === 'fail');
ok('объём не считается — na', $emptyChecks['content_length']['status'] === 'na');

echo "Анализатор: запись таблицы\n";
$record = [
    'key' => 'record:catalog:10',
    'type' => 'record',
    'table' => 'catalog',
    'id' => 10,
    'url' => '/catalog/yuridicheskiy-konsultant',
    'title' => 'Юридический консультант',
    'slug' => 'yuridicheskiy-konsultant',
    'description' => 'Разбор договоров и поиск рисков',
    'image' => '',
    'content' => '<div>Разбор договоров и поиск рисков</div>',
    'updated_at' => '',
];
$recordResult = $analyzer->analyze($record, []);
$recordChecks = byId($recordResult['checks']);
ok('H1 берётся из заголовка записи', $recordChecks['h1_count']['status'] === 'ok');
ok('ссылки в записи не проверяются', $recordChecks['internal_links']['status'] === 'na');
ok('подзаголовки в записи не проверяются', $recordChecks['headings']['status'] === 'na');

echo "Анализатор: дубли\n";
$dup = $good;
$dup['title'] = 'Одинаковый заголовок страницы для теста';
$dupResult = $analyzer->analyze($dup, []);
$dupChecks = byId($dupResult['checks']);
ok('дубль заголовка замечен', $dupChecks['title_unique']['status'] === 'warn');

echo "Анализатор: адрес\n";
$badSlug = $good;
$badSlug['slug'] = 'как-выбрать-промт';
$badResult = $analyzer->analyze($badSlug, []);
ok('кириллица в адресе — fail', byId($badResult['checks'])['slug_quality']['status'] === 'fail');

echo "Анализатор: robots\n";
$noindex = $analyzer->analyze($good, ['robots' => 'noindex,follow']);
ok('noindex замечен', byId($noindex['checks'])['robots_index']['status'] === 'warn');

echo "Плотность ключевой фразы\n";
$density = Analyzer::density('промт для нейросети и ещё раз промт для нейросети', 'промт для нейросети');
ok('плотность считается', $density > 0.5 && $density <= 1.0, (string) $density);

echo "\n";
if ($failed > 0) {
    echo "ПРОВАЛЕНО: {$failed}, прошло: {$passed}\n";
    exit(1);
}

echo "Все проверки прошли: {$passed}\n";
exit(0);
