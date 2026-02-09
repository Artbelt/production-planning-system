<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $filter = $_GET['filter'] ?? '';

    if (empty($filter)) {
        echo json_encode(['success' => false, 'orders' => []]);
        exit;
    }

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

    // В плане может быть [48] или [h48] — ищем оба варианта
    $filter_alt = preg_replace('/\[(\d+)\]/', '[h$1]', $filter);
    $stmt = $pdo->prepare("
        SELECT DISTINCT order_number 
        FROM corrugation_plan 
        WHERE (filter_label = ? OR filter_label = ?)
          AND order_number IS NOT NULL 
          AND order_number != ''
          AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY order_number DESC
    ");
    $stmt->execute([$filter, $filter_alt]);
    $ordersFromPlan = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $orders = array_unique(array_merge($ordersFromOrders, $ordersFromPlan));
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
