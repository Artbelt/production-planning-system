<?php
/**
 * AJAX endpoint для получения детального расчета процентов выполнения по позициям
 * для конкретной бригады или нескольких бригад (машины) за конкретную дату
 */

require_once('tools/tools.php');
require_once('settings.php');

header('Content-Type: application/json; charset=utf-8');

$date = $_GET['date'] ?? '';
$teams_param = $_GET['teams'] ?? $_GET['team'] ?? '';

if (empty($date) || empty($teams_param)) {
    echo json_encode(['error' => 'Не указаны дата или бригады']);
    exit;
}

try {
    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
    
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    
    if ($mysqli->connect_errno) {
        echo json_encode(['error' => 'Ошибка подключения к БД']);
        exit;
    }
    
    // Разбираем список бригад (может быть одна или несколько через запятую)
    $teams = array_map('intval', explode(',', $teams_param));
    $teams = array_filter($teams); // Убираем пустые значения
    
    if (empty($teams)) {
        echo json_encode(['error' => 'Не указаны корректные бригады']);
        exit;
    }
    
    // Формируем плейсхолдеры для IN запроса
    $placeholders = str_repeat('?,', count($teams) - 1) . '?';
    
    // Получаем детальную информацию по каждой позиции
    // Группируем по фильтру и заявке, суммируя количество
    // Используем подзапрос для salon_filter_structure, чтобы избежать дублирования при JOIN
    $sql = "SELECT 
                mp.name_of_filter,
                mp.name_of_order,
                SUM(mp.count_of_filters) AS count_of_filters,
                COALESCE(MAX(sfs.build_complexity), 0) AS build_complexity
            FROM manufactured_production mp
            LEFT JOIN (
                SELECT 
                    filter,
                    MAX(build_complexity) AS build_complexity
                FROM salon_filter_structure
                GROUP BY filter
            ) sfs ON sfs.filter = mp.name_of_filter
            WHERE mp.date_of_production = ?
              AND mp.team IN ($placeholders)
            GROUP BY mp.name_of_filter, mp.name_of_order
            ORDER BY mp.name_of_filter, mp.name_of_order";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Ошибка подготовки запроса: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    
    // Биндим параметры: сначала дата (строка), потом все бригады (целые числа)
    $types = 's' . str_repeat('i', count($teams));
    $params = array_merge([$date], $teams);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $total_count = 0;
    $norms_sum = 0.0;
    
    while ($row = $result->fetch_assoc()) {
        $count = (int)$row['count_of_filters'];
        $build_complexity = (float)$row['build_complexity'];
        
        $total_count += $count;
        
        // Рассчитываем нормы для этой позиции
        $norms = 0.0;
        $item_percentage = 0;
        
        if ($build_complexity > 0) {
            $norms = $count / $build_complexity;
            $norms_sum += $norms;
            $item_percentage = round($norms * 100, 0);
        }
        
        $items[] = [
            'filter_name' => htmlspecialchars($row['name_of_filter'] ?? '', ENT_QUOTES, 'UTF-8'),
            'order_number' => htmlspecialchars($row['name_of_order'] ?? '', ENT_QUOTES, 'UTF-8'),
            'count' => $count,
            'build_complexity' => $build_complexity,
            'norms' => $norms,
            'item_percentage' => $item_percentage
        ];
    }
    
    $stmt->close();
    $mysqli->close();
    
    // Рассчитываем общий процент
    $percentage = $norms_sum > 0 ? round($norms_sum * 100, 0) : 0;
    
    // Если нет данных
    if (empty($items)) {
        echo json_encode([
            'date' => $date,
            'teams' => $teams,
            'total_count' => 0,
            'norms_sum' => 0,
            'percentage' => 0,
            'items' => []
        ]);
        exit;
    }
    
    echo json_encode([
        'date' => htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
        'teams' => $teams,
        'total_count' => $total_count,
        'norms_sum' => $norms_sum,
        'percentage' => $percentage,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}

