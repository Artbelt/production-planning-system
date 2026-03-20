<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan');

    $filter = $_GET['filter'] ?? '';
    $filter = is_string($filter) ? trim($filter) : '';

    if (empty($filter)) {
        echo json_encode(['success' => false, 'orders' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hasBracket = strpos($filter, '[') !== false;
    // Базовое имя (до пробела/скобки), чтобы находить заявки даже если размеры/пометки отличаются.
    $base = preg_split('/\s+/', $filter)[0] ?? $filter;
    $base = is_string($base) ? trim($base) : $filter;

    // В orders иногда хранится "короткое" имя (AF1973s), а в плане — полная метка (AF1973s [48] ...).
    // Поэтому поддерживаем как точное совпадение, так и "начинается с".
    $stmt = $pdo->prepare("
        SELECT DISTINCT order_number
        FROM orders
        WHERE (
            TRIM(`filter`) = ?
            OR TRIM(`filter`) LIKE CONCAT(?, '%')
            OR TRIM(`filter`) = ?
            OR TRIM(`filter`) LIKE CONCAT(?, '%')
        )
          AND order_number IS NOT NULL
          AND order_number != ''
          AND COALESCE(hide, 0) != 1
        ORDER BY order_number DESC
    ");
    $stmt->execute([$filter, $filter, $base, $base]);
    $ordersFromOrders = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($hasBracket) {
        // В плане может быть [48] или [h48], а также могут быть хвостовые значки/пометки после размеров.
        // Поэтому ищем и точное совпадение, и "начинается с" (prefix).
        $filter_alt = preg_replace('/\[(\d+)\]/', '[h$1]', $filter);
        $stmt = $pdo->prepare("
            SELECT DISTINCT order_number
            FROM corrugation_plan
            WHERE (
                filter_label = ? OR filter_label = ?
                OR filter_label LIKE CONCAT(?, '%')
                OR filter_label LIKE CONCAT(?, '%')
                OR filter_label LIKE CONCAT(?, '%')
            )
              AND order_number IS NOT NULL
              AND order_number != ''
              AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY order_number DESC
        ");
        $stmt->execute([$filter, $filter_alt, $filter, $filter_alt, $base]);
    } else {
        // Если ввели короткое имя (без [..]), ищем все позиции, которые начинаются с него:
        // AF1973s -> 'AF1973s%', чтобы находились 'AF1973s [48] 183 ...'
        $stmt = $pdo->prepare("
            SELECT DISTINCT order_number
            FROM corrugation_plan
            WHERE filter_label LIKE CONCAT(?, '%')
              AND order_number IS NOT NULL
              AND order_number != ''
              AND plan_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY order_number DESC
        ");
        $stmt->execute([$base]);
    }
    $ordersFromPlan = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $orders = array_unique(array_merge($ordersFromOrders, $ordersFromPlan));
    rsort($orders);

    echo json_encode([
        'success' => true,
        'orders' => $orders
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'orders' => [],
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
