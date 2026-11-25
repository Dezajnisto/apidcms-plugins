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
            'flash' => Flash::take(),
        ]);
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
