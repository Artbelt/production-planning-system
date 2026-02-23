<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan_u5');

    $filter = $_GET['filter'] ?? '';
    
    if (empty($filter)) {
        echo json_encode(['success' => false, 'orders' => []]);
        exit;
    }

    // Получаем список заявок для данного фильтра из orders и corrugation_plan
    // Сначала проверяем таблицу orders (основной источник заявок)
    $stmt = $pdo->prepare("
        SELECT DISTINCT order_number 
        FROM orders 
        WHERE `filter` = ? 
          AND order_number IS NOT NULL 
          AND order_number != ''
          AND COALESCE(hide, 0) != 1
        ORDER BY order_number DESC
    ");
    $stmt->execute([$filter]);
    $ordersFromOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Также проверяем corrugation_plan за последние 30 дней (для активных планов)
    $stmt = $pdo->prepare("
        SELECT DISTINCT order_number 
        FROM corrugation_plan 
        WHERE filter_label = ? 
          AND order_number IS NOT NULL 
          AND order_number != ''
          AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY order_number DESC
    ");
    $stmt->execute([$filter]);
    $ordersFromPlan = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Объединяем результаты и убираем дубликаты
    $orders = array_unique(array_merge($ordersFromOrders, $ordersFromPlan));
    // Сортируем по убыванию
    rsort($orders);

    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'orders' => [],
        'error' => $e->getMessage()
    ]);
}






