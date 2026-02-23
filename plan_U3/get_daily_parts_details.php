<?php
/**
 * AJAX endpoint для получения детальной информации по изготовленным гофропакетам за день
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
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    
    // Получаем детальную информацию по каждой позиции
    // Группируем по гофропакету и заявке, суммируя количество
    $sql = "SELECT 
                mp.name_of_parts,
                mp.name_of_order,
                SUM(mp.count_of_parts) AS count_of_parts
            FROM manufactured_parts mp
            WHERE mp.date_of_production = ?
            GROUP BY mp.name_of_parts, mp.name_of_order
            ORDER BY mp.name_of_parts, mp.name_of_order";
    
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute([$date])) {
        echo json_encode(['error' => 'Ошибка запроса']);
        exit;
    }
    
    $items = [];
    $total_count = 0;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $count = (int)$row['count_of_parts'];
        $total_count += $count;
        
        $items[] = [
            'part_name' => htmlspecialchars($row['name_of_parts'] ?? '', ENT_QUOTES, 'UTF-8'),
            'order_number' => htmlspecialchars($row['name_of_order'] ?? '', ENT_QUOTES, 'UTF-8'),
            'count' => $count
        ];
    }
    
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




