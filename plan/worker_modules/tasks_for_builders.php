<?php
// view_production_plan_light.php — печатная версия плана сборки
// Формат на день: 3 столбца (Место | Фильтры | Кол-во), места 1..17,
// если на месте несколько позиций — "AF123 → BF456", а количество "100 → 50".
// Повторяющиеся подряд одинаковые позиции на месте склеиваются (сумма qty).

$pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$orient = $_GET['orient'] ?? 'auto'; // если используете блок @page size

// список заявок для селекта
$orders = $pdo->query("SELECT DISTINCT order_number FROM build_plan ORDER BY order_number")
    ->fetchAll(PDO::FETCH_COLUMN);

$order = $_GET['order'] ?? '';
$autoPrint = isset($_GET['print']);
//if ($order === '') { die('Не указан номер заявки (?order=...).'); }
$rows = [];
$days = [];
$totalsByDay = [];
$byPlace = [];

// не падаем без order — просто покажем селектор
$showSelectorOnly = ($order === '');

/* --- утилиты --- */
function norm_name(string $s): string {
    $s = preg_replace('~\s*\[.*$~u', '', $s);     // убрать хвост вида " [..]"
    $s = preg_replace('/[●◩⏃]/u', '', $s);        // убрать тех. метки
    $s = preg_replace('~\s+~u', ' ', trim($s));   // нормализовать пробелы
    return $s;
}
function ruDow(string $ymd){
    $ts = strtotime($ymd);
    $dows = ['вс','пн','вт','ср','чт','пт','сб'];
    return $dows[(int)date('w',$ts)];
}

