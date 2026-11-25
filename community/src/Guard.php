<?php
/**
 * Кто есть кто и что ему можно.
 *
 * Пользователи — из плагина account (таблица plugin_account_users), админ —
 * тот, кто вошёл в панель (в сессии стоит authenticated). Своей регистрации
 * сообщество не заводит: иначе на сайте было бы два разных «пользователя».
 */

namespace Plugins\Community;

class Guard
{
    private Store $store;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
    }

    /** @return array{id:int,name:string,email:string,avatar:string}|null */
    public function user(): ?array
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        if (!$this->store->hasAccounts()) {
            return null; // сайт без регистрации: работают только гости
        }

        try {
            $row = $this->store->db()->query(
                "SELECT id, name, email, avatar FROM plugin_account_users WHERE id = ?",
                [$id]
            )->fetch();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?: ('Пользователь #' . $row['id'])),
            'email' => (string) $row['email'],
            'avatar' => (string) ($row['avatar'] ?? ''),
        ];
    }

    public function userId(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    public function isLoggedIn(): bool
    {
        return $this->userId() > 0;
    }

    /** Вошёл в админку: полные права на всё сообщество. */
    public function isAdmin(): bool
    {
        return ($_SESSION['authenticated'] ?? null) === true;
    }

    public function isMember(int $channelId): bool
    {
        $id = $this->userId();
        if ($id <= 0) {
            return false;
        }

        $row = $this->store->db()->query(
            "SELECT status FROM plugin_community_members WHERE channel_id = ? AND user_id = ?",
            [$channelId, $id]
        )->fetch();

        return $row && ($row['status'] ?? '') === 'active';
    }

    /** Модератор канала или админ. */
    public function canModerate(int $channelId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $id = $this->userId();
        if ($id <= 0) {
            return false;
        }

        $row = $this->store->db()->query(
            "SELECT role FROM plugin_community_members WHERE channel_id = ? AND user_id = ?",
            [$channelId, $id]
        )->fetch();

        return $row && ($row['role'] ?? '') === 'moderator';
    }

    public function canRead(array $channel): bool
    {
        if ($this->isAdmin() || $this->canModerate((int) $channel['id'])) {
            return true;
        }

        if ((int) $channel['is_private'] === 1) {
            return $this->isMember((int) $channel['id']);
        }

        // форум можно закрыть от гостей настройкой
        if ((string) $channel['mode'] === Store::MODE_FORUM) {
            if (!$this->store->settings()->bool('forum_guests_read', true) && !$this->isLoggedIn()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Может ли текущий посетитель писать в канал.
     * @return string пусто — можно; иначе причина отказа для показа
     */
    public function denyReason(array $channel): string
    {
        if ($this->canModerate((int) $channel['id'])) {
            return '';
        }

        $mode = (string) $channel['mode'];
        $loggedIn = $this->isLoggedIn();

        if ($mode === Store::MODE_COMMENTS) {
            if ($loggedIn) {
                return '';
            }
            return $this->store->settings()->bool('comments_guests', true)
                ? ''
                : 'Чтобы комментировать, войдите в аккаунт.';
        }

        // форум и сообщества: только зарегистрированные
        if (!$loggedIn) {
            // На сайте без плагина account регистрации нет вовсе — закрывать
            // форум наглухо значило бы сделать его мёртвым. Тогда пускаем
            // гостей, но их сообщения всегда идут на проверку.
            if (!$this->store->hasAccounts()) {
                return (int) $channel['is_private'] === 1
                    ? 'Это закрытый раздел.'
                    : '';
            }
            return 'Писать в форуме могут только зарегистрированные. Вход — в шапке сайта.';
        }

        if ((int) $channel['is_private'] === 1 && !$this->isMember((int) $channel['id'])) {
            return 'Это закрытое сообщество: сначала нужно в него вступить.';
        }

        if ((int) $channel['requires_join'] === 1 && !$this->isMember((int) $channel['id'])) {
            $status = (string) ($this->membershipStatus((int) $channel['id']) ?? '');

            if ($status === 'pending') {
                return 'Заявка на вступление ждёт решения модератора.';
            }

            return 'Чтобы писать, вступите в сообщество.';
        }

        return '';
    }

    /** Статус участия: active | pending | null (не участник). */
    public function membershipStatus(int $channelId): ?string
    {
        $membership = $this->store->membership($channelId, $this->userId());

        return $membership ? (string) ($membership['status'] ?? '') : null;
    }

    /** Как вступают в канал: open | approval | closed. */
    public function joinPolicy(array $channel): string
    {
        $policy = (string) ($channel['join_policy'] ?? 'open');

        return in_array($policy, ['open', 'approval', 'closed'], true) ? $policy : 'open';
    }

    /**
     * Может ли текущий посетитель вступить в сообщество.
     * @return array{allowed:bool,reason:string,mode:string} mode: join|apply|none
     */
    public function joinState(array $channel): array
    {
        $channelId = (int) $channel['id'];
        $policy = $this->joinPolicy($channel);
        $status = $this->membershipStatus($channelId);

        if ($this->isAdmin() || $this->canModerate($channelId)) {
            return ['allowed' => false, 'reason' => 'Вы модератор этого сообщества.', 'mode' => 'none'];
        }

        if ($status === 'active') {
            return ['allowed' => true, 'reason' => '', 'mode' => 'leave'];
        }

        if ($status === 'pending') {
            return ['allowed' => false, 'reason' => 'Заявка отправлена, ждёт решения модератора.', 'mode' => 'pending'];
        }

        if (!$this->isLoggedIn()) {
            // на сайте без аккаунтов вступать некому — сообщества там не работают
            return [
                'allowed' => false,
                'reason' => $this->store->hasAccounts()
                    ? 'Чтобы вступить, войдите в аккаунт.'
                    : 'На этом сайте нет регистрации, вступление недоступно.',
                'mode' => 'none',
            ];
        }

        if ($policy === 'closed') {
            return ['allowed' => false, 'reason' => 'Вступить можно только по приглашению модератора.', 'mode' => 'none'];
        }

        if ($policy === 'approval') {
            return ['allowed' => true, 'reason' => '', 'mode' => 'apply'];
        }

        return ['allowed' => true, 'reason' => '', 'mode' => 'join'];
    }
}
