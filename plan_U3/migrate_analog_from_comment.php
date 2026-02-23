<?php
/**
 * Скрипт миграции данных аналога из поля comment в поле analog
 * 
 * Этот скрипт извлекает значение ANALOG_FILTER=... из поля comment
 * и переносит его в новое поле analog
 */

require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan_u3');

echo "Начало миграции данных аналога из comment в analog...\n\n";

$st = $pdo->query("SELECT filter, comment FROM round_filter_structure WHERE comment LIKE '%ANALOG_FILTER=%'");
if (!$st) die("Ошибка запроса");

$count = 0;
$updated = 0;
$errors = 0;
$st_upd = $pdo->prepare("UPDATE round_filter_structure SET analog = ?, comment = ? WHERE filter = ?");

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $count++;
    $filter = $row['filter'];
    $comment = $row['comment'];
    
    if (preg_match('/ANALOG_FILTER=([^\s]+)/i', $comment, $matches)) {
        $analog = trim($matches[1]);
        $new_comment = trim(preg_replace('/\s*ANALOG_FILTER=[^\s]+/i', '', $comment));
        
        if ($st_upd->execute([$analog, $new_comment, $filter])) {
            $updated++;
            echo "✓ Обновлен фильтр: $filter, аналог: $analog\n";
        } else {
            $errors++;
            echo "✗ Ошибка при обновлении фильтра $filter\n";
        }
    } else {
        echo "⚠ Не удалось извлечь аналог из comment для фильтра: $filter\n";
    }
}

echo "\nМиграция завершена.\nВсего обработано: $count\nУспешно обновлено: $updated\nОшибок: $errors\n";



