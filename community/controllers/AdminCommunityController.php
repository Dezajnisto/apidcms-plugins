<?php
/**
 * Админка сообщества: очередь проверки и каналы.
 *
 * Модерация сделана одной страницей, а не таблицами ядра: владельцу нужно
 * видеть сразу «что ждёт решения», а не разбираться в фильтрах. Каналы при
 * этом живут в обычной таблице ядра (/admin/table/plugin_community_channels).
 */

namespace Plugins\Community;

class AdminCommunityController extends \Admin\BaseController
{
    private ?Store $store = null;

    private function store(): Store
    {
        if ($this->store === null) {
            $this->store = new Store($this->db, new Settings());
        }
        return $this->store;
    }

    public function index(): void
    {
        $store = $this->store();

        // Адрес обсуждения считаем здесь: у темы он зависит от того, привязана
        // она к странице, к записи каталога или живёт в форуме.
        $links = new Links($store);
        $pending = $store->pending(100);
        foreach ($pending as &$post) {
            $post['url'] = $links->topicUrlById((int) $post['topic_id']);
        }
        unset($post);

        $this->render('@community/admin/moderation.html.twig', [
            'title' => 'Сообщество',
            'pending' => $pending,
            'pending_count' => $store->pendingCount(),
            'channels' => $store->channels('', true),
            'applications' => $store->pendingApplications(100),
            'applications_count' => $store->pendingApplicationsCount(),
            'flash' => Flash::take(),
        ]);
    }

    /**
     * Настройки сообщества: политика вступления и закрытость.
     *
     * Эти два поля есть и в generic-таблице ядра, но там join_policy —
     * обычная textarea: легко написать «approve» вместо «approval» и получить
     * молча неверное поведение. Здесь список допустимых значений.
     */
    public function channel(): void
    {
        $store = $this->store();
        $channelId = (int) ($_POST['channel_id'] ?? 0);
        $channel = $store->channel($channelId);

        if (!$channel) {
            $this->setFlash('error', 'Раздел не найден');
            $this->redirect('/community');
            return;
        }

        $policy = (string) ($_POST['join_policy'] ?? 'open');
        if (!in_array($policy, ['open', 'approval', 'closed'], true)) {
            $policy = 'open';
        }

        $isPrivate = !empty($_POST['is_private']) ? 1 : 0;

        $store->db()->query(
            "UPDATE plugin_community_channels
                SET join_policy = ?, is_private = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?",
            [$policy, $isPrivate, $channelId]
        );

        $this->setFlash('success', 'Настройки сообщества «' . $channel['title'] . '» сохранены');
        $this->redirect('/community');
    }

    /** Админ может одобрить заявку, не заходя на страницу сообщества. */
    public function application(): void
    {
        $store = $this->store();
        $channelId = (int) ($_POST['channel_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        if ($channelId <= 0 || $userId <= 0) {
            $this->setFlash('error', 'Заявка не найдена');
            $this->redirect('/community');
            return;
        }

        if ($action === 'approve') {
            $store->setMembership($channelId, $userId, 'active');
            $this->setFlash('success', 'Заявка одобрена');
        } elseif ($action === 'reject') {
            $store->removeMembership($channelId, $userId);
            $this->setFlash('success', 'Заявка отклонена');
        } else {
            $this->setFlash('error', 'Неизвестное действие');
        }

        $this->redirect('/community');
    }

    public function moderate(): void
    {
        $store = $this->store();
        $postId = (int) ($_POST['post_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        if ($postId <= 0) {
            $this->setFlash('error', 'Сообщение не найдено');
            $this->redirect('/community');
            return;
        }

        switch ($action) {
            case 'approve':
                $store->setPostStatus($postId, 'published');
                $this->setFlash('success', 'Сообщение опубликовано');
                break;
            case 'spam':
                $store->setPostStatus($postId, 'spam');
                $this->setFlash('success', 'Помечено как спам');
                break;
            case 'delete':
                $store->deletePost($postId);
                $this->setFlash('success', 'Сообщение удалено');
                break;
            default:
                $this->setFlash('error', 'Неизвестное действие');
        }

        $this->redirect('/community');
    }
}
