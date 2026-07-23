<?php
/**
 * YooKassa Plugin — Service
 *
 * Low-level YooKassa API client.
 * Provides: createPayment(), getPayment(), handleWebhook(), getConfig()
 *
 * All business logic is in subscription plugin.
 * YooKassa only translates between YooKassa API and subscription hooks.
 */

namespace Plugins\YooKassa;

class Service
{
    /**
     * Create payment via YooKassa API
     *
     * @throws \Exception on API error
     */
    public static function createPayment(array $paymentData, array $config): array
    {
        if (empty($config['shop_id']) || empty($config['secret_key'])) {
            throw new \Exception('YooKassa not configured');
        }

        $idempotencyKey = self::generateUuid();

        $payload = [
            'amount' => [
                'value' => $paymentData['amount']['value'],
                'currency' => $paymentData['amount']['currency'] ?? 'RUB'
            ],
            'description' => $paymentData['description'] ?? '',
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $paymentData['return_url']
            ],
            'metadata' => $paymentData['metadata'] ?? [],
            'capture' => true
        ];

        $ch = curl_init('https://api.yookassa.ru/v3/payments');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Idempotence-Key: ' . $idempotencyKey,
            ],
            CURLOPT_USERPWD => $config['shop_id'] . ':' . $config['secret_key'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('YooKassa API connection error: ' . $error);
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400 || (!empty($data['type']) && $data['type'] === 'error')) {
            $errMsg = $data['description'] ?? ('HTTP ' . $httpCode);
            throw new \Exception('YooKassa API error: ' . $errMsg);
        }

        return $data;
    }

    /**
     * Get payment status via YooKassa API
     */
    public static function getPayment(string $paymentId, array $config): ?array
    {
        if (empty($config['shop_id']) || empty($config['secret_key'])) {
            return null;
        }

        $ch = curl_init('https://api.yookassa.ru/v3/payments/' . $paymentId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => $config['shop_id'] . ':' . $config['secret_key'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Handle YooKassa webhook
     * Translates YooKassa events → subscription hook actions
     */
    public static function handleWebhook($fc): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (empty($data) || ($data['type'] ?? '') !== 'notification') {
            http_response_code(400);
            echo 'Invalid notification';
            return;
        }

        $event = $data['event'] ?? '';
        $object = $data['object'] ?? [];
        $pm = \Core\PluginManager::getInstance();

        if ($event === 'payment.succeeded') {
            // Dispatch to both subscription and credits — each checks metadata.type
            $pm->doAction('credits.payment.confirmed', $object, $fc);
            $pm->doAction('subscription.payment.confirmed', $object, $fc);
        } elseif ($event === 'payment.canceled') {
            $pm->doAction('credits.payment.canceled', $object, $fc);
            $pm->doAction('subscription.payment.canceled', $object, $fc);
        }

        http_response_code(200);
        echo 'OK';
    }

    /**
     * Get plugin config from plugin.json settings
     */
    public static function getConfig(): array
    {
        try {
            $pm = \Core\PluginManager::getInstance();
            $cfg = $pm->getPlugin('yookassa');
            $result = ['shop_id' => '', 'secret_key' => ''];
            if (!empty($cfg['settings'])) {
                foreach ($cfg['settings'] as $setting) {
                    if ($setting['key'] === 'yookassa_shop_id' && !empty($setting['value'])) {
                        $result['shop_id'] = $setting['value'];
                    }
                    if ($setting['key'] === 'yookassa_secret_key' && !empty($setting['value'])) {
                        $result['secret_key'] = $setting['value'];
                    }
                }
            }
            return $result;
        } catch (\Exception $e) {
            return ['shop_id' => '', 'secret_key' => ''];
        }
    }

    /**
     * Get base URL for return_url
     */
    public static function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'bajto.ru';
        return $scheme . '://' . $host;
    }

    /**
     * Generate UUID v4 for idempotency key
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
