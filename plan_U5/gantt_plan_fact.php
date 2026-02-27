<?php
// Диаграмма Ганта план/факт для одной позиции заявки: порезка бухты, гофрирование, сборка
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

initAuthSystem();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

$order_number = $_GET['order_number'] ?? $_GET['order'] ?? '';
$filter_param = $_GET['filter'] ?? '';

if ($order_number === '' || $filter_param === '') {
    die('Укажите параметры: order_number (или order) и filter');
}

require_once __DIR__ . '/settings.php';
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Базовое имя фильтра (без суффикса [ ... ])
$filter_base = trim(preg_replace('/\s*\[.*?\]\s*$/u', '', $filter_param));
if ($filter_base === '') {
    $filter_base = $filter_param;
}

// --- ПЛАН: порезка бухты (roll_plans + cut_plans) ---
$plan_cut = [];
$stmt = $pdo->prepare("
    SELECT DISTINCT rp.work_date, rp.bale_id
    FROM roll_plans rp
    JOIN cut_plans cp ON cp.order_number = rp.order_number AND cp.bale_id = rp.bale_id
    WHERE rp.order_number = ?
      AND TRIM(SUBSTRING_INDEX(cp.filter, ' [', 1)) = ?
    ORDER BY rp.work_date
");
$stmt->execute([$order_number, $filter_base]);
while ($row = $stmt->fetch()) {
    $plan_cut[] = ['date' => $row['work_date'], 'bale_id' => $row['bale_id']];
}

// --- ФАКТ: порезка (бухты с done=1; дата — work_date) ---
$fact_cut = [];
$has_done = true;
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT rp.work_date, rp.bale_id
        FROM roll_plans rp
        JOIN cut_plans cp ON cp.order_number = rp.order_number AND cp.bale_id = rp.bale_id
        WHERE rp.order_number = ?
          AND TRIM(SUBSTRING_INDEX(cp.filter, ' [', 1)) = ?
          AND rp.done = 1
        ORDER BY rp.work_date
    ");
    $stmt->execute([$order_number, $filter_base]);
    while ($row = $stmt->fetch()) {
        $fact_cut[] = ['date' => $row['work_date'], 'bale_id' => $row['bale_id']];
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'done') !== false) {
        $has_done = false;
    } else {
        throw $e;
    }
}

