<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/includes/db.php';
require_once __DIR__ . '/../../auth/includes/roll_plan_mark_cut.php';
$pdo = getPdo('plan_u3');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет ID']);
    exit;
}

$id = (int) $_POST['id'];
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

$result = rollPlanMarkCutDoneById($pdo, $table, $id);
echo json_encode($result);
