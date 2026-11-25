<?php
/**
 * Админка веб-чата: список обращений, переписка, ответ из панели.
 *
 * Ответ из панели — резервный путь: основной сценарий — отвечать из MAX или
 * Telegram. Но если мессенджер недоступен, диалог не должен вставать.
 */

namespace Plugins\Webchat;

class AdminChatsController extends \Admin\BaseController
{
    private ?Chat $chat = null;

    private function chat(): Chat
    {
        if ($this->chat === null) {
            $this->chat = new Chat($this->db, new Settings());
        }
        return $this->chat;
    }

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? 'open');
        $search = trim((string) ($_GET['q'] ?? ''));

        $chat = $this->chat();
        $threads = $chat->listThreads($status === 'all' ? '' : $status, $search, 100);

        $this->render('@webchat/chats.html.twig', [
            'title' => 'Чаты',
            'threads' => $threads,
            'status' => $status,
            'search' => $search,
            'unread' => $chat->unreadCount(),
            'channel_ready' => $chat->channelReady(),
            'channel' => $chat->settings()->channel(),
        ]);
    }

    public function thread(string $id = ''): void
    {
        $chat = $this->chat();
        $thread = $chat->getThread((int) $id);
        if (!$thread) {
            $this->setFlash('error', 'Обращение не найдено');
            $this->redirect('/chats');
            return;
        }

        $chat->markRead((int) $thread['id']);

        $this->render('@webchat/thread.html.twig', [
            'title' => 'Обращение #' . (int) $thread['id'],
            'thread' => $thread,
            'messages' => $chat->messages((int) $thread['id']),
            'channel_ready' => $chat->channelReady(),
        ]);
    }

    public function reply(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/chats/' . (int) $id);
            return;
        }

        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text === '') {
            $this->setFlash('error', 'Пустое сообщение');
            $this->redirect('/chats/' . (int) $id);
            return;
        }

        $result = $this->chat()->addOperatorMessage((int) $id, $text, 'админка');
        if ($result['ok']) {
            $this->setFlash('success', 'Отправлено');
        } else {
            $this->setFlash('error', $result['error']);
        }
        $this->redirect('/chats/' . (int) $id);
    }

    public function status(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/chats');
            return;
        }

        $status = ((string) ($_POST['status'] ?? 'closed')) === 'closed' ? 'closed' : 'open';
        $this->chat()->setStatus((int) $id, $status);
        $this->setFlash('success', $status === 'closed' ? 'Обращение закрыто' : 'Обращение открыто заново');
        $this->redirect('/chats/' . (int) $id);
    }

    public function remove(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/chats');
            return;
        }

        $this->chat()->deleteThread((int) $id);
        $this->setFlash('success', 'Обращение удалено');
        $this->redirect('/chats');
    }

    /**
     * Проверка подключения: подписать вебхук и отправить тестовое сообщение.
     * Ошибки канала (токен, права бота, темы) вылезают здесь, а не на живом
     * обращении посетителя.
     */
    public function check(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/chats');
            return;
        }

        $chat = $this->chat();
        $channel = $chat->channel();
        $settings = $chat->settings();

        if (!$channel) {
            $this->setFlash('error', 'Канал не выбран');
            $this->redirect('/chats');
            return;
        }

        $tokenSet = $channel->name() === 'telegram'
            ? (string) $settings->get('tg_token') !== ''
            : (string) $settings->get('max_token') !== '';

        if (!$tokenSet) {
            $this->setFlash('error', 'Сначала впишите токен бота в настройках плагина');
            $this->redirect('/chats');
            return;
        }

        $secret = $chat->webhookSecret();
        $url = $chat->webhookUrl($channel->name());
        $hook = $channel->ensureWebhook($url, $secret);

        // Получатель может быть ещё неизвестен (бот только что заведён).
        // Тогда подписываем вебхук и ждём, пока владелец напишет боту —
        // адрес запомнится сам из этого события.
        $hasRecipient = $channel->name() === 'telegram'
            ? (string) $settings->get('tg_chat_id') !== ''
            : (string) $settings->get('max_chat_id') !== '';

        $test = ['ok' => true, 'error' => ''];
        if ($hasRecipient) {
            $test = $channel->send(
                ['id' => 0, 'external_ref' => ''],
                "Проверка связи из apidcms.\nВебхук: " . $url
            );
        }

        $errors = [];
        if (!$hook['ok']) {
            $errors[] = 'вебхук: ' . $hook['error'];
        }
        if (!$test['ok']) {
            $errors[] = 'сообщение: ' . $test['error'];
        }

        if ($errors) {
            $this->setFlash('error', 'Подключение не готово — ' . implode('; ', $errors));
        } elseif (!$hasRecipient) {
            $this->setFlash('success', 'Вебхук подписан. Напишите боту любое слово в мессенджере — адрес для обращений запомнится сам');
        } else {
            $this->setFlash('success', 'Подключение работает: вебхук подписан, тестовое сообщение отправлено');
        }

        $this->redirect('/chats');
    }
}
