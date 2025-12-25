<?php
/**
 * AJAX endpoint для получения детальной информации по выпущенной продукции за день
 */

require_once('tools/tools.php');
require_once('settings.php');

header('Content-Type: application/json; charset=utf-8');

$date = $_GET['date'] ?? '';

if (empty($date)) {
    echo json_encode(['error' => 'Не указана дата']);
    exit;
}

try {
    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
    
    $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    
    if ($mysqli->connect_errno) {
        echo json_encode(['error' => 'Ошибка подключения к БД']);
        exit;
    }
    
    // Получаем детальную информацию по каждой позиции
    // Группируем по фильтру и заявке, суммируя количество
    $sql = "SELECT 
                mp.name_of_filter,
                mp.name_of_order,
                SUM(mp.count_of_filters) AS count_of_filters
            FROM manufactured_production mp
            WHERE mp.date_of_production = ?
            GROUP BY mp.name_of_filter, mp.name_of_order
            ORDER BY mp.name_of_filter, mp.name_of_order";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'Ошибка подготовки запроса: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    $total_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $count = (int)$row['count_of_filters'];
        $total_count += $count;
        
        $items[] = [
            'filter_name' => htmlspecialchars($row['name_of_filter'] ?? '', ENT_QUOTES, 'UTF-8'),
            'order_number' => htmlspecialchars($row['name_of_order'] ?? '', ENT_QUOTES, 'UTF-8'),
            'count' => $count
        ];
    }
    
    $stmt->close();
    $mysqli->close();
    
    // Если нет данных
    if (empty($items)) {
        echo json_encode([
            'date' => $date,
            'total_count' => 0,
            'items' => []
        ]);
        exit;
    }
    
    echo json_encode([
        'date' => htmlspecialchars($date, ENT_QUOTES, 'UTF-8'),
        'total_count' => $total_count,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Ошибка: ' . $e->getMessage()]);
}




