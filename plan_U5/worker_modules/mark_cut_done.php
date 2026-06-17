<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/includes/db.php';
require_once __DIR__ . '/../../auth/includes/roll_plan_mark_cut.php';
$pdo = getPdo('plan_u5');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет ID']);
    exit;
}

$id = (int) $_POST['id'];
$result = rollPlanMarkCutDoneById($pdo, 'roll_plans', $id);
echo json_encode($result);
