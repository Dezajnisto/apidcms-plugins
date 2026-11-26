<?php
/**
 * Входящий эндпоинт для источников дохода, которые не проходят через
 * payments.* хуки: движок бота «Дари!» (оплата в ВК/MAX идёт мимо
 * yookassa-плагина), наличные, ручная продажа.
 *
 * POST /npd/income
 *   X-Npd-Timestamp: <unix seconds>
 *   X-Npd-Signature: <hex hmac_sha256(timestamp . "." . raw_body, secret)>
 *   {amount, description, ref?, payment_id?, email?, phone?, income_type?,
 *    payment_type?, operation_time?, async?}
 *
 * Ответ: {ok, status, receipt_uuid, receipt_url, id, error}
 *
 * Секрет — из настроек плагина (внутренний ключ api_secret), он виден в
 * админке. Подпись, а не ?key= в URL: ключ в логах nginx и в истории браузера
 * живёт годами.
 */

namespace Plugins\Npd;

class Api
{
    public static function handle($fc): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $settings = new Settings();
        $raw = (string) file_get_contents('php://input');
        $timestamp = (string) ($_SERVER['HTTP_X_NPD_TIMESTAMP'] ?? '');
        $signature = (string) ($_SERVER['HTTP_X_NPD_SIGNATURE'] ?? '');

        if (!self::verify($settings->apiSecret(), $timestamp, $signature, $raw)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'bad signature'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'invalid json'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'amount must be positive'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $ref = trim((string) ($body['ref'] ?? ''));
        $paymentId = trim((string) ($body['payment_id'] ?? ''));
        if ($paymentId === '' && $ref !== '') {
            // Бот присылает номер заказа — он и есть ключ дедупликации.
            $paymentId = 'api:' . $ref;
        }

        $ledger = new Ledger(\Core\PluginManager::getInstance()->getDatabase());
        $settings = new Settings();
        $enabled = $settings->bool('enabled', false);
        $dryRun = $settings->bool('dry_run', true);

        // Включённость — общая галочка плагина: при выключенном плагине доходы
        // только копятся в журнале, в ФНС ничего не уходит (как и для
        // платежей через payments.confirmed).
        $silent = !$enabled || $dryRun;
        $note = !$enabled
            ? 'плагин выключен: в ФНС не отправлено'
            : 'пробный режим: в ФНС не отправлено';

        $id = $ledger->enqueue([
            'source' => 'api',
            'payment_id' => $paymentId,
            'ref' => $ref,
            'user_id' => (int) ($body['user_id'] ?? 0),
            'amount' => number_format($amount, 2, '.', ''),
            'description' => trim((string) ($body['description'] ?? 'Услуга')),
            'client_email' => trim((string) ($body['email'] ?? '')),
            'client_phone' => trim((string) ($body['phone'] ?? '')),
            'income_type' => trim((string) ($body['income_type'] ?? '')) ?: (string) $settings->get('default_income_type', 'FROM_INDIVIDUAL'),
            'payment_type' => trim((string) ($body['payment_type'] ?? '')) ?: (string) $settings->get('payment_type', 'CASH'),
            'operation_time' => self::operationTime($body['operation_time'] ?? null),
            'status' => $silent ? Ledger::STATUS_DRY_RUN : Ledger::STATUS_PENDING,
            'last_error' => $silent ? $note : '',
        ]);

        if ($id === null) {
            // Такой доход уже в журнале: отдаём готовый чек, а не создаём второй.
            $existing = $ledger->findByPaymentId($paymentId);
            echo json_encode([
                'ok' => true,
                'duplicate' => true,
                'status' => (string) ($existing['status'] ?? ''),
                'receipt_uuid' => (string) ($existing['receipt_uuid'] ?? ''),
                'receipt_url' => (string) ($existing['receipt_url'] ?? ''),
                'id' => (int) ($existing['id'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($silent) {
            echo json_encode([
                'ok' => true,
                'dry_run' => true,
                'status' => Ledger::STATUS_DRY_RUN,
                'note' => $note,
                'id' => $id,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!empty($body['async'])) {
            echo json_encode(['ok' => true, 'queued' => true, 'status' => Ledger::STATUS_PENDING, 'id' => $id], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Синхронно: боту нужна ссылка на чек прямо в ответе, чтобы отдать её
        // покупателю вместе с результатом заказа.
        $issuer = new Issuer($settings, $ledger, new Nalog($settings));
        $row = $ledger->byId($id);
        $outcome = $row ? $issuer->issue($row) : ['ok' => false, 'error' => 'row lost', 'uuid' => '', 'url' => ''];

        echo json_encode([
            'ok' => $outcome['ok'],
            'status' => $outcome['ok'] ? Ledger::STATUS_ISSUED : Ledger::STATUS_PENDING,
            'receipt_uuid' => $outcome['uuid'],
            'receipt_url' => $outcome['url'],
            'id' => $id,
            'error' => $outcome['ok'] ? '' : $outcome['error'],
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function verify(string $secret, string $timestamp, string $signature, string $raw): bool
    {
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
        return hash_equals($expected, strtolower($signature));
    }

    private static function operationTime($value): string
    {
        if (is_string($value) && $value !== '') {
            try {
                return Nalog::localIso(new \DateTimeImmutable($value));
            } catch (\Throwable $e) {
                // Некорректная дата от источника — берём текущее время.
            }
        }
        return Nalog::localIso();
    }
}
