<?php
// NP_view_cut.php — просмотр раскроя (подготовка) по заявке в формате "Собранные бухты" (аналогично У5)
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$order = $_GET['order'] ?? '';
$autoPrint = isset($_GET['print']) ? true : false;
if ($order === '') die('Не указан параметр order.');

// Материал берём из plan.paper_package_panel.p_p_material (например «Бумага гладкая»), не название гофропакета
$sql = "
SELECT
  c.bale_id,
  c.paper,
  COALESCE(ppp.p_p_material, c.paper) AS material,
  c.filter,
  c.width, c.height, c.length,
  c.format
FROM cut_plans c
LEFT JOIN paper_package_panel ppp ON ppp.p_p_name = c.paper
WHERE c.order_number = :ord
ORDER BY c.bale_id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ord' => $order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Группируем по бухтам (формат бухты 1200 мм, 1000 мм или 199 — берётся из cut_plans.format по каждой бухте)
$bales = [];

foreach ($rows as $r) {
    $baleId = (int)$r['bale_id'];
    if (!isset($bales[$baleId])) {
        $bales[$baleId] = [
            'bale_id' => $baleId,
            'material' => $r['material'] ?? $r['paper'] ?? '',
            'format' => (int)($r['format'] ?? 1200), // У2: 1200 мм или 199; в У5 — 1000
            'fact' => null,
            'strips' => [],
            'total_width' => 0.0,
            'total_length' => 0.0
        ];
    }

    $bales[$baleId]['strips'][] = [
        'filter' => $r['filter'] ?? '',
        'width' => (float)($r['width'] ?? 0),
        'height' => (float)($r['height'] ?? 0),
        'length' => (float)($r['length'] ?? 0)
    ];

    $bales[$baleId]['total_width'] += (float)($r['width'] ?? 0);
    $bales[$baleId]['total_length'] += (float)($r['length'] ?? 0);
}

// Сортируем бухты по номеру
ksort($bales);

// Группируем по материалам для статистики
$byMaterial = [];
foreach ($bales as $baleId => $bale) {
    $mat = $bale['material'] ?: 'Simple';
    if (!isset($byMaterial[$mat])) {
        $byMaterial[$mat] = [];
    }
    $byMaterial[$mat][] = $baleId;
}

// Статистика: по формату ширины и по длине бухт
$totalBales = count($bales);
$countFormat1200 = 0;
$countFormat199 = 0;
$countFormat1000 = 0;
$countLength1000 = 0;  // бухт по 1000 м длиной
$countLength500 = 0;   // бухт по 500 м длиной

foreach ($bales as $bale) {
    $fmt = (int)$bale['format'];
    if ($fmt === 1200) $countFormat1200++;
    elseif ($fmt === 199) $countFormat199++;
    elseif ($fmt === 1000) $countFormat1000++;

    // Длина бухты — по первой полосе (в бухте обычно одна длина)
    $baleLength = !empty($bale['strips']) ? (float)$bale['strips'][0]['length'] : 0;
    if ($baleLength >= 999) $countLength1000++;
    elseif ($baleLength >= 499 && $baleLength < 999) $countLength500++;
}

