<?php
/**
 * Клиент API сервиса «Мой налог» (lknpd.nalog.ru).
 *
 * Публичного API у ФНС нет: это тот же закрытый интерфейс /api/v1, которым
 * пользуется веб-кабинет и мобильное приложение. Поэтому:
 *   — все сетевые вызовы собраны в одном классе (поменяется API — правим тут);
 *   — вход только по SMS, пароль от ЛК физлица на сервер не попадает;
 *   — токены лежат в базе зашифрованными (см. Crypto).
 *
 * Схема:
 *   authStart(phone)   → POST /auth/challenge/sms/start   → challengeToken
 *   authVerify(...)    → POST /auth/challenge/sms/verify  → token + refreshToken
 *   token()            → POST /auth/token                 → продление (авто)
 *   createIncome(...)  → POST /income                     → approvedReceiptUuid
 *   cancelIncome(...)  → POST /income/cancel
 */

namespace Plugins\Npd;

/** Сессия ФНС недействительна: нужен новый вход по SMS. */
class NpdAuthRequired extends \RuntimeException
{
}

class Nalog
{
    private const BASE = 'https://lknpd.nalog.ru/api/v1';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    // === Авторизация ===

    /** Запросить SMS с кодом. Возвращает challengeToken. */
    public function authStart(string $phone): string
    {
        $res = $this->request('POST', '/auth/challenge/sms/start', [
            'phone' => self::normalizePhone($phone),
            'deviceInfo' => $this->deviceInfo(),
        ], false, 20, true);

        $challenge = (string) ($res['data']['challengeToken'] ?? '');
        if ($res['status'] !== 200 || $challenge === '') {
            throw new \RuntimeException('ФНС не выдала код: ' . $res['error']);
        }

        return $challenge;
    }

    /**
     * Подтвердить код и сохранить сессию.
     *
     * @return array{inn: string, name: string}
     */
    public function authVerify(string $phone, string $challenge, string $code): array
    {
        $res = $this->request('POST', '/auth/challenge/sms/verify', [
            'phone' => self::normalizePhone($phone),
            'challengeToken' => $challenge,
            'code' => trim($code),
            'deviceInfo' => $this->deviceInfo(),
        ], false, 25, true);

        $token = (string) ($res['data']['token'] ?? '');
        $refresh = (string) ($res['data']['refreshToken'] ?? '');
        if ($res['status'] !== 200 || $token === '' || $refresh === '') {
            throw new \RuntimeException('Код не подошёл или ФНС не ответила: ' . $res['error']);
        }

        $this->storeTokens($token, $refresh, (string) ($res['data']['tokenExpireIn'] ?? ''));

        $inn = (string) ($res['data']['profile']['inn'] ?? '');
        $name = (string) ($res['data']['profile']['displayName'] ?? '');
        if ($inn === '') {
            $inn = $this->fetchInn();
        }
        $this->settings->save('inn', $inn);
        $this->settings->save('name', $name);
        $this->settings->save('auth_error', '');

        return ['inn' => $inn, 'name' => $name];
    }

    public function logout(): void
    {
        $this->settings->forget('token_enc');
        $this->settings->forget('refresh_enc');
        $this->settings->forget('token_expires');
        $this->settings->forget('inn');
        $this->settings->forget('name');
    }

    public function isConnected(): bool
    {
        return $this->settings->get('token_enc', '') !== ''
            || $this->settings->get('refresh_enc', '') !== '';
    }

    public function inn(): string
    {
        return (string) $this->settings->get('inn', '');
    }

    public function name(): string
    {
        return (string) $this->settings->get('name', '');
    }

    /**
     * Действующий токен доступа; при истечении — продлевает.
     *
     * @throws NpdAuthRequired когда продлить нечем (сессию отозвали)
     */
    public function token(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $token = Crypto::open((string) $this->settings->get('token_enc', ''));
            $expires = (string) $this->settings->get('token_expires', '');
            if ($token !== null && $expires !== '' && strtotime($expires) > time() + 120) {
                return $token;
            }
        }

