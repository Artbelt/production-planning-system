<?php
// NP_view_roll_plan.php — просмотр плана порезки бухт (read-only + печать) для plan_U3
require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan_u3');

$order = $_GET['order'] ?? '';
$autoPrint = isset($_GET['print']);
if ($order === '') die('Не указан параметр order.');

$sql = "
SELECT
  r.id,
  r.bale_id,
  r.work_date,
  COALESCE(r.done, 0) AS done,
  c.`filter`,
  TRIM(SUBSTRING_INDEX(c.`filter`,' [',1)) AS base_filter,
  c.width,
  c.height,
  c.length,
  c.material,
  c.format
FROM roll_plans r
JOIN cut_plans c
  ON c.order_number = r.order_number
 AND c.bale_id = r.bale_id
WHERE r.order_number = :ord
ORDER BY r.work_date, CAST(r.bale_id AS UNSIGNED), base_filter
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':ord' => $order]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$bales = [];
foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;
    if (!isset($bales[$id])) {
        $fmt = (int)($r['format'] ?? 1200);
        $len = (int)($r['length'] ?? 0);
        $bales[$id] = [
            'id' => $id,
            'bale_id' => (int)($r['bale_id'] ?? 0),
            'plan_date' => (string)($r['work_date'] ?? ''),
            'done' => (int)($r['done'] ?? 0),
            'material' => (string)($r['material'] ?? ''),
            'format' => $fmt,
            'filters' => [],
            'total_width' => 0.0,
            'length' => $len,
        ];
    }
    $bales[$id]['filters'][] = [
        'base'   => (string)($r['base_filter'] ?? ''),
        'width'  => (float)($r['width'] ?? 0),
        'height' => (float)($r['height'] ?? 0),
        'length' => (int)($r['length'] ?? 0),
    ];
    $bales[$id]['total_width'] += (float)($r['width'] ?? 0);
}

