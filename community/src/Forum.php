<?php
/**
 * Страницы форума: каналы, темы, ответы.
 *
 * Всё под префиксом из настроек (по умолчанию /forum) и обрабатывается хуком
 * front.router.before. Ответ обязательно завершается exit — иначе
 * FrontController дорисует свою 404 поверх нашего ответа.
 */

namespace Plugins\Community;

class Forum
{
    private const TOPICS_PER_PAGE = 20;
    private const POSTS_PER_PAGE = 20;

    private Store $store;
    private Settings $settings;
    private Guard $guard;

    /** Префикс текущего запроса: форум или сообщества. */
    private ?string $activePrefix = null;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
        $this->settings = $this->store->settings();
        $this->guard = new Guard($this->store);
    }

    /**
     * Префикс для ссылок. Внутри сообщества ссылки должны вести в /community,
     * а не в /forum: иначе из сообщества выкидывает в другой раздел.
     */
    public function prefix(): string
    {
        return $this->activePrefix ?? $this->settings->forumPrefix();
    }

    /** Подпись раздела для хлебных крошек. */
    private function sectionLabel(): string
    {
        return $this->prefix() === Communities::PREFIX ? 'Сообщества' : 'Форум';
    }

    /**
     * Префикс, под которым пришёл запрос: форум или сообщества.
     *
     * Темы сообщества живут по тем же правилам, что и темы форума, поэтому
     * второй комплект страниц не нужен — достаточно знать, откуда пришли.
     */
    private function resolvePrefix(string $path): ?string
    {
        $forumPrefix = $this->prefix();

        foreach ([$forumPrefix, Communities::PREFIX] as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return $prefix;
            }
        }

        return null;
    }

    /** @return bool обработан ли запрос */
    public function handle(string $path): bool
    {
        $path = trim($path, '/');
        $prefix = $this->resolvePrefix($path);

        if ($prefix === null) {
            return false;
        }

        $this->activePrefix = $prefix;

        $rest = trim(substr($path, strlen($prefix)), '/');
        $parts = $rest === '' ? [] : explode('/', $rest);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // /forum и /community без слага
        if ($parts === []) {
            if ($prefix === Communities::PREFIX) {
                return (new Communities($this->store))->handle($prefix);
            }
            $this->index();
            return true;
        }

        $channelSlug = $parts[0];
        $channel = $this->store->channelBySlug($channelSlug);

        if (!$channel || (string) $channel['mode'] === Store::MODE_COMMENTS) {
            $this->notFound('Раздел не найден');
            return true;
        }

        if (!$this->guard->canRead($channel)) {
            $this->forbidden();
            return true;
        }

        // /forum/<channel> и /community/<slug>
        if (count($parts) === 1) {
            if ((string) $channel['mode'] === Store::MODE_COMMUNITY) {
                // страницу сообщества рисует Communities: у неё участники и заявки
                return (new Communities($this->store))->handle($prefix . '/' . $channelSlug);
            }
            $this->channelPage($channel);
            return true;
        }

        // /forum/<channel>/new
        if ($parts[1] === 'new') {
            if ($method === 'POST') {
                $this->createTopic($channel);
            } else {
                $this->newTopicForm($channel);
            }
            return true;
        }

        // /forum/<channel>/<topic>
        $topic = $this->store->topicBySlug((int) $channel['id'], $parts[1]);
        if (!$topic) {
            $this->notFound('Тема не найдена');
            return true;
        }

        if (count($parts) === 3 && $parts[2] === 'reply' && $method === 'POST') {
            $this->reply($channel, $topic);
            return true;
        }

        if (count($parts) === 3 && $parts[2] === 'moderate' && $method === 'POST') {
            $this->moderate($channel, $topic);
            return true;
        }

        $this->topicPage($channel, $topic);
        return true;
    }

    // ---------------------------------------------------------------- страницы

    private function index(): void
    {
        $channels = [];

        foreach ($this->store->channels(Store::MODE_FORUM) as $channel) {
            if (!$this->guard->canRead($channel)) {
                continue;
            }

            $last = $this->store->topics((int) $channel['id'], 1, 1);
            $channel['last_topic'] = $last['items'][0] ?? null;
            $channels[] = $channel;
        }

        Render::page('@community/forum/index.html.twig', [
            'title' => 'Форум',
            'channels' => $channels,
            'has_communities' => $this->store->communities() !== [],
            'prefix' => $this->prefix(),
            'section_label' => $this->sectionLabel(),
            'user' => $this->guard->user(),
            'flash' => Flash::take(),
        ]);
    }

    private function channelPage(array $channel): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $this->store->topics((int) $channel['id'], $page, self::TOPICS_PER_PAGE);
        $deny = $this->guard->denyReason($channel);

        Render::page('@community/forum/channel.html.twig', [
            'title' => (string) $channel['title'],
            'channel' => $channel,
            'topics' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::TOPICS_PER_PAGE)),
            'prefix' => $this->prefix(),
            'section_label' => $this->sectionLabel(),
            'can_post' => $deny === '',
            'deny' => $deny,
            'user' => $this->guard->user(),
            'flash' => Flash::take(),
        ]);
    }

    private function newTopicForm(array $channel): void
    {
        $deny = $this->guard->denyReason($channel);
        if ($deny !== '') {
            Flash::set('error', $deny);
            $this->redirect('/' . $this->prefix() . '/' . $channel['slug']);
        }

        Render::page('@community/forum/new_topic.html.twig', [
            'title' => 'Новая тема в «' . $channel['title'] . '»',
            'channel' => $channel,
            'prefix' => $this->prefix(),
            'section_label' => $this->sectionLabel(),
            'csrf' => Csrf::field(),
            'flash' => Flash::take(),
            'uploads' => $this->settings->bool('uploads_enabled', true),
            'upload_max' => $this->settings->int('upload_max_mb', 5),
            'user' => $this->guard->user(),
            'need_name' => $this->guard->user() === null && !$this->store->hasAccounts(),
        ]);
    }

    private function topicPage(array $channel, array $topic): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));

        // счётчик просмотров: один раз на тему за сессию, иначе цифры бесполезны
        $seen = $_SESSION['community_viewed'] ?? [];
        if (!in_array((int) $topic['id'], $seen, true)) {
            $this->store->countView((int) $topic['id']);
            $seen[] = (int) $topic['id'];
            $_SESSION['community_viewed'] = $seen;
        }

        $result = $this->store->posts(
            (int) $topic['id'],
            $page,
            self::POSTS_PER_PAGE,
            true,
            $this->guard->userId()
        );

        $deny = $this->guard->denyReason($channel);
        if ((int) $topic['is_locked'] === 1) {
            $deny = 'Тема закрыта: новые ответы не принимаются.';
        }

        Render::page('@community/forum/topic.html.twig', [
            'title' => (string) $topic['subject'],
            'channel' => $channel,
            'topic' => $topic,
            'posts' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::POSTS_PER_PAGE)),
            'prefix' => $this->prefix(),
            'section_label' => $this->sectionLabel(),
            'can_post' => $deny === '',
            'can_moderate' => $this->guard->canModerate((int) $channel['id']),
            'deny' => $deny,
            'reply_to' => (int) ($_GET['reply_to'] ?? 0),
            'user' => $this->guard->user(),
            'csrf' => Csrf::field(),
            'flash' => Flash::take(),
            'uploads' => $this->settings->bool('uploads_enabled', true),
            'upload_max' => $this->settings->int('upload_max_mb', 5),
            'need_name' => $this->guard->user() === null && !$this->store->hasAccounts(),
        ]);
    }

    // ---------------------------------------------------------------- действия

    private function createTopic(array $channel): void
    {
        $back = '/' . $this->prefix() . '/' . $channel['slug'] . '/new';

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела. Отправьте ещё раз.');
            $this->redirect($back);
        }

        $deny = $this->guard->denyReason($channel);
        if ($deny !== '') {
            Flash::set('error', $deny);
            $this->redirect($back);
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));

        if (mb_strlen($subject) < 3) {
            Flash::set('error', 'Заголовок темы слишком короткий.');
            $this->redirect($back);
        }
        if ($content === '') {
            Flash::set('error', 'Напишите первое сообщение темы.');
            $this->redirect($back);
        }

        $user = $this->guard->user();
        $antispam = new Antispam($this->store);
        $verdict = $antispam->check(
            'topic',
            $content,
            $antispam->ipHash(),
            (int) $channel['is_moderated'] === 1 || $user === null
        );

        if ($verdict['status'] === 'rejected') {
            Flash::set('error', $verdict['error']);
            $this->redirect($back);
        }
        if ($verdict['status'] === 'spam') {
            Flash::set('error', 'Сообщение похоже на спам и не отправлено.');
            $this->redirect($back);
        }

        $topic = $this->store->createTopic((int) $channel['id'], $subject, (int) ($user['id'] ?? 0));
        if (!$topic) {
            Flash::set('error', 'Не удалось создать тему.');
            $this->redirect($back);
        }

        $this->store->addPost([
            'topic_id' => (int) $topic['id'],
            'user_id' => (int) ($user['id'] ?? 0),
            'guest_name' => trim((string) ($_POST['guest_name'] ?? '')),
            'content' => $content,
            'content_html' => Markdown::toHtml($content),
            'status' => $verdict['status'],
            'count_links' => Markdown::linkCount($content),
            'ip_hash' => $antispam->ipHash(),
        ]);

        if ($verdict['status'] === 'published') {
            Flash::set('ok', 'Тема создана.');
        } else {
            Flash::set('ok', 'Тема создана, первое сообщение появится после проверки.');
        }

        $this->redirect('/' . $this->prefix() . '/' . $channel['slug'] . '/' . $topic['slug']);
    }

    private function reply(array $channel, array $topic): void
    {
        $back = '/' . $this->prefix() . '/' . $channel['slug'] . '/' . $topic['slug'];

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела. Отправьте ещё раз.');
            $this->redirect($back);
        }

        $deny = $this->guard->denyReason($channel);
        if ($deny !== '') {
            Flash::set('error', $deny);
            $this->redirect($back);
        }
        if ((int) $topic['is_locked'] === 1) {
            Flash::set('error', 'Тема закрыта.');
            $this->redirect($back);
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            Flash::set('error', 'Пустое сообщение.');
            $this->redirect($back);
        }

        $user = $this->guard->user();
        $antispam = new Antispam($this->store);
        $verdict = $antispam->check(
            'post',
            $content,
            $antispam->ipHash(),
            (int) $channel['is_moderated'] === 1 || $user === null
        );

        if ($verdict['status'] === 'rejected') {
            Flash::set('error', $verdict['error']);
            $this->redirect($back);
        }
        if ($verdict['status'] === 'spam') {
            Flash::set('error', 'Сообщение похоже на спам и не отправлено.');
            $this->redirect($back);
        }

        $postId = $this->store->addPost([
            'topic_id' => (int) $topic['id'],
            'user_id' => (int) ($user['id'] ?? 0),
            'guest_name' => trim((string) ($_POST['guest_name'] ?? '')),
            'parent_id' => (int) ($_POST['parent_id'] ?? 0),
            'content' => $content,
            'content_html' => Markdown::toHtml($content),
            'status' => $verdict['status'],
            'count_links' => Markdown::linkCount($content),
            'ip_hash' => $antispam->ipHash(),
        ]);

        if (!empty($_POST['notify']) && $user !== null) {
            $this->store->follow((int) $topic['id'], (int) $user['id']);
        }

        if ($verdict['status'] === 'published') {
            (new Notify($this->store))->aboutReply((int) $topic['id'], $postId, $user);
            Flash::set('ok', 'Ответ добавлен.');
        } else {
            Flash::set('ok', 'Ответ появится после проверки.');
        }

        // сразу к последнему сообщению
        $total = (int) $topic['count_posts'] + 1;
        $lastPage = max(1, (int) ceil($total / self::POSTS_PER_PAGE));
        $this->redirect($back . ($lastPage > 1 ? '?page=' . $lastPage : '') . '#post-' . $postId);
    }

    private function moderate(array $channel, array $topic): void
    {
        $back = '/' . $this->prefix() . '/' . $channel['slug'] . '/' . $topic['slug'];

        if (!$this->guard->canModerate((int) $channel['id'])) {
            Flash::set('error', 'Недостаточно прав.');
            $this->redirect($back);
        }

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела.');
            $this->redirect($back);
        }

        $action = (string) ($_POST['action'] ?? '');
        $postId = (int) ($_POST['post_id'] ?? 0);

        switch ($action) {
            case 'lock':
                $this->store->lockTopic((int) $topic['id'], true);
                break;
            case 'unlock':
                $this->store->lockTopic((int) $topic['id'], false);
                break;
            case 'stick':
                $this->store->stickTopic((int) $topic['id'], true);
                break;
            case 'unstick':
                $this->store->stickTopic((int) $topic['id'], false);
                break;
            case 'approve':
                $this->store->setPostStatus($postId, 'published');
                break;
            case 'spam':
                $this->store->setPostStatus($postId, 'spam');
                break;
            case 'delete':
                $this->store->deletePost($postId);
                break;
        }

        $this->redirect($back);
    }

    // ---------------------------------------------------------------- мелочи

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
        exit;
    }

    private function notFound(string $text): void
    {
        http_response_code(404);
        Render::page('@community/404.html.twig', [
            'title' => 'Не найдено',
            'message' => $text,
            'prefix' => $this->prefix(),
        ]);
        exit;
    }

    private function forbidden(): void
    {
        http_response_code(403);
        Render::page('@community/404.html.twig', [
            'title' => 'Нет доступа',
            'message' => 'Этот раздел доступен только участникам.',
            'prefix' => $this->prefix(),
        ]);
        exit;
    }
}
