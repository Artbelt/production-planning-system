<?php
/**
 * Восстановление таблицы salon_filter_structure из файла бэкапа (участок U4).
 * Запуск: в браузере или из консоли: php restore_salon_filter_structure_from_backup.php
 *
 * Бэкап: по умолчанию G:\BACKUP\plan_u4_20260201_230002.sql
 * Параметры БД из settings.php (plan_U4, root, без пароля).
 */

$backup_path = 'G:\BACKUP\plan_u4_20260201_230002.sql';
$table_marker = 'salon_filter_structure';

// Подключение настроек БД
require_once __DIR__ . '/../settings.php';

if (php_sapi_name() === 'cli') {
    $out = function ($msg, $isError = false) {
        echo $msg . PHP_EOL;
    };
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre>';
    $out = function ($msg, $isError = false) {
        echo htmlspecialchars($msg) . "\n";
    };
}

// Проверка наличия файла бэкапа
if (!is_file($backup_path)) {
    $out("ОШИБКА: Файл бэкапа не найден: {$backup_path}");
    exit(1);
}

$out("Извлечение таблицы {$table_marker} из бэкапа...");

$lines = file($backup_path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    $out("ОШИБКА: Не удалось прочитать файл бэкапа.");
    exit(1);
}

$block = [];
$capture = false;

foreach ($lines as $line) {
    if (preg_match('/DROP TABLE IF EXISTS.*' . preg_quote($table_marker, '/') . '/', $line)) {
        $capture = true;
    }
    if ($capture) {
        $block[] = $line;
        // Конец блока таблицы — после UNLOCK TABLES
        if (preg_match('/UNLOCK TABLES\s*;?\s*$/', trim($line)) && count($block) > 5) {
            break;
        }
        // Следующая таблица — не включаем её в блок
        if (preg_match('/DROP TABLE IF EXISTS/', $line) && strpos($line, $table_marker) === false && count($block) > 3) {
            array_pop($block);
            break;
        }
    }
}

$extracted = implode("\n", $block);
if (trim($extracted) === '') {
    $out("ОШИБКА: В бэкапе не найден блок для таблицы {$table_marker}.");
    exit(1);
}

$out("Подключение к БД и восстановление...");

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    $out("ОШИБКА подключения к БД: " . $mysqli->connect_error);
    exit(1);
}

$mysqli->set_charset('utf8mb4');

// Выполняем извлечённый SQL (multi_query для нескольких команд подряд)
if (!$mysqli->multi_query($extracted)) {
    $out("ОШИБКА при выполнении SQL: " . $mysqli->error);
    $mysqli->close();
    exit(1);
}

// Очищаем очередь результатов multi_query
do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
} while ($mysqli->next_result());

if ($mysqli->error) {
    $out("ОШИБКА при выполнении SQL: " . $mysqli->error);
    $mysqli->close();
    exit(1);
}

$mysqli->close();

$out("Таблица salon_filter_structure успешно восстановлена из бэкапа (БД " . $mysql_database . ").");
