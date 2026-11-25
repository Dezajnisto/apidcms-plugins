<?php
/**
 * Письма об ответах.
 *
 * Настройки почты берём те же, что у сайта (админка → Настройки → Почта):
 * своего SMTP плагин не заводит. Если почта не настроена, отправка просто
 * не удаётся — сообщение при этом никуда не пропадает, оно уже в БД.
 */

namespace Plugins\Community;

class Notify
{
    private Store $store;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
    }

    /** Разослать подписчикам темы письмо о новом сообщении. */
    public function aboutReply(int $topicId, int $postId, ?array $author): void
    {
        if (!$this->store->settings()->bool('notify_email', true)) {
            return;
        }

        $followers = $this->store->followers($topicId);
        if (!$followers) {
            return;
        }

        $topic = $this->store->topic($topicId);
        $post = $this->store->post($postId);
        if (!$topic || !$post) {
            return;
        }

        $authorId = (int) ($author['id'] ?? 0);
        $authorName = $author['name'] ?? ($post['guest_name'] ?: 'Гость');
        $link = $this->linkToTopic($topic);
        $excerpt = mb_substr(trim(strip_tags((string) $post['content'])), 0, 400);

        $sender = $this->sender();
        if ($sender === null) {
            return;
        }

        $subject = 'Ответ в теме «' . mb_substr((string) $topic['subject'], 0, 80) . '»';

        foreach ($followers as $follower) {
            if ((int) $follower['user_id'] === $authorId) {
                continue;
            }
            $email = trim((string) ($follower['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $body = '<p>Здравствуйте' . ($follower['name'] ? ', ' . htmlspecialchars((string) $follower['name']) : '') . '.</p>'
                . '<p><strong>' . htmlspecialchars($authorName) . '</strong> ответил(а) в теме '
                . '«' . htmlspecialchars((string) $topic['subject']) . '»:</p>'
                . '<blockquote>' . nl2br(htmlspecialchars($excerpt)) . '</blockquote>'
                . ($link !== '' ? '<p><a href="' . htmlspecialchars($link) . '">Открыть обсуждение</a></p>' : '')
                . '<p style="color:#888;font-size:12px">Письмо пришло, потому что вы подписаны на ответы в этой теме.</p>';

            try {
                $sender->send($email, $subject, $body, true);
            } catch (\Throwable $e) {
                error_log('community: письмо не ушло на ' . $email . ' — ' . $e->getMessage());
            }
        }
    }

    private function sender(): ?\Core\EmailSender
    {
        try {
            $db = $this->store->db();
            $settings = new \Core\Settings($db);
            $config = $settings->getEmailConfig();

            $hasSmtp = trim((string) ($config['smtp']['host'] ?? '')) !== '';
            $hasApi = trim((string) ($config['api']['key'] ?? '')) !== '';

            if (!$hasSmtp && !$hasApi) {
                return null;
            }

            return new \Core\EmailSender($config);
        } catch (\Throwable $e) {
            error_log('community: почта недоступна — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Письмо модераторам сообщества о новой заявке.
     *
     * Заявка видна и на странице сообщества, но модератор туда не заходит
     * каждый день — без письма она висит незамеченной неделями.
     */
    public function aboutApplication(array $channel, int $applicantId): void
    {
        if (!$this->store->settings()->bool('notify_email', true)) {
            return;
        }

        $sender = $this->sender();
        if ($sender === null) {
            return;
        }

        $moderators = $this->store->members((int) $channel['id'], 'active', 50);
        $applicant = $this->store->db()->query(
            "SELECT name, email FROM plugin_account_users WHERE id = ?",
            [$applicantId]
        )->fetch();

        $who = $applicant && trim((string) $applicant['name']) !== ''
            ? (string) $applicant['name']
            : 'Пользователь #' . $applicantId;

        $link = (new Links($this->store))->host() . '/community/' . $channel['slug'];
        $subject = 'Заявка в сообщество «' . mb_substr((string) $channel['title'], 0, 60) . '»';

        foreach ($moderators as $moderator) {
            if ((string) ($moderator['role'] ?? '') !== 'moderator') {
                continue;
            }

            $email = trim((string) ($moderator['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $body = '<p>Здравствуйте.</p>'
                . '<p><strong>' . htmlspecialchars($who) . '</strong> просит вступить в сообщество '
                . '«' . htmlspecialchars((string) $channel['title']) . '».</p>'
                . '<p><a href="' . htmlspecialchars($link) . '">Открыть сообщество</a> — заявка ждёт решения на странице.</p>';

            try {
                $sender->send($email, $subject, $body, true);
            } catch (\Throwable $e) {
                error_log('community: письмо о заявке не ушло на ' . $email . ' — ' . $e->getMessage());
            }
        }
    }

    /** Ссылка на обсуждение — общая логика в Links. */
    private function linkToTopic(array $topic): string
    {
        return (new Links($this->store))->topicUrl($topic, true);
    }
}
