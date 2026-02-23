<?php
require_once __DIR__ . '/auth/includes/db.php';
$pdo = getPdo('plan');

echo "Добавление поля corrugation_plan_id в build_plan...\n";

try {
    $pdo->exec("ALTER TABLE build_plan ADD COLUMN corrugation_plan_id INT NULL AFTER filter_label");
    echo "✓ Поле corrugation_plan_id успешно добавлено!\n\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "⚠ Поле corrugation_plan_id уже существует\n\n";
    } else {
        echo "✗ Ошибка: " . $e->getMessage() . "\n\n";
        exit(1);
    }
}

echo "Структура таблицы build_plan:\n";
$stmt = $pdo->query("DESCRIBE build_plan");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$row['Field']}: {$row['Type']}\n";
}

echo "\n✓ Готово!\n";



