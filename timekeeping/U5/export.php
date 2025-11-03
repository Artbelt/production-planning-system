<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="timesheets.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM для кириллицы
fputcsv($output, ['Сотрудник', 'Дата', 'Часы', 'Комментарии']);

$stmt = $db->query('SELECT e.full_name, t.date, t.hours_worked, t.comments FROM timesheets t JOIN employees e ON t.employee_id = e.id ORDER BY e.full_name, t.date');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['full_name'],
        $row['date'],
        $row['hours_worked'],
        $row['comments'] ?? ''
    ]);
}
fclose($output);
exit;
?>