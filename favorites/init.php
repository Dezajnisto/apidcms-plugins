<?php
/**
 * Favorites Plugin - init.php
 */

use Core\PluginManager;
use Twig\TwigFunction;

$pm = PluginManager::getInstance();
$pluginDir = __DIR__;

// === Migrations ===
$pm->addAction('db.migrate', function ($db) use ($pluginDir) {
    $migrationFile = $pluginDir . '/migrations/001_create_table.sql';
    if (!file_exists($migrationFile)) return;
    $sql = file_get_contents($migrationFile);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            try { $db->exec($stmt); }
            catch (\Exception $e) { error_log("favorites migration error: " . $e->getMessage()); }
        }
    }
}, 10, 'favorites');

require_once $pluginDir . '/FavoritesController.php';

// === Twig functions ===
$pm->addAction('twig.init', function ($fc, $twig) {

    // is_favorited(type, slug) -> bool
    $twig->addFunction(new TwigFunction('is_favorited', function (string $entityType, string $entitySlug) {
        if (empty($_SESSION['user_id'])) return false;
        return \Plugins\Favorites\Controller::isFavorited((int)$_SESSION['user_id'], $entityType, $entitySlug);
    }));

    // favorite_button(type, slug) -> HTML
    $twig->addFunction(new TwigFunction('favorite_button', function (string $entityType, string $entitySlug) {
        if (empty($_SESSION['user_id'])) return '';
        $favorited = \Plugins\Favorites\Controller::isFavorited((int)$_SESSION['user_id'], $entityType, $entitySlug);
        $heart = $favorited ? "\u{2764}\u{FE0F}" : "\u{1F90D}";
        $cls   = $favorited ? 'fav-heart fav-heart--active' : 'fav-heart';
        $label = $favorited ? 'В избранном' : 'В избранное';
        $t = htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8');
        $s = htmlspecialchars($entitySlug, ENT_QUOTES, 'UTF-8');
        return '<button class="' . $cls . '" onclick="favToggle(event,\'' . $t . '\',\'' . $s . '\')">'
             . '<span class="fav-heart__icon">' . $heart . '</span>'
             . '<span class="fav-heart__label">' . $label . '</span></button>';
    }, ['is_safe' => ['html']]));

    // favorites_scripts() -> JS
    $twig->addFunction(new TwigFunction('favorites_scripts', function () {
        if (empty($_SESSION['user_id'])) return '';
        return <<<'JS'
<script>(function(){if(window.__favLoaded)return;window.__favLoaded=true;
var HO="\u2764\ufe0f",HF="\uD83E\uDD0D",LO="В избранном",LF="В избранное";
window.favToggle=function(ev,type,slug){ev.preventDefault();var btn=ev.currentTarget;
fetch('/api/favorites/toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({entity_type:type,entity_slug:slug})})
.then(function(r){return r.json()}).then(function(d){if(d.ok){var icon=btn.querySelector('.fav-heart__icon');var label=btn.querySelector('.fav-heart__label');
if(d.favorited){icon.textContent=HO;label.textContent=LO;btn.classList.add('fav-heart--active')}
else{icon.textContent=HF;label.textContent=LF;btn.classList.remove('fav-heart--active')}}
}).catch(function(){})};
window.favRemoveCard=function(ev,type,slug){ev.preventDefault();var card=ev.currentTarget.closest('.fav-card');
fetch('/api/favorites/toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({entity_type:type,entity_slug:slug})})
.then(function(r){return r.json()}).then(function(d){if(d.ok&&!d.favorited){card.style.opacity='0';card.style.transition='opacity .2s';setTimeout(function(){card.remove()},200)}}
).catch(function(){})};})();
</script>
JS;
    }, ['is_safe' => ['html']]));

    // user_favorites() -> raw array [{entity_type, entity_slug, created_at}]
    $twig->addFunction(new TwigFunction('user_favorites', function () {
        if (empty($_SESSION['user_id'])) return [];
        return \Plugins\Favorites\Controller::getUserFavorites((int)$_SESSION['user_id']);
    }));

    // favorite_items() -> enriched array with full item data (catalog lookup)
    $twig->addFunction(new TwigFunction('favorite_items', function () {
        if (empty($_SESSION['user_id'])) return [];
        $favorites = \Plugins\Favorites\Controller::getUserFavorites((int)$_SESSION['user_id']);
        if (empty($favorites)) return [];
        $db = null;
        if (!defined('FRONT_ACCESS')) { define('FRONT_ACCESS', true); }
        $config = require __DIR__ . '/../../front/config/config.php';
        $db = new \Core\Database($config['database']);
        $items = [];
        foreach ($favorites as $fav) {
            if ($fav['entity_type'] === 'catalog') {
                $item = $db->query(
                    "SELECT id, slug, title, description, cover_emoji, category FROM catalog WHERE slug = ? AND status = 'active'",
                    [$fav['entity_slug']]
                )->fetch();
                if ($item) {
                    $item['favorited_at'] = $fav['created_at'];
                    $items[] = $item;
                }
            }
        }
        return $items;
    }));

    // favorites_count() -> int
    $twig->addFunction(new TwigFunction('favorites_count', function () {
        if (empty($_SESSION['user_id'])) return 0;
        return \Plugins\Favorites\Controller::getCount((int)$_SESSION['user_id']);
    }));
}, 10, 'favorites');

// === Routes ===
$pm->addAction('front.router.before', function ($path, $fc) {
    if ($path === 'api/favorites/toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        if (empty($_SESSION['user_id'])) { http_response_code(401); echo '{"error":"Not logged in"}'; exit; }
        \Plugins\Favorites\Controller::toggle((int)$_SESSION['user_id']);
        exit;
    }
    if ($path === 'api/favorites/list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json');
        if (empty($_SESSION['user_id'])) { http_response_code(401); echo '{"error":"Not logged in"}'; exit; }
        \Plugins\Favorites\Controller::list((int)$_SESSION['user_id']);
        exit;
    }
}, 30, 'favorites');
