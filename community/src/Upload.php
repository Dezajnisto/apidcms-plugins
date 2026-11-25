<?php
/**
 * Картинки в сообщениях.
 *
 * Файл кладём в storage/uploads/community/<год>/<месяц>/ — этот каталог уже
 * отдаётся сайтом (nginx пропускает /storage/uploads/ через роутер), так что
 * ничего в конфиге сервера менять не нужно. Имя генерируем сами: исходное
 * имя от посетителя в путь не попадает.
 */

namespace Plugins\Community;

class Upload
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * @param array $file элемент $_FILES
     * @return array{ok:bool,url?:string,error?:string}
     */
    public function save(array $file): array
    {
        if (!$this->settings->bool('uploads_enabled', true)) {
            return ['ok' => false, 'error' => 'Загрузка картинок выключена.'];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Файл не выбран.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Не удалось загрузить файл (код ' . $error . ').'];
        }

        $maxMb = max(1, $this->settings->int('upload_max_mb', 5));
        if ((int) $file['size'] > $maxMb * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Файл больше ' . $maxMb . ' МБ.'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Файл не загружен.'];
        }

        // Тип определяем по содержимому, а не по расширению и не по заголовку
        // от браузера: расширение подделывается одной строкой.
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';

        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'error' => 'Поддерживаются JPG, PNG, WebP и GIF.'];
        }

        $ext = self::ALLOWED[$mime];
        $relative = 'community/' . date('Y') . '/' . date('m');
        $dir = rtrim(ROOT_PATH, '/') . '/storage/uploads/' . $relative;

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Не удалось создать каталог для файла.'];
        }

        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $dir . '/' . $name;

        if (!@move_uploaded_file($tmp, $target)) {
            return ['ok' => false, 'error' => 'Не удалось сохранить файл.'];
        }

        @chmod($target, 0664);

        return ['ok' => true, 'url' => '/storage/uploads/' . $relative . '/' . $name];
    }
}
