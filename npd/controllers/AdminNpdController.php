<?php
/**
 * Админка чеков НПД: состояние подключения, вход по SMS, журнал, повтор.
 *
 * Вход по SMS, а не по логину с паролем, — осознанно: пароль от ЛК физлица
 * совпадает с паролем Госуслуг, и хранить его в базе сайта нельзя. Плагин
 * держит только refresh-токен, зашифрованный ключом из config/npd.php.
 */

namespace Plugins\Npd;

class AdminNpdController extends \Admin\BaseController
{
    private function settings(): Settings
    {
        return new Settings();
    }

    private function ledger(): Ledger
    {
        return new Ledger($this->db);
    }

    private function nalog(): Nalog
    {
        return new Nalog($this->settings());
    }

    public function index(): void
    {
        $settings = $this->settings();
        $ledger = $this->ledger();

        $status = (string) ($_GET['status'] ?? 'all');
        $search = trim((string) ($_GET['q'] ?? ''));

        $summary = null;
        $user = null;
        if ($settings->get('token_enc', '') !== '' || $settings->get('refresh_enc', '') !== '') {
            try {
                $user = $this->nalog()->user();
                $summary = $this->nalog()->summary();
            } catch (\Throwable $e) {
                // Сессия могла отвалиться — это не повод не показывать журнал.
            }
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');

        $this->render('@npd/admin.html.twig', [
            'title' => 'Чеки НПД',
            'receipts' => $ledger->listForAdmin($status, $search, 200),
            'counts' => $ledger->counts(),
            'status' => $status,
            'search' => $search,
            'connected' => $settings->get('token_enc', '') !== '' || $settings->get('refresh_enc', '') !== '',
            'inn' => (string) $settings->get('inn', ''),
            'account_name' => (string) $settings->get('name', ''),
            'auth_error' => (string) $settings->get('auth_error', ''),
            'token_expires' => (string) $settings->get('token_expires', ''),
            'has_key' => Crypto::hasKey(),
            'key_path' => Crypto::keyPath(),
            'api_secret' => $settings->apiSecret(),
            'tick_key' => $settings->tickKey(),
            'enabled' => $settings->bool('enabled', false),
            'dry_run' => $settings->bool('dry_run', true),
            'send_email' => $settings->bool('send_email', false),
            'user' => $user,
            'summary' => $summary,
            'challenge' => $_SESSION['npd_challenge'] ?? null,
            'base_url' => $baseUrl,
        ]);
    }

    /** Отправить SMS с кодом. */
    public function authStart(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($phone === '') {
            $this->setFlash('error', 'Укажите телефон, привязанный к «Моему налогу»');
            $this->redirect('/npd');
            return;
        }

        try {
            $challenge = $this->nalog()->authStart($phone);
            $_SESSION['npd_challenge'] = ['token' => $challenge, 'phone' => $phone];
            $this->setFlash('success', 'Код отправлен по SMS. Он действует пару минут.');
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
        }

        $this->redirect('/npd');
    }

    /** Проверить код из SMS. */
    public function authVerify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $challenge = $_SESSION['npd_challenge'] ?? null;
        if (!is_array($challenge) || empty($challenge['token'])) {
            $this->setFlash('error', 'Сначала запросите код');
            $this->redirect('/npd');
            return;
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        if ($code === '') {
            $this->setFlash('error', 'Введите код из SMS');
            $this->redirect('/npd');
            return;
        }

        try {
            $profile = $this->nalog()->authVerify((string) $challenge['phone'], (string) $challenge['token'], $code);
            unset($_SESSION['npd_challenge']);
            $name = $profile['name'] !== '' ? $profile['name'] : 'налогоплательщик';
            $this->setFlash('success', 'Подключено: ' . $name . ' (ИНН ' . $profile['inn'] . ')');
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
        }

        $this->redirect('/npd');
    }

    /** Проверить связь: запрос профиля в ФНС. */
    public function check(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        try {
            $user = $this->nalog()->user();
            if ($user === null) {
                throw new \RuntimeException('ФНС не отдала профиль');
            }
            $this->settings()->save('auth_error', '');
            $this->settings()->save('last_check', gmdate('c'));
            $name = (string) ($user['displayName'] ?? '');
            $this->setFlash('success', 'Связь есть: ' . ($name !== '' ? $name : 'профиль получен'));
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Связи нет: ' . $e->getMessage());
        }

        $this->redirect('/npd');
    }

    /** Пробить очередь прямо сейчас. */
    public function run(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $result = (new Issuer($this->settings(), $this->ledger(), $this->nalog()))->run(20);

        if (!$result['ran']) {
            $this->setFlash('error', 'Не запущено: ' . $result['reason']);
        } elseif ($result['dry_run'] > 0) {
            $this->setFlash('success', 'Пробный режим: отмечено чеков — ' . $result['dry_run'] . ', в ФНС ничего не отправлено');
        } else {
            $message = 'Пробито: ' . $result['issued'] . ', ошибок: ' . $result['failed'];
            $this->setFlash($result['failed'] > 0 ? 'error' : 'success', $message);
        }

        $this->redirect('/npd');
    }

    /** Пробить один чек заново. */
    public function retry(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $row = $this->ledger()->byId((int) $id);
        if (!$row) {
            $this->setFlash('error', 'Запись не найдена');
            $this->redirect('/npd');
            return;
        }

        if (($row['status'] ?? '') === Ledger::STATUS_ISSUED) {
            $this->setFlash('error', 'Чек уже пробит — повторный создаст дубль');
            $this->redirect('/npd');
            return;
        }

        $this->ledger()->requeue((int) $id);
        $fresh = $this->ledger()->byId((int) $id);

        if ($this->settings()->bool('dry_run', true)) {
            $this->setFlash('error', 'Включён пробный режим — сначала снимите его в настройках плагина');
            $this->redirect('/npd');
            return;
        }

        $outcome = $fresh ? (new Issuer($this->settings(), $this->ledger(), $this->nalog()))->issue($fresh) : ['ok' => false, 'error' => 'запись пропала'];
        $this->setFlash($outcome['ok'] ? 'success' : 'error', $outcome['ok'] ? 'Чек пробит' : $outcome['error']);

        $this->redirect('/npd');
    }

    /** Аннулировать чек в «Моём налоге». */
    public function cancel(string $id = ''): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $row = $this->ledger()->byId((int) $id);
        if (!$row || (string) ($row['receipt_uuid'] ?? '') === '') {
            $this->setFlash('error', 'У этой записи нет чека для аннулирования');
            $this->redirect('/npd');
            return;
        }

        // Причина — из фиксированного списка ФНС (CancelComment). Свободный
        // текст API не принимает: только MISTAKE / REFUND / SERVICE_ERROR.
        $reasons = [
            'MISTAKE' => 'Чек сформирован ошибочно',
            'REFUND' => 'Возврат денежных средств',
            'SERVICE_ERROR' => 'Сбой при формировании чека',
        ];
        $comment = (string) ($_POST['comment'] ?? '');
        if (!isset($reasons[$comment])) {
            $comment = 'MISTAKE';
        }

        try {
            $this->nalog()->cancelIncome((string) $row['receipt_uuid'], $comment);
            $this->ledger()->markCanceled((int) $id, 'аннулирован: ' . $reasons[$comment]);
            $this->setFlash('success', 'Чек аннулирован: ' . $reasons[$comment]);
        } catch (\Throwable $e) {
            $this->setFlash('error', $e->getMessage());
        }

        $this->redirect('/npd');
    }

    /** Забыть сессию ФНС. */
    public function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/npd');
            return;
        }

        $this->nalog()->logout();
        $this->setFlash('success', 'Сессия удалена. Чтобы пробивать чеки, войдите заново по SMS.');
        $this->redirect('/npd');
    }
}
