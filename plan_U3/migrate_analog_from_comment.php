<?php
/**
 * Скрипт миграции данных аналога из поля comment в поле analog
 * 
 * Этот скрипт извлекает значение ANALOG_FILTER=... из поля comment
 * и переносит его в новое поле analog
 */

require_once('settings.php');

global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;

// Подключение к БД
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error);
}

echo "Начало миграции данных аналога из comment в analog...\n\n";

// Получаем все записи, где в comment есть ANALOG_FILTER=
$sql = "SELECT filter, comment FROM round_filter_structure WHERE comment LIKE '%ANALOG_FILTER=%'";
$result = $mysqli->query($sql);

if (!$result) {
    die("Ошибка запроса: " . $mysqli->error);
}

$count = 0;
$updated = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $count++;
    $filter = $row['filter'];
    $comment = $row['comment'];
    
    // Извлекаем значение ANALOG_FILTER=...
    $analog = '';
    if (preg_match('/ANALOG_FILTER=([^\s]+)/i', $comment, $matches)) {
        $analog = trim($matches[1]);
        
        // Удаляем ANALOG_FILTER=... из comment, оставляя остальной текст
        $new_comment = preg_replace('/\s*ANALOG_FILTER=[^\s]+/i', '', $comment);
        $new_comment = trim($new_comment);
        
        // Обновляем запись
        $update_sql = "UPDATE round_filter_structure SET analog = ?, comment = ? WHERE filter = ?";
        $stmt = $mysqli->prepare($update_sql);
        
        if ($stmt) {
            $stmt->bind_param("sss", $analog, $new_comment, $filter);
            if ($stmt->execute()) {
                $updated++;
                echo "✓ Обновлен фильтр: $filter, аналог: $analog\n";
            } else {
                $errors++;
                echo "✗ Ошибка при обновлении фильтра $filter: " . $stmt->error . "\n";
            }
            $stmt->close();
        } else {
            $errors++;
            echo "✗ Ошибка подготовки запроса для фильтра $filter: " . $mysqli->error . "\n";
        }
    } else {
        echo "⚠ Не удалось извлечь аналог из comment для фильтра: $filter\n";
    }
}

echo "\n";
echo "Миграция завершена.\n";
echo "Всего обработано записей: $count\n";
echo "Успешно обновлено: $updated\n";
echo "Ошибок: $errors\n";

$result->close();
$mysqli->close();

?>

