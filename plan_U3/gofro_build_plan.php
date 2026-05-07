<?php
/**
 * Планирование сборки гофропакетов (визуализация покрытия как диаграмма Ганта).
 */

require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';
require_once __DIR__ . '/../auth/includes/db.php';

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

$pdo = getPdo('plan_u3');
$todayIso = (new DateTime())->format('Y-m-d');
$loadError = '';

function normalizeFilterKeyLocal(string $name): string
{
    $name = preg_replace('/\[.*$/u', '', $name);
    $name = trim($name);
    return mb_strtoupper($name, 'UTF-8');
}

function normalizeTextKeyLocal(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name));
    return mb_strtoupper($name, 'UTF-8');
}

function ensureCorrugationPlanV2Table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS corrugation_plan_v2 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_row_key VARCHAR(255) NOT NULL,
            order_number VARCHAR(64) NOT NULL,
            filter_name VARCHAR(255) NOT NULL,
            package_key VARCHAR(255) NOT NULL,
            package_name VARCHAR(255) NOT NULL,
            plan_date DATE NOT NULL,
            group_id VARCHAR(128) NOT NULL,
            strip_id VARCHAR(128) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cp2_row_date (source_row_key, plan_date),
            KEY idx_cp2_order (order_number),
            KEY idx_cp2_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if (isset($_GET['api']) && $_GET['api'] === 'load_plan_v2') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        ensureCorrugationPlanV2Table($pdo);
        $stmt = $pdo->query("
            SELECT
                source_row_key,
                order_number,
                filter_name,
                package_key,
                package_name,
                plan_date,
                group_id,
                strip_id,
                qty
            FROM corrugation_plan_v2
            WHERE qty > 0
            ORDER BY plan_date, source_row_key, group_id, id
        ");
        echo json_encode(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'save_plan_v2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        ensureCorrugationPlanV2Table($pdo);
        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM corrugation_plan_v2");
        $ins = $pdo->prepare("
            INSERT INTO corrugation_plan_v2
            (source_row_key, order_number, filter_name, package_key, package_name, plan_date, group_id, strip_id, qty, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $userId = isset($session['user_id']) ? (int)$session['user_id'] : null;
        $saved = 0;
        foreach ($items as $it) {
            $rowKey = trim((string)($it['source_row_key'] ?? ''));
            $order = trim((string)($it['order_number'] ?? ''));
            $filterName = trim((string)($it['filter_name'] ?? ''));
            $packageKey = trim((string)($it['package_key'] ?? ''));
            $packageName = trim((string)($it['package_name'] ?? ''));
            $planDate = trim((string)($it['plan_date'] ?? ''));
            $groupId = trim((string)($it['group_id'] ?? ''));
            $stripId = trim((string)($it['strip_id'] ?? ''));
            $qty = (int)($it['qty'] ?? 0);
            if (
                $rowKey === '' || $order === '' || $filterName === '' || $packageKey === '' ||
                $planDate === '' || $groupId === '' || $stripId === '' || $qty <= 0 ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)
            ) {
                continue;
            }
            $ins->execute([$rowKey, $order, $filterName, $packageKey, $packageName, $planDate, $groupId, $stripId, $qty, $userId]);
            $saved++;
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo json_encode(['ok' => true, 'saved' => $saved], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$rows = [];
try {
    $sql = "
        SELECT
            agg.order_number,
            agg.filter_name,
            agg.ordered,
            COALESCE(prod.produced, 0) AS produced
        FROM (
            SELECT order_number, `filter` AS filter_name, SUM(`count`) AS ordered
            FROM orders
            WHERE (hide IS NULL OR hide != 1)
            GROUP BY order_number, `filter`
        ) agg
        LEFT JOIN (
            SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
            FROM manufactured_production
            GROUP BY name_of_order, name_of_filter
        ) prod
            ON prod.name_of_order = agg.order_number
           AND prod.name_of_filter = agg.filter_name
        WHERE agg.ordered > COALESCE(prod.produced, 0)
          AND agg.ordered > 0
        ORDER BY agg.order_number, agg.filter_name
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$buildPlanMap = [];
$buildPlanDates = [];
try {
    $sqlBuildPlan = "
        SELECT
            bp.order_number,
            bp.filter AS filter_name,
            bp.day_date,
            SUM(bp.qty) AS qty
        FROM build_plans bp
        INNER JOIN (
            SELECT DISTINCT order_number
            FROM orders
            WHERE (hide IS NULL OR hide != 1)
        ) ao ON ao.order_number = bp.order_number
        WHERE bp.shift = 'D'
        GROUP BY bp.order_number, bp.filter, bp.day_date
        ORDER BY bp.day_date, bp.order_number, bp.filter
    ";
    $planRows = $pdo->query($sqlBuildPlan)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($planRows as $pr) {
        $order = (string)($pr['order_number'] ?? '');
        $filter = (string)($pr['filter_name'] ?? '');
        $date = (string)($pr['day_date'] ?? '');
        $qty = (int)($pr['qty'] ?? 0);
        if ($order === '' || $filter === '' || $date === '' || $date < $todayIso) {
            continue;
        }
        $key = $order . '|' . normalizeFilterKeyLocal($filter);
        if (!isset($buildPlanMap[$key])) {
            $buildPlanMap[$key] = [];
        }
        if (!isset($buildPlanMap[$key][$date])) {
            $buildPlanMap[$key][$date] = 0;
        }
        $buildPlanMap[$key][$date] += $qty;
        $buildPlanDates[$date] = true;
    }
    $buildPlanDates = array_keys($buildPlanDates);
    sort($buildPlanDates);
    if (!empty($buildPlanDates)) {
        $fullDateRange = [];
        $cursor = new DateTimeImmutable((string)$buildPlanDates[0]);
        $lastDate = new DateTimeImmutable((string)$buildPlanDates[count($buildPlanDates) - 1]);
        while ($cursor <= $lastDate) {
            $fullDateRange[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $buildPlanDates = $fullDateRange;
    }
} catch (Throwable $e) {
    $buildPlanMap = [];
    $buildPlanDates = [];
}

$filterMetaByKey = [];
if (!empty($rows)) {
    $rawFilters = [];
    foreach ($rows as $row) {
        $raw = trim((string)($row['filter_name'] ?? ''));
        if ($raw !== '') {
            $rawFilters[$raw] = true;
        }
    }
    if (!empty($rawFilters)) {
        try {
            $rawFilterList = array_keys($rawFilters);
            $placeholders = implode(',', array_fill(0, count($rawFilterList), '?'));
            $sqlMeta = "
                SELECT
                    rfs.filter AS filter_name,
                    rfs.filter_package AS filter_package,
                    ppr.p_p_paper_width AS paper_width_mm,
                    ppr.p_p_fold_height AS fold_height,
                    ppr.p_p_fold_count AS fold_count
                FROM round_filter_structure rfs
                LEFT JOIN paper_package_round ppr
                    ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                WHERE rfs.filter IN ($placeholders)
            ";
            $stmtMeta = $pdo->prepare($sqlMeta);
            $stmtMeta->execute($rawFilterList);
            foreach ($stmtMeta->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                $metaKey = normalizeFilterKeyLocal((string)($metaRow['filter_name'] ?? ''));
                if ($metaKey === '') {
                    continue;
                }
                if (!isset($filterMetaByKey[$metaKey])) {
                    $filterMetaByKey[$metaKey] = [
                        'filter_package' => null,
                        'paper_width_mm' => null,
                        'fold_height' => null,
                        'fold_count' => null,
                    ];
                }
                $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                if ($pkg !== '' && $filterMetaByKey[$metaKey]['filter_package'] === null) {
                    $filterMetaByKey[$metaKey]['filter_package'] = $pkg;
                }
                if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$metaKey]['paper_width_mm'] === null) {
                    $filterMetaByKey[$metaKey]['paper_width_mm'] = (float)$metaRow['paper_width_mm'];
                }
                if ($metaRow['fold_height'] !== null && $filterMetaByKey[$metaKey]['fold_height'] === null) {
                    $filterMetaByKey[$metaKey]['fold_height'] = (float)$metaRow['fold_height'];
                }
                if ($metaRow['fold_count'] !== null && $filterMetaByKey[$metaKey]['fold_count'] === null) {
                    $filterMetaByKey[$metaKey]['fold_count'] = (float)$metaRow['fold_count'];
                }
            }
        } catch (Throwable $e) {
            $filterMetaByKey = [];
        }
    }
}

$gofroProducedByOrderPackage = [];
$packageCatalog = [];
if (!empty($rows) && !empty($filterMetaByKey)) {
    $ordersForGofro = [];
    $packagesForGofro = [];
    foreach ($rows as $row) {
        $rawOrder = trim((string)($row['order_number'] ?? ''));
        $rawFilter = (string)($row['filter_name'] ?? '');
        if ($rawOrder === '' || $rawFilter === '') {
            continue;
        }
        $meta = $filterMetaByKey[normalizeFilterKeyLocal($rawFilter)] ?? null;
        $packageName = trim((string)($meta['filter_package'] ?? ''));
        if ($packageName === '') {
            continue;
        }
        $packageKey = normalizeTextKeyLocal($packageName);
        if ($packageKey !== '' && !isset($packageCatalog[$packageKey])) {
            $packageCatalog[$packageKey] = $packageName;
        }
        $ordersForGofro[$rawOrder] = true;
        $packagesForGofro[$packageName] = true;
    }
    if (!empty($ordersForGofro) && !empty($packagesForGofro)) {
        try {
            $orderList = array_keys($ordersForGofro);
            $packageList = array_keys($packagesForGofro);
            $orderPlaceholders = implode(',', array_fill(0, count($orderList), '?'));
            $packagePlaceholders = implode(',', array_fill(0, count($packageList), '?'));
            $sqlGofroProduced = "
                SELECT
                    mp.name_of_order,
                    mp.name_of_parts,
                    SUM(COALESCE(mp.count_of_parts, 0)) AS qty
                FROM manufactured_parts mp
                WHERE mp.name_of_order IN ($orderPlaceholders)
                  AND mp.name_of_parts IN ($packagePlaceholders)
                GROUP BY mp.name_of_order, mp.name_of_parts
            ";
            $stmtGofroProduced = $pdo->prepare($sqlGofroProduced);
            $stmtGofroProduced->execute(array_merge($orderList, $packageList));
            foreach ($stmtGofroProduced->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                $order = trim((string)($gr['name_of_order'] ?? ''));
                $partKey = normalizeTextKeyLocal((string)($gr['name_of_parts'] ?? ''));
                if ($order === '' || $partKey === '') {
                    continue;
                }
                if (!isset($gofroProducedByOrderPackage[$order])) {
                    $gofroProducedByOrderPackage[$order] = [];
                }
                $gofroProducedByOrderPackage[$order][$partKey] = (int)($gr['qty'] ?? 0);
            }
        } catch (Throwable $e) {
            $gofroProducedByOrderPackage = [];
        }
    }
}

$pageTitle = 'Планирование сборки гофропакетов';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — U3</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2457e6;
            --ok-bg: #dcfce7;
            --ok-line: #16a34a;
            --warn-bg: #fef3c7;
            --warn-line: #d97706;
            --plan-bg: #dbeafe;
            --plan-line: #2563eb;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 13px/1.35 "Segoe UI", Roboto, Arial, sans-serif;
        }
        .wrap {
            max-width: 100%;
            margin: 0 auto;
            padding: 14px;
        }
        .layout {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 12px;
            align-items: start;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            /* Не задавать overflow: здесь ломает position:sticky у шапки при прокрутке страницы */
            overflow: visible;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
        }
        th, td {
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 2px 4px;
            white-space: nowrap;
        }
        th {
            background: #f9fafb;
            font-size: 11px;
        }
        thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            box-shadow: 0 1px 0 0 var(--border);
        }
        td.num, th.num { text-align: right; }
        th.num-left, td.num-left { text-align: left; }
        td.date-cell, th.date-col {
            text-align: left;
            min-width: 28px;
            width: 28px;
        }
        th.date-col {
            text-align: center;
        }
        thead th.date-col.weekend {
            color: #9ca3af;
        }
        .date-total-value {
            font-weight: 400;
        }
        td.date-cell {
            position: relative;
        }
        thead th.date-col.date-hover {
            z-index: 7;
            background: #e8ecfd;
            outline: 2px solid rgba(36, 87, 230, 0.5);
            outline-offset: -2px;
        }
        td.filter-name-cell.name-hover {
            background: #eef2ff;
            box-shadow: inset 3px 0 0 #4f46e5;
        }
        td.date-cell.drop-valid {
            outline: 2px dashed rgba(22, 163, 74, 0.65);
            outline-offset: -2px;
        }
        td.date-cell.drop-invalid {
            opacity: .45;
        }
        .cell-qty {
            position: absolute;
            left: 2px;
            top: 1px;
            font-size: 8px;
            line-height: 1;
            color: #94a3b8;
            font-weight: 500;
            pointer-events: none;
        }
        .cell-supply {
            position: absolute;
            right: 2px;
            bottom: 1px;
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 2px;
            max-width: calc(100% - 4px);
            pointer-events: auto;
        }
        .cell-supply-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 14px;
            height: 10px;
            padding: 0 3px;
            border: 1px solid #0ea5e9;
            border-radius: 2px;
            background: #e0f2fe;
            color: #075985;
            font-size: 8px;
            line-height: 1;
            font-weight: 700;
            box-sizing: border-box;
            white-space: nowrap;
            cursor: pointer;
        }
        .cell-supply-item:hover {
            background: #bae6fd;
            border-color: #0284c7;
        }
        td.date-cell.gantt-full {
            background: var(--ok-bg);
            box-shadow: inset 0 0 0 1px rgba(22, 163, 74, .2);
        }
        td.date-cell.gantt-partial {
            background: var(--warn-bg);
            box-shadow: inset 0 0 0 1px rgba(217, 119, 6, .2);
        }
        td.date-cell.gantt-plan-full {
            background: var(--plan-bg);
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .22);
        }
        td.date-cell.gantt-plan-partial {
            background: #eff6ff;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .35);
        }
        .muted {
            color: var(--muted);
        }
        .muted-light {
            color: #d1d5db;
        }
        .strip-pool {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
            position: sticky;
            top: 12px;
            max-height: calc(100vh - 24px);
            overflow: auto;
        }
        .strip-pool h3 {
            margin: 0 0 8px;
            font-size: 13px;
        }
        .strip-group {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px;
            margin-bottom: 10px;
            background: #f8fafc;
        }
        .strip-group__title {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 6px;
        }
        .strip {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px;
            margin-bottom: 6px;
            background: #fff;
            cursor: grab;
        }
        .strip:active { cursor: grabbing; }
        .strip.strip-selected {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.2);
        }
        .strip-meta {
            font-size: 11px;
            color: #334155;
            display: flex;
            justify-content: space-between;
            gap: 6px;
        }
        .drag-preview {
            position: fixed;
            z-index: 9999;
            pointer-events: none;
            background: #111827;
            color: #fff;
            font-size: 11px;
            border-radius: 6px;
            padding: 4px 6px;
            box-shadow: 0 6px 16px rgba(0,0,0,.25);
            display: none;
            white-space: nowrap;
        }
        @media (max-width: 1200px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .strip-pool {
                position: static;
                max-height: none;
            }
        }
        th.row-dismiss-col, td.row-dismiss-col {
            width: 28px;
            min-width: 28px;
            max-width: 28px;
            text-align: center;
            padding: 2px;
            vertical-align: middle;
        }
        .row-dismiss-btn {
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 16px;
            line-height: 1;
            padding: 0 2px;
            cursor: pointer;
            border-radius: 4px;
        }
        .row-dismiss-btn:hover {
            color: #b91c1c;
            background: #fee2e2;
        }
        .row-pool-hint {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 12px;
            height: 12px;
            margin-left: 4px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            color: #94a3b8;
            font-size: 9px;
            line-height: 1;
            vertical-align: middle;
            cursor: help;
            user-select: none;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1 style="margin:0 0 8px; font-size:20px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <div style="display:flex; gap:8px; align-items:center; margin:0 0 10px;">
        <button id="save-plan-btn" type="button" style="border:1px solid #2563eb; background:#2563eb; color:#fff; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">
            Сохранить план V2
        </button>
        <button id="reset-dismissed-btn" type="button" style="border:1px solid #475569; background:#fff; color:#334155; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">
            Сбросить скрытые позиции
        </button>
        <button id="task-sheet-btn" type="button" style="border:1px solid #0f766e; background:#0f766e; color:#fff; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">
            Задание
        </button>
        <span id="save-plan-status" class="muted" style="font-size:12px;"></span>
    </div>
    <p class="muted" style="margin:0 0 12px;">
        Линия/заливка показывает покрытие смен оставшимися гофропакетами. Число сборки фильтров показано маленьким фоновым текстом в левом верхнем углу ячейки.
    </p>
    <?php if ($loadError !== ''): ?>
        <div class="panel" style="padding:12px;">Ошибка загрузки: <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="layout">
        <div class="panel">
            <table>
                <thead>
                <tr>
                    <th class="row-dismiss-col" title="Скрыть позицию (только у вас в браузере)"></th>
                    <th>Фильтр</th>
                    <th>Заявка</th>
                    <th class="num-left">Остаток фильтров</th>
                    <th class="num">Г/п изготовлено</th>
                    <th class="num">Г/п доступно</th>
                    <th class="num">Потребность в г/п</th>
                    <?php foreach ($buildPlanDates as $planDate): ?>
                        <?php
                            $d = DateTime::createFromFormat('Y-m-d', (string)$planDate);
                            $isWeekend = $d ? in_array((int)$d->format('N'), [6, 7], true) : false;
                            $dateColClass = $isWeekend ? 'date-col weekend' : 'date-col';
                        ?>
                        <th class="<?= htmlspecialchars($dateColClass, ENT_QUOTES, 'UTF-8') ?>" data-date-total="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>" title="Суммарно распределено гофропакетов из пула: 0">
                            <?= htmlspecialchars($d ? $d->format('d.m') : (string)$planDate, ENT_QUOTES, 'UTF-8') ?><br>
                            <span class="muted date-total-value" style="font-size:10px;">0</span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= 7 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
                            Нет данных для отображения.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $rawOrder = (string)($r['order_number'] ?? '');
                        $rawFilter = (string)($r['filter_name'] ?? '');
                        $ordered = (int)($r['ordered'] ?? 0);
                        $produced = (int)($r['produced'] ?? 0);
                        $remaining = max(0, $ordered - $produced);
                        $planKey = $rawOrder . '|' . normalizeFilterKeyLocal($rawFilter);
                        // Уникальный ключ строки для DnD/сохранения: без обрезки суффиксов в [...]
                        // Иначе разные позиции одной заявки могут схлопываться в один rowKey.
                        $rowKey = $rawOrder . '|' . normalizeTextKeyLocal($rawFilter);
                        $planQtyByDate = $buildPlanMap[$planKey] ?? [];

                        $meta = $filterMetaByKey[normalizeFilterKeyLocal($rawFilter)] ?? null;
                        $package = trim((string)($meta['filter_package'] ?? ''));
                        $packageKey = normalizeTextKeyLocal($package);
                        $gofroProduced = $packageKey !== '' && $rawOrder !== ''
                            ? (int)($gofroProducedByOrderPackage[$rawOrder][$packageKey] ?? 0)
                            : 0;
                        $gofroAvailable = $gofroProduced - $produced;
                        $gofroNeed = max(0, $remaining - $gofroAvailable);
                        $foldHeight = (float)($meta['fold_height'] ?? 0);
                        $foldCount = (float)($meta['fold_count'] ?? 0);
                        $paperWidthMm = (float)($meta['paper_width_mm'] ?? 0);
                        $packLengthM = ($foldHeight > 0 && $foldCount > 0)
                            ? (($foldHeight * 2 + 1) * $foldCount) / 1000
                            : 0.0;
                        $packagesPerRoll = $packLengthM > 0 ? (int)floor(600 / $packLengthM) : 0;
                        $poolHintReason = '';
                        if ($packageKey === '') {
                            $poolHintReason = 'В пул не попадет: для фильтра не задан гофропакет в справочнике round_filter_structure.';
                        } elseif ($gofroNeed <= 0) {
                            $poolHintReason = 'В пул не попадет: потребность в г/п равна 0 (доступного г/п уже хватает).';
                        } elseif ($packagesPerRoll <= 0) {
                            $poolHintReason = 'В пул не попадет: не рассчитано количество г/п с рулона (проверьте параметры в paper_package_round: высота/число ребер).';
                        }

                        $coverageNeed = max(0, $gofroAvailable);
                        $coverageEndIdx = -1;
                        $coveragePartialIdx = -1;
                        if ($coverageNeed > 0 && !empty($buildPlanDates)) {
                            $acc = 0;
                            foreach ($buildPlanDates as $idx => $dKey) {
                                $cellQty = (int)($planQtyByDate[$dKey] ?? 0);
                                if ($cellQty <= 0) {
                                    continue;
                                }
                                if ($acc < $coverageNeed && $acc + $cellQty >= $coverageNeed) {
                                    $coverageEndIdx = (int)$idx;
                                    if ($acc + $cellQty > $coverageNeed) {
                                        $coveragePartialIdx = (int)$idx;
                                    }
                                    break;
                                }
                                $acc += $cellQty;
                            }
                            if ($coverageEndIdx < 0) {
                                // Покрытие хватает на весь текущий горизонт.
                                $coverageEndIdx = count($buildPlanDates) - 1;
                            }
                        }
                    ?>
                        <tr
                            data-row-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                            data-filter-name="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                            data-paper-width-mm="<?= htmlspecialchars((string)$paperWidthMm, ENT_QUOTES, 'UTF-8') ?>"
                            data-fold-height="<?= htmlspecialchars((string)$foldHeight, ENT_QUOTES, 'UTF-8') ?>"
                            data-fold-count="<?= htmlspecialchars((string)$foldCount, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-key="<?= htmlspecialchars($packageKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-name="<?= htmlspecialchars($package, ENT_QUOTES, 'UTF-8') ?>"
                            data-base-available="<?= (int)$gofroAvailable ?>"
                            data-gofro-need="<?= (int)$gofroNeed ?>"
                            data-packages-per-roll="<?= (int)$packagesPerRoll ?>"
                        >
                            <td class="row-dismiss-col">
                                <button type="button" class="row-dismiss-btn" data-dismiss-row="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>" title="Скрыть позицию и убрать её полосы из пула (сохраняется в браузере)" aria-label="Скрыть позицию">×</button>
                            </td>
                            <td class="filter-name-cell">
                                <?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($poolHintReason !== ''): ?>
                                    <span class="row-pool-hint" title="<?= htmlspecialchars($poolHintReason, ENT_QUOTES, 'UTF-8') ?>">i</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num-left" title="Остаток к производству из заказанного по позиции"><?= (int)$remaining ?><span class="muted-light"> из <?= (int)$ordered ?></span></td>
                            <td class="num"><?= $gofroProduced ?></td>
                            <td class="num"><?= $gofroAvailable ?></td>
                            <td
                                class="num"
                                title="Остаток фильтров: <?= (int)$remaining ?> из <?= (int)$ordered ?>; доступно г/п: <?= (int)$gofroAvailable ?>; потребность: <?= (int)$gofroNeed ?>"
                            ><?= (int)$gofroNeed ?></td>
                            <?php foreach ($buildPlanDates as $idx => $planDate):
                                $qty = (int)($planQtyByDate[$planDate] ?? 0);
                                $classes = ['date-cell'];
                                if ($coverageEndIdx >= 0 && $idx <= $coverageEndIdx) {
                                    if ($idx === $coveragePartialIdx) {
                                        $classes[] = 'gantt-partial';
                                    } else {
                                        $classes[] = 'gantt-full';
                                    }
                                }
                            ?>
                                <td class="<?= htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') ?>"
                                    data-date="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>"
                                    data-plan-qty="<?= (int)$qty ?>">
                                    <?php if ($qty > 0): ?><span class="cell-qty"><?= (int)$qty ?></span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <aside class="strip-pool">
            <h3>Пул полос</h3>
            <div id="strip-pool"></div>
        </aside>
        </div>
    <?php endif; ?>
</div>
<div id="drag-preview" class="drag-preview"></div>
<div id="task-sheet-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:10000; align-items:center; justify-content:center; padding:14px;">
    <div style="background:#fff; border-radius:12px; width:360px; max-width:100%; box-shadow:0 16px 40px rgba(2,6,23,.25); border:1px solid #e2e8f0;">
        <div style="padding:12px; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:14px;">Печать задания сборщицам</div>
        <div style="padding:12px; display:grid; gap:8px;">
            <label style="font-size:12px; color:#334155;">
                С даты
                <input id="task-date-from" type="date" style="display:block; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:6px 8px; margin-top:4px;">
            </label>
            <label style="font-size:12px; color:#334155;">
                По дату
                <input id="task-date-to" type="date" style="display:block; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:6px 8px; margin-top:4px;">
            </label>
        </div>
        <div style="padding:10px 12px; display:flex; justify-content:flex-end; gap:8px; border-top:1px solid #e2e8f0;">
            <button id="task-sheet-cancel" type="button" style="border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">Отмена</button>
            <button id="task-sheet-print" type="button" style="border:1px solid #0f766e; background:#0f766e; color:#fff; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">Сформировать и печатать</button>
        </div>
    </div>
</div>
<?php if ($loadError === ''): ?>
<script>
(() => {
    const NORM_PER_UNIT = 2.5; // м на 1 гофропакет
    const DISMISSED_ROWS_STORAGE_KEY = 'gofroBuildPlanDismissedRowKeys';
    const packageCatalog = <?= json_encode($packageCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
    const stripPool = document.getElementById('strip-pool');
    const dragPreview = document.getElementById('drag-preview');
    const savePlanBtn = document.getElementById('save-plan-btn');
    const resetDismissedBtn = document.getElementById('reset-dismissed-btn');
    const savePlanStatus = document.getElementById('save-plan-status');
    const taskSheetBtn = document.getElementById('task-sheet-btn');
    const taskSheetModal = document.getElementById('task-sheet-modal');
    const taskDateFrom = document.getElementById('task-date-from');
    const taskDateTo = document.getElementById('task-date-to');
    const taskSheetCancel = document.getElementById('task-sheet-cancel');
    const taskSheetPrint = document.getElementById('task-sheet-print');
    if (!stripPool || !dragPreview) {
        return;
    }

    const rows = Array.from(document.querySelectorAll('tbody tr[data-row-key]'));
    const rowStateMap = new Map();
    function isTargetState(state) {
        const order = String(state && state.order ? state.order : '');
        const filter = String(state && state.filterName ? state.filterName : '');
        return order === 'TF-19-20-20-26-2' && filter.includes('TF 325*218*760*13');
    }
    rows.forEach((row) => {
        const state = {
            row,
            dismissed: false,
            order: String(row.dataset.order || ''),
            filterName: String(row.dataset.filterName || ''),
            paperWidthMm: Math.max(0, Number(row.dataset.paperWidthMm || 0) || 0),
            foldHeight: Math.max(0, Number(row.dataset.foldHeight || 0) || 0),
            foldCount: Math.max(0, Number(row.dataset.foldCount || 0) || 0),
            packageKey: String(row.dataset.packageKey || ''),
            packageName: String(row.dataset.packageName || ''),
            baseAvailable: parseInt(row.dataset.baseAvailable || '0', 10) || 0,
            gofroNeed: Math.max(0, parseInt(row.dataset.gofroNeed || '0', 10) || 0),
            packagesPerRoll: Math.max(0, parseInt(row.dataset.packagesPerRoll || '0', 10) || 0),
            dateCells: Array.from(row.querySelectorAll('td.date-cell[data-date]')),
            allocatedByDate: {},
            allocatedObjectsByDate: {},
        };
        rowStateMap.set(row.dataset.rowKey, state);
    });

    function loadDismissedRowKeys() {
        try {
            const raw = localStorage.getItem(DISMISSED_ROWS_STORAGE_KEY);
            if (!raw) {
                return new Set();
            }
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return new Set();
            }
            return new Set(parsed.filter((k) => typeof k === 'string' && k));
        } catch {
            return new Set();
        }
    }

    function persistDismissedRowKeys(set) {
        try {
            localStorage.setItem(DISMISSED_ROWS_STORAGE_KEY, JSON.stringify([...set]));
        } catch (_) {
            /* ignore quota */
        }
    }

    let dismissedRowKeys = loadDismissedRowKeys();

    function applyStoredDismissedRows() {
        dismissedRowKeys.forEach((rowKey) => {
            const state = rowStateMap.get(rowKey);
            if (!state) {
                return;
            }
            state.dismissed = true;
            state.row.style.display = 'none';
        });
    }

    let strips = [];
    let stripSeq = 1;
    let allocationGroupSeq = 1;
    let allocations = [];
    let dragContext = null;
    const supplyMap = {};
    let selectedSourceRow = '';
    const selectedStripIds = new Set();
    const dateHeaderTotals = {};
    document.querySelectorAll('th.date-col[data-date-total]').forEach((th) => {
        const date = String(th.dataset.dateTotal || '');
        if (!date) {
            return;
        }
        const valueEl = th.querySelector('.date-total-value');
        if (!valueEl) {
            return;
        }
        dateHeaderTotals[date] = { th, valueEl };
    });

    function bootstrapStrips() {
        strips = [];
        rowStateMap.forEach((state, rowKey) => {
            let skipReason = '';
            if (state.dismissed) {
                skipReason = 'dismissed';
                return;
            }
            if (!state.packageKey || state.gofroNeed <= 0 || state.packagesPerRoll <= 0) {
                skipReason = !state.packageKey ? 'empty_packageKey' : (state.gofroNeed <= 0 ? 'gofroNeed_le_0' : 'packagesPerRoll_le_0');
                return;
            }
            const rollsNeeded = Math.ceil(state.gofroNeed / state.packagesPerRoll);
            for (let i = 0; i < rollsNeeded; i += 1) {
                const qtyFromRoll = state.packagesPerRoll;
                const length = qtyFromRoll * NORM_PER_UNIT;
                strips.push({
                    id: `S${stripSeq++}`,
                    length,
                    qty_capacity: qtyFromRoll,
                    package_type: state.packageKey,
                    package_label: state.filterName || state.packageName || getPackageLabel(state.packageKey),
                    order: state.order || '',
                    source_row: rowKey,
                });
            }
        });
    }

    function getPackageLabel(pkgKey) {
        return packageCatalog[pkgKey] || pkgKey || '—';
    }

    function clearDropHints() {
        document.querySelectorAll('td.date-cell.drop-valid, td.date-cell.drop-invalid').forEach((cell) => {
            cell.classList.remove('drop-valid', 'drop-invalid');
        });
    }

    function computeQtyFromLength(length) {
        const safeLength = Math.max(0, Number(length) || 0);
        return Math.max(0, Math.floor(safeLength / NORM_PER_UNIT));
    }

    function getAllocatedObjectQty(obj) {
        if (obj && typeof obj === 'object') {
            return Math.max(0, parseInt(obj.qty || 0, 10) || 0);
        }
        return Math.max(0, parseInt(obj || 0, 10) || 0);
    }

    function getAllocatedObjectItems(obj) {
        if (obj && typeof obj === 'object' && Array.isArray(obj.items) && obj.items.length > 0) {
            return obj.items
                .map((item) => ({
                    strip_id: String(item.strip_id || ''),
                    qty: Math.max(0, parseInt(item.qty || 0, 10) || 0),
                }))
                .filter((item) => item.qty > 0);
        }
        const qty = getAllocatedObjectQty(obj);
        if (qty <= 0) {
            return [];
        }
        const stripId = obj && typeof obj === 'object' ? String(obj.strip_id || '') : '';
        return [{ strip_id: stripId, qty }];
    }

    function setSaveStatus(text, isError = false) {
        if (!savePlanStatus) {
            return;
        }
        savePlanStatus.textContent = text;
        savePlanStatus.style.color = isError ? '#b91c1c' : '#6b7280';
    }

    function formatDateRu(iso) {
        if (!iso) {
            return '';
        }
        const parts = String(iso).split('-');
        if (parts.length !== 3) {
            return String(iso);
        }
        return `${parts[2]}.${parts[1]}.${parts[0]}`;
    }

    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function collectTaskSheetRows(dateFrom, dateTo) {
        const rowsOut = [];
        rowStateMap.forEach((state) => {
            if (state.dismissed) {
                return;
            }
            Object.keys(state.allocatedByDate).forEach((date) => {
                if (date < dateFrom || date > dateTo) {
                    return;
                }
                const qty = Math.max(0, parseInt(state.allocatedByDate[date] || 0, 10) || 0);
                if (qty <= 0) {
                    return;
                }
                rowsOut.push({
                    date,
                    order: String(state.order || ''),
                    filter: String(state.filterName || ''),
                    qty,
                    paperWidthMm: Math.max(0, Number(state.paperWidthMm || 0) || 0),
                    foldHeight: Math.max(0, Number(state.foldHeight || 0) || 0),
                    foldCount: Math.max(0, Number(state.foldCount || 0) || 0),
                });
            });
        });
        rowsOut.sort((a, b) => {
            if (a.date !== b.date) return a.date.localeCompare(b.date);
            if (a.order !== b.order) return a.order.localeCompare(b.order, 'ru');
            return a.filter.localeCompare(b.filter, 'ru');
        });
        return rowsOut;
    }

    function openTaskSheetModal() {
        if (!taskSheetModal || !taskDateFrom || !taskDateTo) {
            return;
        }
        const now = new Date();
        const fromIso = now.toISOString().slice(0, 10);
        const toDate = new Date(now.getTime());
        toDate.setDate(toDate.getDate() + 6);
        const toIso = toDate.toISOString().slice(0, 10);
        taskDateFrom.value = taskDateFrom.value || fromIso;
        taskDateTo.value = taskDateTo.value || toIso;
        taskSheetModal.style.display = 'flex';
    }

    function closeTaskSheetModal() {
        if (!taskSheetModal) {
            return;
        }
        taskSheetModal.style.display = 'none';
    }

    function printTaskSheet(dateFrom, dateTo) {
        const rowsForPrint = collectTaskSheetRows(dateFrom, dateTo);
        const totalQty = rowsForPrint.reduce((acc, row) => acc + row.qty, 0);
        const bodyRows = rowsForPrint.length > 0
            ? rowsForPrint.map((row, idx) => `
                <tr>
                    <td>${idx + 1}</td>
                    <td>${htmlEscape(formatDateRu(row.date))}</td>
                    <td>${htmlEscape(row.order)}</td>
                    <td>${htmlEscape(row.filter)}</td>
                    <td style="text-align:right;">${row.paperWidthMm > 0 ? htmlEscape(String(row.paperWidthMm)) : '—'}</td>
                    <td style="text-align:right;">${row.foldHeight > 0 ? htmlEscape(String(row.foldHeight)) : '—'}</td>
                    <td style="text-align:right;">${row.foldCount > 0 ? htmlEscape(String(row.foldCount)) : '—'}</td>
                    <td style="text-align:right;">${row.qty}</td>
                    <td></td>
                </tr>
            `).join('')
            : '<tr><td colspan="9" style="text-align:center;color:#64748b;">Нет распланированных позиций за выбранный период</td></tr>';

        const printHtml = `
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Задание сборщицам</title>
  <style>
    body { font: 13px/1.35 "Segoe UI", Arial, sans-serif; color:#0f172a; margin:16px; }
    h1 { margin:0 0 6px; font-size:18px; }
    .meta { margin:0 0 10px; color:#475569; font-size:12px; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid #cbd5e1; padding:4px 6px; }
    th { background:#f1f5f9; text-align:left; }
    @media print { body { margin:8mm; } }
  </style>
</head>
<body>
  <h1>Задание сборщицам</h1>
  <div class="meta">Период: ${htmlEscape(formatDateRu(dateFrom))} - ${htmlEscape(formatDateRu(dateTo))}. Всего г/п: ${totalQty}</div>
  <table>
    <thead>
      <tr>
        <th style="width:34px;">#</th>
        <th style="width:90px;">Дата</th>
        <th style="width:120px;">Заявка</th>
        <th>Фильтр</th>
        <th style="width:95px; text-align:right;">Ширина, мм</th>
        <th style="width:95px; text-align:right;">Высота ребра</th>
        <th style="width:105px; text-align:right;">Кол-во ребер</th>
        <th style="width:90px; text-align:right;">Кол-во г/п</th>
        <th style="width:120px;">Примечание</th>
      </tr>
    </thead>
    <tbody>${bodyRows}</tbody>
  </table>
</body>
</html>`;
        let frame = document.getElementById('task-sheet-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'task-sheet-print-frame';
            frame.style.position = 'fixed';
            frame.style.right = '0';
            frame.style.bottom = '0';
            frame.style.width = '0';
            frame.style.height = '0';
            frame.style.border = '0';
            frame.setAttribute('aria-hidden', 'true');
            document.body.appendChild(frame);
        }
        const doc = frame.contentWindow ? frame.contentWindow.document : null;
        if (!doc || !frame.contentWindow) {
            alert('Не удалось подготовить печать.');
            return;
        }
        doc.open();
        doc.write(printHtml);
        doc.close();
        setTimeout(() => {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }, 100);
    }

    function renderStrips() {
        stripPool.innerHTML = '';
        if (strips.length === 0) {
            stripPool.innerHTML = '<div class="muted">Расчетных полос нет (потребность закрыта или не хватает параметров гофропакета).</div>';
            return;
        }
        const grouped = new Map();
        strips.forEach((strip) => {
            const groupKey = String(strip.order || '').trim() || 'Без заявки';
            if (!grouped.has(groupKey)) {
                grouped.set(groupKey, []);
            }
            grouped.get(groupKey).push(strip);
        });

        Array.from(grouped.entries()).sort((a, b) => a[0].localeCompare(b[0])).forEach(([order, list]) => {
            const groupEl = document.createElement('div');
            groupEl.className = 'strip-group';
            groupEl.innerHTML = `<div class="strip-group__title">Заявка: ${order}</div>`;

            list.forEach((strip) => {
                const stripEl = document.createElement('div');
                stripEl.className = 'strip';
                stripEl.draggable = true;
                stripEl.dataset.stripId = strip.id;
                stripEl.dataset.length = String(strip.length);
                stripEl.dataset.package = strip.package_type;
                stripEl.dataset.sourceRow = String(strip.source_row || '');
                if (selectedStripIds.has(strip.id)) {
                    stripEl.classList.add('strip-selected');
                }
                const qty = Math.max(0, parseInt(strip.qty_capacity || computeQtyFromLength(strip.length), 10) || 0);
                stripEl.innerHTML = `
                    <div class="strip-meta">
                        <span>${strip.package_label || getPackageLabel(strip.package_type)}</span>
                        <span>${qty} шт</span>
                    </div>
                `;
                stripEl.addEventListener('click', (e) => {
                    const sourceRow = String(strip.source_row || '');
                    if (!sourceRow) {
                        return;
                    }
                    if (selectedSourceRow && selectedSourceRow !== sourceRow) {
                        selectedStripIds.clear();
                    }
                    selectedSourceRow = sourceRow;
                    if (selectedStripIds.has(strip.id)) {
                        selectedStripIds.delete(strip.id);
                    } else {
                        selectedStripIds.add(strip.id);
                    }
                    if (selectedStripIds.size === 0) {
                        selectedSourceRow = '';
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    renderStrips();
                });
                stripEl.addEventListener('dragstart', (e) => {
                    let bundleIds = [strip.id];
                    if (selectedStripIds.has(strip.id) && selectedSourceRow === String(strip.source_row || '')) {
                        bundleIds = strips
                            .filter((s) => selectedStripIds.has(s.id) && String(s.source_row || '') === selectedSourceRow)
                            .map((s) => s.id);
                    }
                    if (bundleIds.length === 0) {
                        bundleIds = [strip.id];
                    }
                    let totalLength = 0;
                    let totalQty = 0;
                    bundleIds.forEach((id) => {
                        const s = strips.find((x) => x.id === id);
                        if (!s) {
                            return;
                        }
                        const length = Number(s.length) || 0;
                        const capQty = Math.max(0, parseInt(s.qty_capacity || computeQtyFromLength(length), 10) || 0);
                        totalLength += length;
                        totalQty += capQty;
                    });
                    dragContext = {
                        strip_ids: bundleIds,
                        package_type: strip.package_type,
                        order: String(strip.order || ''),
                        length: totalLength,
                        qty: totalQty,
                        used_length: totalQty * NORM_PER_UNIT,
                    };
                    if (e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', bundleIds.join(','));
                    }
                    highlightValidCells();
                });
                stripEl.addEventListener('dragend', () => {
                    dragContext = null;
                    clearDropHints();
                    hidePreview();
                });
                groupEl.appendChild(stripEl);
            });

            stripPool.appendChild(groupEl);
        });
    }

    function highlightValidCells() {
        clearDropHints();
        if (!dragContext) {
            return;
        }
        rowStateMap.forEach((state) => {
            if (state.dismissed) {
                return;
            }
            const validRow = state.packageKey !== ''
                && state.packageKey === dragContext.package_type
                && state.order !== ''
                && state.order === String(dragContext.order || '');
            state.dateCells.forEach((cell) => {
                cell.classList.add(validRow ? 'drop-valid' : 'drop-invalid');
            });
        });
    }

    function showPreview(x, y, text) {
        dragPreview.textContent = text;
        dragPreview.style.left = `${Math.round(x + 14)}px`;
        dragPreview.style.top = `${Math.round(y + 14)}px`;
        dragPreview.style.display = 'block';
    }

    function hidePreview() {
        dragPreview.style.display = 'none';
    }

    function isValidDropCell(cell) {
        if (!dragContext || !cell || !cell.classList.contains('date-cell')) {
            return false;
        }
        const row = cell.closest('tr[data-row-key]');
        if (!row) {
            return false;
        }
        const dropRowKey = String(row.dataset.rowKey || '');
        const dropState = rowStateMap.get(dropRowKey);
        if (!dropState || dropState.dismissed) {
            return false;
        }
        const pkg = String(row.dataset.packageKey || '');
        const order = String(row.dataset.order || '');
        return pkg !== ''
            && pkg === dragContext.package_type
            && order !== ''
            && order === String(dragContext.order || '');
    }

    function updateCoverage() {
        const dailyTotals = {};
        rowStateMap.forEach((state, rowKey) => {
            if (state.dismissed) {
                return;
            }
            let allocated = 0;
            let allocatedInHorizon = 0;
            Object.keys(state.allocatedByDate).forEach((d) => {
                const qty = parseInt(state.allocatedByDate[d] || 0, 10) || 0;
                allocated += qty;
                const hasDateCell = state.dateCells.some((cell) => String(cell.dataset.date || '') === d);
                if (hasDateCell) {
                    allocatedInHorizon += qty;
                }
            });
            const baseCoverageNeed = Math.max(0, state.baseAvailable);
            const totalCoverageNeed = Math.max(0, state.baseAvailable + allocatedInHorizon);
            let baseEndIdx = -1;
            let basePartialIdx = -1;
            let totalEndIdx = -1;
            let totalPartialIdx = -1;
            const baseStartIdx = 0;
            let totalStartIdx = 0;
            const allocatedDates = Object.keys(state.allocatedByDate || {}).filter((d) => {
                const qty = parseInt(state.allocatedByDate[d] || 0, 10) || 0;
                if (qty <= 0) {
                    return false;
                }
                return state.dateCells.some((cell) => String(cell.dataset.date || '') === d);
            });
            if (allocatedDates.length > 0) {
                const firstAllocatedDate = allocatedDates.sort()[0];
                const idxByDate = state.dateCells.findIndex((cell) => String(cell.dataset.date || '') === firstAllocatedDate);
                if (idxByDate >= 0) {
                    totalStartIdx = idxByDate;
                }
            }

            let lastPlanIdx = -1;
            for (let i = baseStartIdx; i < state.dateCells.length; i += 1) {
                const q = parseInt(state.dateCells[i].dataset.planQty || '0', 10) || 0;
                if (q > 0) {
                    lastPlanIdx = i;
                }
            }

            function findCoverageWindow(needQty, startIdx) {
                let acc = 0;
                let endIdx = -1;
                let partialIdx = -1;
                for (let i = startIdx; i < state.dateCells.length; i += 1) {
                    const cell = state.dateCells[i];
                    const planQty = parseInt(cell.dataset.planQty || '0', 10) || 0;
                    if (planQty <= 0) {
                        continue;
                    }
                    if (acc < needQty && acc + planQty >= needQty) {
                        endIdx = i;
                        if (acc + planQty > needQty) {
                            partialIdx = i;
                        }
                        break;
                    }
                    acc += planQty;
                }
                if (needQty > 0 && endIdx < 0 && lastPlanIdx >= startIdx) {
                    endIdx = lastPlanIdx;
                }
                return { endIdx, partialIdx };
            }
            const baseWindow = findCoverageWindow(baseCoverageNeed, baseStartIdx);
            baseEndIdx = baseWindow.endIdx;
            basePartialIdx = baseWindow.partialIdx;
            const totalWindow = findCoverageWindow(totalCoverageNeed, totalStartIdx);
            totalEndIdx = totalWindow.endIdx;
            totalPartialIdx = totalWindow.partialIdx;

            state.dateCells.forEach((cell, idx) => {
                cell.classList.remove('gantt-full', 'gantt-partial', 'gantt-plan-full', 'gantt-plan-partial');
                const inBase = baseEndIdx >= 0 && idx >= baseStartIdx && idx <= baseEndIdx;
                const inTotal = totalEndIdx >= 0 && idx >= totalStartIdx && idx <= totalEndIdx;
                if (inBase) {
                    cell.classList.add(idx === basePartialIdx ? 'gantt-partial' : 'gantt-full');
                } else if (inTotal) {
                    cell.classList.add(idx === totalPartialIdx ? 'gantt-plan-partial' : 'gantt-plan-full');
                }

                const date = cell.dataset.date || '';
                const qty = parseInt(state.allocatedByDate[date] || 0, 10) || 0;
                if (qty > 0) {
                    dailyTotals[date] = (parseInt(dailyTotals[date] || 0, 10) || 0) + qty;
                }
                let supplyEl = cell.querySelector('.cell-supply');
                const objects = Array.isArray(state.allocatedObjectsByDate[date])
                    ? state.allocatedObjectsByDate[date]
                    : [];

                if (objects.length > 0) {
                    if (!supplyEl) {
                        supplyEl = document.createElement('div');
                        supplyEl.className = 'cell-supply';
                        cell.appendChild(supplyEl);
                    }
                    supplyEl.innerHTML = '';
                    objects.forEach((obj, objIdx) => {
                        const objQty = getAllocatedObjectQty(obj);
                        if (objQty <= 0) {
                            return;
                        }
                        const item = document.createElement('span');
                        item.className = 'cell-supply-item';
                        item.textContent = String(objQty);
                        item.title = 'Клик: вернуть в пул';
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            returnAllocationToPool(rowKey, date, objIdx);
                        });
                        supplyEl.appendChild(item);
                    });
                } else if (supplyEl) {
                    supplyEl.remove();
                }
            });
        });

        Object.keys(dateHeaderTotals).forEach((date) => {
            const total = parseInt(dailyTotals[date] || 0, 10) || 0;
            const entry = dateHeaderTotals[date];
            entry.valueEl.textContent = String(total);
            entry.th.title = `Суммарно распределено гофропакетов из пула: ${total}`;
        });
    }

    function returnAllAllocationsForRow(rowKey) {
        const state = rowStateMap.get(rowKey);
        if (!state) {
            return;
        }
        const dates = Object.keys(state.allocatedObjectsByDate || {});
        dates.forEach((date) => {
            while (state.allocatedObjectsByDate[date] && state.allocatedObjectsByDate[date].length > 0) {
                returnAllocationToPool(rowKey, date, 0, true);
            }
        });
    }

    function dismissPositionRow(rowKey) {
        const state = rowStateMap.get(rowKey);
        if (!state || state.dismissed) {
            return;
        }
        returnAllAllocationsForRow(rowKey);
        strips = strips.filter((s) => String(s.source_row || '') !== String(rowKey));
        Array.from(selectedStripIds).forEach((id) => {
            if (!strips.some((s) => s.id === id)) {
                selectedStripIds.delete(id);
            }
        });
        if (selectedStripIds.size === 0) {
            selectedSourceRow = '';
        }
        state.dismissed = true;
        state.row.style.display = 'none';
        dismissedRowKeys.add(rowKey);
        persistDismissedRowKeys(dismissedRowKeys);
        renderStrips();
        updateCoverage();
    }

    function resetDismissedRows() {
        dismissedRowKeys = new Set();
        persistDismissedRowKeys(dismissedRowKeys);
        rowStateMap.forEach((state) => {
            state.dismissed = false;
            state.row.style.display = '';
        });
        selectedStripIds.clear();
        selectedSourceRow = '';
        allocations = [];
        Object.keys(supplyMap).forEach((k) => delete supplyMap[k]);
        rowStateMap.forEach((state) => {
            state.allocatedByDate = {};
            state.allocatedObjectsByDate = {};
        });
        bootstrapStrips();
        renderStrips();
        updateCoverage();
        setSaveStatus('Скрытые позиции сброшены');
    }

    function returnAllocationToPool(rowKey, date, objIdx, silentRefresh) {
        const state = rowStateMap.get(rowKey);
        if (!state) {
            return;
        }
        const list = Array.isArray(state.allocatedObjectsByDate[date]) ? state.allocatedObjectsByDate[date] : [];
        if (objIdx < 0 || objIdx >= list.length) {
            return;
        }
        const allocObj = list[objIdx];
        const qty = getAllocatedObjectQty(allocObj);
        if (qty <= 0) {
            return;
        }
        const itemsToRestore = getAllocatedObjectItems(allocObj);
        if (itemsToRestore.length === 0) {
            return;
        }

        list.splice(objIdx, 1);
        state.allocatedByDate[date] = Math.max(0, (parseInt(state.allocatedByDate[date] || 0, 10) || 0) - qty);
        if (list.length === 0) {
            delete state.allocatedObjectsByDate[date];
        }

        if (supplyMap[rowKey]) {
            supplyMap[rowKey][date] = Math.max(0, (parseInt(supplyMap[rowKey][date] || 0, 10) || 0) - qty);
            if ((parseInt(supplyMap[rowKey][date] || 0, 10) || 0) <= 0) {
                delete supplyMap[rowKey][date];
            }
        }

        itemsToRestore.forEach((entry) => {
            allocations.push({
                strip_id: entry.strip_id || `RETURN-${stripSeq}`,
                row_key: rowKey,
                date,
                qty: -entry.qty,
                used_length: -(entry.qty * NORM_PER_UNIT),
                action: 'return_to_pool',
            });
        });

        itemsToRestore.forEach((entry) => {
            strips.push({
                id: entry.strip_id || `S${stripSeq++}`,
                length: entry.qty * NORM_PER_UNIT,
                qty_capacity: entry.qty,
                package_type: state.packageKey,
                package_label: state.filterName || state.packageName || getPackageLabel(state.packageKey),
                order: state.order || '',
                source_row: rowKey,
            });
        });

        if (!silentRefresh) {
            renderStrips();
            updateCoverage();
        }
    }

    function buildSavePayload() {
        const items = [];
        rowStateMap.forEach((state, rowKey) => {
            if (state.dismissed) {
                return;
            }
            Object.keys(state.allocatedObjectsByDate).forEach((date) => {
                const objects = Array.isArray(state.allocatedObjectsByDate[date]) ? state.allocatedObjectsByDate[date] : [];
                objects.forEach((obj, idx) => {
                    const groupId = (obj && typeof obj === 'object' && String(obj.group_id || '') !== '')
                        ? String(obj.group_id)
                        : `ROW:${rowKey}|DATE:${date}|IDX:${idx}`;
                    const entries = getAllocatedObjectItems(obj);
                    entries.forEach((entry) => {
                        items.push({
                            source_row_key: rowKey,
                            order_number: state.order || '',
                            filter_name: state.filterName || '',
                            package_key: state.packageKey || '',
                            package_name: state.packageName || '',
                            plan_date: date,
                            group_id: groupId,
                            strip_id: entry.strip_id || '',
                            qty: entry.qty,
                        });
                    });
                });
            });
        });
        return { items };
    }

    async function savePlanV2() {
        if (!savePlanBtn) {
            return;
        }
        savePlanBtn.disabled = true;
        setSaveStatus('Сохраняем...');
        try {
            const payload = buildSavePayload();
            const res = await fetch('?api=save_plan_v2', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Ошибка сохранения');
            }
            setSaveStatus(`Сохранено: ${Number(data.saved || 0)} строк`);
        } catch (err) {
            setSaveStatus(`Ошибка сохранения: ${err.message || err}`, true);
        } finally {
            savePlanBtn.disabled = false;
        }
    }

    function removeStripFromPoolByIdOrQty(state, stripId, qty) {
        let idx = strips.findIndex((s) => s.id === stripId);
        if (idx < 0) {
            idx = strips.findIndex((s) =>
                String(s.source_row || '') === String(state.row.dataset.rowKey || '')
                && (Math.max(0, parseInt(s.qty_capacity || 0, 10) || 0) === qty)
            );
        }
        if (idx < 0) {
            return null;
        }
        const removed = strips[idx];
        selectedStripIds.delete(removed.id);
        strips.splice(idx, 1);
        return removed;
    }

    function applyLoadedPlanRows(rows) {
        const grouped = new Map();
        rows.forEach((r) => {
            const rowKey = String(r.source_row_key || '').trim();
            const date = String(r.plan_date || '').trim();
            const groupId = String(r.group_id || '').trim();
            const stripId = String(r.strip_id || '').trim();
            const qty = Math.max(0, parseInt(r.qty || 0, 10) || 0);
            if (!rowKey || !date || !groupId || !stripId || qty <= 0) {
                return;
            }
            const mapKey = `${rowKey}||${date}||${groupId}`;
            if (!grouped.has(mapKey)) {
                grouped.set(mapKey, { rowKey, date, groupId, items: [] });
            }
            grouped.get(mapKey).items.push({ strip_id: stripId, qty });
        });

        grouped.forEach((group) => {
            const state = rowStateMap.get(group.rowKey);
            if (!state || state.dismissed) {
                return;
            }
            const hasDateInHorizon = state.dateCells.some((cell) => String(cell.dataset.date || '') === group.date);
            if (!hasDateInHorizon) {
                return;
            }
            const restoredItems = [];
            group.items.forEach((item) => {
                const removed = removeStripFromPoolByIdOrQty(state, item.strip_id, item.qty);
                if (!removed) {
                    return;
                }
                restoredItems.push({
                    strip_id: item.strip_id || removed.id,
                    qty: item.qty,
                });
            });
            if (restoredItems.length === 0) {
                return;
            }
            const totalQty = restoredItems.reduce((acc, x) => acc + (parseInt(x.qty || 0, 10) || 0), 0);
            if (!Array.isArray(state.allocatedObjectsByDate[group.date])) {
                state.allocatedObjectsByDate[group.date] = [];
            }
            state.allocatedObjectsByDate[group.date].push({
                qty: totalQty,
                group_id: group.groupId,
                items: restoredItems,
            });
            state.allocatedByDate[group.date] = (parseInt(state.allocatedByDate[group.date] || 0, 10) || 0) + totalQty;
            if (!supplyMap[group.rowKey]) {
                supplyMap[group.rowKey] = {};
            }
            supplyMap[group.rowKey][group.date] = (parseInt(supplyMap[group.rowKey][group.date] || 0, 10) || 0) + totalQty;
        });
        if (selectedStripIds.size === 0) {
            selectedSourceRow = '';
        }
    }

    async function loadPlanV2() {
        setSaveStatus('Загружаем сохраненный план...');
        try {
            const res = await fetch('?api=load_plan_v2');
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Ошибка загрузки');
            }
            applyLoadedPlanRows(Array.isArray(data.items) ? data.items : []);
            renderStrips();
            updateCoverage();
            setSaveStatus('Сохраненный план загружен');
        } catch (err) {
            setSaveStatus(`Ошибка загрузки: ${err.message || err}`, true);
        }
    }

    function applyDrop(cell) {
        if (!isValidDropCell(cell)) {
            return;
        }
        const row = cell.closest('tr[data-row-key]');
        if (!row || !dragContext) {
            return;
        }
        const rowKey = row.dataset.rowKey || '';
        const date = cell.dataset.date || '';
        if (!rowKey || !date) {
            return;
        }
        const stripIds = Array.isArray(dragContext.strip_ids) && dragContext.strip_ids.length > 0
            ? dragContext.strip_ids
            : [];
        if (stripIds.length === 0) {
            return;
        }

        const selectedStripData = [];
        stripIds.forEach((id) => {
            const idx = strips.findIndex((s) => s.id === id);
            if (idx < 0) {
                return;
            }
            const strip = strips[idx];
            const qty = computeQtyFromLength(strip.length);
            if (qty <= 0) {
                return;
            }
            const usedLength = qty * NORM_PER_UNIT;
            if (usedLength > strip.length + 1e-9) {
                return;
            }
            selectedStripData.push({
                id: strip.id,
                qty,
                usedLength,
                index: idx,
            });
        });
        if (selectedStripData.length === 0) {
            return;
        }
        let totalQty = 0;
        selectedStripData.forEach((entry) => {
            totalQty += entry.qty;
            allocations.push({
                strip_id: entry.id,
                row_key: rowKey,
                date,
                qty: entry.qty,
                used_length: entry.usedLength,
            });
        });
        if (!supplyMap[rowKey]) {
            supplyMap[rowKey] = {};
        }
        supplyMap[rowKey][date] = (parseInt(supplyMap[rowKey][date] || 0, 10) || 0) + totalQty;

        const state = rowStateMap.get(rowKey);
        if (state) {
            state.allocatedByDate[date] = (parseInt(state.allocatedByDate[date] || 0, 10) || 0) + totalQty;
            if (!Array.isArray(state.allocatedObjectsByDate[date])) {
                state.allocatedObjectsByDate[date] = [];
            }
            const groupId = `G${allocationGroupSeq++}`;
            state.allocatedObjectsByDate[date].push({
                qty: totalQty,
                group_id: groupId,
                items: selectedStripData.map((entry) => ({
                    strip_id: entry.id,
                    qty: entry.qty,
                })),
            });
        }

        selectedStripData
            .map((entry) => entry.index)
            .sort((a, b) => b - a)
            .forEach((idx) => {
                if (idx >= 0 && idx < strips.length) {
                    const removed = strips[idx];
                    selectedStripIds.delete(removed.id);
                    strips.splice(idx, 1);
                }
            });
        if (selectedStripIds.size === 0) {
            selectedSourceRow = '';
        }

        renderStrips();
        updateCoverage();
    }

    document.querySelectorAll('td.date-cell[data-date]').forEach((cell) => {
        cell.addEventListener('dragover', (e) => {
            if (!dragContext) {
                return;
            }
            const valid = isValidDropCell(cell);
            if (valid) {
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
            }
            const txt = `${(Number(dragContext.length) || 0).toFixed(1)} м -> ${dragContext.qty} шт`;
            showPreview(e.clientX, e.clientY, txt);
        });
        cell.addEventListener('dragenter', (e) => {
            if (dragContext && isValidDropCell(cell)) {
                e.preventDefault();
            }
        });
        cell.addEventListener('dragleave', () => {
            hidePreview();
        });
        cell.addEventListener('drop', (e) => {
            if (!dragContext) {
                return;
            }
            e.preventDefault();
            hidePreview();
            applyDrop(cell);
            clearDropHints();
        });
    });

    applyStoredDismissedRows();
    bootstrapStrips();
    renderStrips();
    updateCoverage();

    const tableBody = document.querySelector('.panel table tbody');
    if (tableBody) {
        tableBody.addEventListener('click', (e) => {
            const btn = e.target.closest('.row-dismiss-btn');
            if (!btn) {
                return;
            }
            e.preventDefault();
            const rk = String(btn.dataset.dismissRow || '');
            if (!rk) {
                return;
            }
            dismissPositionRow(rk);
        });
    }
    if (savePlanBtn) {
        savePlanBtn.addEventListener('click', savePlanV2);
    }
    if (resetDismissedBtn) {
        resetDismissedBtn.addEventListener('click', resetDismissedRows);
    }
    if (taskSheetBtn) {
        taskSheetBtn.addEventListener('click', openTaskSheetModal);
    }
    if (taskSheetCancel) {
        taskSheetCancel.addEventListener('click', closeTaskSheetModal);
    }
    if (taskSheetPrint) {
        taskSheetPrint.addEventListener('click', () => {
            const dateFrom = taskDateFrom ? String(taskDateFrom.value || '') : '';
            const dateTo = taskDateTo ? String(taskDateTo.value || '') : '';
            if (!dateFrom || !dateTo) {
                alert('Укажите период задания.');
                return;
            }
            if (dateFrom > dateTo) {
                alert('Дата начала больше даты окончания.');
                return;
            }
            closeTaskSheetModal();
            printTaskSheet(dateFrom, dateTo);
        });
    }
    if (taskSheetModal) {
        taskSheetModal.addEventListener('click', (e) => {
            if (e.target === taskSheetModal) {
                closeTaskSheetModal();
            }
        });
    }

    const planTable = document.querySelector('.layout > .panel table');
    if (planTable) {
        let hoveredPlanDate = '';
        let hoveredRowKey = '';

        function clearDateColumnHover() {
            planTable.querySelectorAll('th.date-col.date-hover').forEach((el) => {
                el.classList.remove('date-hover');
            });
            hoveredPlanDate = '';
        }

        function clearNameHover() {
            planTable.querySelectorAll('td.filter-name-cell.name-hover').forEach((el) => {
                el.classList.remove('name-hover');
            });
            hoveredRowKey = '';
        }

        function setDateColumnHover(dateIso) {
            const next = String(dateIso || '');
            if (next === hoveredPlanDate) {
                return;
            }
            planTable.querySelectorAll('th.date-col.date-hover').forEach((el) => {
                el.classList.remove('date-hover');
            });
            hoveredPlanDate = next;
            if (!next || !/^\d{4}-\d{2}-\d{2}$/.test(next)) {
                hoveredPlanDate = '';
                return;
            }
            planTable.querySelectorAll(`th.date-col[data-date-total="${next}"]`).forEach((el) => {
                el.classList.add('date-hover');
            });
        }

        function setNameHoverForRow(rowKey) {
            const next = String(rowKey || '');
            if (next === hoveredRowKey) {
                return;
            }
            planTable.querySelectorAll('td.filter-name-cell.name-hover').forEach((el) => {
                el.classList.remove('name-hover');
            });
            hoveredRowKey = next;
            if (!next) {
                return;
            }
            const row = planTable.querySelector(`tbody tr[data-row-key="${next}"]`);
            if (!row) {
                hoveredRowKey = '';
                return;
            }
            const nameCell = row.querySelector('td.filter-name-cell');
            if (!nameCell) {
                hoveredRowKey = '';
                return;
            }
            nameCell.classList.add('name-hover');
        }

        planTable.addEventListener('mouseover', (e) => {
            const cell = e.target.closest('th.date-col, td.date-cell[data-date]');
            if (!cell || !planTable.contains(cell)) {
                clearDateColumnHover();
                clearNameHover();
                return;
            }
            if (cell.matches('th.date-col')) {
                setDateColumnHover(cell.dataset.dateTotal || '');
                clearNameHover();
            } else {
                setDateColumnHover(cell.dataset.date || '');
                const row = cell.closest('tr[data-row-key]');
                setNameHoverForRow(row ? row.dataset.rowKey : '');
            }
        });
        planTable.addEventListener('mouseleave', () => {
            clearDateColumnHover();
            clearNameHover();
        });
    }

    loadPlanV2();
})();
</script>
<?php endif; ?>
</body>
</html>
