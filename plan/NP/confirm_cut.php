<?php
// confirm_cut.php — обработка утверждения раскроя

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
