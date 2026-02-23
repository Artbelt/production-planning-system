<?php

require_once('tools/tools.php');
require_once ('style/table.txt');

if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$order = $_POST['order_number'];

echo "Заявка отправлена в архив <p>";

$stmt = $pdo->prepare("UPDATE orders SET hide = 1 WHERE order_number = ?");
$stmt->execute([$order]);

echo "<button class='a' onclick='window.close();' style='cursor: pointer;'>Закрыть страницу</button>";