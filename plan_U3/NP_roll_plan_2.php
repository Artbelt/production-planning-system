<?php
/**
 * NP_roll_plan_2.php — план по заявке: одна таблица, каждый фильтр в 3 строки (сборка, порезка, гофрирование).
 */
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

$order = (string)($_GET['order'] ?? '');
if ($order === '') {
    http_response_code(400);
    exit('Укажите номер заявки: ?order=...');
}
$startDateRaw = (string)($_GET['start_date'] ?? '');
$startDateObj = DateTime::createFromFormat('Y-m-d', $startDateRaw);
$startDateExplicit = ($startDateObj && $startDateObj->format('Y-m-d') === $startDateRaw);
if (!$startDateExplicit) {
    $startDateObj = new DateTime('today');
}
$startDate = $startDateObj->format('Y-m-d');

function normalizeFilterKey(string $filter): string {
    $s = str_replace("\xC2\xA0", ' ', $filter);
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return strtoupper($s);
}

try {
    $pdo = getPdo('plan_u3');

    // Таблицы (если нет — создаём)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roll_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL,
            bale_id INT NOT NULL,
            work_date DATE NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_order_bale (order_number, bale_id),
            KEY idx_order_date (order_number, work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS corrugation_plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(64) NOT NULL,
            filter VARCHAR(128) NOT NULL,
            day_date DATE NOT NULL,
            qty INT NOT NULL,
            saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_order_date (order_number, day_date),
            KEY idx_order_filter (order_number, filter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $hasDoneColumn = true;
    $stCol = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'roll_plans' AND COLUMN_NAME = 'done'
    ");
    if ((int)$stCol->fetchColumn() === 0) {
        @$pdo->exec("ALTER TABLE roll_plans ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
        $hasDoneColumn = false;
    }

    $allDatesMap = [];
    $allFiltersMap = []; // filterKey => display name (first seen)

    // --- План сборки (build_plans) ---
    $stBuild = $pdo->prepare("
        SELECT day_date, filter, SUM(qty) AS qty
        FROM build_plans
        WHERE order_number = ? AND shift = 'D'
        GROUP BY day_date, filter
        ORDER BY day_date, filter
    ");
    $stBuild->execute([$order]);
    $buildMatrix = [];
    foreach ($stBuild->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string)($row['day_date'] ?? '');
        $filter = trim((string)($row['filter'] ?? ''));
        $filterKey = normalizeFilterKey($filter);
        $qty = (int)($row['qty'] ?? 0);
        if ($date === '' || $filterKey === '') continue;
        $allDatesMap[$date] = true;
        if (!isset($allFiltersMap[$filterKey])) $allFiltersMap[$filterKey] = $filter;
        if (!isset($buildMatrix[$filterKey])) $buildMatrix[$filterKey] = [];
        $buildMatrix[$filterKey][$date] = ($buildMatrix[$filterKey][$date] ?? 0) + $qty;
    }
    $buildTotalByFilter = [];
    foreach ($buildMatrix as $fk => $byDate) {
        $buildTotalByFilter[$fk] = array_sum($byDate);
    }

    // --- Раскрой по бухтам (cut_plans) для расчёта порезки ---
    $stBaleFilterLen = $pdo->prepare("
        SELECT bale_id, TRIM(filter) AS filter_name,
               SUM(COALESCE(NULLIF(fact_length, 0), length)) AS total_len
        FROM cut_plans
        WHERE order_number = ?
        GROUP BY bale_id, TRIM(filter)
    ");
    $stBaleFilterLen->execute([$order]);
    $baleFilterLen = [];
    $totalLenByFilter = [];
    while ($row = $stBaleFilterLen->fetch(PDO::FETCH_ASSOC)) {
        $baleId = (int)($row['bale_id'] ?? 0);
        $fname = trim((string)($row['filter_name'] ?? ''));
        $fkey = normalizeFilterKey($fname);
        $len = (float)($row['total_len'] ?? 0);
        if ($baleId <= 0 || $fkey === '' || $len <= 0) continue;
        if (!isset($baleFilterLen[$baleId])) $baleFilterLen[$baleId] = [];
        $baleFilterLen[$baleId][$fkey] = ($baleFilterLen[$baleId][$fkey] ?? 0) + $len;
        $totalLenByFilter[$fkey] = ($totalLenByFilter[$fkey] ?? 0) + $len;
    }

    $baleSupplyMap = [];
    foreach ($baleFilterLen as $baleId => $filters) {
        foreach ($filters as $fkey => $len) {
            $planTotal = (float)($buildTotalByFilter[$fkey] ?? 0);
            $lenTotal = (float)($totalLenByFilter[$fkey] ?? 0);
            $qtyShare = ($planTotal > 0 && $lenTotal > 0) ? ($planTotal * $len / $lenTotal) : 0;
            if ($qtyShare <= 0) continue;
            if (!isset($baleSupplyMap[$baleId])) $baleSupplyMap[$baleId] = [];
            $baleSupplyMap[$baleId][$fkey] = $qtyShare;
        }
    }

    // --- План порезки бухт (roll_plans) → матрица порезки по фильтрам/датам ---
    $stRoll = $pdo->prepare(
        $hasDoneColumn
            ? "SELECT bale_id, work_date FROM roll_plans WHERE order_number = ?"
            : "SELECT bale_id, work_date FROM roll_plans WHERE order_number = ?"
    );
    $stRoll->execute([$order]);
    $rollMap = [];
    while ($r = $stRoll->fetch(PDO::FETCH_ASSOC)) {
        $bid = (int)$r['bale_id'];
        $wd = (string)($r['work_date'] ?? '');
        $rollMap[$bid] = $wd;
        if ($wd !== '') $allDatesMap[$wd] = true;
    }

    $cutMatrix = [];
    foreach ($rollMap as $baleId => $workDate) {
        if ($workDate === '') continue;
        $supply = $baleSupplyMap[$baleId] ?? [];
        foreach ($supply as $fkey => $qty) {
            if (!isset($cutMatrix[$fkey])) $cutMatrix[$fkey] = [];
            $cutMatrix[$fkey][$workDate] = ($cutMatrix[$fkey][$workDate] ?? 0) + round($qty, 2);
            if (!isset($allFiltersMap[$fkey])) $allFiltersMap[$fkey] = $fkey;
        }
    }

    // --- План гофрирования (corrugation_plans) ---
    $stCorr = $pdo->prepare("
        SELECT day_date, filter, SUM(qty) AS qty
        FROM corrugation_plans
        WHERE order_number = ?
        GROUP BY day_date, filter
        ORDER BY day_date, filter
    ");
    $stCorr->execute([$order]);
    $corrMatrix = [];
    foreach ($stCorr->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string)($row['day_date'] ?? '');
        $filter = trim((string)($row['filter'] ?? ''));
        $filterKey = normalizeFilterKey($filter);
        $qty = (int)($row['qty'] ?? 0);
        if ($date === '' || $filterKey === '') continue;
        $allDatesMap[$date] = true;
        if (!isset($allFiltersMap[$filterKey])) $allFiltersMap[$filterKey] = $filter;
        if (!isset($corrMatrix[$filterKey])) $corrMatrix[$filterKey] = [];
        $corrMatrix[$filterKey][$date] = ($corrMatrix[$filterKey][$date] ?? 0) + $qty;
    }

    // Единый список дат (от start_date, минимум 10 дней или до макс. даты)
    $planDatesList = array_keys($allDatesMap);
    if (!$startDateExplicit && !empty($planDatesList)) {
        $rangeStartObj = DateTime::createFromFormat('Y-m-d', min($planDatesList));
        if ($rangeStartObj) {
            $startDateObj = $rangeStartObj;
            $startDate = $startDateObj->format('Y-m-d');
        }
    }
    $maxDateObj = clone $startDateObj;
    foreach ($planDatesList as $d) {
        $dObj = DateTime::createFromFormat('Y-m-d', $d);
        if ($dObj && $dObj > $maxDateObj) $maxDateObj = $dObj;
    }
    $minDays = 10;
    $diffDays = (int)$startDateObj->diff($maxDateObj)->format('%r%a');
    if ($diffDays < 0) $diffDays = 0;
    $daysCount = max($minDays, $diffDays + 1);
    $columnDates = [];
    for ($i = 0; $i < $daysCount; $i++) {
        $columnDates[] = (clone $startDateObj)->modify('+' . $i . ' day')->format('Y-m-d');
    }

    // Сортируем фильтры по отображаемому имени
    $filterKeys = array_keys($allFiltersMap);
    asort($allFiltersMap, SORT_NATURAL | SORT_FLAG_CASE);
    $filterKeysOrdered = array_keys($allFiltersMap);

    // Бухты в раскрое и назначения по датам (для правой панели)
    $baleIds = array_keys($baleFilterLen);
    sort($baleIds, SORT_NUMERIC);
    $dateToBales = array_fill_keys($columnDates, []);
    foreach ($rollMap as $bid => $workDate) {
        if ($workDate !== '' && isset($dateToBales[$workDate])) {
            $dateToBales[$workDate][] = $bid;
        }
    }
    foreach ($dateToBales as $d => $list) {
        sort($dateToBales[$d], SORT_NUMERIC);
    }

} catch (Throwable $e) {
    http_response_code(500);
    exit('Ошибка БД: ' . htmlspecialchars($e->getMessage()));
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План по заявке (сборка / порезка / гофрирование) — <?= htmlspecialchars($order) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f6f8fb; color: #1f2937; }
        .container { width: 100%; max-width: none; margin: 0; padding: 0; box-sizing: border-box; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .top-panel { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .top-panel .title { margin: 0; font-size: 20px; font-weight: 700; }
        .top-panel .meta { margin: 0; color: #4b5563; font-size: 14px; }
        .top-panel .actions { margin-left: auto; }
        .date-form { display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; }
        .date-form label { font-size: 12px; color: #4b5563; }
        .date-form input[type="date"] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn { display: inline-block; border: 0; border-radius: 8px; padding: 8px 12px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; font-size: 14px; }
        .btn.secondary { background: #6b7280; }
        .table-scroll { width: 100%; overflow-x: auto; overflow-y: hidden; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
        .table-scroll-vertical { max-height: 70vh; overflow-y: auto; }
        .table-scroll-vertical table thead { position: sticky; top: 0; z-index: 4; background: #f3f4f6; }
        table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        th.date-header {
            writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; white-space: nowrap;
            min-width: 2.5ch; width: 2.5ch; height: 10ch; padding: 4px 2px; vertical-align: middle;
        }
        .date-label { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .center { text-align: center; }
        .zero { color: #9ca3af; }
        .sticky-left { position: sticky; left: 0; background: #fff; z-index: 2; }
        .filter-name-cell { min-width: 140px; vertical-align: middle; }
        .stage-col { min-width: 90px; }
        .stage-col.sticky-left { left: 140px; }
        th.sticky-left { background: #f3f4f6; z-index: 3; }
        .row-build { background: #fefce8; }
        .row-cut  { background: #f0fdf4; }
        .row-corr { background: #eff6ff; }
        .row-build td.sticky-left { background: #fef9c3; }
        .row-cut td.sticky-left  { background: #dcfce7; }
        .row-corr td.sticky-left { background: #dbeafe; }
        th.date-header.weekend { background: #e5e7eb; }
        td.weekend { background: #f9fafb; }
        /* подсветка блока из 3 строк при наведении */
        .block-hover.row-build,
        .block-hover.row-build td.sticky-left { background: #fde047 !important; }
        .block-hover.row-cut,
        .block-hover.row-cut td.sticky-left { background: #86efac !important; }
        .block-hover.row-corr,
        .block-hover.row-corr td.sticky-left { background: #93c5fd !important; }

        /* Правая панель: бухты и таблица дата/набор бухт */
        .page-wrap { display: flex; min-height: 100vh; width: 100%; margin: 0; padding: 0; box-sizing: border-box; }
        .main-content { flex: 1; padding: 20px; overflow: auto; min-width: 0; box-sizing: border-box; background: #f6f8fb; }
        .right-panel {
            width: 20%;
            min-width: 160px;
            max-width: 280px;
            display: flex;
            flex-direction: column;
            border-left: 1px solid #e5e7eb;
            background: #f8fafc;
            flex-shrink: 0;
        }
        .panel-top {
            height: 25%;
            min-height: 100px;
            max-height: 25vh;
            overflow: auto;
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .panel-top h3 { margin: 0 0 8px; font-size: 13px; color: #374151; }
        .bale-circles { display: flex; flex-wrap: wrap; gap: 6px; align-content: flex-start; }
        .bale-circle {
            width: 32px; height: 32px; border-radius: 50%;
            background: #3b82f6; color: #fff; font-size: 12px; font-weight: 600;
            display: flex; align-items: center; justify-content: center;
            cursor: grab; user-select: none; flex-shrink: 0;
            border: 2px solid #2563eb;
        }
        .bale-circle:hover { background: #2563eb; }
        .bale-circle.dragging { opacity: 0.6; cursor: grabbing; }
        .bale-circle.in-date { background: #059669; border-color: #047857; }
        .panel-bottom { flex: 1; overflow: auto; padding: 10px; min-height: 0; }
        .panel-bottom h3 { margin: 0 0 8px; font-size: 13px; color: #374151; }
        .date-bales-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .date-bales-table th, .date-bales-table td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; }
        .date-bales-table th { background: #e5e7eb; }
        .date-bales-table tr.drop-target { background: #dbeafe; }
        .date-bales-table .bales-cell { min-height: 28px; }
        .date-bales-table .bales-cell .bale-chip {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 50%; font-size: 10px; font-weight: 600;
            background: #059669; color: #fff; margin: 1px; cursor: grab;
        }
        .date-bales-table .bales-cell .bale-chip:hover { opacity: 0.9; }
        .date-bales-table .bales-cell .bale-chip.dragging { opacity: 0.5; }
    </style>
</head>
<body>
<div class="page-wrap">
<div class="main-content">
<div class="container">
    <div class="card">
        <div class="top-panel">
            <h1 class="title">План по заявке (сборка / порезка / гофрирование)</h1>
            <span class="meta">Заявка: <b><?= htmlspecialchars($order) ?></b></span>
            <div class="actions">
                <a class="btn secondary" href="NP_cut_index.php">Назад к этапам</a>
                <a class="btn secondary" href="NP_roll_plan.php?order=<?= urlencode($order) ?>&amp;start_date=<?= htmlspecialchars($startDate) ?>">План порезки бухт</a>
                <form method="get" class="date-form">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                    <label>Дата начала <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>"></label>
                    <button type="submit" class="btn">Показать</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>План по заявке: сборка — порезка — гофрирование</h2>
        <p class="meta">Каждый фильтр представлен тремя строками: план сборки, план порезки (по назначенным бухтам), план гофрирования.</p>
        <?php if (empty($filterKeysOrdered) || empty($columnDates)): ?>
            <p>Нет данных по заявке.</p>
        <?php else: ?>
            <div class="table-scroll table-scroll-vertical">
                <table id="planTable">
                    <thead>
                    <tr>
                        <th class="sticky-left">Фильтр</th>
                        <th class="sticky-left stage-col">Этап</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                            <th class="date-header<?= $weekend ? ' weekend' : '' ?>"><span class="date-label"><?= htmlspecialchars($date) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filterKeysOrdered as $blockIndex => $filterKey): ?>
                        <?php $filterTitle = $allFiltersMap[$filterKey]; ?>
                        <tr class="row-build" data-block-index="<?= (int)$blockIndex ?>">
                            <td class="sticky-left filter-name-cell" rowspan="3"><?= htmlspecialchars($filterTitle) ?></td>
                            <td class="sticky-left stage-col">сборка</td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($buildMatrix[$filterKey][$date] ?? 0); $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center <?= $weekend ? 'weekend' : '' ?> <?= $qty === 0 ? 'zero' : '' ?>"><?= $qty !== 0 ? $qty : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="row-cut" data-block-index="<?= (int)$blockIndex ?>">
                            <td class="sticky-left stage-col">порезка</td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $val = $cutMatrix[$filterKey][$date] ?? 0; $qty = is_numeric($val) ? (float)$val : 0; $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center <?= $weekend ? 'weekend' : '' ?> <?= $qty == 0 ? 'zero' : '' ?>"><?= $qty != 0 ? ($qty == (int)$qty ? (int)$qty : number_format($qty, 1)) : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="row-corr" data-block-index="<?= (int)$blockIndex ?>">
                            <td class="sticky-left stage-col">гофрирование</td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($corrMatrix[$filterKey][$date] ?? 0); $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center <?= $weekend ? 'weekend' : '' ?> <?= $qty === 0 ? 'zero' : '' ?>"><?= $qty !== 0 ? $qty : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<aside class="right-panel" id="rightPanel">
    <div class="panel-top" id="panelTop">
        <h3>Бухты в раскрое</h3>
        <div class="bale-circles" id="baleCircles">
            <?php foreach ($baleIds as $bid): ?>
                <span class="bale-circle" data-bale-id="<?= (int)$bid ?>" draggable="true" title="Бухта <?= (int)$bid ?>"><?= (int)$bid ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="panel-bottom">
        <h3>Дата → набор бухт</h3>
        <table class="date-bales-table" id="dateBalesTable">
            <thead><tr><th>Дата</th><th>Набор бухт</th></tr></thead>
            <tbody>
                <?php foreach ($columnDates as $date): ?>
                    <tr data-date="<?= htmlspecialchars($date) ?>">
                        <td><?= htmlspecialchars($date) ?></td>
                        <td class="bales-cell" data-date="<?= htmlspecialchars($date) ?>">
                            <?php
                            $balesForDate = $dateToBales[$date] ?? [];
                            foreach ($balesForDate as $bid): ?>
                                <span class="bale-chip" data-bale-id="<?= (int)$bid ?>" draggable="true"><?= (int)$bid ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</aside>
</div>

<script>
(function () {
    var table = document.getElementById('planTable');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    function getBlockRows(tr) {
        var idx = tr.getAttribute('data-block-index');
        if (idx === null) return [];
        return tbody.querySelectorAll('tr[data-block-index="' + idx + '"]');
    }

    function highlightBlock(tr, on) {
        var rows = getBlockRows(tr);
        rows.forEach(function (r) {
            if (on) r.classList.add('block-hover');
            else r.classList.remove('block-hover');
        });
    }

    tbody.addEventListener('mouseover', function (e) {
        var tr = e.target.closest('tr');
        if (!tr || !tr.getAttribute('data-block-index')) return;
        highlightBlock(tr, true);
    });

    tbody.addEventListener('mouseout', function (e) {
        var tr = e.target.closest('tr');
        if (!tr || !tr.getAttribute('data-block-index')) return;
        var related = e.relatedTarget;
        if (related && tr.contains(related)) return;
        var rows = getBlockRows(tr);
        var stillInside = false;
        if (related) {
            rows.forEach(function (r) {
                if (r.contains(related)) stillInside = true;
            });
        }
        if (!stillInside) highlightBlock(tr, false);
    });
})();

(function () {
    var dateToBales = <?= json_encode($dateToBales) ?>;
    var columnDates = <?= json_encode($columnDates) ?>;
    var baleIds = <?= json_encode($baleIds) ?>;

    var panelTop = document.getElementById('panelTop');
    var dateBalesTable = document.getElementById('dateBalesTable');
    var baleCircles = document.getElementById('baleCircles');
    if (!panelTop || !dateBalesTable || !baleCircles) return;

    function getUnassignedBales() {
        return baleIds.filter(function (id) {
            return !columnDates.some(function (d) {
                return (dateToBales[d] || []).indexOf(id) !== -1;
            });
        });
    }

    function bindTopCirclesDrag() {
        baleCircles.querySelectorAll('.bale-circle').forEach(function (circle) {
            circle.ondragstart = function (e) {
                e.dataTransfer.setData('text/plain', circle.getAttribute('data-bale-id'));
                e.dataTransfer.setData('source', 'top');
                e.dataTransfer.effectAllowed = 'move';
                circle.classList.add('dragging');
            };
            circle.ondragend = function () { circle.classList.remove('dragging'); };
        });
    }

    function reRenderTopCircles() {
        var unassigned = getUnassignedBales();
        baleCircles.innerHTML = '';
        unassigned.forEach(function (bid) {
            var span = document.createElement('span');
            span.className = 'bale-circle';
            span.setAttribute('data-bale-id', bid);
            span.draggable = true;
            span.title = 'Бухта ' + bid;
            span.textContent = bid;
            baleCircles.appendChild(span);
        });
        bindTopCirclesDrag();
    }

    function reRenderBalesCells() {
        var rows = dateBalesTable.querySelectorAll('tbody tr');
        rows.forEach(function (tr) {
            var date = tr.getAttribute('data-date');
            var cell = tr.querySelector('.bales-cell');
            if (!cell || !date) return;
            var list = dateToBales[date] || [];
            cell.innerHTML = '';
            list.forEach(function (bid) {
                var chip = document.createElement('span');
                chip.className = 'bale-chip';
                chip.setAttribute('data-bale-id', bid);
                chip.draggable = true;
                chip.textContent = bid;
                cell.appendChild(chip);
            });
            bindChipDrag(cell);
        });
    }

    function removeBaleFromAllDates(baleId) {
        columnDates.forEach(function (d) {
            var arr = dateToBales[d] || [];
            var i = arr.indexOf(baleId);
            if (i !== -1) arr.splice(i, 1);
            dateToBales[d] = arr;
        });
    }

    function addBaleToDate(baleId, date) {
        removeBaleFromAllDates(baleId);
        if (!dateToBales[date]) dateToBales[date] = [];
        if (dateToBales[date].indexOf(baleId) === -1) {
            dateToBales[date].push(baleId);
            dateToBales[date].sort(function (a, b) { return a - b; });
        }
    }

    function bindChipDrag(container) {
        if (!container) return;
        container.querySelectorAll('.bale-chip').forEach(function (chip) {
            chip.onmouseenter = null;
            chip.ondragstart = function (e) {
                e.dataTransfer.setData('text/plain', chip.getAttribute('data-bale-id'));
                e.dataTransfer.setData('source', 'cell');
                e.dataTransfer.setData('date', container.getAttribute('data-date'));
                chip.classList.add('dragging');
            };
            chip.ondragend = function () { chip.classList.remove('dragging'); };
        });
    }

    panelTop.addEventListener('dragover', function (e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });
    panelTop.addEventListener('drop', function (e) {
        e.preventDefault();
        var baleId = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (!baleId) return;
        removeBaleFromAllDates(baleId);
        reRenderBalesCells();
        reRenderTopCircles();
    });

    dateBalesTable.querySelectorAll('.bales-cell').forEach(function (cell) {
        cell.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var row = cell.closest('tr');
            if (row) row.classList.add('drop-target');
        });
        cell.addEventListener('dragleave', function (e) {
            var row = cell.closest('tr');
            if (row) row.classList.remove('drop-target');
        });
        cell.addEventListener('drop', function (e) {
            e.preventDefault();
            var row = cell.closest('tr');
            if (row) row.classList.remove('drop-target');
            var baleId = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var date = cell.getAttribute('data-date');
            if (!baleId || !date) return;
            addBaleToDate(baleId, date);
            reRenderBalesCells();
            reRenderTopCircles();
        });
    });

    dateBalesTable.querySelectorAll('tbody tr').forEach(function (tr) {
        var cell = tr.querySelector('.bales-cell');
        if (cell) bindChipDrag(cell);
    });

    reRenderTopCircles();
})();
</script>
</body>
</html>
