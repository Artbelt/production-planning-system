<?php
// NP_view_cut_labels.php — печать этикеток для полос раскроя (по одной этикетке на полосу)
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4","root","",[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
]);

$order = $_GET['order'] ?? '';
if ($order === '') die('Не указан параметр order.');

$sql = "
SELECT
  c.bale_id,
  c.strip_no,
  c.material,
  c.filter,
  c.width, c.height, c.length,
  c.format,
  c.fact_length
FROM cut_plans c
WHERE c.order_number = :ord
ORDER BY c.bale_id, c.strip_no
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ord' => $order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Плоский список этикеток: одна этикетка на полосу
$labels = [];
$baleIndex = [];
foreach ($rows as $r) {
    $baleId = (int)$r['bale_id'];
    if (!isset($baleIndex[$baleId])) {
        $baleIndex[$baleId] = count($baleIndex) + 1;
    }
    $labels[] = [
        'order'   => $order,
        'bale_id' => $baleId,
        'bale_no' => $baleIndex[$baleId],
        'strip_no'=> (int)($r['strip_no'] ?? 0),
        'material'=> $r['material'] ?? '',
        'filter'  => $r['filter'] ?? '',
        'width'   => (float)($r['width'] ?? 0),
        'height'  => (float)($r['height'] ?? 0),
        'length'  => (float)($r['length'] ?? 0),
    ];
}

function fmt1($v) { return number_format((float)$v, 1, '.', ' '); }
function fmt0($v) { return number_format((float)$v, 0, '.', ' '); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Этикетки — <?= htmlspecialchars($order) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root { --line:#333; --muted:#555; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 12px; font-size: 14px; color: #111; }
        h1 { margin: 0 0 12px; text-align: center; font-size: 18px; }
        .toolbar { text-align: center; margin-bottom: 12px; }
        .toolbar .btn { padding: 8px 16px; border: 1px solid #ccc; border-radius: 6px; background: #fff; cursor: pointer; font-size: 14px; }
        .toolbar .btn:hover { background: #f0f0f0; }
        .labels { max-width: 65mm; margin: 0 auto; }
        .label {
            border: 1px solid #ccc;
            padding: 2.5mm 3mm;
            margin-bottom: 8px;
            width: 65mm;
            min-height: 38mm;
            font-size: 11px;
            line-height: 1.3;
            color: #000;
            background: #fff;
        }
        .label-row { display: flex; align-items: baseline; gap: 5px; margin-bottom: 1px; }
        .label-row .name { color: var(--muted); white-space: nowrap; flex-shrink: 0; font-size: inherit; }
        .label-row .val { font-weight: normal; }
        .label .label-pos .val { font-size: 20px; font-weight: bold; letter-spacing: 0.02em; }
        .label .label-order .val { font-size: 16px; font-weight: bold; }
        .label .label-pos .name { font-size: 11px; }
        .label .label-order .name { font-size: 11px; }
        .label .label-small { font-size: 11px; }
        @media print {
            @page { size: 65mm 38mm; margin: 0; }
            body { padding: 0; margin: 0; background: #fff; }
            .toolbar, h1 { display: none !important; }
            .labels { max-width: none; margin: 0; padding: 0; }
            .label {
                width: 65mm !important;
                height: 38mm !important;
                min-height: 38mm !important;
                max-height: 38mm !important;
                margin: 0 !important;
                padding: 2mm 2.5mm !important;
                border: none !important;
                page-break-after: always;
                overflow: hidden;
                font-size: 10pt !important;
                line-height: 1.25 !important;
            }
            .label:last-child { page-break-after: auto; }
            .label .label-pos .val { font-size: 14pt !important; font-weight: bold !important; }
            .label .label-order .val { font-size: 12pt !important; font-weight: bold !important; }
            .label .label-pos .name, .label .label-order .name { font-size: 9pt !important; }
            .label .label-small, .label .label-small .name, .label .label-small .val { font-size: 9pt !important; }
        }
    </style>
</head>
<body>

<h1>Этикетки для раскроя — заявка <?= htmlspecialchars($order) ?></h1>

<div class="toolbar">
    <button class="btn" onclick="window.print()">Печать этикеток</button>
</div>

<?php if (empty($labels)): ?>
    <p>Нет полос для печати этикеток по этой заявке.</p>
<?php else: ?>
    <div class="labels">
        <?php foreach ($labels as $L):
            $materialLabel = $L['material'] ?: '—';
        ?>
            <div class="label">
                <div class="label-row label-pos">
                    <span class="name">Позиция:</span>
                    <span class="val"><?= htmlspecialchars($L['filter'] ?: '—') ?></span>
                </div>
                <div class="label-row label-order">
                    <span class="name">Заявка:</span>
                    <span class="val"><?= htmlspecialchars($L['order']) ?></span>
                </div>
                <div class="label-row label-small">
                    <span class="name">Ширина:</span>
                    <span class="val"><?= fmt0($L['width']) ?> мм</span>
                </div>
                <div class="label-row label-small">
                    <span class="name">Длина:</span>
                    <span class="val"><?= fmt0($L['length']) ?> м</span>
                </div>
                <div class="label-row label-small">
                    <span class="name">Бухта</span>
                    <span class="val"><?= (int)$L['bale_no'] ?></span>
                </div>
                <div class="label-row label-small">
                    <span class="name">Материал:</span>
                    <span class="val"><?= htmlspecialchars($materialLabel) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</body>
</html>
