<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/includes/db.php';
$pdo = getPdo('plan_u3');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет ID']);
    exit;
}

$id = (int)$_POST['id'];
// Как cut_operator: для U3 основная таблица — roll_plans
$table = 'roll_plans';
$chk = $pdo->query("SHOW TABLES LIKE 'roll_plans'");
if (!$chk || $chk->rowCount() === 0) {
    $chkLegacy = $pdo->query("SHOW TABLES LIKE 'roll_plan'");
    if ($chkLegacy && $chkLegacy->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Таблица плана порезки не найдена']);
        exit;
    }
    $table = 'roll_plan';
}

$stmt = $pdo->prepare("UPDATE {$table} SET done = 1, fact_cut_date = CURDATE() WHERE id = ?");
$success = $stmt->execute([$id]);

echo json_encode(['success' => $success]);
