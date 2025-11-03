<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
require_once 'config.php';

header('Content-Type: application/json');

try {
    $employee_id = $_POST['employee_id'] ?? null;
    $date = $_POST['date'] ?? null;
    $hours_worked = $_POST['hours_worked'] ?? null;
    $comments = $_POST['comments'] ?? '';

    if (!$employee_id || !$date || $hours_worked === null) {
        echo json_encode(['success' => false, 'error' => 'Недостаточно данных']);
        exit;
    }

    // Проверяем, есть ли запись
    $stmt = $db->prepare('SELECT id FROM timesheets WHERE employee_id = ? AND date = ?');
    $stmt->execute([$employee_id, $date]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Обновляем запись
        $stmt = $db->prepare('UPDATE timesheets SET hours_worked = ?, comments = ? WHERE id = ?');
        $stmt->execute([$hours_worked, $comments, $existing['id']]);
    } else {
        // Создаем новую запись
        $stmt = $db->prepare('INSERT INTO timesheets (employee_id, date, hours_worked, comments) VALUES (?, ?, ?, ?)');
        $stmt->execute([$employee_id, $date, $hours_worked, $comments]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>