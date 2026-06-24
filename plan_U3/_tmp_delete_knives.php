<?php
require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan_u3');
$names = ['Верхний №5', 'Верхний №7', 'Нижний №6', 'Нижний №8'];

$placeholders = implode(',', array_fill(0, count($names), '?'));
$stmt = $pdo->prepare("SELECT id, knife_name, knife_type FROM knives WHERE knife_name IN ($placeholders)");
$stmt->execute($names);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found:\n";
foreach ($rows as $row) {
    echo "  id={$row['id']} name={$row['knife_name']} type={$row['knife_type']}\n";
}

if (empty($rows)) {
    echo "Nothing to delete.\n";
    exit(0);
}

$del = $pdo->prepare("DELETE FROM knives WHERE id = ?");
foreach ($rows as $row) {
    $del->execute([$row['id']]);
    echo "Deleted id={$row['id']} ({$row['knife_name']})\n";
}

echo "Done.\n";
