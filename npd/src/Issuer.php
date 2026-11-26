<?php
/**
 * Пробитие чеков из очереди.
 *
 * Отдельный класс, потому что вызывается из трёх мест: вебхук оплаты
 * (асинхронно, после ответа ЮKassa), cron-тик и кнопка в админке. Логика
 * одна и та же, отличается только «когда».
 */

namespace Plugins\Npd;

class Issuer
{
    private Settings $settings;
    private Ledger $ledger;
    private Nalog $nalog;

    public function __construct(Settings $settings, Ledger $ledger, Nalog $nalog)
    {
        $this->settings = $settings;
        $this->ledger = $ledger;
        $this->nalog = $nalog;
    }

    /**
     * Обработать очередь.
     *
     * @return array{ran: bool, issued: int, failed: int, dry_run: int, errors: array<int,string>, reason: string}
     */
    public function run(int $limit = 10): array
    {
        $result = ['ran' => false, 'issued' => 0, 'failed' => 0, 'dry_run' => 0, 'errors' => [], 'reason' => ''];

        if (!$this->settings->bool('enabled', false)) {
            $result['reason'] = 'плагин выключен (настройка «Пробивать чеки»)';
            return $result;
        }

        [$locked, $lock] = $this->lock();
        if (!$locked) {
            $result['reason'] = 'уже идёт обработка';
            return $result;
        }

        $result['ran'] = true;
        $dryRun = $this->settings->bool('dry_run', true);

        try {
            foreach ($this->ledger->pending($limit) as $row) {
                $id = (int) $row['id'];

                if ($dryRun) {
                    $this->ledger->markDryRun($id, 'пробный режим: в ФНС не отправлено');
                    $result['dry_run']++;
                    continue;
                }

                $outcome = $this->issue($row);
                if ($outcome['ok']) {
                    $result['issued']++;
                } else {
                    $result['failed']++;
                    $result['errors'][$id] = $outcome['error'];
                }
            }
        } finally {
            $this->unlock($lock);
        }

        return $result;
    }

    /**
     * Пробить один чек.
     *
     * @param array<string,mixed> $row
     * @return array{ok: bool, error: string, uuid: string, url: string}
     */
    public function issue(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'нет строки журнала', 'uuid' => '', 'url' => ''];
        }

        if (($row['status'] ?? '') === Ledger::STATUS_ISSUED) {
            return [
                'ok' => true,
                'error' => '',
                'uuid' => (string) $row['receipt_uuid'],
                'url' => (string) $row['receipt_url'],
            ];
        }

        $income = [
            'amount' => (string) $row['amount'],
            'description' => $this->description($row),
            'operation_time' => (string) ($row['operation_time'] ?: Nalog::localIso()),
            'payment_type' => (string) ($row['payment_type'] ?: $this->settings->get('payment_type', 'CASH')),
            'income_type' => (string) ($row['income_type'] ?: $this->settings->get('default_income_type', 'FROM_INDIVIDUAL')),
            'client_phone' => (string) $row['client_phone'],
            'client_name' => '',
        ];

        try {
            $receipt = $this->nalog->createIncome($income);
        } catch (NpdAuthRequired $e) {
            $this->ledger->markFailed($id, $e->getMessage());
            $this->alertOnce('npd-auth', 'Чеки НПД: нужен повторный вход в «Мой налог»', $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage(), 'uuid' => '', 'url' => ''];
        } catch (\Throwable $e) {
            $this->ledger->markFailed($id, $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage(), 'uuid' => '', 'url' => ''];
        }

        $this->ledger->markIssued($id, $receipt['uuid'], $receipt['url']);

        $fresh = $this->ledger->byId($id) ?? $row;
        $fresh['receipt_uuid'] = $receipt['uuid'];
        $fresh['receipt_url'] = $receipt['url'];
        (new Delivery($this->settings))->receipt($fresh);

        return ['ok' => true, 'error' => '', 'uuid' => $receipt['uuid'], 'url' => $receipt['url']];
    }

    /** Наименование услуги для чека: шаблон из настроек + подстановки. */
    public function description(array $row): string
    {
        $template = (string) $this->settings->get('service_name', '{description}');
        if (trim($template) === '') {
            $template = '{description}';
        }

        $text = str_replace(
            ['{description}', '{ref}', '{id}'],
            [
                (string) ($row['description'] ?? ''),
                (string) ($row['ref'] ?? ''),
                (string) ($row['payment_id'] ?? ''),
            ],
            $template
        );

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        // Пустое наименование ФНС не примет, а «Услуга» — законный минимум.
        if ($text === '') {
            $text = 'Услуга';
        }

        return mb_substr($text, 0, 250);
    }

    // === Замок: два тика одновременно не должны пробить чек дважды ===

    /**
     * @return array{0: bool, 1: mixed} [получили ли замок, дескриптор]
     */
    private function lock(): array
    {
        // tmp/ — служебное: он в .gitignore, а storage/ с недавних пор
        // частично отслеживается гитом (см. .gitignore проекта).
        $path = (defined('PROJECT_ROOT') ? PROJECT_ROOT : STORAGE_PATH) . '/tmp/npd.lock';
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return [true, null];
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            // Нет прав на storage — работаем без замка: риск двойного чека
            // ниже, чем риск вообще никогда не пробить чек.
            return [true, null];
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return [false, null];
        }

        return [true, $handle];
    }

    private function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Письмо владельцу — один раз на тип проблемы за сутки, иначе при
     * недоступной сессии ящик получает письмо на каждый платёж.
     */
    private function alertOnce(string $key, string $subject, string $message): void
    {
        $stamp = (string) $this->settings->get('alert_' . $key, '');
        if ($stamp !== '' && strtotime($stamp) > time() - 86400) {
            return;
        }
        $this->settings->save('alert_' . $key, gmdate('c'));
        (new Delivery($this->settings))->notifyAdmin($subject, $message);
    }
}
