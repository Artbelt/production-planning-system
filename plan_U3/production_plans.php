<?php
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

// Получаем список активных планов (только те, которые связаны с активными заявками)
$stmt = $pdo->query("
    SELECT DISTINCT bp.order_number
    FROM build_plans bp
    INNER JOIN orders o ON bp.order_number = o.order_number
    WHERE (o.hide IS NULL OR o.hide != 1)
    ORDER BY bp.day_date DESC
");
$plans = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Планы производства</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f4f4;
        }

        .button-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .btn {
            padding: 12px 25px;
            font-size: 16px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .btn:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
<div class="button-container">
    <?php if ($plans): ?>
        <?php foreach ($plans as $plan): ?>
            <button class="btn" onclick="window.open('view_production_plan.php?order=<?= urlencode($plan) ?>', '_blank')">
                <?= htmlspecialchars($plan) ?>
            </button>
        <?php endforeach; ?>
    <?php else: ?>
        <p>Нет доступных планов.</p>
    <?php endif; ?>
</div>
</body>
</html>
