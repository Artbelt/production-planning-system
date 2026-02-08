<?php
/**
 * Восстановление таблиц участка из файла бэкапа — по одной или выбранным.
 * Позволяет указать путь к бэкапу и восстановить любые таблицы по отдельности.
 *
 * Запуск: в браузере — http://.../plan_U5/tools/restore_tables_from_backup.php
 */

require_once __DIR__ . '/../settings.php';

$default_backup_path = 'G:\BACKUP\plan_u5_20260201_230002.sql';
$backup_path = trim($_POST['backup_path'] ?? $_GET['backup_path'] ?? $default_backup_path);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$selected_tables = $_POST['tables'] ?? [];

header('Content-Type: text/html; charset=utf-8');

/**
 * Извлекает список имён таблиц из дампа (строки DROP TABLE IF EXISTS).
 */
function get_tables_from_backup(string $path): array {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    $tables = [];
    foreach ($lines as $line) {
        if (preg_match('/DROP TABLE IF EXISTS\s*[`"]?([a-zA-Z0-9_]+)[`"]?/i', $line, $m)) {
            $tables[] = $m[1];
        }
    }
    return array_unique($tables);
}

/**
 * Извлекает блок SQL для одной таблицы (от DROP TABLE до UNLOCK TABLES или следующего DROP TABLE).
 */
function extract_table_block(string $path, string $table_name): string {
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return '';
    }
    $block = [];
    $capture = false;
    foreach ($lines as $line) {
        if (preg_match('/DROP TABLE IF EXISTS\s*[`"]?' . preg_quote($table_name, '/') . '[`"]?/i', $line)) {
            $capture = true;
        }
        if ($capture) {
            $block[] = $line;
            if (preg_match('/UNLOCK TABLES\s*;?\s*$/i', trim($line)) && count($block) > 5) {
                break;
            }
            if (preg_match('/DROP TABLE IF EXISTS/i', $line) && stripos($line, $table_name) === false && count($block) > 3) {
                array_pop($block);
                break;
            }
        }
    }
    return implode("\n", $block);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Восстановление таблиц из бэкапа</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; max-width: 720px; background: #f5f5f5; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.08); margin-bottom: 20px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .muted { color: #666; font-size: 13px; margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; }
        input[type="text"] { width: 100%; max-width: 480px; padding: 10px 12px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; }
        .tables { max-height: 360px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px; background: #fafafa; }
        .tables label { display: flex; align-items: center; gap: 8px; padding: 6px 0; cursor: pointer; }
        .tables input { margin: 0; }
        .btn { padding: 10px 18px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #e5e7eb; color: #374151; margin-left: 8px; }
        .btn-secondary:hover { background: #d1d5db; }
        .msg { padding: 12px; border-radius: 8px; margin-top: 12px; }
        .msg.ok { background: #d1fae5; color: #065f46; }
        .msg.err { background: #fee2e2; color: #991b1b; }
        .msg.warn { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body>

<div class="card">
    <h1>Восстановление таблиц из бэкапа</h1>
    <p class="muted">Укажите путь к файлу .sql (дампа mysqldump). Затем выберите таблицы и восстановите их в БД <?= h($mysql_database) ?>.</p>

    <form method="post" id="formPath">
        <label for="backup_path">Путь к файлу бэкапа</label>
        <input type="text" name="backup_path" id="backup_path" value="<?= h($backup_path) ?>" placeholder="G:\BACKUP\plan_u5_20260201_230002.sql">
        <input type="hidden" name="action" value="show_tables">
        <p style="margin-top: 12px;">
            <button type="submit" class="btn btn-primary">Показать таблицы в бэкапе</button>
        </p>
    </form>
</div>

<?php
if ($action === 'show_tables') {
    if (!is_file($backup_path)) {
        echo '<div class="card"><div class="msg err">Файл бэкапа не найден: ' . h($backup_path) . '</div></div>';
    } else {
        $tables = get_tables_from_backup($backup_path);
        if (empty($tables)) {
            echo '<div class="card"><div class="msg warn">В файле не найдено ни одной таблицы (ожидаются строки DROP TABLE IF EXISTS).</div></div>';
        } else {
            sort($tables);
            echo '<div class="card">';
            echo '<h2 style="margin:0 0 12px; font-size:16px;">Таблицы в бэкапе (' . count($tables) . ')</h2>';
            echo '<form method="post">';
            echo '<input type="hidden" name="backup_path" value="' . h($backup_path) . '">';
            echo '<input type="hidden" name="action" value="restore">';
            echo '<div class="tables" id="tablesBox">';
            foreach ($tables as $t) {
                echo '<label><input type="checkbox" name="tables[]" value="' . h($t) . '" class="tb-cb"> ' . h($t) . '</label>';
            }
            echo '</div>';
            echo '<p style="margin-top: 8px;"><button type="button" class="btn btn-secondary" onclick="document.querySelectorAll(\'.tb-cb\').forEach(c=>c.checked=true)">Выбрать все</button> ';
            echo '<button type="button" class="btn btn-secondary" onclick="document.querySelectorAll(\'.tb-cb\').forEach(c=>c.checked=false)">Снять все</button></p>';
            echo '<p style="margin-top: 12px;">';
            echo '<button type="submit" class="btn btn-primary">Восстановить выбранные таблицы</button>';
            echo '<a href="?" class="btn btn-secondary">Сменить файл</a>';
            echo '</p>';
            echo '</form></div>';
        }
    }
}

if ($action === 'restore' && !empty($selected_tables) && is_array($selected_tables)) {
    if (!is_file($backup_path)) {
        echo '<div class="card"><div class="msg err">Файл бэкапа не найден: ' . h($backup_path) . '</div></div>';
    } else {
        $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
        if ($mysqli->connect_errno) {
            echo '<div class="card"><div class="msg err">Ошибка подключения к БД: ' . h($mysqli->connect_error) . '</div></div>';
        } else {
            $mysqli->set_charset('utf8mb4');
            $ok = [];
            $fail = [];
            foreach ($selected_tables as $table_name) {
                $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                if ($table_name === '') {
                    continue;
                }
                $block = extract_table_block($backup_path, $table_name);
                if (trim($block) === '') {
                    $fail[$table_name] = 'Блок таблицы не найден в бэкапе';
                    continue;
                }
                if (!$mysqli->multi_query($block)) {
                    $fail[$table_name] = $mysqli->error;
                    while ($mysqli->next_result()) {;}
                    continue;
                }
                do {
                    if ($res = $mysqli->store_result()) {
                        $res->free();
                    }
                } while ($mysqli->next_result());
                if ($mysqli->error) {
                    $fail[$table_name] = $mysqli->error;
                } else {
                    $ok[] = $table_name;
                }
            }
            $mysqli->close();

            echo '<div class="card">';
            echo '<h2 style="margin:0 0 12px; font-size:16px;">Результат восстановления</h2>';
            if (!empty($ok)) {
                echo '<div class="msg ok">Восстановлены: ' . h(implode(', ', $ok)) . '</div>';
            }
            if (!empty($fail)) {
                echo '<div class="msg err">Ошибки:<ul style="margin:8px 0 0; padding-left:20px;">';
                foreach ($fail as $t => $err) {
                    echo '<li>' . h($t) . ': ' . h($err) . '</li>';
                }
                echo '</ul></div>';
            }
            echo '<p style="margin-top: 12px;"><a href="?backup_path=' . rawurlencode($backup_path) . '&action=show_tables" class="btn btn-secondary">Вернуться к выбору таблиц</a></p>';
            echo '</div>';
        }
    }
}
?>

</body>
</html>
