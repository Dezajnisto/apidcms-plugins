<?php
/**
 * Сообщества: список, страница сообщества, вступление, заявки.
 *
 * Сообщество — тот же канал (mode = community), поэтому темы, ответы и
 * модерация сообщений работают общим кодом Forum. Здесь только то, чего
 * у форума нет: участники, вступление и заявки.
 *
 * Обрабатываем лишь «свои» адреса — /community, /community/<slug> и действия
 * под ним. Более глубокие пути (/community/<slug>/<тема>) отдаём Forum.
 */

namespace Plugins\Community;

class Communities
{
    public const PREFIX = 'community';

    private Store $store;
    private Settings $settings;
    private Guard $guard;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
        $this->settings = $this->store->settings();
        $this->guard = new Guard($this->store);
    }

    /** @return bool обработан ли запрос */
    public function handle(string $path): bool
    {
        $path = trim($path, '/');

        if ($path === self::PREFIX) {
            $this->index();
            return true;
        }

        if (strpos($path, self::PREFIX . '/') !== 0) {
            return false;
        }

        $parts = explode('/', substr($path, strlen(self::PREFIX) + 1));
        $slug = (string) ($parts[0] ?? '');

        if ($slug === '') {
            $this->index();
            return true;
        }

        $channel = $this->store->channelBySlug($slug);

        if (!$channel || (string) $channel['mode'] !== Store::MODE_COMMUNITY) {
            return false; // возможно, это раздел форума — пусть решает Forum
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = (string) ($parts[1] ?? '');

        // /community/<slug> — сама страница
        if (count($parts) === 1) {
            $this->show($channel);
            return true;
        }

        if ($action === 'join' && $method === 'POST') {
            $this->join($channel);
            return true;
        }

        if ($action === 'leave' && $method === 'POST') {
            $this->leave($channel);
            return true;
        }

        if ($action === 'members' && $method === 'POST') {
            $this->manageMember($channel);
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------- страницы

    private function index(): void
    {
        $items = [];

        foreach ($this->store->communities() as $channel) {
            // Закрытые сообщества тоже показываем: иначе о них никто не узнает
            // и заявку подать негде. Содержимое закрывает Guard, здесь только
            // название, описание и число участников.
            $channel['join'] = $this->guard->joinState($channel);
            $channel['locked'] = !$this->guard->canRead($channel);
            $channel['last_topic'] = $channel['locked']
                ? null
                : (($this->store->topics((int) $channel['id'], 1, 1)['items'] ?? [])[0] ?? null);
            $items[] = $channel;
        }

        Render::page('@community/community/index.html.twig', [
            'title' => 'Сообщества',
            'communities' => $items,
            'forum_prefix' => $this->settings->forumPrefix(),
            'user' => $this->guard->user(),
            'flash' => Flash::take(),
        ]);
    }

    private function show(array $channel): void
    {
        $channelId = (int) $channel['id'];

        // Не участнику показываем витрину: название, описание, число участников
        // и кнопку вступления. Список тем и участников — только своим.
        $locked = !$this->guard->canRead($channel);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $topics = $locked ? ['items' => [], 'total' => 0] : $this->store->topics($channelId, $page, 20);

        $canModerate = $this->guard->canModerate($channelId);
        $deny = $this->guard->denyReason($channel);

        Render::page('@community/community/show.html.twig', [
            'title' => (string) $channel['title'],
            'channel' => $channel,
            'topics' => $topics['items'],
            'total' => $topics['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($topics['total'] / 20)),
            'locked' => $locked,
            'members' => $locked ? [] : $this->store->members($channelId, 'active', 60),
            'applications' => $canModerate ? $this->store->applications($channelId) : [],
            'can_moderate' => $canModerate,
            'can_post' => $deny === '',
            'deny' => $deny,
            'join' => $this->guard->joinState($channel),
            'join_policy' => $this->guard->joinPolicy($channel),
            'csrf' => Csrf::field(),
            'user' => $this->guard->user(),
            'flash' => Flash::take(),
        ]);
    }

    // ---------------------------------------------------------------- действия

    private function join(array $channel): void
    {
        $back = '/' . self::PREFIX . '/' . $channel['slug'];

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела. Обновите страницу и попробуйте снова.');
            $this->redirect($back);
        }

        $state = $this->guard->joinState($channel);

        if (!$state['allowed'] || !in_array($state['mode'], ['join', 'apply'], true)) {
            Flash::set('error', $state['reason'] !== '' ? $state['reason'] : 'Вступление недоступно.');
            $this->redirect($back);
        }

        $userId = $this->guard->userId();

        if ($state['mode'] === 'apply') {
            $this->store->setMembership((int) $channel['id'], $userId, 'pending');

            // модераторам сообщества — письмо, чтобы заявка не висела незамеченной
            (new Notify($this->store))->aboutApplication($channel, $userId);

            Flash::set('ok', 'Заявка отправлена. Модератор сообщества её рассмотрит.');
            $this->redirect($back);
        }

        $this->store->setMembership((int) $channel['id'], $userId, 'active');
        Flash::set('ok', 'Вы вступили в сообщество. Теперь можно писать.');
        $this->redirect($back);
    }

    private function leave(array $channel): void
    {
        $back = '/' . self::PREFIX . '/' . $channel['slug'];

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела.');
            $this->redirect($back);
        }

        $userId = $this->guard->userId();
        if ($userId <= 0) {
            $this->redirect($back);
        }

        if ($this->guard->canModerate((int) $channel['id'])) {
            Flash::set('error', 'Модератор не может выйти из своего сообщества.');
            $this->redirect($back);
        }

        $this->store->removeMembership((int) $channel['id'], $userId);
        Flash::set('ok', 'Вы вышли из сообщества.');
        $this->redirect($back);
    }

    /** Действия модератора с участниками и заявками. */
    private function manageMember(array $channel): void
    {
        $back = '/' . self::PREFIX . '/' . $channel['slug'];

        if (!$this->guard->canModerate((int) $channel['id'])) {
            Flash::set('error', 'Недостаточно прав.');
            $this->redirect($back);
        }

        if (!Csrf::valid($_POST['_csrf'] ?? '')) {
            Flash::set('error', 'Форма устарела.');
            $this->redirect($back);
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        if ($userId <= 0) {
            Flash::set('error', 'Участник не найден.');
            $this->redirect($back);
        }

        // себя модератор не понижает и не выгоняет: иначе сообщество останется
        // без ответственного, а вернуть права сможет только админ сайта
        if ($userId === $this->guard->userId() && in_array($action, ['remove', 'demote'], true)) {
            Flash::set('error', 'Нельзя понизить или удалить самого себя.');
            $this->redirect($back);
        }

        switch ($action) {
            case 'approve':
                $this->store->setMembership((int) $channel['id'], $userId, 'active');
                Flash::set('ok', 'Заявка одобрена.');
                break;
            case 'reject':
                $this->store->removeMembership((int) $channel['id'], $userId);
                Flash::set('ok', 'Заявка отклонена.');
                break;
            case 'promote':
                $this->store->setMemberRole((int) $channel['id'], $userId, 'moderator');
                Flash::set('ok', 'Права модератора выданы.');
                break;
            case 'demote':
                $this->store->setMemberRole((int) $channel['id'], $userId, 'member');
                Flash::set('ok', 'Участник стал обычным участником.');
                break;
            case 'remove':
                $this->store->removeMembership((int) $channel['id'], $userId);
                Flash::set('ok', 'Участник удалён из сообщества.');
                break;
            default:
                Flash::set('error', 'Неизвестное действие.');
        }

        $this->redirect($back);
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 302);
        exit;
    }
}
