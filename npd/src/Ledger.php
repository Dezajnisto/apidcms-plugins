<?php
/**
 * Журнал чеков НПД.
 *
 * Одна строка = один доход, который надо (или уже удалось) зарегистрировать.
 * Таблица — это одновременно очередь: платёж приходит из вебхука, строка
 * создаётся со статусом pending, и дальше её добирает Issuer. Поэтому сбой
 * ФНС или таймаут не теряет чек: он остаётся в журнале и виден в админке.
 *
 * Уникальный индекс по payment_id — защита от двойного чека: ЮKassa повторяет
 * вебхук, если не получила 200, а второго чека на тот же платёж быть не должно.
 */

namespace Plugins\Npd;

class Ledger
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DRY_RUN = 'dry_run';

    /** После стольких неудач чек перестаёт автоматически повторяться. */
    public const MAX_ATTEMPTS = 5;

    private \Core\Database $db;

    public function __construct(\Core\Database $db)
    {
        $this->db = $db;
    }

    public function db(): \Core\Database
    {
        return $this->db;
    }

    /**
     * Поставить доход в очередь.
     *
     * @param array{source?: string, payment_id?: string, ref?: string, user_id?: int, amount: string,
     *              description: string, client_email?: string, client_phone?: string,
     *              income_type?: string, payment_type?: string, operation_time?: string,
     *              status?: string, last_error?: string} $data
     * @return int|null id строки или null, если такой платёж уже в журнале
     */
    public function enqueue(array $data): ?int
    {
        $paymentId = (string) ($data['payment_id'] ?? '');
        if ($paymentId !== '' && $this->findByPaymentId($paymentId) !== null) {
            return null;
        }

        $status = (string) ($data['status'] ?? self::STATUS_PENDING);
        $amount = number_format((float) ($data['amount'] ?? 0), 2, '.', '');

        $this->db->query(
            'INSERT OR IGNORE INTO plugin_npd_receipts
                (source, payment_id, ref, user_id, amount, description, client_email, client_phone,
                 income_type, payment_type, status, last_error, operation_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) ($data['source'] ?? 'payment'),
                $paymentId,
                (string) ($data['ref'] ?? ''),
                (int) ($data['user_id'] ?? 0),
                $amount,
                (string) ($data['description'] ?? ''),
                (string) ($data['client_email'] ?? ''),
                (string) ($data['client_phone'] ?? ''),
                (string) ($data['income_type'] ?? 'FROM_INDIVIDUAL'),
                (string) ($data['payment_type'] ?? 'CASH'),
                $status,
                (string) ($data['last_error'] ?? ''),
                (string) ($data['operation_time'] ?? Nalog::localIso()),
            ]
        );

        // INSERT OR IGNORE мог ничего не вставить (гонка двух вебхуков) —
        // тогда id не наш, и повторно пробивать чек нельзя.
        if ($this->db->query('SELECT changes()')->fetchColumn() == 0) {
            return null;
        }

        return (int) $this->db->getConnection()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        $row = $this->db->query('SELECT * FROM plugin_npd_receipts WHERE id = ?', [$id])->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByPaymentId(string $paymentId): ?array
    {
        if ($paymentId === '') {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM plugin_npd_receipts WHERE payment_id = ? LIMIT 1',
            [$paymentId]
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Очередь: необработанные и неудачные, которым ещё рано сдаваться.
     *
     * @return array<int, array<string,mixed>>
     */
    public function pending(int $limit = 10): array
    {
        return $this->db->query(
            "SELECT * FROM plugin_npd_receipts
             WHERE status = ? AND attempts < ?
             ORDER BY id ASC LIMIT ?",
            [self::STATUS_PENDING, self::MAX_ATTEMPTS, $limit]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function markIssued(int $id, string $uuid, string $url): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET status = ?, receipt_uuid = ?, receipt_url = ?, attempts = attempts + 1,
                 last_error = '', issued_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [self::STATUS_ISSUED, $uuid, $url, $id]
        );
    }

    /**
     * Неудача: попытка засчитана. Пока попыток меньше MAX_ATTEMPTS — статус
     * остаётся pending, и следующий тик попробует снова.
     */
    public function markFailed(int $id, string $error): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET attempts = attempts + 1,
                 status = CASE WHEN attempts + 1 >= ? THEN ? ELSE ? END,
                 last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [self::MAX_ATTEMPTS, self::STATUS_FAILED, self::STATUS_PENDING, mb_substr($error, 0, 500), $id]
        );
    }

    /** Вернуть в очередь (кнопка «пробить ещё раз»). */
    public function requeue(int $id): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET status = ?, attempts = 0, last_error = '', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status <> ?",
            [self::STATUS_PENDING, $id, self::STATUS_ISSUED]
        );
    }

    public function markCanceled(int $id, string $note = ''): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET status = ?, last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [self::STATUS_CANCELED, mb_substr($note, 0, 500), $id]
        );
    }

    public function markSkipped(int $id, string $note): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET status = ?, last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [self::STATUS_SKIPPED, mb_substr($note, 0, 500), $id]
        );
    }

    public function markDryRun(int $id, string $note): void
    {
        $this->db->query(
            "UPDATE plugin_npd_receipts
             SET status = ?, attempts = attempts + 1, last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [self::STATUS_DRY_RUN, mb_substr($note, 0, 500), $id]
        );
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listForAdmin(string $status = '', string $search = '', int $limit = 200): array
    {
        $sql = 'SELECT * FROM plugin_npd_receipts WHERE 1 = 1';
        $params = [];

        if ($status !== '' && $status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $sql .= ' AND (description LIKE ? OR payment_id LIKE ? OR ref LIKE ? OR receipt_uuid LIKE ? OR client_email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->db->query(
            'SELECT * FROM plugin_npd_receipts WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,int> статус => количество */
    public function counts(): array
    {
        $rows = $this->db->query(
            'SELECT status, COUNT(*) AS n FROM plugin_npd_receipts GROUP BY status'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Сколько чеков требует внимания владельца (для значка в меню). */
    public function problemCount(): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) FROM plugin_npd_receipts WHERE status = ?',
            [self::STATUS_FAILED]
        )->fetchColumn();

        return (int) $row;
    }

    /**
     * Почта покупателя по id аккаунта сайта (плагин account, если он есть).
     */
    public function emailForUser(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        try {
            $row = $this->db->query(
                'SELECT email FROM plugin_account_users WHERE id = ? LIMIT 1',
                [$userId]
            )->fetch(\PDO::FETCH_ASSOC);
            return (string) ($row['email'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
