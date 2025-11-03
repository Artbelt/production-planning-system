<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_POST['order'] ?? '';
$data = json_decode($_POST['plan_data'] ?? '[]', true);

if (!$order || !is_array($data)) {
    exit('Ошибка данных');
}

// Удалим предыдущие записи
$stmt = $pdo->prepare("DELETE FROM build_plan WHERE order_number = ?");
$stmt->execute([$order]);

// Вставим новые
$insert = $pdo->prepare("
    INSERT INTO build_plan (order_number, assign_date, place, filter_label, count, corrugation_plan_id)
    VALUES (?, ?, ?, ?, ?, ?)
");

foreach ($data as $date => $places) {
    foreach ($places as $place => $items) {
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            $count = $item['count'] ?? 0;
            $corr_id = $item['corrugation_plan_id'] ?? null;

            if ($label && $count > 0) {
                $insert->execute([$order, $date, $place, $label, $count, $corr_id]);
            }
        }
    }
}

// Отметим как готовое
$pdo->prepare("UPDATE orders SET build_ready = 1 WHERE order_number = ?")->execute([$order]);

// Если параметр stay=1, просто возвращаем успех (для AJAX)
if (isset($_GET['stay']) && $_GET['stay'] == '1') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'План сохранён']);
    exit;
}

// Иначе вернёмся на главную
header("Location: ../NP_cut_index.php");
exit;
