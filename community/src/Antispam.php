<?php
/**
 * Антиспам и антифлуд.
 *
 * Задача не «победить спам», а не дать модерации утонуть: у владельца сайта нет
 * отдельного человека на модерацию. Поэтому проверки дешёвые и по порядку,
 * а сомнительное не выбрасывается, а уходит в очередь на проверку.
 */

namespace Plugins\Community;

class Antispam
{
    private Store $store;
    private Settings $settings;

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? new Store();
        $this->settings = $this->store->settings();
    }

    /**
     * @param string $kind post|topic
     * @return array{status:string,error:string} status: published|pending|spam, error — текст для показа
     */
    public function check(string $kind, string $content, string $ipHash, bool $moderated = false): array
    {
        $content = trim($content);
        $length = mb_strlen($content);

        $min = $kind === 'topic' ? 3 : 2;
        if ($length < $min) {
            return ['status' => 'rejected', 'error' => 'Сообщение слишком короткое.'];
        }
        if ($length > 20000) {
            return ['status' => 'rejected', 'error' => 'Слишком длинное сообщение (максимум 20000 символов).'];
        }

        // 1. бот не заполняет скрытое поле и отправляет форму мгновенно
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            return ['status' => 'rejected', 'error' => 'Не удалось отправить сообщение.'];
        }

        $elapsed = (int) ($_POST['_t'] ?? 0);
        $minSeconds = $this->settings->int('min_fill_seconds', 3);
        if ($elapsed > 0 && (time() - $elapsed) < $minSeconds) {
            return ['status' => 'rejected', 'error' => 'Слишком быстро. Проверьте текст и отправьте ещё раз.'];
        }

        // 2. частота по хешу адреса
        $limit = $kind === 'topic'
            ? $this->settings->int('topics_per_hour', 3)
            : $this->settings->int('posts_per_10min', 5);
        $window = $kind === 'topic' ? '-1 hour' : '-10 minutes';

        if ($ipHash !== '') {
            $recent = (int) ($this->store->db()->query(
                "SELECT COUNT(*) AS c FROM plugin_community_posts
                  WHERE ip_hash = ? AND created_at > datetime('now', ?)",
                [$ipHash, $window]
            )->fetch()['c'] ?? 0);

            if ($recent >= $limit) {
                return [
                    'status' => 'rejected',
                    'error' => $kind === 'topic'
                        ? 'Слишком много новых тем с вашего адреса. Попробуйте через час.'
                        : 'Слишком часто. Подождите несколько минут.',
                ];
            }
        }

        // 3. стоп-слова
        $lower = mb_strtolower($content);
        foreach ($this->settings->stopWords() as $word) {
            if ($word !== '' && mb_strpos($lower, $word) !== false) {
                return ['status' => 'spam', 'error' => ''];
            }
        }

        // 4. много ссылок — на проверку
        if (Markdown::linkCount($content) > 2) {
            return ['status' => 'pending', 'error' => ''];
        }

        // 5. канал с премодерацией — на проверку
        if ($moderated) {
            return ['status' => 'pending', 'error' => ''];
        }

        return ['status' => 'published', 'error' => ''];
    }

    /**
     * Хеш адреса: сырой IP не храним. Соль — секрет плагина, поэтому по хешу
     * нельзя перебрать все адреса мира, но можно отличить «свой» от «чужого».
     */
    public function ipHash(): string
    {
        $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        if ($ip === '') {
            return '';
        }

        return hash_hmac('sha256', $ip, $this->settings->csrfSecret());
    }
}
