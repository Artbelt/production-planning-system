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
                    rfs.filter_package AS filter_package,
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
                        'fold_height' => null,
                        'fold_count' => null,
                    ];
                }
                $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                if ($pkg !== '' && $filterMetaByKey[$metaKey]['filter_package'] === null) {
                    $filterMetaByKey[$metaKey]['filter_package'] = $pkg;
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
            font-size: 8px;
            line-height: 1;
            color: #0369a1;
            font-weight: 600;
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
        <div class="layout">
        <div class="panel">
            <table>
                <thead>
                <tr>
                    <th>Фильтр</th>
                    <th>Заявка</th>
                    <th class="num">Остаток фильтров</th>
                    <th class="num">Г/п изготовлено</th>
                    <th class="num">Г/п доступно</th>
                    <th class="num">Потребность в г/п</th>
                    <?php foreach ($buildPlanDates as $planDate): ?>
                        <?php $d = DateTime::createFromFormat('Y-m-d', (string)$planDate); ?>
                        <th class="date-col"><?= htmlspecialchars($d ? $d->format('d.m') : (string)$planDate, ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= 6 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
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
                        $gofroNeed = max(0, $remaining - $gofroAvailable);
                        $foldHeight = (float)($meta['fold_height'] ?? 0);
                        $foldCount = (float)($meta['fold_count'] ?? 0);
                        $packLengthM = ($foldHeight > 0 && $foldCount > 0)
                            ? (($foldHeight * 2 + 1) * $foldCount) / 1000
                            : 0.0;
                        $packagesPerRoll = $packLengthM > 0 ? (int)floor(600 / $packLengthM) : 0;

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
                            data-row-key="<?= htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-key="<?= htmlspecialchars($packageKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-name="<?= htmlspecialchars($package, ENT_QUOTES, 'UTF-8') ?>"
                            data-base-available="<?= (int)$gofroAvailable ?>"
                            data-gofro-need="<?= (int)$gofroNeed ?>"
                            data-packages-per-roll="<?= (int)$packagesPerRoll ?>"
                        >
                            <td><?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num"><?= $remaining ?></td>
                            <td class="num"><?= $gofroProduced ?></td>
                            <td class="num"><?= $gofroAvailable ?></td>
                            <td
                                class="num"
                                title="Остаток фильтров: <?= (int)$remaining ?>; доступно г/п: <?= (int)$gofroAvailable ?>; потребность: <?= (int)$gofroNeed ?>"
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
<?php if ($loadError === ''): ?>
<script>
(() => {
    const NORM_PER_UNIT = 2.5; // м на 1 гофропакет
    const packageCatalog = <?= json_encode($packageCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
    const stripPool = document.getElementById('strip-pool');
    const dragPreview = document.getElementById('drag-preview');
    if (!stripPool || !dragPreview) {
        return;
    }

    const rows = Array.from(document.querySelectorAll('tbody tr[data-row-key]'));
    const rowStateMap = new Map();
    rows.forEach((row) => {
        rowStateMap.set(row.dataset.rowKey, {
            row,
            order: String(row.dataset.order || ''),
            packageKey: String(row.dataset.packageKey || ''),
            packageName: String(row.dataset.packageName || ''),
            baseAvailable: parseInt(row.dataset.baseAvailable || '0', 10) || 0,
            gofroNeed: Math.max(0, parseInt(row.dataset.gofroNeed || '0', 10) || 0),
            packagesPerRoll: Math.max(0, parseInt(row.dataset.packagesPerRoll || '0', 10) || 0),
            dateCells: Array.from(row.querySelectorAll('td.date-cell[data-date]')),
            allocatedByDate: {},
        });
    });

    let strips = [];
    let stripSeq = 1;
    let allocations = [];
    let dragContext = null;
    const supplyMap = {};

    function bootstrapStrips() {
        strips = [];
        rowStateMap.forEach((state, rowKey) => {
            if (!state.packageKey || state.gofroNeed <= 0 || state.packagesPerRoll <= 0) {
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
                    package_label: state.packageName || getPackageLabel(state.packageKey),
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
                const qty = Math.max(0, parseInt(strip.qty_capacity || computeQtyFromLength(strip.length), 10) || 0);
                stripEl.innerHTML = `
                    <div class="strip-meta">
                        <span>${strip.package_label || getPackageLabel(strip.package_type)}</span>
                        <span>${qty} шт</span>
                    </div>
                `;
                stripEl.addEventListener('dragstart', (e) => {
                    const length = Number(strip.length) || 0;
                    const capQty = Math.max(0, parseInt(strip.qty_capacity || computeQtyFromLength(length), 10) || 0);
                    dragContext = {
                        strip_id: strip.id,
                        package_type: strip.package_type,
                        order: String(strip.order || ''),
                        length,
                        qty: capQty,
                        used_length: capQty * NORM_PER_UNIT,
                    };
                    if (e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', strip.id);
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
        const pkg = String(row.dataset.packageKey || '');
        const order = String(row.dataset.order || '');
        return pkg !== ''
            && pkg === dragContext.package_type
            && order !== ''
            && order === String(dragContext.order || '');
    }

    function updateCoverage() {
        rowStateMap.forEach((state, rowKey) => {
            let allocated = 0;
            Object.keys(state.allocatedByDate).forEach((d) => {
                allocated += parseInt(state.allocatedByDate[d] || 0, 10) || 0;
            });
            const available = state.baseAvailable + allocated;
            let coverageNeed = Math.max(0, available);
            let endIdx = -1;
            let partialIdx = -1;
            let startIdx = 0;

            const allocatedDates = Object.keys(state.allocatedByDate).filter((d) => (parseInt(state.allocatedByDate[d] || 0, 10) || 0) > 0);
            if (allocatedDates.length > 0) {
                let minIdx = null;
                state.dateCells.forEach((cell, idx) => {
                    const d = cell.dataset.date || '';
                    if (allocatedDates.includes(d)) {
                        if (minIdx === null || idx < minIdx) {
                            minIdx = idx;
                        }
                    }
                });
                if (minIdx !== null) {
                    startIdx = minIdx;
                }
            }

            let lastPlanIdx = -1;
            for (let i = startIdx; i < state.dateCells.length; i += 1) {
                const q = parseInt(state.dateCells[i].dataset.planQty || '0', 10) || 0;
                if (q > 0) {
                    lastPlanIdx = i;
                }
            }

            let acc = 0;
            for (let i = startIdx; i < state.dateCells.length; i += 1) {
                const cell = state.dateCells[i];
                const planQty = parseInt(cell.dataset.planQty || '0', 10) || 0;
                if (planQty <= 0) {
                    continue;
                }
                if (acc < coverageNeed && acc + planQty >= coverageNeed) {
                    endIdx = i;
                    if (acc + planQty > coverageNeed) {
                        partialIdx = i;
                    }
                    break;
                }
                acc += planQty;
            }
            if (coverageNeed > 0 && endIdx < 0 && lastPlanIdx >= startIdx) {
                endIdx = lastPlanIdx;
            }

            state.dateCells.forEach((cell, idx) => {
                cell.classList.remove('gantt-full', 'gantt-partial');
                if (endIdx >= 0 && idx >= startIdx && idx <= endIdx) {
                    cell.classList.add(idx === partialIdx ? 'gantt-partial' : 'gantt-full');
                }

                const date = cell.dataset.date || '';
                const qty = parseInt(state.allocatedByDate[date] || 0, 10) || 0;
                let supplyEl = cell.querySelector('.cell-supply');
                if (qty > 0) {
                    if (!supplyEl) {
                        supplyEl = document.createElement('div');
                        supplyEl.className = 'cell-supply';
                        cell.appendChild(supplyEl);
                    }
                    supplyEl.textContent = `+${qty}`;
                } else if (supplyEl) {
                    supplyEl.remove();
                }
            });
        });
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
        const stripIdx = strips.findIndex((s) => s.id === dragContext.strip_id);
        if (stripIdx < 0) {
            return;
        }
        const strip = strips[stripIdx];
        const qty = computeQtyFromLength(strip.length);
        if (qty <= 0) {
            return;
        }
        const usedLength = qty * NORM_PER_UNIT;
        if (usedLength > strip.length + 1e-9) {
            return;
        }

        allocations.push({
            strip_id: strip.id,
            row_key: rowKey,
            date,
            qty,
            used_length: usedLength,
        });
        if (!supplyMap[rowKey]) {
            supplyMap[rowKey] = {};
        }
        supplyMap[rowKey][date] = (parseInt(supplyMap[rowKey][date] || 0, 10) || 0) + qty;

        const state = rowStateMap.get(rowKey);
        if (state) {
            state.allocatedByDate[date] = (parseInt(state.allocatedByDate[date] || 0, 10) || 0) + qty;
        }

        strip.length = Math.max(0, strip.length - usedLength);
        if (strip.length <= 0.0001) {
            strips.splice(stripIdx, 1);
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

    bootstrapStrips();
    renderStrips();
    updateCoverage();
})();
</script>
<?php endif; ?>
</body>
</html>
