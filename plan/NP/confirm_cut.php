<?php
// confirm_cut.php — обработка утверждения раскроя

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan');

    $order = $_POST['order'] ?? $_GET['order'] ?? '';

    if (!$order) {
        http_response_code(400);
        echo "NO ORDER";
        exit;
    }

    // Обновляем статус утверждения
    $stmt = $pdo->prepare("UPDATE orders SET cut_confirmed = 1 WHERE order_number = ?");
    $stmt->execute([$order]);

    // Редирект на страницу планирования
    echo "<script>window.location.href = '../NP_cut_index.php';</script>";
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
