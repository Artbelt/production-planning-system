<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$order = $_GET['order'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM cut_plans WHERE order_number = ?");
$stmt->execute([$order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bales = [];
foreach ($rows as $r) {
    $bales[$r['bale_id']][] = $r;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Планирование гофрирования: <?= htmlspecialchars($order) ?></title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f7f9fc; color: #333; }
        h2 { color: #2c3e50; font-size: 24px; margin-bottom: 5px; }
        .btn { background-color: #1a73e8; color: white; border: none; border-radius: 5px; padding: 8px 16px; font-size: 14px; cursor: pointer; margin-top: 20px; }
        .btn:hover { background-color: #1557b0; }
        ul { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<h2>Планирование гофрирования по заявке <?= htmlspecialchars($order) ?></h2>
<p>Ниже приведены бухты, входящие в заявку. Реализация интерфейса планирования аналогична раскрою. Ниже просто список как заглушка:</p>
<ul>
    <?php foreach ($bales as $bale_id => $items): ?>
        <li><b>Бухта <?= $bale_id ?>:</b>
            <?php foreach ($items as $i): ?>
                <?= htmlspecialchars($i['filter']) ?> [<?= $i['height'] ?>×<?= $i['width'] ?>];
            <?php endforeach; ?>
        </li>
    <?php endforeach; ?>
</ul>

<form method="post" action="save_corr_plan.php">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <button type="submit" class="btn">Сохранить и завершить этап</button>
</form>
</body>
</html>
