<?php
// NP_view_corrugation_plan.php — очень компактный просмотр плана гофрирования (read-only + печать)
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

$order = $_GET['order'] ?? '';
$autoPrint = isset($_GET['print']);

if ($order==='') die('Не указан параметр order.');

$sql = "
SELECT
  id,
  order_number,
  plan_date,
  filter_label,
  TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
  `count`
FROM corrugation_plan
WHERE order_number = :ord
ORDER BY plan_date, base_filter
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ord'=>$order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// группировка по дате
$byDate = [];
foreach ($rows as $r) {
    $d = $r['plan_date'];
    if (!isset($byDate[$d])) $byDate[$d] = [];
    $byDate[$d][] = $r;
}
ksort($byDate);

// итоги
function sumDay($arr){ $s=0; foreach($arr as $x) $s+=(int)$x['count']; return $s; }
$total = 0; foreach ($rows as $r) $total += (int)$r['count'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>План гофрирования — заявка <?= htmlspecialchars($order) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{ --bg:#f6f7fb; --card:#fff; --line:#e5e7eb; --muted:#6b7280; --ink:#111827; }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:0;padding:10px;color:var(--ink);font-size:12px}
        h1{margin:4px 0 10px;text-align:center;font-size:16px}
        .panel{max-width:1400px;margin:0 auto 8px;display:flex;gap:8px;justify-content:center;align-items:center}
        .btn{padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;font-size:12px}
        .muted{color:var(--muted)}

        /* Плитка карточек: максимально узко, без пустоты */
        .wrap{max-width:1400px;margin:0 auto}
        .grid{
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap:8px;
            align-items:start;
        }
        .card{
            background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px;
        }
        .header{
            display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;
            font-variant-numeric:tabular-nums;
        }

        /* Суперплотная таблица */
        table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}
        colgroup col:nth-child(1){width:70%}
        colgroup col:nth-child(2){width:30%}
        th,td{border:1px solid var(--line);padding:3px 5px;text-align:center;vertical-align:middle;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        th{background:#f3f4f6;font-weight:600}
        td.left{text-align:left}
        tbody tr:nth-child(even){background:#fbfbfb}

        @media print{
            @page { size: landscape; margin: 8mm; }
            body{background:#fff;padding:0}
            .panel{display:none}
            .grid{gap:6px;grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));}
            .card{break-inside:avoid}
            th,td{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load',()=>window.print());</script>
    <?php endif; ?>
</head>
<body>

<h1>План гофрирования — заявка № <?= htmlspecialchars($order) ?></h1>

<div class="panel">
    <button class="btn" onclick="window.print()">Печать</button>
    <span class="muted">Всего по заявке: <?= (int)$total ?> шт.</span>
</div>

<div class="wrap">
    <?php if (!$byDate): ?>
        <div class="card"><em class="muted">Нет данных по гофрированию для этой заявки.</em></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($byDate as $d => $items): ?>
                <div class="card">
                    <div class="header">
                        <strong><?= htmlspecialchars($d) ?></strong>
                        <span class="muted">Итого: <b><?= (int)sumDay($items) ?></b> шт.</span>
                    </div>
                    <table>
                        <colgroup><col><col></colgroup>
                        <thead>
                        <tr>
                            <th class="left">Фильтр</th>
                            <th>Кол-во</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $r): ?>
                            <tr>
                                <td class="left" title="<?= htmlspecialchars($r['filter_label']) ?>"><?= htmlspecialchars($r['base_filter']) ?></td>
                                <td><?= (int)$r['count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
