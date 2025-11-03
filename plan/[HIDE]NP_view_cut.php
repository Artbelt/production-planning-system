<?php
// [HIDE]NP_view_cut.php — просмотр раскроя (подготовка) по заявке (read-only + печать)
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

$order = $_GET['order'] ?? '';
$autoPrint = isset($_GET['print']) ? true : false;
if ($order==='') die('Не указан параметр order.');

$sql = "
SELECT
  c.id,
  c.order_number,
  c.manual,
  c.filter,
  TRIM(SUBSTRING_INDEX(c.filter,' [',1)) AS base_filter,
  c.paper,
  c.width, c.height, c.length, c.waste,
  c.bale_id,
  c.plan_date
FROM cut_plans c
WHERE c.order_number = :ord
ORDER BY c.plan_date, c.bale_id, base_filter
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ord'=>$order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// totals
$tot_len = 0; $tot_rows = count($rows);
foreach ($rows as $r) $tot_len += (int)$r['length'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Раскрой — заявка <?= htmlspecialchars($order) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{ --bg:#f6f7fb; --card:#fff; --line:#e5e7eb; --muted:#6b7280; }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:0;padding:12px;color:#111827;font-size:14px}
        h1{margin:6px 0 12px;text-align:center}
        .panel{max-width:1100px;margin:0 auto 12px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;display:flex;gap:8px;justify-content:center;align-items:center}
        .btn{padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
        .muted{color:var(--muted)}
        .wrap{max-width:1100px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th,td{border:1px solid var(--line);padding:6px 8px;text-align:center;vertical-align:middle;white-space:nowrap}
        th{background:#f3f4f6}
        td.left{text-align:left;white-space:normal}
        tbody tr:nth-child(even){background:#fafafa}
        .totals{margin-top:8px;font-size:13px;color:#374151;display:flex;justify-content:space-between}
        @media print{
            @page { size: landscape; margin: 10mm; }
            body{background:#fff}
            .panel{display:none}
            .wrap{border:none;padding:0}
            th,td{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load',()=>window.print());</script>
    <?php endif; ?>
</head>
<body>

<h1>Раскрой — заявка № <?= htmlspecialchars($order) ?></h1>

<div class="panel">
    <button class="btn" onclick="window.print()">Печать</button>
    <span class="muted">Позиции: <?= (int)$tot_rows ?> • Сумма длины: <?= (int)$tot_len ?> м</span>
</div>

<div class="wrap">
    <?php if (!$rows): ?>
        <p class="muted">Нет данных по раскрою для этой заявки.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Бухта</th>
                <th class="left">Фильтр (база)</th>
                <th>Бумага</th>
                <th>Ширина</th>
                <th>Высота</th>
                <th>Длина, м</th>
                <th>Отход</th>
                <th>Режим</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['plan_date'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['bale_id'] ?? '') ?></td>
                    <td class="left"><?= htmlspecialchars($r['base_filter']) ?></td>
                    <td><?= htmlspecialchars($r['paper'] ?? '') ?></td>
                    <td><?= (float)$r['width'] ?></td>
                    <td><?= (float)$r['height'] ?></td>
                    <td><?= (int)$r['length'] ?></td>
                    <td><?= is_null($r['waste']) ? '' : (float)$r['waste'] ?></td>
                    <td><?= $r['manual'] ? 'ручной' : 'авто' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="6" style="text-align:right;font-weight:600">Итого длина:</td>
                <td colspan="3" style="text-align:left;font-weight:600"><?= (int)$tot_len ?> м</td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