// --- ПЛАН: гофрирование (corrugation_plan) ---
$plan_corr = [];
$stmt = $pdo->prepare("
    SELECT plan_date, count
    FROM corrugation_plan
    WHERE order_number = ?
      AND TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
    ORDER BY plan_date
");
$stmt->execute([$order_number, $filter_base]);
while ($row = $stmt->fetch()) {
    $plan_corr[] = ['date' => $row['plan_date'], 'count' => (int)$row['count']];
}

// --- ФАКТ: гофрирование (manufactured_corrugated_packages) ---
$fact_corr = [];
$stmt = $pdo->prepare("
    SELECT date_of_production, SUM(COALESCE(count, 0)) AS cnt
    FROM manufactured_corrugated_packages
    WHERE order_number = ?
      AND TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
      AND COALESCE(count, 0) > 0
    GROUP BY date_of_production
    ORDER BY date_of_production
");
$stmt->execute([$order_number, $filter_base]);
while ($row = $stmt->fetch()) {
    $fact_corr[] = ['date' => $row['date_of_production'], 'count' => (int)$row['cnt']];
}

// --- ПЛАН: сборка (build_plan) ---
$plan_build = [];
$stmt = $pdo->prepare("
    SELECT plan_date, count
    FROM build_plan
    WHERE order_number = ?
      AND TRIM(SUBSTRING_INDEX(COALESCE(filter,''), ' [', 1)) = ?
    ORDER BY plan_date
");
$stmt->execute([$order_number, $filter_base]);
while ($row = $stmt->fetch()) {
    $plan_build[] = ['date' => $row['plan_date'], 'count' => (int)$row['count']];
}

// --- ФАКТ: сборка (manufactured_production) ---
$fact_build = [];
$stmt = $pdo->prepare("
    SELECT date_of_production, SUM(COALESCE(count_of_filters, 0)) AS cnt
    FROM manufactured_production
    WHERE name_of_order = ?
      AND TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) = ?
      AND COALESCE(count_of_filters, 0) > 0
    GROUP BY date_of_production
    ORDER BY date_of_production
");
$stmt->execute([$order_number, $filter_base]);
while ($row = $stmt->fetch()) {
    $fact_build[] = ['date' => $row['date_of_production'], 'count' => (int)$row['cnt']];
}

// Собираем все даты для оси времени
$all_dates = [];
foreach ([$plan_cut, $fact_cut, $plan_corr, $fact_corr, $plan_build, $fact_build] as $arr) {
    foreach ($arr as $item) {
        $all_dates[] = $item['date'];
    }
}
$all_dates = array_unique($all_dates);
sort($all_dates);

if (empty($all_dates)) {
    $today = date('Y-m-d');
    $all_dates = [date('Y-m-d', strtotime($today . ' -7 days')), $today, date('Y-m-d', strtotime($today . ' +7 days'))];
}

$min_date = $all_dates[0];
$max_date = end($all_dates);
$range_start = new DateTime($min_date);
$range_end = new DateTime($max_date);
$range_end->modify('+1 day');
$interval = new DateInterval('P1D');
$period = new DatePeriod($range_start, $interval, $range_end);
$timeline_dates = [];
foreach ($period as $d) {
    $timeline_dates[] = $d->format('Y-m-d');
}

function bars_by_date($items, $key_date = 'date') {
    $out = [];
    foreach ($items as $item) {
        $d = $item[$key_date];
        if (!isset($out[$d])) {
            $out[$d] = ['count' => 0, 'details' => []];
        }
        $out[$d]['count'] += isset($item['count']) ? $item['count'] : 1;
        if (isset($item['bale_id'])) {
            $out[$d]['details'][] = 'Бухта ' . $item['bale_id'];
        }
    }
    return $out;
}

$plan_cut_bars = bars_by_date($plan_cut);
$fact_cut_bars = bars_by_date($fact_cut);
$plan_corr_bars = bars_by_date($plan_corr);
$fact_corr_bars = bars_by_date($fact_corr);
$plan_build_bars = bars_by_date($plan_build);
$fact_build_bars = bars_by_date($fact_build);

$page_title = 'Диаграмма Ганта план/факт — ' . htmlspecialchars($order_number) . ' · ' . htmlspecialchars($filter_base);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --cut: #0ea5e9;
            --corr: #8b5cf6;
            --build: #10b981;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Inter", "Segoe UI", Arial, sans-serif; background: var(--bg); color: var(--ink); padding: 16px; font-size: 14px; }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin-bottom: 8px; }
        .subtitle { color: var(--muted); font-size: 0.875rem; margin-bottom: 20px; }
        .chart-block { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 24px; margin-left: auto; margin-right: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: block; width: max-content; max-width: 100%; }
        .chart-title { font-size: 1rem; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
        .gantt-wrap { overflow-x: auto; display: inline-block; max-width: 100%; }
        .gantt-table { border-collapse: collapse; width: max-content; font-size: 12px; }
        .gantt-table th, .gantt-table td { border: 1px solid var(--border); padding: 4px 6px; vertical-align: middle; }
        .gantt-table th { background: #f1f5f9; font-weight: 600; text-align: center; }
        .gantt-table th.col-label { text-align: left; width: 1%; white-space: nowrap; position: sticky; left: 0; background: #f1f5f9; z-index: 2; }
        .gantt-table td.col-label { position: sticky; left: 0; background: var(--panel); z-index: 1; width: 1%; white-space: nowrap; font-weight: 500; }
        .gantt-table .date-cell { min-width: 32px; width: 32px; text-align: center; font-size: 10px; color: var(--muted); }
        .gantt-table .date-cell.weekend { background: #fef2f2; }
        .gantt-table .date-cell.today { background: #dbeafe; font-weight: 600; }
        .gantt-bar { height: 20px; border-radius: 4px; min-width: 4px; display: inline-block; cursor: default; }
        .gantt-bar.cut { background: var(--cut); }
        .gantt-bar.corr { background: var(--corr); }
        .gantt-bar.build { background: var(--build); }
        .gantt-bar[title] { cursor: help; }
        .legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; color: var(--muted); }
        .legend span { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
        .legend .cut { background: var(--cut); }
        .legend .corr { background: var(--corr); }
        .legend .build { background: var(--build); }
    </style>
</head>
<body>
<div class="container">
    <h1>Диаграмма Ганта план/факт</h1>
    <p class="subtitle">Заявка: <strong><?= htmlspecialchars($order_number) ?></strong> · Позиция: <strong><?= htmlspecialchars($filter_base) ?></strong></p>

    <div class="legend">
        <span class="cut"></span> Порезка бухты &nbsp;
        <span class="corr"></span> Гофрирование &nbsp;
        <span class="build"></span> Сборка
    </div>

    <!-- План -->
    <div class="chart-block">
        <div class="chart-title">План</div>
        <div class="gantt-wrap">
            <table class="gantt-table">
                <thead>
                    <tr>
                        <th class="col-label">Этап</th>
                        <?php foreach ($timeline_dates as $d): ?>
                            <th class="date-cell <?= in_array(date('N', strtotime($d)), [6, 7]) ? 'weekend' : '' ?> <?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= date('d.m', strtotime($d)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-label">1. Порезка бухты</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($plan_cut_bars[$d])): $bar = $plan_cut_bars[$d]; $title = $d . ': ' . $bar['count'] . ' бухт' . (isset($bar['details'][0]) ? ' — ' . implode(', ', $bar['details']) : ''); ?>
                                    <span class="gantt-bar cut" title="<?= htmlspecialchars($title) ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="col-label">2. Гофрирование</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($plan_corr_bars[$d])): $bar = $plan_corr_bars[$d]; ?>
                                    <span class="gantt-bar corr" title="<?= htmlspecialchars($d . ': ' . $bar['count'] . ' шт') ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="col-label">3. Сборка</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($plan_build_bars[$d])): $bar = $plan_build_bars[$d]; ?>
                                    <span class="gantt-bar build" title="<?= htmlspecialchars($d . ': ' . $bar['count'] . ' шт') ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Факт -->
    <div class="chart-block">
        <div class="chart-title">Факт</div>
        <div class="gantt-wrap">
            <table class="gantt-table">
                <thead>
                    <tr>
                        <th class="col-label">Этап</th>
                        <?php foreach ($timeline_dates as $d): ?>
                            <th class="date-cell <?= in_array(date('N', strtotime($d)), [6, 7]) ? 'weekend' : '' ?> <?= $d === date('Y-m-d') ? 'today' : '' ?>"><?= date('d.m', strtotime($d)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-label">1. Порезка бухты</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($fact_cut_bars[$d])): $bar = $fact_cut_bars[$d]; $title = $d . ': ' . $bar['count'] . ' бухт' . (isset($bar['details'][0]) ? ' — ' . implode(', ', $bar['details']) : ''); ?>
                                    <span class="gantt-bar cut" title="<?= htmlspecialchars($title) ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="col-label">2. Гофрирование</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($fact_corr_bars[$d])): $bar = $fact_corr_bars[$d]; ?>
                                    <span class="gantt-bar corr" title="<?= htmlspecialchars($d . ': ' . $bar['count'] . ' шт') ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="col-label">3. Сборка</td>
                        <?php foreach ($timeline_dates as $d): ?>
                            <td>
                                <?php if (!empty($fact_build_bars[$d])): $bar = $fact_build_bars[$d]; ?>
                                    <span class="gantt-bar build" title="<?= htmlspecialchars($d . ': ' . $bar['count'] . ' шт') ?>"><?= $bar['count'] ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$has_done): ?>
        <p style="font-size: 12px; color: var(--muted);">Факт порезки: в таблице <code>roll_plans</code> не найден столбец <code>done</code>. Отображается только план порезки.</p>
    <?php endif; ?>
</div>
<script>
(function() {
    function sendSize() {
        if (window.parent === window) return;
        var container = document.querySelector('.container');
        var tables = document.querySelectorAll('.gantt-table');
        var w = 0, h = 0;
        if (tables.length) {
            tables.forEach(function(t) {
                w = Math.max(w, t.scrollWidth);
            });
        }
        if (container) {
            h = container.scrollHeight;
            var docH = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
            if (docH > h) h = docH;
        }
        if (w > 0 && h > 0) {
            try {
                window.parent.postMessage({ type: 'gantt-resize', width: w, height: h }, '*');
            } catch (e) {}
        }
    }
    if (document.readyState === 'complete') {
        sendSize();
    } else {
        window.addEventListener('load', sendSize);
    }
})();
</script>
</body>
</html>
