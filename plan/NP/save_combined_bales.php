<?php
$data = json_decode(file_get_contents("php://input"), true);
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");

$order = $data['order'];
$auto_bales = $data['auto_bales'];
$manual_bales = $data['manual_bales'];

if (!isset($order) || !is_array($auto_bales) || !is_array($manual_bales)) {
    http_response_code(400);
    exit("Некорректные данные");
}

// Удалим старые раскрои по заявке
$pdo->prepare("DELETE FROM cut_plans WHERE order_number = ?")->execute([$order]);

// Получим текущий максимум bale_id
$max_id = intval($pdo->query("SELECT MAX(bale_id) FROM cut_plans")->fetchColumn());

// Сохраняем авто-бухты
foreach ($auto_bales as $bale) {
    $max_id++;
    
    // Определяем формат для всей бухты - проверяем ВСЕ рулоны
    $bale_format = '199'; // Предполагаем что это формат 199
    foreach ($bale as $roll) {
        if (isset($roll['width'])) {
            $width = (float)$roll['width'];
            // Если хотя бы один рулон НЕ формата 199, то вся бухта формата 1000
            if (!($width == 199 || ($width >= 175 && $width <= 190))) {
                $bale_format = '1000';
                break;
            }
        }
    }
    
    // Сохраняем все рулоны бухты с одним форматом
    foreach ($bale as $roll) {
        $stmt = $pdo->prepare("INSERT INTO cut_plans (order_number, manual, filter, paper, width, height, length, format, waste, bale_id)
            VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $order,
            $roll['filter'],
            $roll['paper'],
            $roll['width'],
            $roll['height'],
            $roll['length'],
            $bale_format,
            $roll['waste'] ?? null,
            $max_id
        ]);
    }
}

// Сохраняем ручные бухты
foreach ($manual_bales as $bale) {
    $max_id++;
    
    // Определяем формат для всей бухты - проверяем ВСЕ рулоны
    $bale_format = '199'; // Предполагаем что это формат 199
    foreach ($bale as $roll) {
        if (isset($roll['width'])) {
            $width = (float)$roll['width'];
            // Если хотя бы один рулон НЕ формата 199, то вся бухта формата 1000
            if (!($width == 199 || ($width >= 175 && $width <= 190))) {
                $bale_format = '1000';
                break;
            }
        }
    }
    
    // Сохраняем все рулоны бухты с одним форматом
    foreach ($bale as $roll) {
        $stmt = $pdo->prepare("INSERT INTO cut_plans (order_number, manual, filter, paper, width, height, length, format, waste, bale_id)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $order,
            $roll['filter'],
            $roll['paper'],
            $roll['width'],
            $roll['height'],
            $roll['length'],
            $bale_format,
            $roll['waste'] ?? null,
            $max_id
        ]);
    }
}

echo "OK";
