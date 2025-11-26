<?php
/**
 * Скрипт для анализа логов Apache на предмет запросов к обработке фильтров
 */

header('Content-Type: text/html; charset=utf-8');

$access_log_path = 'C:/xampp/apache/logs/access.log';
$error_log_path = 'C:/xampp/apache/logs/error.log';
$php_log_path = 'C:/xampp/php/logs/php_error_log';

echo "<!DOCTYPE html><html lang='ru'><head><meta charset='UTF-8'><title>Анализ логов</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
    h2 { color: #555; margin-top: 30px; }
    .log-entry { background: #f8f9fa; padding: 10px; margin: 5px 0; border-left: 3px solid #4CAF50; font-family: monospace; font-size: 12px; }
    .log-entry.error { border-left-color: #dc3545; background: #f8d7da; }
    .log-entry.warning { border-left-color: #ffc107; background: #fff3cd; }
    .info { background: #d1ecf1; border-left: 4px solid #0c5460; padding: 10px; margin: 10px 0; }
    .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin: 10px 0; }
    .error { background: #f8d7da; border-left: 4px solid #dc3545; padding: 10px; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th { background: #4CAF50; color: white; padding: 10px; text-align: left; }
    td { padding: 8px; border-bottom: 1px solid #ddd; }
    .timestamp { color: #666; font-weight: bold; }
    .ip { color: #0066cc; }
    .code-200 { color: #28a745; }
    .code-500 { color: #dc3545; }
</style></head><body><div class='container'>";

echo "<h1>📋 Анализ логов Apache</h1>";
echo "<p><strong>Дата проверки:</strong> " . date('Y-m-d H:i:s') . "</p>";

// Функция для чтения последних строк файла
function readLastLines($filename, $lines = 1000) {
    if (!file_exists($filename)) {
        return [];
    }
    
    $file = file($filename);
    return array_slice($file, -$lines);
}

// 1. Анализ access.log
echo "<h2>1. Анализ Apache Access Log</h2>";

if (file_exists($access_log_path)) {
    $access_logs = readLastLines($access_log_path, 5000);
    
    // Фильтруем записи, связанные с обработкой фильтров
    $filter_related = [];
    $edit_filter_requests = [];
    $add_filter_requests = [];
    
    foreach ($access_logs as $line) {
        if (strpos($line, 'processing_edit_filter_properties') !== false) {
            $edit_filter_requests[] = $line;
            $filter_related[] = ['type' => 'edit', 'line' => $line];
        }
        if (strpos($line, 'processing_add_salon_filter_into_db') !== false) {
            $add_filter_requests[] = $line;
            $filter_related[] = ['type' => 'add', 'line' => $line];
        }
    }
    
    echo "<div class='info'>";
    echo "<strong>Всего записей в access.log:</strong> " . count($access_logs) . "<br>";
    echo "<strong>Запросов к processing_edit_filter_properties.php:</strong> " . count($edit_filter_requests) . "<br>";
    echo "<strong>Запросов к processing_add_salon_filter_into_db.php:</strong> " . count($add_filter_requests);
    echo "</div>";
    
    if (count($edit_filter_requests) > 0) {
        echo "<h3>Последние запросы к редактированию фильтров (последние 20):</h3>";
        echo "<table>";
        echo "<tr><th>Время</th><th>IP</th><th>Метод</th><th>URL</th><th>Код</th><th>Размер</th></tr>";
        
        $recent = array_slice($edit_filter_requests, -20);
        foreach ($recent as $line) {
            // Парсим строку лога Apache
            // Формат: IP - - [дата] "метод URL протокол" код размер
            if (preg_match('/^(\S+)\s+.*?\[([^\]]+)\]\s+"(\S+)\s+(\S+)\s+[^"]*"\s+(\d+)\s+(\S+)/', $line, $matches)) {
                $ip = $matches[1];
                $timestamp = $matches[2];
                $method = $matches[3];
                $url = $matches[4];
                $code = $matches[5];
                $size = $matches[6];
                
                $code_class = $code == '200' ? 'code-200' : ($code >= '400' ? 'code-500' : '');
                
                echo "<tr>";
                echo "<td class='timestamp'>" . htmlspecialchars($timestamp) . "</td>";
                echo "<td class='ip'>" . htmlspecialchars($ip) . "</td>";
                echo "<td>" . htmlspecialchars($method) . "</td>";
                echo "<td><small>" . htmlspecialchars(substr($url, 0, 80)) . "</small></td>";
                echo "<td class='{$code_class}'>" . htmlspecialchars($code) . "</td>";
                echo "<td>" . htmlspecialchars($size) . "</td>";
                echo "</tr>";
            } else {
                echo "<tr><td colspan='6' class='log-entry'>" . htmlspecialchars($line) . "</td></tr>";
            }
        }
        echo "</table>";
        
        // Группировка по датам
        $by_date = [];
        foreach ($edit_filter_requests as $line) {
            if (preg_match('/\[([^\]]+)\]/', $line, $matches)) {
                $date = substr($matches[1], 0, 11); // Берем только дату
                if (!isset($by_date[$date])) {
                    $by_date[$date] = 0;
                }
                $by_date[$date]++;
            }
        }
        
        if (count($by_date) > 0) {
            echo "<h3>Статистика по датам:</h3>";
            echo "<table>";
            echo "<tr><th>Дата</th><th>Количество запросов</th></tr>";
            krsort($by_date);
            foreach ($by_date as $date => $count) {
                $highlight = $count > 10 ? 'warning' : '';
                echo "<tr class='{$highlight}'>";
                echo "<td>" . htmlspecialchars($date) . "</td>";
                echo "<td><strong>" . $count . "</strong></td>";
                echo "</tr>";
            }
            echo "</table>";
            if (max($by_date) > 10) {
                echo "<div class='warning'>⚠️ Обнаружены дни с большим количеством запросов (>10). Проверьте эти даты на предмет массовых обновлений.</div>";
            }
        }
    } else {
        echo "<div class='warning'>В access.log не найдено запросов к processing_edit_filter_properties.php</div>";
    }
} else {
    echo "<div class='error'>Файл access.log не найден по пути: " . htmlspecialchars($access_log_path) . "</div>";
}

// 2. Анализ error.log
echo "<h2>2. Анализ Apache Error Log</h2>";

if (file_exists($error_log_path)) {
    $error_logs = readLastLines($error_log_path, 500);
    
    $db_errors = [];
    $filter_errors = [];
    
    foreach ($error_logs as $line) {
        if (stripos($line, 'salon_filter_structure') !== false || 
            stripos($line, 'processing_edit_filter') !== false ||
            stripos($line, 'mysql') !== false ||
            stripos($line, 'mysqli') !== false) {
            $db_errors[] = $line;
        }
        if (stripos($line, 'filter') !== false) {
            $filter_errors[] = $line;
        }
    }
    
    echo "<div class='info'>";
    echo "<strong>Всего записей в error.log:</strong> " . count($error_logs) . "<br>";
    echo "<strong>Ошибок связанных с БД/фильтрами:</strong> " . count($db_errors);
    echo "</div>";
    
    if (count($db_errors) > 0) {
        echo "<h3>Ошибки связанные с БД или фильтрами (последние 20):</h3>";
        echo "<div style='max-height: 400px; overflow-y: auto;'>";
        foreach (array_slice($db_errors, -20) as $line) {
            $class = (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) ? 'error' : 'warning';
            echo "<div class='log-entry {$class}'>" . htmlspecialchars($line) . "</div>";
        }
        echo "</div>";
    } else {
        echo "<div class='info'>Ошибок связанных с БД или фильтрами не найдено.</div>";
    }
} else {
    echo "<div class='warning'>Файл error.log не найден по пути: " . htmlspecialchars($error_log_path) . "</div>";
}

// 3. Анализ PHP error log
echo "<h2>3. Анализ PHP Error Log</h2>";

if (file_exists($php_log_path)) {
    $php_logs = readLastLines($php_log_path, 500);
    
    $relevant_errors = [];
    foreach ($php_logs as $line) {
        if (stripos($line, 'salon_filter_structure') !== false || 
            stripos($line, 'processing_edit_filter') !== false ||
            stripos($line, 'mysql') !== false) {
            $relevant_errors[] = $line;
        }
    }
    
    echo "<div class='info'>";
    echo "<strong>Всего записей в php_error_log:</strong> " . count($php_logs) . "<br>";
    echo "<strong>Релевантных ошибок:</strong> " . count($relevant_errors);
    echo "</div>";
    
    if (count($relevant_errors) > 0) {
        echo "<h3>Релевантные ошибки PHP (последние 20):</h3>";
        echo "<div style='max-height: 400px; overflow-y: auto;'>";
        foreach (array_slice($relevant_errors, -20) as $line) {
            echo "<div class='log-entry error'>" . htmlspecialchars($line) . "</div>";
        }
        echo "</div>";
    } else {
        echo "<div class='info'>Релевантных ошибок не найдено.</div>";
    }
} else {
    echo "<div class='warning'>Файл php_error_log не найден по пути: " . htmlspecialchars($php_log_path) . "</div>";
}

// Рекомендации
echo "<h2>4. Рекомендации</h2>";
echo "<div class='info'>";
echo "<strong>Что делать дальше:</strong><ol>";
echo "<li><strong>Проверьте даты с большим количеством запросов</strong> - это может указывать на массовое обновление</li>";
echo "<li><strong>Обратите внимание на запросы с кодом 500</strong> - это ошибки сервера</li>";
echo "<li><strong>Проверьте IP-адреса</strong> - кто делал запросы в проблемные дни</li>";
echo "<li><strong>Если найдены ошибки в error.log</strong> - они могут указывать на причину проблемы</li>";
echo "<li><strong>Сравните время запросов</strong> с временем, когда вы заметили пропажу данных</li>";
echo "</ol>";
echo "</div>";

echo "</div></body></html>";
?>




