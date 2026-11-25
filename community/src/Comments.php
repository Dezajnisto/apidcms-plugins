<?php
/**
 * Комментарии к страницам сайта.
 *
 * Тема создаётся не при просмотре страницы, а при первом комментарии: иначе
 * каждый заход на каждую страницу писал бы строку в БД, а админка показывала
 * бы сотни пустых тем. Пока комментариев нет, блок рисует пустое состояние
 * и форму — этого достаточно, чтобы тема появилась в момент отправки.
 */

namespace Plugins\Community;

class Comments
{
    private Store $store;
    private Settings $settings;
    private Guard $guard;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
        $this->settings = $this->store->settings();
        $this->guard = new Guard($this->store);
    }

    /**
     * Что за страница сейчас рендерится и можно ли под ней комментировать.
     *
     * Определяем по данным, а не по имени шаблона: у тем бывают свои шаблоны
     * страниц (kontakty.html.twig и т. п.), и жёсткий список имён ломался бы на
     * каждом сайте. Ключи page/post в этом ядре передаются только при рендере
     * одной страницы или одной записи — списки и поиск их не передают.
     *
     * @return array{code:string,subject:string}|null
     */
    public static function target(array $data, string $template): ?array
    {
        // главную не комментируем: там обычно лендинг, а не статья
        if (!empty($data['is_home'])) {
            return null;
        }

        if (!empty($data['page']['id'])) {
            return [
                'code' => 'page:' . (int) $data['page']['id'],
                'subject' => (string) ($data['page']['title'] ?? 'Страница'),
            ];
        }

        if (!empty($data['post']['id'])) {
            return [
                'code' => 'item:posts:' . (int) $data['post']['id'],
                'subject' => (string) ($data['post']['title'] ?? 'Запись'),
            ];
        }

        return null;
    }

    public function enabled(): bool
    {
        return $this->settings->bool('comments_enabled', true);
    }

    public function excluded(): bool
    {
        $path = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        foreach ($this->settings->excludedPaths() as $excluded) {
            if ($path === $excluded || strpos($path . '/', $excluded . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /** HTML блока комментариев для вставки под текстом страницы. */
    public function block(string $code, string $subject): string
    {
        $channel = $this->store->commentsChannel();
        if (!$channel) {
            return '';
        }

        $topic = $this->store->topicByEmbed($code, (int) $channel['id']);

        $page = max(1, (int) ($_GET['cpage'] ?? 1));
        $perPage = max(5, $this->settings->int('comments_per_page', 30));

        $posts = ['items' => [], 'total' => 0];
        if ($topic) {
            $posts = $this->store->posts(
                (int) $topic['id'],
                $page,
                $perPage,
                true,
                $this->guard->userId()
            );
        }

        $deny = $this->guard->denyReason($channel);

        return Render::fragment('@community/comments.html.twig', [
            'code' => $code,
            'subject' => $subject,
            'channel' => $channel,
            'topic' => $topic,
            'posts' => $posts['items'],
            'total' => $posts['total'],
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($posts['total'] / $perPage)),
            'can_post' => $deny === '',
            'deny' => $deny,
            'user' => $this->guard->user(),
            'csrf' => Csrf::field(),
            'flash' => Flash::take(),
            'uploads' => $this->settings->bool('uploads_enabled', true),
            'upload_max' => $this->settings->int('upload_max_mb', 5),
            'return_to' => (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
        ]);
    }

    /**
     * POST /community/reply — новый комментарий к странице.
     * Ответ всегда редиректом: обновление страницы не должно отправлять второй раз.
     */
    public function reply(): void
    {
        $returnTo = $this->safeReturn((string) ($_POST['return_to'] ?? '/'));
        $code = trim((string) ($_POST['embed_code'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? 'Страница'));

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            $this->back($returnTo, 'error', 'Форма устарела. Обновите страницу и отправьте ещё раз.');
        }

        $channel = $this->store->commentsChannel();
        if (!$channel) {
            $this->back($returnTo, 'error', 'Комментарии не настроены.');
        }

        $deny = $this->guard->denyReason($channel);
        if ($deny !== '') {
            $this->back($returnTo, 'error', $deny);
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            $this->back($returnTo, 'error', 'Пустое сообщение.');
        }

        $user = $this->guard->user();
        $guestName = '';
        $guestEmail = '';

        if ($user === null) {
            $guestName = trim((string) ($_POST['guest_name'] ?? ''));
            $guestEmail = trim((string) ($_POST['guest_email'] ?? ''));

            if (mb_strlen($guestName) < 2) {
                $this->back($returnTo, 'error', 'Представьтесь, пожалуйста: имя нужно для ответа.');
            }
            if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                $this->back($returnTo, 'error', 'Почта выглядит неправильно.');
            }
        }

        // гости всегда через проверку, даже если канал не модерируется
        $moderated = (int) $channel['is_moderated'] === 1 || $user === null;

        $antispam = new Antispam($this->store);
        $verdict = $antispam->check('post', $content, $antispam->ipHash(), $moderated);

        if ($verdict['status'] === 'rejected') {
            $this->back($returnTo, 'error', $verdict['error']);
        }
        if ($verdict['status'] === 'spam') {
            $this->back($returnTo, 'error', 'Сообщение похоже на спам и не отправлено.');
        }

        $topic = $this->store->ensureEmbedTopic($code, (int) $channel['id'], $subject);
        if (!$topic) {
            $this->back($returnTo, 'error', 'Не удалось открыть обсуждение. Попробуйте позже.');
        }

        $postId = $this->store->addPost([
            'topic_id' => (int) $topic['id'],
            'user_id' => $user['id'] ?? 0,
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'parent_id' => (int) ($_POST['parent_id'] ?? 0),
            'content' => $content,
            'content_html' => Markdown::toHtml($content),
            'status' => $verdict['status'],
            'count_links' => Markdown::linkCount($content),
            'ip_hash' => $antispam->ipHash(),
        ]);

        if ($verdict['status'] === 'published') {
            (new Notify($this->store))->aboutReply((int) $topic['id'], $postId, $user);
            $this->back($returnTo, 'ok', 'Спасибо! Комментарий опубликован.');
        }

        $this->back($returnTo, 'ok', 'Спасибо! Комментарий появится после проверки.');
    }

    private function back(string $to, string $type, string $text): void
    {
        Flash::set($type, $text);
        header('Location: ' . $to . '#comments', true, 302);
        exit;
    }

    /** Куда возвращать: только свой сайт и только путь, без чужих доменов. */
    private function safeReturn(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            return '/';
        }
        if (strpos($path, '//') === 0) {
            return '/';
        }
        return $path;
    }
}
