<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");

// Получаем список заявок
$orders = $pdo->query("SELECT DISTINCT order_number FROM orders WHERE hide IS NULL OR hide=0 ORDER BY order_number ASC")
    ->fetchAll(PDO::FETCH_COLUMN);

$selected_order = $_GET['order'] ?? ($orders[0] ?? null);

// Если заявка выбрана, подгружаем данные для каждого блока
$cut_plan = $cut_fact = $corr_plan = $corr_fact = $build_plan = $build_fact = $pack_plan = $pack_fact = [];

if ($selected_order) {
    // --- Порезка ---
    $stmt = $pdo->prepare("SELECT bale_id, plan_date FROM roll_plan WHERE order_number = ?");
    $stmt->execute([$selected_order]);
    $cut_plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT bale_id, plan_date FROM roll_plan WHERE order_number = ? AND done = 1");
    $stmt->execute([$selected_order]);
    $cut_fact = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Гофрирование ---
    $stmt = $pdo->prepare("SELECT filter_label, plan_date, count FROM corrugation_plan WHERE order_number = ?");
    $stmt->execute([$selected_order]);
    $corr_plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Сборка ---
    $stmt = $pdo->prepare("SELECT filter_label, assign_date, count FROM build_plan WHERE order_number = ?");
    $stmt->execute([$selected_order]);
    $build_plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Упаковка (пока заглушка, зависит от структуры упаковки) ---
    $stmt = $pdo->prepare("SELECT name_of_parts, count_of_parts, date_of_production FROM manufactured_parts WHERE name_of_order = ?");
    $stmt->execute([$selected_order]);
    $pack_fact = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Диспетчерский пункт</title>
    <style>
        body {
            font-family: sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 10px;
        }
        h2 {
            text-align: center;
            margin-bottom: 20px;
        }
        .block {
            background: #fff;
            margin-bottom: 20px;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 0 4px rgba(0,0,0,0.1);
        }
        .block h3 {
            margin-top: 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #ccc;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
            font-size: 14px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: center;
        }
        th {
            background: #f0f0f0;
        }
        .plan-title {
            color: #333;
            font-weight: bold;
            margin-top: 10px;
        }
        .fact-title {
            color: #007700;
            font-weight: bold;
            margin-top: 10px;
        }
        select {
            padding: 5px;
            font-size: 14px;
        }
        .order-select {
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
<h2>Диспетчерский пункт</h2>

<div class="order-select">
    <form method="get">
        Заявка:
        <select name="order" onchange="this.form.submit()">
            <?php foreach ($orders as $order): ?>
                <option value="<?= htmlspecialchars($order) ?>" <?= ($selected_order == $order ? 'selected' : '') ?>>
                    <?= htmlspecialchars($order) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($selected_order): ?>

    <!-- Порезка -->
    <div class="block">
        <h3>Порезка</h3>
        <div class="plan-title">План</div>
        <?php if ($cut_plan): ?>
            <table>
                <tr><th>Бухта</th><th>Дата</th></tr>
                <?php foreach ($cut_plan as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['bale_id']) ?></td>
                        <td><?= htmlspecialchars($row['plan_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>Нет данных по плану</p>
        <?php endif; ?>

        <div class="fact-title">Факт</div>
        <?php if ($cut_fact): ?>
            <table>
                <tr><th>Бухта</th><th>Дата</th></tr>
                <?php foreach ($cut_fact as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['bale_id']) ?></td>
                        <td><?= htmlspecialchars($row['plan_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>Нет данных по факту</p>
        <?php endif; ?>
    </div>

    <!-- Гофрирование -->
    <div class="block">
        <h3>Гофрирование</h3>
        <div class="plan-title">План</div>
        <?php if ($corr_plan): ?>
            <table>
                <tr><th>Фильтр</th><th>Дата</th><th>Кол-во</th></tr>
                <?php foreach ($corr_plan as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['filter_label']) ?></td>
                        <td><?= htmlspecialchars($row['plan_date']) ?></td>
                        <td><?= htmlspecialchars($row['count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>Нет данных по плану</p>
        <?php endif; ?>
    </div>

    <!-- Сборка -->
    <div class="block">
        <h3>Сборка</h3>
        <div class="plan-title">План</div>
        <?php if ($build_plan): ?>
            <table>
                <tr><th>Фильтр</th><th>Дата</th><th>Кол-во</th></tr>
                <?php foreach ($build_plan as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['filter_label']) ?></td>
                        <td><?= htmlspecialchars($row['assign_date']) ?></td>
                        <td><?= htmlspecialchars($row['count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>Нет данных по плану</p>
        <?php endif; ?>
    </div>

    <!-- Упаковка -->
    <div class="block">
        <h3>Изготовление упаковки</h3>
        <div class="fact-title">Факт</div>
        <?php if ($pack_fact): ?>
            <table>
                <tr><th>Деталь</th><th>Кол-во</th><th>Дата</th></tr>
            </table>
        <?php else: ?>
            <p>Нет данных</p>
        <?php endif; ?>
    </div>

<?php else: ?>
    <p style="text-align:center;">Выберите заявку</p>
<?php endif; ?>

</body>
</html>
