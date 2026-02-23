<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/includes/db.php';
$pdo = getPdo('plan');

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Нет ID']);
    exit;
}

$id = (int)$_POST['id'];
$stmt = $pdo->prepare("UPDATE roll_plan SET done = 1 WHERE id = ?");
$success = $stmt->execute([$id]);

echo json_encode(['success' => $success]);
