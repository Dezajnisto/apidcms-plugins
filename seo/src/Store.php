<?php
/**
 * Хранилище SEO-полей: одна таблица на все сущности.
 *
 * Ключ сущности — (entity_type, entity_table, entity_id):
 *   page     pages      5   — статическая страница
 *   post     posts       7   — пост блога (если таблица есть)
 *   record   products   12  — запись таблицы, entity_table = имя таблицы
 *   landing  ''          3   — раздел навигации (список записей, /blog)
 */

namespace Plugins\Seo;

class Store
{
    private \Core\Database $db;

    public function __construct(\Core\Database $db)
    {
        $this->db = $db;
    }

    /**
     * Разобрать ключ вида "page:pages:5" в тройку.
     *
     * @return array{0:string,1:string,2:int}|null
     */
    public static function parseKey(string $key): ?array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 3) {
            return null;
        }
        return [$parts[0], $parts[1], (int) $parts[2]];
    }

    public static function makeKey(string $type, string $table, int $id): string
    {
        return $type . ':' . $table . ':' . $id;
    }

    /** @return array<string,mixed> */
    public function get(string $type, string $table, int $id): array
    {
        try {
            $row = $this->db->query(
                "SELECT * FROM plugin_seo_meta
                  WHERE entity_type = ? AND entity_table = ? AND entity_id = ?",
                [$type, $table, $id]
            )->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }

        return $row ?: [];
    }

    /**
     * Все записи таблицы, разложенные по ключу сущности — для отчёта
     * и для проверки дублей без N запросов.
     *
     * @return array<string, array<string,mixed>>
     */
    public function all(): array
    {
        $result = [];
        try {
            $rows = $this->db->query("SELECT * FROM plugin_seo_meta")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $result;
        }

        foreach ($rows as $row) {
            $key = self::makeKey(
                (string) $row['entity_type'],
                (string) $row['entity_table'],
                (int) $row['entity_id']
            );
            $result[$key] = $row;
        }

        return $result;
    }

    /**
     * Сохранить поля. Пустая строка = «не задано», тогда на сайте работает
     * значение из контента.
     *
     * @param array<string,mixed> $data
     */
    public function save(string $type, string $table, int $id, array $data): void
    {
        $fields = [
            'title', 'description', 'focus_keyword', 'canonical', 'robots',
            'og_title', 'og_description', 'og_image', 'schema_json',
        ];

        $values = [];
        foreach ($fields as $field) {
            $value = trim(self::toUtf8((string) ($data[$field] ?? '')));
            $values[$field] = $value === '' ? null : $value;
        }

        // robots: пусто = по умолчанию, поэтому нормализуем регистр и мусор
        if ($values['robots'] !== null) {
            $allowed = ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'];
            $values['robots'] = in_array(strtolower($values['robots']), $allowed, true)
                ? strtolower($values['robots'])
                : 'index,follow';
        }

        // canonical: только путь от корня или абсолютный http(s)
        if ($values['canonical'] !== null && !preg_match('#^(https?://|/)#i', $values['canonical'])) {
            $values['canonical'] = '/' . ltrim($values['canonical'], '/');
        }

        $existing = $this->get($type, $table, $id);

        if ($existing) {
            $sets = [];
            $params = [];
            foreach ($values as $field => $value) {
                $sets[] = "\"{$field}\" = ?";
                $params[] = $value;
            }
            $params[] = (int) $existing['id'];
            $this->db->query(
                "UPDATE plugin_seo_meta SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                $params
            );
            return;
        }

        // Пустая запись не нужна: без полей работает контент.
        $hasContent = false;
        foreach ($values as $value) {
            if ($value !== null) {
                $hasContent = true;
                break;
            }
        }
        if (!$hasContent) {
            return;
        }

        $columns = array_keys($values);
        $placeholders = array_fill(0, count($columns), '?');
        $params = array_values($values);
        array_unshift($params, $type, $table, $id);

        $this->db->query(
            'INSERT INTO plugin_seo_meta (entity_type, entity_table, entity_id, "'
            . implode('", "', $columns) . '", updated_at) VALUES (?, ?, ?, '
            . implode(', ', $placeholders) . ', CURRENT_TIMESTAMP)',
            $params
        );
    }

    /**
     * Привести значение к UTF-8. Текст из Word, 1С или старого сайта
     * приезжает в Windows-1251; в базе он превратился бы в мусор, а в теге —
     * в пустую строку, потому что htmlspecialchars на таком тексте отдаёт ''.
     */
    public static function toUtf8(string $value): string
    {
        if ($value === '' || preg_match('//u', $value)) {
            return $value;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
            return $converted;
        }

        // Последний шанс — выкинуть невалидные байты
        $scrubbed = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        return is_string($scrubbed) ? $scrubbed : '';
    }

    /**
     * Записать результат проверки. Row создаётся при необходимости: оценка
     * полезна и для страниц, где SEO-поля не заполнялись.
     */
    public function saveScore(string $type, string $table, int $id, int $score): void
    {
        $existing = $this->get($type, $table, $id);
        try {
            if ($existing) {
                $this->db->query(
                    "UPDATE plugin_seo_meta SET score = ?, checked_at = CURRENT_TIMESTAMP WHERE id = ?",
                    [$score, (int) $existing['id']]
                );
            } else {
                $this->db->query(
                    "INSERT INTO plugin_seo_meta (entity_type, entity_table, entity_id, score, checked_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)",
                    [$type, $table, $id, $score]
                );
            }
        } catch (\Throwable $e) {
            // оценку писать необязательно — молчим
        }
    }

    /** Удалить запись (сущность исчезла из контента). */
    public function forget(string $type, string $table, int $id): void
    {
        try {
            $this->db->query(
                "DELETE FROM plugin_seo_meta WHERE entity_type = ? AND entity_table = ? AND entity_id = ?",
                [$type, $table, $id]
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
