<?php
header('Content-Type: application/json; charset=utf-8');

$order = $_GET['order'] ?? '';

if (empty($order)) {
    echo json_encode(['ok' => false, 'error' => 'Не указана заявка']);
    exit;
}

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    
    // Общая информация о заявке
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(count) as total_count FROM orders WHERE order_number = ? AND (hide IS NULL OR hide != 1)");
    $stmt->execute([$order]);
    $order_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Количество бухт в раскрое
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT bale_id) as bales_count FROM cut_plans WHERE order_number = ?");
    $stmt->execute([$order]);
    $bales_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Анализ позиций с большим количеством заказа и расчет смен
    // Получаем позиции с количеством и продуктивностью
    $stmt = $pdo->prepare("
        SELECT 
            o.filter,
            SUM(o.count) as total_count,
            rfs.productivity
        FROM orders o
        LEFT JOIN round_filter_structure rfs ON TRIM(o.filter) = TRIM(rfs.filter)
        WHERE o.order_number = ? AND (o.hide IS NULL OR o.hide != 1)
        GROUP BY o.filter, rfs.productivity
        ORDER BY SUM(o.count) DESC
    ");
    $stmt->execute([$order]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Рассчитываем количество смен для каждой позиции
    $positions_with_shifts = [];
    foreach ($positions as $pos) {
        $count = (int)$pos['total_count'];
        $productivity = $pos['productivity'] !== null ? (int)$pos['productivity'] : null;
        
        $shifts = null;
        if ($productivity !== null && $productivity > 0) {
            $shifts = ceil($count / $productivity); // Округляем вверх
        }
        
        $positions_with_shifts[] = [
            'filter' => $pos['filter'],
            'count' => $count,
            'productivity' => $productivity,
            'shifts' => $shifts
        ];
    }
    
    echo json_encode([
        'ok' => true,
        'total_filters' => (int)$order_info['total_count'],
        'unique_filters' => (int)$order_info['total'],
        'bales_count' => (int)$bales_info['bales_count'],
        'positions' => $positions_with_shifts
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>