function fmt0($v) { return number_format((float)$v, 0, '.', ' '); }
function fmt1($v) { return number_format((float)$v, 1, '.', ' '); }
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
        .panel{max-width:1100px;margin:0 auto 12px;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap}
        .btn{padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer}
        .muted{color:var(--muted)}
        .wrap{max-width:1100px;margin:0 auto;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px}
        
        .stats{font-size:12px;color:#374151;display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-bottom:12px}
        
        .balesList{margin-top:12px}
        .material-group{display:grid;grid-template-columns:repeat(2,1fr);row-gap:8px;column-gap:8px;margin-bottom:12px;align-items:start}
        .material-header{grid-column:1/-1;margin:0 0 6px 0;padding:0;font-weight:600}
        .card{border:1px dashed #bbb;border-radius:8px;padding:8px;margin:0;background:#fff}
        
        @media (max-width: 768px){
            .material-group{grid-template-columns:1fr}
        }
        .cardHead{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;gap:8px;font-size:13px}
        
        .baleTbl{table-layout:fixed;width:100%;margin-top:6px;border-collapse:collapse;font-size:12px}
        .baleTbl th,.baleTbl td{border:1px solid #ccc;padding:4px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
        .baleTbl th{background:#f6f6f6;text-align:left}
        .baleTbl td{text-align:left}
        .baleTbl .bcol-pos{width:160px}
        .baleTbl .bcol-w{width:80px}
        .baleTbl .bcol-h{width:70px}
        .baleTbl .bcol-l{width:140px}
        
        @media print{
            @page { size: A4 portrait; margin: 10mm; }
            body{background:#fff;margin:0;padding:0}
            .panel{display:none}
            .wrap{border:none;padding:0;margin:0}
            h1{margin:0 0 4mm;padding:0;font-size:16px}
            .stats{display:none}
            .balesList{margin-top:0;display:block}
            .material-group{display:grid !important;grid-template-columns:1fr 1fr;row-gap:4mm;column-gap:8mm;margin-bottom:4mm;align-items:start}
            .material-header{grid-column:1/-1;margin:0 0 2mm 0;padding:0;font-size:12px}
            .card{break-inside:avoid;page-break-inside:avoid;margin:0;border:1px solid #000;box-shadow:none;background:#fff;padding:3mm}
            .cardHead{margin-bottom:2mm;font-size:11px;line-height:1.2}
            .baleTbl{font-size:9px;margin-top:2mm}
            .baleTbl th,.baleTbl td{padding:1mm 1mm;line-height:1.2}
            .baleTbl .bcol-pos{width:32mm}
            .baleTbl .bcol-w{width:18mm}
            .baleTbl .bcol-h{width:12mm}
            .baleTbl .bcol-l{width:14mm}
            *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
        }
    </style>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load',()=>window.print());</script>
    <?php endif; ?>
</head>
<body>

<h1>Собранные бухты — заявка <?= htmlspecialchars($order) ?></h1>

<div class="panel">
    <button class="btn" onclick="window.print()">Печать</button>
    <div class="stats">
        <span>Всего бухт: <b><?= $totalBales ?></b></span>
        <span>Ширина 1200 мм: <b><?= $countFormat1200 ?></b></span>
        <span>Ширина 199 мм: <b><?= $countFormat199 ?></b></span>
        <?php if ($countFormat1000 > 0): ?><span>Ширина 1000 мм: <b><?= $countFormat1000 ?></b></span><?php endif; ?>
        <span>Длиной 1000 м: <b><?= $countLength1000 ?></b></span>
        <span>Длиной 500 м: <b><?= $countLength500 ?></b></span>
    </div>
</div>

<div class="wrap">
    <?php if (!$bales): ?>
        <p class="muted">Нет данных по раскрою для этой заявки.</p>
    <?php else: ?>
        <div class="balesList">
            <?php foreach ($byMaterial as $mat => $baleIds): ?>
                <div class="material-group">
                    <?php foreach ($baleIds as $baleId):
                        $bale = $bales[$baleId];
                        // Остаток = ширина бухты (формат) минус сумма ширин полос; формат из БД: 1200, 1000 или 199 мм
                        $baleWidth = (float)$bale['format'];
                        $leftover = max(0, round($baleWidth - $bale['total_width'], 1));
                        $baleNum = array_search($baleId, array_keys($bales)) + 1;
                    ?>
                        <div class="card">
                            <div class="cardHead">
                                <div>
                                    <b>Бухта #<?= $baleNum ?></b> ·
                                    Материал: <b><?= htmlspecialchars($bale['material'] ?: 'Simple') ?></b> ·
                                    Остаток: <b><?= $leftover ?> мм</b> ·
                                    Формат: <b><?= $bale['format'] ?> мм</b>
                                    <?php if ($bale['fact'] !== null): ?>
                                        · Факт: <b><?= fmt0($bale['fact']) ?> м</b>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <table class="baleTbl">
                                <colgroup>
                                    <col class="bcol-pos">
                                    <col class="bcol-w">
                                    <col class="bcol-h">
                                    <col class="bcol-l">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Позиция</th>
                                        <th>Ширина</th>
                                        <th>H</th>
                                        <th>Длина</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bale['strips'] as $strip): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($strip['filter']) ?></td>
                                            <td><?= fmt1($strip['width']) ?> мм</td>
                                            <td><?= fmt0($strip['height']) ?> мм</td>
                                            <td><?= fmt0($strip['length']) ?> м</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
