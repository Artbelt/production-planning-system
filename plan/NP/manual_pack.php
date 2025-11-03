<?php
// manual_pack.php

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");

// Сохраняем одну вручную собранную бухту
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order = $_POST['order_number'] ?? '';
    $bale_data = json_decode($_POST['bale_data'] ?? '[]', true);

    $bale_id = 1000; // чтобы не пересекалось с автоматическими, можно начинать с 1000

    foreach ($bale_data as $roll) {
        $stmt = $pdo->prepare("INSERT INTO cut_plans (order_number, manual, filter, paper, width, height, length, waste, bale_id)
        VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $order,
            $roll['filter'],
            $roll['paper'],
            $roll['width'],
            $roll['height'],
            $roll['length'],
            $roll['waste'] ?? null,
            $bale_id
        ]);
        $bale_id++;
    }


    // ✅ Помечаем заявку как готовую к утверждению
    $stmt = $pdo->prepare("UPDATE orders SET cut_ready = 1 WHERE order_number = ?");
    $stmt->execute([$order]);

    header("Location: ../cut.php?order=" . urlencode($order));
    exit;
}
?>
