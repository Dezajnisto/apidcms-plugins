<?php
/**
 * Канал доставки обращений: MAX или Telegram.
 *
 * Виджет и БД про канал ничего не знают — он подменяем. MAX не умеет темы
 * в групповом чате (у Telegram это forum topics), поэтому у MAX каждое
 * сообщение несёт номер обращения в первой строке, а ответ оператора
 * привязывается либо к процитированному сообщению, либо к префиксу «#12».
 */

namespace Plugins\Webchat\Channels;

interface ChannelInterface
{
    public function name(): string;

    /** Готов ли канал: есть токен и ID чата. */
    public function isReady(): bool;

    /**
     * Подписать вебхук на этот сайт.
     *
     * @return array{ok:bool,error:string}
     */
    public function ensureWebhook(string $url, string $secret): array;

    /**
     * Открыть «ветку» обращения. Возвращает внешний идентификатор ветки
     * (для Telegram — id темы, для MAX — id чата) или '' если ветка не нужна.
     */
    public function openThread(array $thread): string;

    /**
     * Отправить текст оператору в мессенджер.
     *
     * @return array{ok:bool,mid:string,error:string}
     */
    public function send(array $thread, string $text, array $context = []): array;

    /**
     * Разобрать входящее событие вебхука в нормализованный вид.
     *
     * @return array{text:string,from_bot:bool,reply_mid:string,author:string,chat_id:string}|null
     */
    public function parseUpdate(array $update): ?array;

    /**
     * Обслуживает ли этот бот несколько сайтов одновременно.
     *
     * Один бот на все проекты — нормальная схема (у владельца один чат), но
     * тогда каждое событие приходит на вебхук КАЖДОГО сайта. Ответ оператора
     * нельзя привязывать «к последнему обращению»: он уйдёт посетителю
     * чужого сайта. Поэтому в общем режиме маршрут определяется только по
     * цитате (mid) или по метке сайта в номере обращения.
     */
    public function isShared(): bool;

    /**
     * mid последнего сообщения бота в чате оператора ('' — не удалось узнать).
     *
     * Нужен в общем режиме: оператор часто отвечает без цитаты, и тогда
     * адресат — тот, чьё сообщение бота в чате последнее.
     */
    public function lastBotMid(): string;
}
