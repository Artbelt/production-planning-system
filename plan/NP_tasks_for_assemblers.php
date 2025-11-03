<?php
// tasks_for_assemblers.php — задания для сборщиков (что и когда делать)

// --- DB ---
$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// --- входные параметры ---
$today = date('Y-m-d');
$start = $_GET['start'] ?? $today;
$end   = $_GET['end']   ?? $today;
$order = $_GET['order'] ?? '';

// список заявок для селекта
$orders = $pdo->query("SELECT DISTINCT order_number FROM build_plan ORDER BY order_number")->fetchAll(PDO::FETCH_COLUMN);

// --- выборка заданий ---
// Базовое имя фильтра = всё до " ["
$sql = "
SELECT
  assign_date,
  TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base_filter,
  SUM(`count`) AS qty
FROM build_plan
WHERE assign_date BETWEEN :s AND :e
".($order ? " AND order_number = :ord " : "")."
GROUP BY assign_date, base_filter
ORDER BY assign_date, base_filter
";
$stmt = $pdo->prepare($sql);
$bind = [':s'=>$start, ':e'=>$end];
if ($order) $bind[':ord'] = $order;
$stmt->execute($bind);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// группировка по дате
$byDate = [];
foreach ($rows as $r) {
    $d = $r['assign_date'];
    if (!isset($byDate[$d])) $byDate[$d] = [];
    $byDate[$d][] = $r;
}

// суммарно по дню
function dayTotal(array $arr){ $s=0; foreach($arr as $x){ $s += (float)$x['qty']; } return $s; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Задания для сборщиков<?= $order ? ' — заявка '.$order : '' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{
            --bg:#f6f7fb; --card:#ffffff; --line:#e5e7eb; --muted:#6b7280; --accent:#2563eb;
        }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:0;padding:12px;color:#111827;font-size:14px}
        h1{margin:6px 0 10px;text-align:center}
        .panel{max-width:1000px;margin:0 auto 12px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;display:flex;gap:10px;flex-wrap:wrap;justify-content:center;align-items:center}
        label{white-space:nowrap}
        input[type="date"],select,button{padding:7px 10px;font-size:13px;border:1px solid var(--line);border-radius:8px;background:#fff}
        button{cursor:pointer}
        .btn{background:#eaf1ff;color:var(--accent);border-color:#cfe0ff;font-weight:600}
        .wrap{max-width:1000px;margin:0 auto;display:flex;flex-direction:column;gap:12px}
        .day{background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px}
        .day-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
        .date{font-weight:700;white-space:nowrap}
        .muted{color:var(--muted)}
        table{width:100%;border-collapse:collapse}
        th,td{border:1px solid var(--line);padding:6px 8px;text-align:center}
        th{background:#f5f7ff}
        td.left{text-align:left}
        tbody tr:nth-child(even){background:#fafafa}
        @media print{
            @page { size: portrait; margin: 10mm; }
            body{background:#fff}
            .panel{display:none}
            .day{break-inside:avoid}
            th,td{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<h1>Задания для сборщиков <?= $order ? '— заявка №'.htmlspecialchars($order) : '' ?></h1>

<form class="panel" method="get">
    <label>С даты: <input type="date" name="start" value="<?= htmlspecialchars($start) ?>"></label>
    <label>По дату: <input type="date" name="end" value="<?= htmlspecialchars($end) ?>"></label>
    <label>Заявка:
        <select name="order">
            <option value="">— любая —</option>
            <?php foreach ($orders as $o): ?>
                <option value="<?= htmlspecialchars($o) ?>" <?= $order===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Показать</button>
    <button type="button" class="btn" onclick="window.print()">Печать</button>
</form>

<div class="wrap">
    <?php if (!$byDate): ?>
        <div class="day"><em class="muted">Нет заданий в выбранном диапазоне</em></div>
    <?php else: ?>
        <?php foreach ($byDate as $d => $items): ?>
            <div class="day">
                <div class="day-header">
                    <div class="date"><?= date('d.m.Y', strtotime($d)) ?></div>
                    <div class="muted">Итого по дню: <b><?= (float)dayTotal($items) ?></b> шт.</div>
                </div>
                <table>
                    <thead>
                    <tr>
                        <th style="width:60%">Фильтр (база)</th>
                        <th style="width:20%">План, шт</th>
                        <th style="width:20%">Комментарий</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $r): ?>
                        <tr>
                            <td class="left"><?= htmlspecialchars($r['base_filter']) ?></td>
                            <td><?= (float)$r['qty'] ?></td>
                            <td class="muted">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
