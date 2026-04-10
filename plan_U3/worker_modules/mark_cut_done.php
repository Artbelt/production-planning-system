<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/includes/db.php';
$pdo = getPdo('plan_u3');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет ID']);
    exit;
}

$id = (int)$_POST['id'];
// Поддержка обеих схем: roll_plan (нормализованная) и roll_plans (наследие)
$table = 'roll_plan';
$chk = $pdo->query("SHOW TABLES LIKE 'roll_plan'");
if (!$chk || $chk->rowCount() === 0) {
    $chkLegacy = $pdo->query("SHOW TABLES LIKE 'roll_plans'");
    if ($chkLegacy && $chkLegacy->rowCount() > 0) {
        $table = 'roll_plans';
    }
}

$stmt = $pdo->prepare("UPDATE {$table} SET done = 1, fact_cut_date = CURDATE() WHERE id = ?");
$success = $stmt->execute([$id]);

echo json_encode(['success' => $success]);