uksort($bales, fn($a,$b)=>($bales[$a]['plan_date'] <=> $bales[$b]['plan_date']) ?: ($bales[$a]['bale_id'] <=> $bales[$b]['bale_id']));
$total_bales = count($bales);
$done_bales = array_sum(array_map(fn($x)=>(int)$x['done'], $bales));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>План порезки бухт — № <?= htmlspecialchars($order) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root{ --bg:#f6f7fb; --card:#fff; --line:#e5e7eb; --muted:#6b7280; --ok:#16a34a; --ink:#111827; }
        *{box-sizing:border-box}
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);margin:0;padding:8px;color:var(--ink);font-size:12px}
        h1{margin:4px 0 10px;text-align:center;font-size:16px}
        .panel{max-width:1300px;margin:0 auto 8px;display:flex;gap:8px;justify-content:center;align-items:center;flex-wrap:wrap}
        .btn{padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;font-size:12px}
        .muted{color:var(--muted)}
        .grid{max-width:1300px;margin:0 auto;display:grid;grid-template-columns:repeat(3, 1fr);gap:8px}
        .card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px}
        .hdr{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:4px}
        .hdr-left{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .tag{font-size:11px;padding:1px 6px;border-radius:999px;border:1px solid var(--line);background:#fff;white-space:nowrap}
        .ok{color:var(--ok);border-color:#c9f2d9;background:#f1f9f4}
        .done{background:#dcfce7;border:2px solid #16a34a;opacity:1;box-shadow:0 2px 8px rgba(34,197,94,0.3)}
        .done .hdr{background:#16a34a;color:white;border-radius:6px;padding:4px 6px;margin:-2px -2px 4px -2px;}
        .done .hdr .muted{color:white !important}
        .done .tag.ok{background:#16a34a;border-color:#15803d;color:white;font-weight:bold}
        .mono{font-variant-numeric:tabular-nums;white-space:nowrap}
        table{width:100%;border-collapse:collapse;font-size:11px}
        th,td{border:1px solid var(--line);padding:3px 5px;text-align:center;vertical-align:middle}
        th{background:#f6f7fb;font-weight:600}
        td.left{text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        tbody tr:nth-child(even){background:#fbfbfb}
        @media(max-width:1100px){ .grid{grid-template-columns:repeat(2, 1fr)} }
        @media(max-width:700px){  .grid{grid-template-columns:1fr} }
        .filter-btn{padding:6px 12px;border:1px solid var(--line);border-radius:8px;background:#fff;cursor:pointer;font-size:12px;transition:all 0.2s}
        .filter-btn.active{background:#2563eb;color:white;border-color:#2563eb}
        .card.hidden{display:none}

        @media print{
            @page { size: landscape; margin: 8mm; }
            body{background:#fff;padding:0}
            .panel{display:none}
            .grid{gap:6px;grid-template-columns:repeat(3, 1fr)}
            .card{break-inside:avoid}
            th,td{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
    <?php if ($autoPrint): ?>
        <script>window.addEventListener('load',()=>window.print());</script>
    <?php endif; ?>
</head>
<body>

<h1>План порезки бухт — заявка № <?= htmlspecialchars($order) ?></h1>

<div class="panel">
    <button class="btn" onclick="window.print()">Печать</button>
    <span class="muted">Бухт: <?= (int)$total_bales ?> | Порезано: <strong><?= (int)$done_bales ?></strong> | Осталось: <strong><?= (int)($total_bales - $done_bales) ?></strong></span>
    <div style="display:flex;gap:8px;align-items:center">
        <span class="muted">Фильтр:</span>
        <button class="filter-btn active" onclick="filterMaterial('all', event)">Все</button>
        <button class="filter-btn" onclick="filterMaterial('white', event)">Белые</button>
        <button class="filter-btn" onclick="filterMaterial('carbon', event)">Угольные</button>
    </div>
</div>

<div class="grid">
    <?php if (!$bales): ?>
        <div class="card"><em class="muted">Нет данных по плану порезки бухт для этой заявки.</em></div>
    <?php else: foreach ($bales as $b):
        $fmt = (int)($b['format'] ?? 1200);
        $leftover = max(0, (float)$fmt - (float)$b['total_width']);
        $materialUpper = strtoupper(trim((string)($b['material'] ?? '')));
        $isCarbon = ($materialUpper === 'CARBON');
        $materialType = $isCarbon ? 'carbon' : 'white';
        ?>
        <div class="card <?= ((int)$b['done'] === 1) ? 'done' : '' ?>" data-material="<?= htmlspecialchars($materialType) ?>">
            <div class="hdr">
                <div class="hdr-left">
                    <strong class="mono"><?= htmlspecialchars((string)$b['plan_date']) ?></strong>
                    <span>Бухта: <strong><?= (int)$b['bale_id'] ?></strong></span>
                    <span class="muted">ост.: <strong class="mono"><?= (float)$leftover ?> мм</strong></span>
                    <span class="muted">формат: <strong class="mono"><?= (int)$fmt ?> мм</strong></span>
                    <span class="muted">длина: <strong class="mono"><?= (int)($b['length'] ?? 0) ?> м</strong></span>
                </div>
                <div class="tag <?= ((int)$b['done'] === 1) ? 'ok' : '' ?>"><?= ((int)$b['done'] === 1) ? '✓ Порезано' : 'Запланировано' ?></div>
            </div>

            <table>
                <thead>
                <tr>
                    <th class="left">Фильтр</th>
                    <th>Шир, мм</th>
                    <th>Выс, мм</th>
                    <th>Длина, м</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($b['filters'] as $f): ?>
                    <tr>
                        <td class="left" title="<?= htmlspecialchars((string)$f['base']) ?>"><?= htmlspecialchars((string)$f['base']) ?></td>
                        <td class="mono"><?= (float)$f['width'] ?></td>
                        <td class="mono"><?= (float)$f['height'] ?></td>
                        <td class="mono"><?= (int)$f['length'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; endif; ?>
</div>

<script>
function filterMaterial(type, ev) {
    document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
    if (ev && ev.target) ev.target.classList.add('active');

    document.querySelectorAll('.card[data-material]').forEach(card => {
        const material = card.dataset.material;
        if (type === 'all') {
            card.classList.remove('hidden');
        } else {
            card.classList.toggle('hidden', material !== type);
        }
    });
}
</script>

</body>
</html>

