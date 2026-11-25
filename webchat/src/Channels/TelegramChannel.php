<?php
/**
 * Telegram: группа с темами (форум) — по теме на посетителя.
 *
 * Если темы выключены, createForumTopic вернёт ошибку; тогда работаем без тем,
 * с префиксом «#12» в тексте — так же, как MAX.
 */

namespace Plugins\Webchat\Channels;

use Plugins\Webchat\Http;

class TelegramChannel implements ChannelInterface
{
    private const API = 'https://api.telegram.org/bot';

    private string $token;
    private string $chatId;
    private string $api;

    /**
     * @param string $api Переопределение адреса API — только для локальных
     *                    тестов (см. README, «Проверка без реального бота»).
     */
    public function __construct(string $token, string $chatId, string $api = '')
    {
        $this->token = trim($token);
        $this->chatId = trim($chatId);
        $this->api = rtrim($api !== '' ? $api : self::API, '/');
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function isReady(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    public function ensureWebhook(string $url, string $secret): array
    {
        if (!$this->isReady()) {
            return ['ok' => false, 'error' => 'не заданы токен или ID чата'];
        }

        $res = Http::postJson($this->endpoint('setWebhook'), [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ]);

        return [
            'ok' => $res['ok'] && !empty($res['data']['ok']),
            'error' => $res['ok'] && !empty($res['data']['ok']) ? '' : ($res['error'] ?: 'setWebhook не принят'),
        ];
    }

    public function openThread(array $thread): string
    {
        if (!$this->isReady()) {
            return '';
        }

        $title = $this->topicTitle($thread);
        $res = Http::postJson($this->endpoint('createForumTopic'), [
            'chat_id' => $this->chatId,
            'name' => $title,
        ]);

        $threadId = $res['data']['result']['message_thread_id'] ?? null;
        return is_int($threadId) || is_string($threadId) ? (string) $threadId : '';
    }

    public function send(array $thread, string $text, array $context = []): array
    {
        if (!$this->isReady()) {
            return ['ok' => false, 'mid' => '', 'error' => 'канал не настроен'];
        }

        $payload = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $topicId = (string) ($thread['external_ref'] ?? '');
        if ($topicId !== '') {
            $payload['message_thread_id'] = (int) $topicId;
        }

        $res = Http::postJson($this->endpoint('sendMessage'), $payload);
        $ok = $res['ok'] && !empty($res['data']['ok']);
        $mid = $ok ? (string) ($res['data']['result']['message_id'] ?? '') : '';

        return ['ok' => $ok, 'mid' => $mid, 'error' => $ok ? '' : ($res['error'] ?: 'sendMessage не принят')];
    }

    public function parseUpdate(array $update): ?array
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $fromBot = !empty($message['from']['is_bot']);
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'from_bot' => $fromBot,
            'reply_mid' => (string) ($message['reply_to_message']['message_id'] ?? ''),
            'author' => trim((string) ($message['from']['first_name'] ?? '') . ' ' . (string) ($message['from']['last_name'] ?? '')),
            'chat_id' => (string) ($message['chat']['id'] ?? ''),
            'chat_type' => (string) ($message['chat']['type'] ?? ''),
            'sender_id' => (string) ($message['from']['id'] ?? ''),
            'topic_id' => (string) ($message['message_thread_id'] ?? ''),
        ];
    }

    /** Заголовок темы: «#12 · Иван · /catalog». */
    public function topicTitle(array $thread): string
    {
        $parts = ['#' . ($thread['id'] ?? '')];
        $name = trim((string) ($thread['visitor_name'] ?? ''));
        $parts[] = $name !== '' ? $name : 'Посетитель';
        $page = trim((string) ($thread['page_url'] ?? ''));
        if ($page !== '') {
            $path = parse_url($page, PHP_URL_PATH) ?: '/';
            $parts[] = mb_substr($path, 0, 40);
        }
        return mb_substr(implode(' · ', $parts), 0, 120);
    }

    private function endpoint(string $method): string
    {
        return $this->api . '/' . $this->token . '/' . $method;
    }
}
