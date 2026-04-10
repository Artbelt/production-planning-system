<?php
/**
 * Подключение daily_auth с понятной ошибкой на странице/в JSON, если файл не выложен на сервер.
 *
 * @param array{json_errors?: bool} $options
 */
function laser_operator_load_daily_auth_file(array $options = []) {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $useJson = !empty($options['json_errors']);
    $f = __DIR__ . '/includes/daily_auth.php';
    if (is_readable($f)) {
        require_once $f;
        $loaded = true;
        return;
    }

    $incDir = __DIR__ . '/includes';
    $listing = null;
    if (is_dir($incDir)) {
        $listing = array_values(array_diff(scandir($incDir) ?: [], ['.', '..']));
    }

    if ($useJson) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'server_config',
            'message' => 'Нет файла laser_operator/includes/daily_auth.php — выложите его на сервер.',
            'expected_path' => $f,
            'includes_exists' => is_dir($incDir),
            'includes_files' => $listing,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
    }
    $listHtml = $listing === null
        ? 'папка <code>includes</code> отсутствует'
        : htmlspecialchars(implode(', ', $listing), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>laser_operator</title></head><body style="font-family:system-ui,Segoe UI,sans-serif;padding:24px;max-width:800px;line-height:1.5">';
    echo '<h1 style="color:#b91c1c">Не найден файл проверки входа</h1>';
    echo '<p><strong>Ожидался путь:</strong><br><code style="background:#f1f5f9;padding:4px 8px">' . htmlspecialchars($f, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
    echo '<p><strong>Содержимое <code>laser_operator/includes/</code>:</strong> ' . $listHtml . '</p>';
    echo '<p>Скопируйте на сервер из репозитория каталог <code>laser_operator/includes/</code> (файл <code>daily_auth.php</code>) и файл <code>laser_operator/daily_auth_load.php</code>.</p>';
    echo '</body></html>';
    exit;
}
