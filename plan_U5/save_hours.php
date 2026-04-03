<?php
// save_hours.php

require_once __DIR__ . '/settings.php';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo "Ошибка подключения к БД: " . $e->getMessage();
    exit;
}

// Обработка формы
$inserted = 0;

if (isset($_POST['hours']) && is_array($_POST['hours'])) {
    // Та же нормализация даты, что и в show_manufactured_filters (reverse_date)
    if (isset($_POST['selected_date']) && trim((string)$_POST['selected_date']) !== '') {
        $production_date = date('Y-m-d', strtotime($_POST['selected_date']));
    } else {
        $production_date = date('Y-m-d');
    }
    foreach ($_POST['hours'] as $key => $value) {
        if (trim($value) === '') continue;

        $parts = explode('_', $key, 2);
        if (count($parts) !== 2) continue;

        $filter = $parts[0];
        $order_number = $parts[1];
        $hours = (float)$value;
        if ($hours <= 0) continue;

        // 💾 Обновляем запрос, чтобы сохранять дату
        $stmt = $pdo->prepare("INSERT INTO hourly_work_log (filter, order_number, date_of_work, hours) VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE  hours = VALUES(hours)");
        if ($stmt->execute([$filter, $order_number, $production_date, $hours])) {
            $inserted++;
        } else {
            $error = $stmt->errorInfo();
            echo "Ошибка вставки: " . $error[2] . "<br>";
        }
    }
}

echo "Сохранено строк: $inserted";
