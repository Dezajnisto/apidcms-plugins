<?php
/**
 * Минимальный HTTP-клиент для Bot API (MAX, Telegram).
 *
 * Только curl, только то, что нужно: JSON-запрос с таймаутом и разбор ответа.
 * Ошибка сети не бросает исключение — плагин не должен ломать страницу сайта,
 * если мессенджер недоступен; вызывающий код смотрит на ['ok'].
 */

namespace Plugins\Webchat;

class Http
{
    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    public static function postJson(string $url, array $body, array $headers = [], int $timeout = 8): array
    {
        return self::request('POST', $url, $body, $headers, $timeout);
    }

    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    public static function get(string $url, array $headers = [], int $timeout = 8): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    private static function request(string $method, string $url, ?array $body, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'curl не установлен'];
        }

        $result = self::doRequest($method, $url, $body, $headers, $timeout, null);

        // MAX отдаёт сертификат Минцифры, которого нет в стандартном наборе
        // корневых сертификатов большинства серверов. Плагин носит его с собой
        // и при ошибке TLS повторяет запрос с ним — иначе на обычном хостинге
        // в РФ канал просто не работает, а сообщение об ошибке невнятное.
        if (!$result['ok'] && stripos($result['error'], 'certificate') !== false) {
            $ca = dirname(__DIR__) . '/assets/ca/russian_trusted.pem';
            if (is_file($ca)) {
                $retry = self::doRequest($method, $url, $body, $headers, $timeout, $ca);
                if ($retry['ok']) {
                    return $retry;
                }
                $result = $retry;
            }
        }

        return $result;
    }

    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    private static function doRequest(string $method, string $url, ?array $body, array $headers, int $timeout, ?string $caFile): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ];
        if ($caFile !== null) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error ?: 'сетевая ошибка'];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = ['raw' => (string) $raw];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => $data,
            'error' => ($status >= 200 && $status < 300) ? '' : self::errorText($data, $status),
        ];
    }

    private static function errorText(array $data, int $status): string
    {
        foreach (['message', 'error', 'description'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }
        if (!empty($data['error']['message']) && is_string($data['error']['message'])) {
            return $data['error']['message'];
        }
        return 'HTTP ' . $status;
    }

    /**
     * ID сообщения из ответа MAX: {message:{body:{mid:...}}} либо {message:{mid:...}}.
     * Формат объекта один раз уже менялся, поэтому ищем mid по известным местам,
     * а в крайнем случае — рекурсивно.
     */
    public static function extractMid(array $payload): string
    {
        $candidates = [
            $payload['message']['body']['mid'] ?? null,
            $payload['message']['mid'] ?? null,
            $payload['mid'] ?? null,
            $payload['result']['message_id'] ?? null,
            $payload['result']['message']['body']['mid'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return self::searchMid($payload, 0);
    }

    private static function searchMid(array $payload, int $depth): string
    {
        if ($depth > 4) {
            return '';
        }
        foreach ($payload as $key => $value) {
            if ($key === 'mid' && is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value)) {
                $found = self::searchMid($value, $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }
}