        return $this->refresh();
    }

    /** @throws NpdAuthRequired */
    private function refresh(): string
    {
        $refresh = Crypto::open((string) $this->settings->get('refresh_enc', ''));
        if ($refresh === null || $refresh === '') {
            $this->fail('нет сохранённой сессии — нужен вход по SMS');
        }

        $res = $this->request('POST', '/auth/token', [
            'deviceInfo' => $this->deviceInfo(),
            'refreshToken' => $refresh,
        ], false, 20, true);

        $token = (string) ($res['data']['token'] ?? '');
        if ($res['status'] !== 200 || $token === '') {
            // 401 здесь — сессию отозвали (сменили пароль, «выйти на всех
            // устройствах»). Молча повторять бессмысленно: нужен вход по SMS.
            $this->fail('сессия ФНС не продлилась (' . $res['status'] . '): ' . $res['error']);
        }

        $this->storeTokens(
            $token,
            (string) ($res['data']['refreshToken'] ?? $refresh),
            (string) ($res['data']['tokenExpireIn'] ?? '')
        );
        $this->settings->save('auth_error', '');

        return $token;
    }

    private function storeTokens(string $token, string $refresh, string $expires): void
    {
        if (!Crypto::hasKey()) {
            throw new \RuntimeException(
                'нет файла ключа шифрования: выполните php plugins/npd/tools/make-key.php'
            );
        }

        $sealedToken = Crypto::seal($token);
        $sealedRefresh = Crypto::seal($refresh);
        if ($sealedToken === null || $sealedRefresh === null) {
            throw new \RuntimeException('не удалось зашифровать токен');
        }

        $this->settings->save('token_enc', $sealedToken);
        $this->settings->save('refresh_enc', $sealedRefresh);
        $this->settings->save('token_expires', $expires !== '' ? $expires : gmdate('c', time() + 3600));
    }

    private function fail(string $message): void
    {
        $this->settings->save('auth_error', $message);
        throw new NpdAuthRequired($message);
    }

    private function fetchInn(): string
    {
        try {
            $user = $this->user();
            return (string) ($user['inn'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    // === Данные ===

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $res = $this->call('GET', '/user');
        return is_array($res['data']) ? $res['data'] : null;
    }

    /** Сводка по доходам (нужна для предупреждения о лимите 2,4 млн ₽). */
    public function summary(): ?array
    {
        $res = $this->call('GET', '/incomes/summary');
        return is_array($res['data']) ? $res['data'] : null;
    }

    /**
     * Зарегистрировать доход.
     *
     * @param array{amount: string, description: string, operation_time: string, payment_type: string, income_type: string, client_phone: string, client_name: string} $income
     * @return array{uuid: string, url: string, json_url: string}
     * @throws \RuntimeException
     */
    public function createIncome(array $income): array
    {
        $amount = number_format((float) $income['amount'], 2, '.', '');
        $now = self::localIso();

        $payload = [
            'operationTime' => $income['operation_time'],
            'requestTime' => $now,
            'services' => [[
                'name' => mb_substr((string) $income['description'], 0, 250),
                'amount' => $amount,
                'quantity' => 1,
            ]],
            'totalAmount' => $amount,
            'client' => [
                'contactPhone' => $income['client_phone'] !== '' ? $income['client_phone'] : null,
                'displayName' => $income['client_name'] !== '' ? $income['client_name'] : null,
                'inn' => null,
                'incomeType' => self::incomeType((string) $income['income_type']),
            ],
            'paymentType' => self::paymentType((string) $income['payment_type']),
            'ignoreMaxTotalIncomeRestriction' => false,
        ];

        $res = $this->call('POST', '/income', $payload, 40);

        $uuid = (string) ($res['data']['approvedReceiptUuid'] ?? '');
        if ($uuid === '') {
            // Отдельная формулировка для типовой причины отказа: сумма сверх
            // лимита НПД. Иначе в журнале будет невнятное «400».
            $message = $res['error'];
            if (stripos($message, 'limit') !== false || stripos($message, 'превыш') !== false) {
                $message = 'превышен лимит дохода НПД: ' . $message;
            }
            throw new \RuntimeException('чек не зарегистрирован: ' . $message);
        }

        $inn = $this->inn();

        return [
            'uuid' => $uuid,
            'url' => self::BASE . '/receipt/' . $inn . '/' . $uuid . '/print',
            'json_url' => self::BASE . '/receipt/' . $inn . '/' . $uuid . '/json',
        ];
    }

    /**
     * Аннулировать чек.
     *
     * Два подводных камня, проверенные живыми запросами 26.11.2026:
     *   — endpoint именно `/api/v1/cancel`, а не `/income/cancel` (последний
     *     отдаёт 404 «Неверный формат запроса», что сбивает с толку);
     *   — поле `requestTime` обязательно, без него 400 Validation failed.
     *
     * $comment — причина из CancelComment: MISTAKE (чек сформирован ошибочно),
     * REFUND (возврат денежных средств), SERVICE_ERROR.
     */
    public function cancelIncome(string $uuid, string $comment = 'MISTAKE'): array
    {
        $res = $this->call('POST', '/cancel', [
            'receiptUuid' => $uuid,
            'requestTime' => self::localIso(),
            'comment' => $comment !== '' ? $comment : 'MISTAKE',
        ]);

        if ($res['status'] !== 200) {
            throw new \RuntimeException('чек не аннулирован: ' . $res['error']);
        }

        return is_array($res['data']) ? $res['data'] : [];
    }

    // === Транспорт ===

    /**
     * Авторизованный вызов: продлевает токен и один раз повторяет на 401.
     *
     * @return array{status: int, data: mixed, error: string}
     */
    private function call(string $method, string $path, ?array $body = null, int $timeout = 25): array
    {
        $token = $this->token();
        $res = $this->request($method, $path, $body, true, $timeout, false, $token);

        if ($res['status'] === 401) {
            $token = $this->token(true);
            $res = $this->request($method, $path, $body, true, $timeout, false, $token);
        }

        if ($res['status'] === 401) {
            $this->fail('ФНС отклонила токен: нужен новый вход по SMS');
        }

        return $res;
    }

    /**
     * @return array{status: int, data: mixed, error: string}
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $auth = true,
        int $timeout = 25,
        bool $fromCabinet = false,
        string $token = ''
    ): array {
        $url = self::BASE . $path;
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Accept-Language: ru-RU,ru;q=0.9',
            'User-Agent: ' . self::UA,
        ];

        // Запросы авторизации ФНС принимает только с «браузерными» Origin и
        // Referer — без них отвечает 403 без объяснений.
        if ($fromCabinet) {
            $headers[] = 'Origin: https://lknpd.nalog.ru';
            $headers[] = 'Referer: https://lknpd.nalog.ru/';
        }
        if ($auth && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method !== 'GET' && $body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'data' => null, 'error' => 'сеть недоступна: ' . $curlError];
        }

        $data = json_decode((string) $raw, true);
        $error = '';
        if (is_array($data)) {
            $error = (string) ($data['message'] ?? $data['description'] ?? '');
        }
        if ($error === '') {
            $error = $status >= 400 ? ('HTTP ' . $status) : '';
        }

        return ['status' => $status, 'data' => $data, 'error' => $error];
    }

    private function deviceInfo(): array
    {
        return [
            'sourceDeviceId' => $this->settings->deviceId(),
            'sourceType' => 'WEB',
            'appVersion' => '1.0.0',
            'metaDetails' => ['userAgent' => self::UA],
        ];
    }

    // === Мелочи ===

    /** Телефон в формате ФНС: только цифры, без +7 → 7XXXXXXXXXX. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }

    /**
     * Способ расчёта из белого списка ФНС: CASH или WIRE.
     *
     * Незнакомое значение (например, старое «ACCOUNT» из прежней версии
     * настроек) не отправляем: ФНС отвечает 400 «Неверный формат запроса»
     * без указания поля, и чек теряется на каждом платеже. Подставляем CASH —
     * оплата картой на сайте приравнена к наличным по 422-ФЗ.
     */
    public static function paymentType(string $value): string
    {
        $value = strtoupper(trim($value));
        if (in_array($value, ['CASH', 'WIRE'], true)) {
            return $value;
        }
        if ($value !== '') {
            error_log('npd: способ расчёта «' . $value . '» не поддерживается ФНС, отправляю CASH');
        }
        return 'CASH';
    }

    /** Тип плательщика из белого списка ФНС. */
    public static function incomeType(string $value): string
    {
        $value = strtoupper(trim($value));
        if (in_array($value, ['FROM_INDIVIDUAL', 'FROM_LEGAL_ENTITY', 'FROM_FOREIGN_AGENCY'], true)) {
            return $value;
        }
        if ($value !== '') {
            error_log('npd: тип плательщика «' . $value . '» не поддерживается ФНС, отправляю FROM_INDIVIDUAL');
        }
        return 'FROM_INDIVIDUAL';
    }

    /** Время в формате, который ждёт ФНС: локальное с числовым смещением. */
    public static function localIso(?\DateTimeInterface $at = null): string
    {
        $tz = new \DateTimeZone('Europe/Moscow');
        $date = $at === null
            ? new \DateTimeImmutable('now', $tz)
            : \DateTimeImmutable::createFromInterface($at)->setTimezone($tz);

        return $date->format('Y-m-d\TH:i:sP');
    }
}