/* --- строки плана текущей заявки (берём всё напрямую из build_plan) --- */
if (!$showSelectorOnly) {
    $stmt = $pdo->prepare("
        SELECT
            DATE(assign_date) AS d,                            -- дата жёстко как YYYY-MM-DD
            TRIM(SUBSTRING_INDEX(filter_label,' [',1)) AS base, -- «чистое» имя фильтра
            `count` AS qty,
            place
        FROM build_plan
        WHERE order_number = ?
          AND assign_date IS NOT NULL
          AND place BETWEEN 1 AND 17
        ORDER BY d, id
    ");
    $stmt->execute([$order]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
/* --- агрегаты по дням и раскладка по местам --- */
$days = [];              // список дат
$totalsByDay = [];       // [day] => total count
$byPlace = [];           // [day][place] => [ [name,count], [name2,count2], ... ]

foreach ($rows as $r) {
    $d     = $r['d'];                   // уже YYYY-MM-DD
    $base  = norm_name($r['base']);
    $cnt   = (int)$r['qty'];
    $place = (int)$r['place'];

    if ($d === null || $d === '') continue;

    $days[$d] = true;
    $totalsByDay[$d] = ($totalsByDay[$d] ?? 0) + $cnt;

    if ($base === '' || $place < 1 || $place > 17) continue;

    if (!isset($byPlace[$d])) {
        $byPlace[$d] = [];
        for ($i=1;$i<=17;$i++) $byPlace[$d][$i] = [];
    }
    // копим в последовательности добавления (показывает смену позиций)
    $byPlace[$d][$place][] = ['name'=>$base, 'count'=>$cnt];
}

/* --- склейка подряд идущих одинаковых позиций на месте --- */
foreach ($byPlace as $d => $places) {
    for ($p=1; $p<=17; $p++) {
        $chain = $places[$p] ?? [];
        if (count($chain) < 2) continue;
        $comp = [];
        foreach ($chain as $it) {
            if ($comp && $comp[count($comp)-1]['name'] === $it['name']) {
                $comp[count($comp)-1]['count'] += (int)$it['count'];
            } else {
                $comp[] = ['name'=>$it['name'], 'count'=>(int)$it['count']];
            }
        }
        $byPlace[$d][$p] = $comp;
    }
}

$days = array_keys($days);
sort($days, SORT_STRING);

/* итого по всем дням */
$grand = 0; foreach ($totalsByDay as $v) $grand += (int)$v;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>План сборки (лайт) — заявка <?= htmlspecialchars($order) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{ --bg:#f6f7fb; --card:#fff; --line:#e5e7eb; --muted:#6b7280; --ink:#111827; }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:0;padding:10px;color:var(--ink);font-size:12px}
        h1{margin:4px 0 10px;text-align:center;font-size:16px}
        .panel{max-width:1400px;margin:0 auto 8px;display:flex;gap:8px;justify-content:center;align-items:center}
        .btn{padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;font-size:12px}
        .muted{color:var(--muted)}
        .wrap{max-width:1400px;margin:0 auto}
        .grid{display:grid;grid-template-columns: repeat(auto-fill,minmax(320px,1fr));gap:8px;align-items:start}
        .card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px;}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-variant-numeric:tabular-nums}
        .dow{font-size:11px;color:var(--muted);margin-left:6px}

        table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}
        th,td{border:1px solid var(--line);padding:4px 6px;text-align:center;vertical-align:middle}
        th{background:#f3f4f6;font-weight:600}
        td.left{text-align:left;white-space:normal}
        .place-col{width:52px}
        .qty-col{width:110px}
        .filters-cell{line-height:1.25}
        .arrow{padding:0 4px; opacity:.75}
        tbody tr:nth-child(even){background:#fbfbfb}

        @media print{
            @page {
                margin: 8mm;
                <?php if ($orient === 'landscape'): ?>size: landscape;
                <?php elseif ($orient === 'portrait'): ?>size: portrait;
                <?php else: ?>size: auto;<?php endif; ?>
            }
            body{background:#fff;padding:0}
            .panel{display:none}
            .grid{gap:6px;grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));}
            .card{break-inside:avoid}
            th,td{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load',()=>window.print());</script>
    <?php endif; ?>
</head>
<body>

<h1>План сборки (лайт) — заявка № <?= htmlspecialchars($order) ?></h1>

<div class="panel">
    <label>Заявка:</label>
    <select id="orderSel">
        <option value="">— выберите —</option>
        <?php foreach ($orders as $o): ?>
            <option value="<?= htmlspecialchars($o) ?>" <?= $order===$o?'selected':'' ?>>
                <?= htmlspecialchars($o) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button class="btn" onclick="go()">Показать</button>
    <button class="btn" onclick="goPrint()">Открыть для печати</button>

    <?php if(!$showSelectorOnly): ?>
        <span class="muted">Дней: <?= count($days) ?> • Всего по плану: <?= (int)$grand ?> шт.</span>
    <?php endif; ?>
</div>


<div class="wrap">
    <?php if ($showSelectorOnly): ?>
        <div class="card"><em class="muted">Выберите номер заявки сверху и нажмите «Показать».</em></div>
    <?php elseif (!$days): ?>
        <div class="card"><em class="muted">Нет строк плана сборки для этой заявки.</em></div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($days as $d): ?>
                <?php
                if (!isset($byPlace[$d])) {
                    $byPlace[$d] = [];
                    for ($i=1;$i<=17;$i++) $byPlace[$d][$i] = [];
                }
                ?>
                <div class="card">
                    <div class="header">
                        <strong><?= htmlspecialchars($d) ?><span class="dow"> / <?= ruDow($d) ?></span></strong>
                        <span class="muted">Итого: <b><?= (int)($totalsByDay[$d] ?? 0) ?></b> шт.</span>
                    </div>

                    <table>
                        <colgroup>
                            <col class="place-col"><col><col class="qty-col">
                        </colgroup>
                        <thead>
                        <tr>
                            <th>Место</th>
                            <th>Фильтры</th>
                            <th>Кол-во</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php for ($p=1; $p<=17; $p++):
                            $chain = $byPlace[$d][$p];                     // [ [name,count], ... ]
                            $names = [];
                            $qtys  = [];
                            foreach ($chain as $it) {
                                $names[] = htmlspecialchars($it['name']);
                                $qtys[]  = (int)$it['count'];
                            }
                            $namesHtml = $names ? implode('<span class="arrow">→</span>', $names) : '';
                            $qtyHtml   = $qtys  ? implode('<span class="arrow">→</span>', array_map('strval',$qtys)) : '';
                            ?>
                            <tr>
                                <td><?= $p ?></td>
                                <td class="left filters-cell"><?= $namesHtml ?></td>
                                <td><?= $qtyHtml ?></td>
                            </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    function go(){
        const ord = document.getElementById('orderSel').value.trim();
        if(!ord){ alert('Выберите заявку'); return; }
        const url = new URL(window.location.href);
        url.searchParams.set('order', ord);
        url.searchParams.delete('print');
        window.location = url.toString();
    }
    function goPrint(){
        const ord = document.getElementById('orderSel').value.trim();
        if(!ord){ alert('Сначала выберите заявку'); return; }
        const url = new URL(window.location.href);
        url.searchParams.set('order', ord);
        url.searchParams.set('print', '1');
        window.location = url.toString();
    }
</script>

</body>
</html>
