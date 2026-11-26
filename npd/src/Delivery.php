<?php
/**
 * Доставка чека покупателю и писем владельцу.
 *
 * 422-ФЗ требует не только зарегистрировать доход, но и передать чек
 * покупателю. ФНС сама письмо не шлёт (в этом API нет поля с почтой
 * получателя), поэтому ссылку на чек отдаёт наш сайт: страница /receipts в
 * личном кабинете, письмо (если включено) и ответ API для ботов.
 */

namespace Plugins\Npd;

class Delivery
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /** Письмо покупателю со ссылкой на чек. Молча ничего не делает, если выключено. */
    public function receipt(array $row): bool
    {
        if (!$this->settings->bool('send_email', false)) {
            return false;
        }

        $email = trim((string) ($row['client_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $url = (string) ($row['receipt_url'] ?? '');
        if ($url === '') {
            return false;
        }

        $amount = number_format((float) ($row['amount'] ?? 0), 2, ',', ' ') . ' ₽';
        $description = htmlspecialchars((string) ($row['description'] ?? ''), ENT_QUOTES, 'UTF-8');

        $body = '<p>Здравствуйте!</p>'
            . '<p>Спасибо за оплату' . ($description !== '' ? ' — ' . $description : '') . ' на сумму <strong>' . $amount . '</strong>.</p>'
            . '<p>Чек сформирован в сервисе ФНС «Мой налог»:</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
            . 'style="display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none">'
            . 'Открыть чек</a></p>'
            . '<p style="color:#888;font-size:12px">Ссылка ведёт на официальный сервис налоговой службы. '
            . 'Письмо отправлено автоматически, отвечать на него не нужно.</p>';

        $sender = $this->sender();
        if ($sender === null) {
            error_log('npd: почта не настроена, чек ' . ($row['receipt_uuid'] ?? '') . ' не отправлен на ' . $email);
            return false;
        }

        try {
            return (bool) $sender->send($email, 'Чек за оплату — ' . $amount, $body, true);
        } catch (\Throwable $e) {
            error_log('npd: письмо с чеком не ушло на ' . $email . ' — ' . $e->getMessage());
            return false;
        }
    }

    /** Письмо владельцу сайта о сбое. */
    public function notifyAdmin(string $subject, string $message): void
    {
        $to = trim((string) $this->settings->get('notify_email', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $sender = $this->sender();
        if ($sender === null) {
            return;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $body = '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p><a href="' . htmlspecialchars($this->adminUrl(), ENT_QUOTES, 'UTF-8') . '">Журнал чеков в админке</a></p>'
            . ($host !== '' ? '<p style="color:#888;font-size:12px">Сайт: ' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . '</p>' : '');

        try {
            $sender->send($to, $subject, $body, true);
        } catch (\Throwable $e) {
            error_log('npd: письмо о сбое не ушло на ' . $to . ' — ' . $e->getMessage());
        }
    }

    private function adminUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return $scheme . '://' . $host . '/admin/npd';
    }

    private function sender(): ?\Core\EmailSender
    {
        try {
            $db = \Core\PluginManager::getInstance()->getDatabase();
            if (!$db) {
                return null;
            }
            $config = (new \Core\Settings($db))->getEmailConfig();

            $hasSmtp = trim((string) ($config['smtp']['host'] ?? '')) !== '';
            $hasApi = trim((string) ($config['api']['key'] ?? '')) !== '';
            if (!$hasSmtp && !$hasApi) {
                return null;
            }

            return new \Core\EmailSender($config);
        } catch (\Throwable $e) {
            error_log('npd: почта недоступна — ' . $e->getMessage());
            return null;
        }
    }
}
