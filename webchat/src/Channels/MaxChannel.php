<?php
/**
 * MAX (max.ru): групповой чат, бот присылает туда обращения.
 *
 * У MAX в Bot API нет тем, как в Telegram-форуме, поэтому ветка обращения
 * живёт в префиксе сообщения («#12 · Иван · /catalog»), а ответ оператора
 * привязывается так:
 *   1. оператор ответил (quote) на сообщение бота — берём mid из link.reply;
 *   2. оператор начал текст с «#12 » — берём номер;
 *   3. иначе — последнее активное обращение (обычный случай для одного человека).
 */

namespace Plugins\Webchat\Channels;

use Plugins\Webchat\Http;

class MaxChannel implements ChannelInterface
{
    /** Новый домен: старый platform-api.max.ru отключают. */
    private const API = 'https://platform-api2.max.ru';

    private string $token;
    private string $chatId;
    private string $api;

    /** ID чата для ЧТЕНИЯ истории. В диалоге с ботом он не равен user_id. */
    private string $readChatId;

    /**
     * @param string $api        Переопределение адреса API — только для локальных
     *                           тестов (см. README, «Проверка без реального бота»).
     * @param string $readChatId chat_id чата оператора (для GET /messages).
     */
    public function __construct(string $token, string $chatId, string $api = '', string $readChatId = '')
    {
        $this->token = trim($token);
        $this->chatId = trim($chatId);
        $this->api = rtrim($api !== '' ? $api : self::API, '/');
        $this->readChatId = trim($readChatId);
    }

    public function name(): string
    {
        return 'max';
    }

    public function isReady(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    public function ensureWebhook(string $url, string $secret): array
    {
        if ($this->token === '') {
            return ['ok' => false, 'error' => 'не задан токен бота'];
        }

        $res = Http::postJson($this->api . '/subscriptions', [
            'url' => $url,
            'update_types' => ['message_created', 'bot_added'],
            'secret' => $secret,
        ], $this->auth());

        $ok = $res['ok'] && ($res['data']['success'] ?? true) !== false;

        return ['ok' => $ok, 'error' => $ok ? '' : ($res['error'] ?: 'подписка не принята')];
    }

    public function openThread(array $thread): string
    {
        // Веток нет — все обращения идут в один чат, различаем по префиксу.
        return $this->chatId;
    }

    public function send(array $thread, string $text, array $context = []): array
    {
        if ($this->chatId === '') {
            return ['ok' => false, 'mid' => '', 'error' => 'не задан ID чата MAX'];
        }
        if ($this->token === '') {
            return ['ok' => false, 'mid' => '', 'error' => 'не задан токен MAX'];
        }

        // Отрицательный ID — групповой чат, положительный — user_id оператора
        // (личный диалог с ботом). Оба сценария поддерживаются одним полем:
        // не у всех есть группа, а личка с ботом есть всегда.
        $key = (strpos($this->chatId, '-') === 0) ? 'chat_id' : 'user_id';
        $url = $this->api . '/messages?' . $key . '=' . rawurlencode($this->chatId);
        $res = Http::postJson($url, ['text' => $text], $this->auth());

        $ok = $res['ok'];
        return [
            'ok' => $ok,
            'mid' => $ok ? Http::extractMid($res['data']) : '',
            'error' => $ok ? '' : ($res['error'] ?: 'сообщение не отправлено'),
        ];
    }

    public function parseUpdate(array $update): ?array
    {
        $type = (string) ($update['update_type'] ?? '');
        if ($type !== 'message_created') {
            return null;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $text = trim((string) ($message['body']['text'] ?? $message['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $replyMid = '';
        $replyText = '';
        $link = $message['link'] ?? null;
        if (is_array($link) && ($link['type'] ?? '') === 'reply') {
            // Структура у MAX уже менялась: mid ищем во всех известных местах.
            $quoted = is_array($link['message'] ?? null) ? $link['message'] : [];
            foreach ([
                $quoted['body']['mid'] ?? null,
                $quoted['mid'] ?? null,
                $link['mid'] ?? null,
            ] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $replyMid = $candidate;
                    break;
                }
            }

            // Текст цитаты — второй, независимый путь: в нём есть номер
            // обращения («#example-12 · Посетитель»), и по нему ответ
            // привязывается даже если mid не совпал.
            $replyText = (string) ($quoted['body']['text'] ?? $quoted['text'] ?? '');
        }

        $sender = $message['sender'] ?? [];
        $recipient = $message['recipient'] ?? [];

        return [
            'text' => $text,
            'from_bot' => !empty($sender['is_bot']),
            'reply_mid' => $replyMid,
            'reply_text' => $replyText,
            'author' => trim((string) ($sender['first_name'] ?? '') . ' ' . (string) ($sender['last_name'] ?? '')),
            'chat_id' => (string) ($recipient['chat_id'] ?? $update['chat_id'] ?? ''),
            'chat_type' => (string) ($recipient['chat_type'] ?? ''),
            'sender_id' => (string) ($sender['user_id'] ?? ''),
            'topic_id' => '',
        ];
    }

    private function auth(): array
    {
        return ['Authorization: ' . $this->token];
    }

    /**
     * Бот обслуживает больше одного сайта: в /subscriptions больше одного URL.
     *
     * MAX разрешает несколько вебхуков у одного бота (в отличие от Telegram),
     * поэтому общий бот на все проекты — рабочая схема. Плата за неё — каждое
     * событие уходит на вебхук каждого сайта.
     */
    public function isShared(): bool
    {
        if ($this->token === '') {
            return false;
        }

        $res = Http::get($this->api . '/subscriptions', $this->auth());
        if (!$res['ok']) {
            return false;
        }

        $urls = [];
        foreach ((array) ($res['data']['subscriptions'] ?? []) as $sub) {
            $url = trim((string) ($sub['url'] ?? ''));
            if ($url !== '') {
                $urls[$url] = true;
            }
        }

        return count($urls) > 1;
    }

    /** mid последнего сообщения бота в чате оператора. */
    public function lastBotMid(): string
    {
        if ($this->token === '' || $this->readChatId === '') {
            return '';
        }

        $url = $this->api . '/messages?chat_id=' . rawurlencode($this->readChatId) . '&count=20';
        $res = Http::get($url, $this->auth());
        if (!$res['ok']) {
            return '';
        }

        // Порядок в ответе API не гарантирован — берём по максимальному
        // timestamp, а не «первое сверху».
        $messages = (array) ($res['data']['messages'] ?? []);
        $bestMid = '';
        $bestAt = -1;
        foreach ($messages as $message) {
            if (empty($message['sender']['is_bot'])) {
                continue;
            }
            $mid = (string) ($message['body']['mid'] ?? ($message['mid'] ?? ''));
            if ($mid === '') {
                continue;
            }
            $at = (int) ($message['timestamp'] ?? 0);
            if ($at >= $bestAt) {
                $bestAt = $at;
                $bestMid = $mid;
            }
        }

        return $bestMid;
    }
}
