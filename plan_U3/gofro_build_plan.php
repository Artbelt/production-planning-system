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
                    rfs.filter_package AS filter_package
                FROM round_filter_structure rfs
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
                    $filterMetaByKey[$metaKey] = ['filter_package' => null];
                }
                $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                if ($pkg !== '' && $filterMetaByKey[$metaKey]['filter_package'] === null) {
                    $filterMetaByKey[$metaKey]['filter_package'] = $pkg;
                }
            }
        } catch (Throwable $e) {
            $filterMetaByKey = [];
        }
    }
}

$gofroProducedByOrderPackage = [];
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
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
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
            position: sticky;
            top: 0;
            z-index: 3;
            font-size: 11px;
        }
        td.num, th.num { text-align: right; }
        td.date-cell, th.date-col {
            text-align: left;
            min-width: 28px;
            width: 28px;
            position: relative;
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
        td.date-cell.gantt-full {
            background: var(--ok-bg);
            box-shadow: inset 0 0 0 1px rgba(22, 163, 74, .2);
        }
        td.date-cell.gantt-partial {
            background: var(--warn-bg);
            box-shadow: inset 0 0 0 1px rgba(217, 119, 6, .2);
        }
        .muted {
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1 style="margin:0 0 8px; font-size:20px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted" style="margin:0 0 12px;">
        Линия/заливка показывает покрытие смен оставшимися гофропакетами. Число сборки фильтров показано маленьким фоновым текстом в левом верхнем углу ячейки.
    </p>
    <?php if ($loadError !== ''): ?>
        <div class="panel" style="padding:12px;">Ошибка загрузки: <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="panel">
            <table>
                <thead>
                <tr>
                    <th>Фильтр</th>
                    <th>Заявка</th>
                    <th class="num">Остаток фильтров</th>
                    <th class="num">Г/п изготовлено</th>
                    <th class="num">Г/п доступно</th>
                    <?php foreach ($buildPlanDates as $planDate): ?>
                        <?php $d = DateTime::createFromFormat('Y-m-d', (string)$planDate); ?>
                        <th class="date-col"><?= htmlspecialchars($d ? $d->format('d.m') : (string)$planDate, ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= 5 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
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
                        $planQtyByDate = $buildPlanMap[$planKey] ?? [];

                        $meta = $filterMetaByKey[normalizeFilterKeyLocal($rawFilter)] ?? null;
                        $package = trim((string)($meta['filter_package'] ?? ''));
                        $packageKey = normalizeTextKeyLocal($package);
                        $gofroProduced = $packageKey !== '' && $rawOrder !== ''
                            ? (int)($gofroProducedByOrderPackage[$rawOrder][$packageKey] ?? 0)
                            : 0;
                        $gofroAvailable = $gofroProduced - $produced;

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
                        <tr>
                            <td><?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num"><?= $remaining ?></td>
                            <td class="num"><?= $gofroProduced ?></td>
                            <td class="num"><?= $gofroAvailable ?></td>
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
                                <td class="<?= htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($qty > 0): ?><span class="cell-qty"><?= (int)$qty ?></span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
