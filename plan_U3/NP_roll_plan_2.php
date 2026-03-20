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

    $filtersBaleIds = [];
    foreach ($baleFilterLen as $baleId => $filters) {
        foreach ($filters as $fkey => $len) {
            if (!isset($filtersBaleIds[$fkey])) $filtersBaleIds[$fkey] = [];
            $filtersBaleIds[$fkey][] = $baleId;
            $planTotal = (float)($buildTotalByFilter[$fkey] ?? 0);
            $lenTotal = (float)($totalLenByFilter[$fkey] ?? 0);
            $qtyShare = ($planTotal > 0 && $lenTotal > 0) ? ($planTotal * $len / $lenTotal) : 0;
            if ($qtyShare <= 0) continue;
            if (!isset($baleSupplyMap[$baleId])) $baleSupplyMap[$baleId] = [];
            $baleSupplyMap[$baleId][$fkey] = $qtyShare;
        }
    }
    foreach ($filtersBaleIds as $fk => $list) {
        $filtersBaleIds[$fk] = array_values(array_unique($list));
        sort($filtersBaleIds[$fk], SORT_NUMERIC);
    }

    // Высоты штор по фильтрам (для подсветки строк сборки)
    $filterHeights = [];
    $allHeights = [];
    $stHeights = $pdo->prepare("
        SELECT TRIM(filter) AS filter_name, TRIM(height) AS h
        FROM cut_plans
        WHERE order_number = ?
          AND TRIM(height) <> ''
        GROUP BY TRIM(filter), TRIM(height)
        ORDER BY TRIM(height)
    ");
    $stHeights->execute([$order]);
    while ($row = $stHeights->fetch(PDO::FETCH_ASSOC)) {
        $fname = trim((string)($row['filter_name'] ?? ''));
        $h = trim((string)($row['h'] ?? ''));
        if ($fname === '' || $h === '') continue;
        $fkey = normalizeFilterKey($fname);
        if (!isset($filterHeights[$fkey])) $filterHeights[$fkey] = [];
        $filterHeights[$fkey][] = $h;
        $allHeights[$h] = true;
    }
    $allHeightsList = array_keys($allHeights);
    sort($allHeightsList, SORT_NUMERIC);

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

    // Суммы по дням для заголовков
    $buildPerDate = array_fill_keys($columnDates, 0);
    foreach ($buildMatrix as $fk => $byDate) {
        foreach ($byDate as $d => $qty) {
            if (isset($buildPerDate[$d])) {
                $buildPerDate[$d] += (int)$qty;
            }
        }
    }
    // Порезка по дням (кол-во бухт) — по сохранённому плану roll_plans
    $cutBalesPerDate = array_fill_keys($columnDates, 0);
    foreach ($rollMap as $bid => $workDate) {
        if ($workDate !== '' && isset($cutBalesPerDate[$workDate])) {
            $cutBalesPerDate[$workDate]++;
        }
    }
    $corrPerDate = array_fill_keys($columnDates, 0);
    foreach ($corrMatrix as $fk => $byDate) {
        foreach ($byDate as $d => $qty) {
            if (isset($corrPerDate[$d])) {
                $corrPerDate[$d] += (int)$qty;
            }
        }
    }

    // "Высота бумаги" для гофрирования по дням (на основе фильтров, у которых есть план гофрирования в этот день)
    $corrPaperHeightsPerDate = array_fill_keys($columnDates, ['text' => '', 'title' => '']);
    foreach ($columnDates as $d) {
        $hs = [];
        foreach ($corrMatrix as $fk => $byDate) {
            $q = (int)($byDate[$d] ?? 0);
            if ($q <= 0) continue;
            foreach (($filterHeights[$fk] ?? []) as $h) {
                $h = trim((string)$h);
                if ($h !== '') $hs[$h] = true;
            }
        }
        $list = array_keys($hs);
        if (!empty($list)) {
            sort($list, SORT_NUMERIC);
            $title = implode(', ', $list);
            $text = implode("\n", $list);
            $corrPaperHeightsPerDate[$d] = ['text' => $text, 'title' => $title];
        }
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
        /* индикатор загрузки */
        .page-loading {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(246, 248, 251, 0.85);
            backdrop-filter: blur(2px);
        }
        .page-loading.is-hidden { display: none; }
        .page-loading .spinner {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 4px solid rgba(37, 99, 235, 0.18);
            border-top-color: #2563eb;
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .container { width: 100%; max-width: none; margin: 0; padding: 0; box-sizing: border-box; }
        .container h2 { font-size: 14px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .top-panel { display: flex; align-items: center; gap: 12px; flex-wrap: nowrap; }
        .top-panel .title { margin: 0; font-size: 18px; font-weight: 700; white-space: nowrap; }
        .top-panel .actions { margin-left: auto; display: flex; align-items: center; gap: 12px; }
        .date-form { display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; flex: 1; justify-content: center; }
        .date-form label {
            font-size: 12px;
            color: #4b5563;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .date-form input[type="date"] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn { display: inline-block; border: 0; border-radius: 8px; padding: 8px 12px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; font-size: 14px; }
        .btn.secondary { background: #6b7280; }
        .btn.btn-sm { padding: 4px 8px; font-size: 12px; }
        .table-scroll { width: 100%; overflow-x: auto; overflow-y: hidden; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
        .table-scroll-vertical {
            height: calc(100vh - 220px);
            min-height: 300px;
            overflow-y: auto;
        }
        .table-scroll-vertical table thead { position: sticky; top: 0; z-index: 4; background: #f3f4f6; }
        table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 2px 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: normal; }
        th.date-header {
            writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; white-space: nowrap;
            min-width: 2.5ch; width: 2.5ch; height: 10ch; padding: 4px 2px; vertical-align: middle;
        }
        .date-label { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
        .center { text-align: center; }
        .zero { color: #9ca3af; }
        .sticky-left { position: sticky; left: 0; background: #fff; z-index: 2; }
        .filter-name-cell { min-width: 140px; vertical-align: middle; }
        .stage-col {
            min-width: 120px;
            white-space: nowrap;
            line-height: 1.1;
            text-align: right;
        }
        .stage-stat { font-size: 10px; color: #4b5563; margin-left: 4px; opacity: 0.9; }
        .stage-bar {
            display: block;
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: #e5e7eb;
            overflow: hidden;
            margin-top: 2px;
        }
        .stage-bar-fill {
            display: block;
            height: 100%;
            width: calc(var(--p, 0) * 1%);
            background: linear-gradient(90deg, #bfdbfe, #60a5fa);
        }
        .stage-col.stage-bar-filled { background: #dbeafe; }

        .height-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            font-size: 12px;
            color: #4b5563;
        }
        .height-toolbar-label { white-space: nowrap; }
        .height-btn {
            border-radius: 999px;
            padding: 3px 8px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            font-size: 11px;
            cursor: pointer;
        }
        .height-btn.active {
            border-color: #2563eb;
            background: #e0ecff;
            color: #1d4ed8;
        }
        .height-btn sup.height-count {
            font-size: 9px;
            line-height: 1;
            vertical-align: super;
            margin-left: 2px;
            color: #1d4ed8;
            opacity: 0.9;
        }
        .row-corr td.corr-day-highlight {
            background: #dbeafe;
            box-shadow: inset 0 0 0 1px #60a5fa;
        }
        .filter-bale-dots { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 4px; }
        .filter-bale-dot {
            width: 20px; height: 20px; border-radius: 50%; font-size: 10px; font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center;
            background: #93c5fd; color: #1e40af; border: 1px solid #60a5fa;
        }
        .filter-bale-dot.assigned { background: #9ca3af; color: #4b5563; border-color: #6b7280; opacity: 0.7; }
        .stage-col.sticky-left { left: 140px; }
        th.sticky-left { background: #f3f4f6; z-index: 3; }
        .row-build { }
        .row-cut  { }
        .row-corr { }
        th.date-header.weekend { background: #e5e7eb; }
        td.weekend { background: #f9fafb; }
        /* подсветка блока из 3 строк при наведении — единая синяя гамма */
        .block-hover.row-build,
        .block-hover.row-build td.sticky-left,
        .block-hover.row-cut,
        .block-hover.row-cut td.sticky-left,
        .block-hover.row-corr,
        .block-hover.row-corr td.sticky-left {
            background: #e8eeff !important;
        }

        /* подсветка столбца при наведении */
        td.col-hover,
        th.col-hover {
            background-color: #e0ecff !important;
        }
        /* пересечение строки и столбца — более насыщенный синий */
        .block-hover td.col-hover,
        .block-hover th.col-hover {
            background-color: #bfdbfe !important;
        }

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
            padding: 6px 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .panel-top h3 { margin: 0 0 6px; font-size: 12px; color: #374151; }
        .bale-circles { display: flex; flex-wrap: wrap; gap: 4px; align-content: flex-start; }
        .bale-circle {
            width: 26px; height: 26px; border-radius: 50%;
            background: #3b82f6; color: #fff; font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            cursor: grab; user-select: none; flex-shrink: 0;
            border: 1px solid #2563eb;
        }
        .bale-circle:hover { background: #2563eb; }
        .bale-circle.dragging { opacity: 0.6; cursor: grabbing; }
        .bale-circle.in-date { background: #059669; border-color: #047857; }
        .panel-bottom {
            /* высота, согласованная с основной таблицей */
            height: calc(100vh - 220px);
            min-height: 300px;
            overflow: auto;
            padding: 10px;
            box-sizing: border-box;
        }
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
        th.header-paper-height {
            background: #eef2ff;
            vertical-align: top;
        }
        th.header-paper-height > span {
            display: block;
            white-space: pre-line;
            line-height: 1.1;
            min-height: 24px;
        }
    </style>
</head>
<body>
<div class="page-loading" id="pageLoading" aria-label="Загрузка" role="status">
    <div class="spinner" aria-hidden="true"></div>
</div>
<div class="page-wrap">
<div class="main-content">
<div class="container">
    <div class="card">
        <div class="top-panel">
            <h1 class="title">План по заявке (сборка / порезка / гофрирование) — заявка <b><?= htmlspecialchars($order) ?></b></h1>
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
        <?php if (empty($filterKeysOrdered) || empty($columnDates)): ?>
            <p>Нет данных по заявке.</п>
        <?php else: ?>
            <?php if (!empty($allHeightsList)): ?>
                <div class="height-toolbar">
                    <span class="height-toolbar-label">Высота шторы:</span>
                    <?php foreach ($allHeightsList as $h): ?>
                        <button type="button" class="height-btn" data-height="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="height-btn" data-height="">Сброс</button>
                </div>
            <?php endif; ?>
            <div class="table-scroll table-scroll-vertical">
                <table id="planTable">
                    <thead>
                    <tr class="paper-height-row">
                        <th class="sticky-left stage-col" colspan="2">высота бумаги</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php
                            $ph = $corrPaperHeightsPerDate[$date] ?? ['text' => '', 'title' => ''];
                            $txt = (string)($ph['text'] ?? '');
                            $ttl = (string)($ph['title'] ?? '');
                            ?>
                            <th class="header-paper-height" data-date="<?= htmlspecialchars($date) ?>" title="<?= htmlspecialchars($ttl) ?>">
                                <span style="font-size:10px;"><?= htmlspecialchars($txt) ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sticky-left" rowspan="4">Фильтр</th>
                        <th class="sticky-left stage-col">Этап</th>
                        <?php
                        $ruMonths = [
                            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
                            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
                            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
                        ];
                        ?>
                        <?php foreach ($columnDates as $date): ?>
                            <?php
                            $d = DateTime::createFromFormat('Y-m-d', $date);
                            $w = $d ? (int)$d->format('w') : -1;
                            $weekend = ($w === 0 || $w === 6);
                            $day = $d ? (int)$d->format('j') : '';
                            $monthNum = $d ? (int)$d->format('n') : 0;
                            $monthName = $ruMonths[$monthNum] ?? '';
                            $label = trim($day . ' ' . $monthName);
                            ?>
                            <th class="date-header<?= $weekend ? ' weekend' : '' ?>" data-date="<?= htmlspecialchars($date) ?>"><span class="date-label"><?= htmlspecialchars($label) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sticky-left stage-col">сборка</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php $bSum = (int)($buildPerDate[$date] ?? 0); ?>
                            <th data-date="<?= htmlspecialchars($date) ?>">
                                <span style="font-size:10px;"><?= $bSum ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sticky-left stage-col">порезка</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php $cBales = (int)($cutBalesPerDate[$date] ?? 0); ?>
                            <th class="header-cut-bales" data-date="<?= htmlspecialchars($date) ?>">
                                <span style="font-size:10px;"><?= $cBales ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="sticky-left stage-col">гофрирование</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php $gSum = (int)($corrPerDate[$date] ?? 0); ?>
                            <th class="header-corr-sum" data-date="<?= htmlspecialchars($date) ?>">
                                <span style="font-size:10px;"><?= $gSum ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $assignedBaleIds = [];
                    foreach ($dateToBales as $list) {
                        foreach ($list as $bid) { $assignedBaleIds[$bid] = true; }
                    }
                    ?>
                    <?php foreach ($filterKeysOrdered as $blockIndex => $filterKey): ?>
                        <?php
                        $filterTitle = $allFiltersMap[$filterKey];
                        $baleIdsForFilter = $filtersBaleIds[$filterKey] ?? [];
                        $totalBuild = (int)($buildTotalByFilter[$filterKey] ?? 0);
                        $totalCut = array_sum($cutMatrix[$filterKey] ?? []);
                        $totalCorr = array_sum($corrMatrix[$filterKey] ?? []);
                        $pctBuild = ($totalBuild > 0) ? 100 : 0;
                        $pctCut = ($totalBuild > 0 && $totalCut > 0) ? min(100, round($totalCut / $totalBuild * 100)) : 0;
                        $pctCorr = ($totalBuild > 0 && $totalCorr > 0) ? min(100, round($totalCorr / $totalBuild * 100)) : 0;
                        $heightsForFilter = $filterHeights[$filterKey] ?? [];
                        ?>
                        <tr class="row-build" data-block-index="<?= (int)$blockIndex ?>" data-heights="<?= htmlspecialchars(implode(',', $heightsForFilter)) ?>">
                            <td class="sticky-left filter-name-cell" rowspan="3" data-filter-key="<?= htmlspecialchars($filterKey) ?>">
                                <strong><?= htmlspecialchars($filterTitle) ?></strong>
                                <?php if (!empty($baleIdsForFilter)): ?>
                                    <div class="filter-bale-dots">
                                        <?php foreach ($baleIdsForFilter as $bid): ?>
                                            <span class="filter-bale-dot<?= isset($assignedBaleIds[$bid]) ? ' assigned' : '' ?>" data-bale-id="<?= (int)$bid ?>"><?= (int)$bid ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="sticky-left stage-col<?= $pctBuild > 0 ? ' stage-bar-filled' : '' ?>">
                                сборка
                                <span class="stage-stat">
                                    <?= $totalBuild ?> из <?= $totalBuild ?>
                                </span>
                                <span class="stage-bar" style="--p: <?= $pctBuild ?>;">
                                    <span class="stage-bar-fill"></span>
                                </span>
                            </td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($buildMatrix[$filterKey][$date] ?? 0); $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center <?= $weekend ? 'weekend' : '' ?> <?= $qty === 0 ? 'zero' : '' ?>" data-date="<?= htmlspecialchars($date) ?>"><?= $qty !== 0 ? $qty : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="row-cut" data-block-index="<?= (int)$blockIndex ?>" data-filter-key="<?= htmlspecialchars($filterKey) ?>">
                            <td class="sticky-left stage-col<?= $pctCut > 0 ? ' stage-bar-filled' : '' ?>">
                                порезка
                                <span class="stage-stat">
                                    <?= (int)$totalCut ?> из <?= $totalBuild ?>
                                </span>
                                <span class="stage-bar" style="--p: <?= $pctCut ?>;">
                                    <span class="stage-bar-fill"></span>
                                </span>
                            </td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $val = $cutMatrix[$filterKey][$date] ?? 0; $qty = is_numeric($val) ? (float)$val : 0; $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center cut-cell <?= $weekend ? 'weekend' : '' ?> <?= $qty == 0 ? 'zero' : '' ?>" data-date="<?= htmlspecialchars($date) ?>"><?= $qty != 0 ? ($qty == (int)$qty ? (int)$qty : number_format($qty, 1)) : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="row-corr" data-block-index="<?= (int)$blockIndex ?>" data-filter-key="<?= htmlspecialchars($filterKey) ?>">
                            <td class="sticky-left stage-col<?= $pctCorr > 0 ? ' stage-bar-filled' : '' ?>">
                                гофрирование
                                <span class="stage-stat">
                                    <?= (int)$totalCorr ?> из <?= $totalBuild ?>
                                </span>
                                <span class="stage-bar" style="--p: <?= $pctCorr ?>;">
                                    <span class="stage-bar-fill"></span>
                                </span>
                            </td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($corrMatrix[$filterKey][$date] ?? 0); $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <td class="center <?= $weekend ? 'weekend' : '' ?> <?= $qty === 0 ? 'zero' : '' ?>" contenteditable="true" data-date="<?= htmlspecialchars($date) ?>"><?= $qty !== 0 ? $qty : '' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:8px; display:flex; justify-content:flex-end;">
                <button type="button" id="btnSavePlans" class="btn btn-sm">Сохранить планы</button>
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
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <h3 style="margin: 0;">Дата → набор бухт</h3>
            <button type="button" class="btn btn-sm secondary" id="btnClearPlan">Очистить план порезки</button>
        </div>
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
// скрываем индикатор, когда страница полностью готова
(function () {
    var el = document.getElementById('pageLoading');
    if (!el) return;
    function hide() { el.classList.add('is-hidden'); }
    if (document.readyState === 'complete') hide();
    window.addEventListener('load', hide, { once: true });
})();

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

    function clearColHover() {
        var tableRoot = table;
        tableRoot.querySelectorAll('td.col-hover, th.col-hover').forEach(function (cell) {
            cell.classList.remove('col-hover');
        });
    }

    tbody.addEventListener('mouseover', function (e) {
        var cell = e.target.closest('td,th');
        var tr = e.target.closest('tr');
        if (!tr || !tr.getAttribute('data-block-index') || !cell) return;
        highlightBlock(tr, true);

        // подсветка столбца по data-date (одинаково для сборки, порезки, гофрирования и заголовков)
        var date = cell.getAttribute('data-date');
        if (!date) {
            clearColHover();
            return;
        }
        clearColHover();
        var sel = 'td[data-date="' + date.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"], th[data-date="' + date.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]';
        table.querySelectorAll(sel).forEach(function (c) { c.classList.add('col-hover'); });
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
        if (!stillInside) {
            highlightBlock(tr, false);
            clearColHover();
        }
    });

    // Подсветка строк сборки по высоте шторы
    var heightButtons = document.querySelectorAll('.height-btn');
    function updateHeightButtonCounts() {
        var counts = {};
        table.querySelectorAll('tr.row-build').forEach(function (row) {
            var hs = (row.getAttribute('data-heights') || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
            hs.forEach(function (h) {
                counts[h] = (counts[h] || 0) + 1;
            });
        });
        heightButtons.forEach(function (btn) {
            var h = (btn.getAttribute('data-height') || '').trim();
            if (!h) return; // "Сброс"
            var n = counts[h] || 0;
            var base = btn.getAttribute('data-height');
            btn.innerHTML = String(base) + '<sup class="height-count">' + String(n) + '</sup>';
        });
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/fec3737a-7de3-4e7b-bbe9-58f5d30241f0',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'3dc4b9'},body:JSON.stringify({sessionId:'3dc4b9',runId:'pre-fix',hypothesisId:'H1',location:'plan_U3/NP_roll_plan_2.php:updateHeightButtonCounts',message:'height button counts computed',data:{buttons:heightButtons.length,distinct:Object.keys(counts).length},timestamp:Date.now()})}).catch(()=>{});
        // #endregion agent log
    }
    function applyHeightFilter(hVal) {
        var target = (hVal || '').trim();
        // очищаем предыдущую подсветку по дням в строках гофрирования
        table.querySelectorAll('tr.row-corr td.corr-day-highlight').forEach(function (td) {
            td.classList.remove('corr-day-highlight');
        });
        if (!target) return;

        table.querySelectorAll('tr.row-build').forEach(function (row) {
            var hs = (row.getAttribute('data-heights') || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
            var match = hs.indexOf(target) !== -1;
            if (!match) return;

            var blockIndex = row.getAttribute('data-block-index');
            if (!blockIndex) return;
            var corrRow = table.querySelector('tr.row-corr[data-block-index="' + String(blockIndex).replace(/"/g, '\\"') + '"]');
            if (!corrRow) return;
            // подсвечиваем ячейки по дням (все td с data-date)
            corrRow.querySelectorAll('td[data-date]').forEach(function (td) {
                var t = (td.textContent || '').trim();
                if (t !== '') td.classList.add('corr-day-highlight');
            });
        });
    }
    heightButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var val = btn.getAttribute('data-height') || '';
            if (val === '') {
                heightButtons.forEach(function (b) { b.classList.remove('active'); });
                applyHeightFilter('');
                return;
            }
            var isActive = btn.classList.contains('active');
            heightButtons.forEach(function (b) { b.classList.remove('active'); });
            if (!isActive) {
                btn.classList.add('active');
                applyHeightFilter(val);
            } else {
                applyHeightFilter('');
            }
        });
    });
    updateHeightButtonCounts();
})();

(function () {
    var dateToBales = <?= json_encode($dateToBales) ?>;
    var columnDates = <?= json_encode($columnDates) ?>;
    var baleIds = <?= json_encode($baleIds) ?>;
    var baleSupplyMap = <?= json_encode($baleSupplyMap) ?>;
    var filterKeysOrdered = <?= json_encode(array_values($filterKeysOrdered)) ?>;
    var buildTotalByFilter = <?= json_encode($buildTotalByFilter) ?>;
    var filterDisplayMap = <?= json_encode($allFiltersMap, JSON_UNESCAPED_UNICODE) ?>;
    var orderNumber = <?= json_encode($order) ?>;
    var startDateVal = <?= json_encode($startDate) ?>;

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
                chip.title = 'Двойной щелчок — вернуть в буфер';
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

    function getAssignedBaleIds() {
        var set = {};
        columnDates.forEach(function (d) {
            (dateToBales[d] || []).forEach(function (bid) { set[bid] = true; });
        });
        return set;
    }

    function updateFilterBaleDots() {
        var assigned = getAssignedBaleIds();
        document.querySelectorAll('.filter-bale-dot').forEach(function (dot) {
            var bid = parseInt(dot.getAttribute('data-bale-id'), 10);
            dot.classList.toggle('assigned', !!assigned[bid]);
        });
    }

    function recalcCutMatrixAndUpdate() {
        var cutMatrix = {};
        columnDates.forEach(function (date) {
            (dateToBales[date] || []).forEach(function (bid) {
                var supply = baleSupplyMap[String(bid)] || baleSupplyMap[bid] || {};
                Object.keys(supply).forEach(function (fkey) {
                    if (!cutMatrix[fkey]) cutMatrix[fkey] = {};
                    cutMatrix[fkey][date] = (cutMatrix[fkey][date] || 0) + (supply[fkey] || 0);
                });
            });
        });
        var planTable = document.getElementById('planTable');
        if (!planTable) return;

        // Обновляем ячейки порезки по дням
        planTable.querySelectorAll('tr.row-cut .cut-cell').forEach(function (cell) {
            var row = cell.closest('tr');
            var fkey = row ? row.getAttribute('data-filter-key') : '';
            var date = cell.getAttribute('data-date');
            if (!fkey || !date) return;
            var val = (cutMatrix[fkey] && cutMatrix[fkey][date]) || 0;
            var qty = Number(val);
            cell.textContent = qty !== 0 ? (qty === (qty | 0) ? String(qty | 0) : qty.toFixed(1)) : '';
            cell.classList.toggle('zero', qty === 0);
        });

        // Обновляем суммарные цифры "X из Y" и гистограмму для порезки
        planTable.querySelectorAll('tr.row-cut').forEach(function (row) {
            var fkey = row.getAttribute('data-filter-key');
            if (!fkey) return;
            var totalBuild = Number(buildTotalByFilter[fkey] || 0);
            var sumCut = 0;
            if (cutMatrix[fkey]) {
                Object.keys(cutMatrix[fkey]).forEach(function (d) {
                    sumCut += Number(cutMatrix[fkey][d] || 0);
                });
            }
            var stat = row.querySelector('.stage-stat');
            var bar = row.querySelector('.stage-bar');
            if (stat) {
                stat.textContent = (sumCut | 0) + ' из ' + (totalBuild | 0);
            }
            if (bar) {
                var p = (totalBuild > 0) ? Math.min(100, (sumCut / totalBuild) * 100) : 0;
                bar.style.setProperty('--p', String(p));
                var stageTd = bar.closest('.stage-col');
                if (stageTd) {
                    if (p > 0) stageTd.classList.add('stage-bar-filled'); else stageTd.classList.remove('stage-bar-filled');
                }
            }
        });

        // Обновляем строку заголовка "порезка" по дням
        var headerCutCells = planTable.querySelectorAll('thead .header-cut-bales');
        headerCutCells.forEach(function (th) {
            var date = th.getAttribute('data-date');
            if (!date) return;
            var cnt = (dateToBales[date] || []).filter(function (v, i, a) { return a.indexOf(v) === i; }).length;
            var span = th.querySelector('span');
            if (span) {
                span.textContent = String(cnt);
            } else {
                th.textContent = String(cnt);
            }
        });
    }

    function updateCorrHeader() {
        var planTable = document.getElementById('planTable');
        if (!planTable) return;
        var headerCells = planTable.querySelectorAll('thead .header-corr-sum');
        headerCells.forEach(function (th) {
            var date = th.getAttribute('data-date');
            if (!date) return;
            var sum = 0;
            var details = [];
            planTable.querySelectorAll('tr.row-corr').forEach(function (row) {
                var td = row.querySelector('td[data-date="' + date.replace(/"/g, '\\"') + '"]');
                if (!td) return;
                var v = td.textContent.trim();
                if (v === '') return;
                var n = parseInt(v, 10);
                if (isNaN(n) || n <= 0) return;
                sum += n;
                var fkey = row.getAttribute('data-filter-key') || '';
                var filterName = filterDisplayMap[fkey] || fkey || 'Позиция';
                details.push(filterName + ': ' + String(n));
            });
            var span = th.querySelector('span');
            if (span) {
                span.textContent = String(sum);
            } else {
                th.textContent = String(sum);
            }
            th.title = details.length ? details.join('\n') : 'Нет гофрирования в этот день';
        });
        // #region agent log
        fetch('http://127.0.0.1:7242/ingest/fec3737a-7de3-4e7b-bbe9-58f5d30241f0',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'3dc4b9'},body:JSON.stringify({sessionId:'3dc4b9',runId:'pre-fix',hypothesisId:'H3',location:'plan_U3/NP_roll_plan_2.php:updateCorrHeader',message:'corr header updated',data:{cells:headerCells.length},timestamp:Date.now()})}).catch(()=>{});
        // #endregion agent log
    }

    function updateCorrPaperHeightHeader() {
        var planTable = document.getElementById('planTable');
        if (!planTable) return;
        var headerCells = planTable.querySelectorAll('thead .header-paper-height');
        if (!headerCells.length) return;

        // blockIndex -> heights[]
        var heightsByBlock = {};
        planTable.querySelectorAll('tr.row-build').forEach(function (row) {
            var bi = row.getAttribute('data-block-index');
            if (!bi) return;
            heightsByBlock[bi] = (row.getAttribute('data-heights') || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
        });

        headerCells.forEach(function (th) {
            var date = th.getAttribute('data-date');
            if (!date) return;
            var set = {};
            planTable.querySelectorAll('tr.row-corr').forEach(function (r) {
                var bi = r.getAttribute('data-block-index');
                if (!bi) return;
                var td = r.querySelector('td[data-date="' + date.replace(/"/g, '\\"') + '"]');
                if (!td) return;
                var v = (td.textContent || '').trim();
                if (v === '') return;
                var n = parseInt(v, 10);
                if (isNaN(n) || n <= 0) return;
                (heightsByBlock[bi] || []).forEach(function (h) { set[h] = true; });
            });
            var list = Object.keys(set);
            list.sort(function (a, b) { return (parseFloat(a) || 0) - (parseFloat(b) || 0); });
            var text = list.join('\n');
            var span = th.querySelector('span');
            if (span) span.textContent = text; else th.textContent = text;
            th.title = list.join(', ');
        });
    }

    // Пересчёт гофрирования при ручном вводе в ячейки
    function setupCorrEditableHandlers() {
        var planTable = document.getElementById('planTable');
        if (!planTable) return;
        planTable.querySelectorAll('tr.row-corr').forEach(function (row) {
            var stat = row.querySelector('.stage-stat');
            var bar = row.querySelector('.stage-bar');
            var tds = Array.prototype.slice.call(row.querySelectorAll('td')).slice(1); // пропускаем ячейку Этап
            var baseTotal = 0;
            if (stat) {
                // Пытаемся вытащить "N из M" и запомнить M
                var m = stat.textContent.match(/из\s+(\d+)/);
                if (m && m[1]) {
                    baseTotal = parseInt(m[1], 10) || 0;
                }
                stat.dataset.total = String(baseTotal);
            }
            function recompute() {
                var sum = 0;
                tds.forEach(function (td) {
                    var v = td.textContent.trim();
                    if (v !== '') {
                        var n = parseInt(v, 10);
                        if (!isNaN(n)) sum += n;
                    }
                });
                var totalBuild = parseInt(stat ? (stat.dataset.total || '0') : '0', 10) || 0;
                if (stat) {
                    stat.textContent = (sum | 0) + ' из ' + totalBuild;
                }
                if (bar) {
                    var p = (totalBuild > 0) ? Math.min(100, (sum / totalBuild) * 100) : 0;
                    bar.style.setProperty('--p', String(p));
                    var stageCol = bar.closest('.stage-col');
                    if (stageCol) {
                        if (p > 0) stageCol.classList.add('stage-bar-filled'); else stageCol.classList.remove('stage-bar-filled');
                    }
                }
                updateCorrHeader();
                updateCorrPaperHeightHeader();
                // #region agent log
                fetch('http://127.0.0.1:7242/ingest/fec3737a-7de3-4e7b-bbe9-58f5d30241f0',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'3dc4b9'},body:JSON.stringify({sessionId:'3dc4b9',runId:'pre-fix',hypothesisId:'H1',location:'plan_U3/NP_roll_plan_2.php:recompute(corr)',message:'corr recompute',data:{filterKey:row.getAttribute('data-filter-key')||null,sum:sum,totalBuild:totalBuild},timestamp:Date.now()})}).catch(()=>{});
                // #endregion agent log
            }
            tds.forEach(function (td) {
                td.addEventListener('input', recompute);
                td.addEventListener('blur', recompute);
            });
            // начальная синхронизация заголовка "гофрирование"
            recompute();
        });
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
            chip.ondblclick = function () {
                var baleId = parseInt(chip.getAttribute('data-bale-id'), 10);
                if (!baleId) return;
                removeBaleFromAllDates(baleId);
                reRenderBalesCells();
                reRenderTopCircles();
                recalcCutMatrixAndUpdate();
                updateFilterBaleDots();
            };
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
        recalcCutMatrixAndUpdate();
        updateFilterBaleDots();
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
            recalcCutMatrixAndUpdate();
            updateFilterBaleDots();
        });
    });

    dateBalesTable.querySelectorAll('tbody tr').forEach(function (tr) {
        var cell = tr.querySelector('.bales-cell');
        if (cell) bindChipDrag(cell);
    });

    var btnClearPlan = document.getElementById('btnClearPlan');
    if (btnClearPlan) {
        btnClearPlan.onclick = function () {
            columnDates.forEach(function (d) { dateToBales[d] = []; });
            reRenderBalesCells();
            reRenderTopCircles();
            recalcCutMatrixAndUpdate();
            updateFilterBaleDots();
        };
    }

    reRenderTopCircles();
    setupCorrEditableHandlers();

    var btnSavePlans = document.getElementById('btnSavePlans');
    if (btnSavePlans) {
        btnSavePlans.addEventListener('click', function () {
            saveRollPlans().then(function () {
                return saveCorrPlans();
            }).then(function () {
                alert('Планы порезки и гофрирования сохранены.');
            }).catch(function (e) {
                console.error(e);
                alert('Ошибка сохранения планов.');
            });
        });
    }

    function saveRollPlans() {
        var planDates = {};
        columnDates.forEach(function (date) {
            (dateToBales[date] || []).forEach(function (bid) {
                planDates[bid] = date;
            });
        });
        var fd = new FormData();
        fd.append('order', orderNumber);
        fd.append('start_date', startDateVal);
        fd.append('action', 'save');
        Object.keys(planDates).forEach(function (bid) {
            fd.append('plan_dates[' + bid + ']', planDates[bid]);
        });
        return fetch('NP_roll_plan.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.text(); }).then(function (text) {
            try {
                var data = JSON.parse(text);
                if (!data.ok) throw new Error(data.message || 'Ошибка сохранения плана порезки');
            } catch (e) {
                // если это не JSON, просто считаем что всё прошло
            }
        });
    }

    function saveCorrPlans() {
        var items = [];
        var planTable = document.getElementById('planTable');
        if (!planTable) return Promise.resolve();
        planTable.querySelectorAll('tr.row-corr').forEach(function (row) {
            var fkey = row.getAttribute('data-filter-key');
            var filterName = filterDisplayMap[fkey] || fkey;
            row.querySelectorAll('td[contenteditable="true"]').forEach(function (td) {
                var date = td.getAttribute('data-date');
                var v = td.textContent.trim();
                if (!date || v === '') return;
                var n = parseInt(v, 10);
                if (isNaN(n) || n <= 0) return;
                items.push({ filter: filterName, date: date, qty: n });
            });
        });
        if (items.length === 0) return Promise.resolve();
        var payload = {
            order: orderNumber,
            start: startDateVal,
            days: columnDates.length,
            items: items
        };
        return fetch('NP_corrugation_plan.php?action=save_corr', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data.ok) throw new Error(data.error || 'Ошибка сохранения плана гофрирования');
        }).catch(function (e) {
            // если сервер вернул не JSON — не падаем, но логируем
            console.error('save_corr error', e);
        });
    }
})();
</script>
</body>
</html>
