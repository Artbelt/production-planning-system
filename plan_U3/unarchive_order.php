<?php

require_once('tools/tools.php');
require_once ('style/table.txt');

if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$order = isset($_POST['order_number']) ? trim((string)$_POST['order_number']) : '';

if ($order === '') {
    echo '<p>Номер заявки не указан.</p>';
    echo "<button class='a' onclick='window.close();' style='cursor: pointer;'>Закрыть страницу</button>";
    return;
}

echo "<p>Заявка «".htmlspecialchars($order, ENT_QUOTES, 'UTF-8')."» возвращена в активные.</p>";

$stmt = $pdo->prepare("UPDATE orders SET hide = 0 WHERE order_number = ?");
$stmt->execute([$order]);

echo "<button class='a' onclick='window.close();' style='cursor: pointer;'>Закрыть страницу</button>";
