<?php
/**
 * Активные позиции: строки по незакрытым позициям (заказано > изготовлено) в активных заявках.
 */

/** Макс. % выполнения для показа (включительно). Строго выше — строка не выводится; порог потом можно завязать на настройки. */
$activePositionsMaxCompletionPct = 80;

/** Погрешность при сравнении «сумма плана с сегодня» vs «остаток»: не хуже max(абс., % от остатка), но не больше остатка−1. */
$statePlanToleranceAbs = 2;
$statePlanTolerancePct = 5;
$indicatorNormWidth600 = 150;
$indicatorNormDiameter = 100;
$indicatorNormTotal = 300;

require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';

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

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan_u3');

/**
 * Нормализуем имя фильтра для сопоставления позиций заявки и записей планов.
 */
function normalizeFilterKey(string $name): string
{
    $name = preg_replace('/\[.*$/u', '', $name);
    $name = trim($name);
    return mb_strtoupper($name, 'UTF-8');
}

/**
 * Допуск при проверке «план с сегодня» покрывает остаток (шт. и %, с ограничением сверху).
 */
function activePositionsPlanTolerance(int $remaining, int $absTol, int $pctTol): int
{
    if ($remaining <= 0) {
        return 0;
    }
    $fromPct = (int) ceil($remaining * ($pctTol / 100));
    $raw = max($absTol, $fromPct);

    return min(max(0, $remaining - 1), $raw);
}

$maxPct = max(0, min(100, (int) $activePositionsMaxCompletionPct));

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
  AND COALESCE(prod.produced, 0) * 100 <= {$maxPct} * agg.ordered
ORDER BY agg.order_number, agg.filter_name
";

$rows = [];
try {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// План сборки по датам: [order|filter] => [Y-m-d => qty]
$buildPlanMap = [];
$buildPlanDates = [];
$todayIso = (new DateTime())->format('Y-m-d');
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
        if ($order === '' || $filter === '' || $date === '') {
            continue;
        }
        $key = $order . '|' . normalizeFilterKey($filter);
        if (!isset($buildPlanMap[$key])) {
            $buildPlanMap[$key] = [];
        }
        if ($date < $todayIso) {
            continue;
        }
        if (!isset($buildPlanMap[$key][$date])) {
            $buildPlanMap[$key][$date] = 0;
        }
        $buildPlanMap[$key][$date] += $qty;
        if (!isset($buildPlanDates[$date])) {
            $buildPlanDates[$date] = true;
        }
    }
    $buildPlanDates = array_keys($buildPlanDates);
    sort($buildPlanDates);
} catch (Throwable $e) {
    $buildPlanMap = [];
    $buildPlanDates = [];
}

$filterMetaByKey = [];
if (!empty($rows)) {
    $rawFilters = [];
    foreach ($rows as $row) {
        $rawFilter = (string)($row['filter_name'] ?? '');
        if ($rawFilter === '') {
            continue;
        }
        $rawFilters[$rawFilter] = true;
    }

    if (!empty($rawFilters)) {
        try {
            $rawFilterList = array_keys($rawFilters);
            $placeholders = implode(',', array_fill(0, count($rawFilterList), '?'));
            $sqlMeta = "
                SELECT
                    rfs.filter AS filter_name,
                    rfs.press AS press,
                    rfs.Diametr_outer AS diametr_outer,
                    ppr.p_p_paper_width AS paper_width_mm
                FROM round_filter_structure rfs
                LEFT JOIN paper_package_round ppr
                    ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                WHERE rfs.filter IN ($placeholders)
            ";
            $stmtMeta = $pdo->prepare($sqlMeta);
            $stmtMeta->execute($rawFilterList);
            $metaRows = $stmtMeta->fetchAll(PDO::FETCH_ASSOC);

            foreach ($metaRows as $metaRow) {
                $metaFilter = (string)($metaRow['filter_name'] ?? '');
                $metaKey = normalizeFilterKey($metaFilter);
                if ($metaKey === '') {
                    continue;
                }
                if (!isset($filterMetaByKey[$metaKey])) {
                    $filterMetaByKey[$metaKey] = [
                        'press' => false,
                        'diametr_outer' => null,
                        'paper_width_mm' => null,
                    ];
                }

                $filterMetaByKey[$metaKey]['press'] = $filterMetaByKey[$metaKey]['press']
                    || (isset($metaRow['press']) && (string)$metaRow['press'] === '1');

                if ($metaRow['diametr_outer'] !== null && $filterMetaByKey[$metaKey]['diametr_outer'] === null) {
                    $filterMetaByKey[$metaKey]['diametr_outer'] = (float)$metaRow['diametr_outer'];
                }
                if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$metaKey]['paper_width_mm'] === null) {
                    $filterMetaByKey[$metaKey]['paper_width_mm'] = (float)$metaRow['paper_width_mm'];
                }
            }
        } catch (Throwable $e) {
            $filterMetaByKey = [];
        }
    }
}

$dateIndicators = [];
foreach ($buildPlanDates as $planDate) {
    $dateIndicators[(string) $planDate] = [
        'press_filters' => [],
        'diameter_qty' => 0,
        'w600_qty' => 0,
        'total_qty' => 0,
    ];
}

if (!empty($rows) && !empty($buildPlanDates)) {
    foreach ($rows as $row) {
        $rawOrder = (string)($row['order_number'] ?? '');
        $rawFilter = (string)($row['filter_name'] ?? '');
        if ($rawOrder === '' || $rawFilter === '') {
            continue;
        }

        $planKey = $rawOrder . '|' . normalizeFilterKey($rawFilter);
        $planQtyByDate = $buildPlanMap[$planKey] ?? [];
        if (empty($planQtyByDate)) {
            continue;
        }

        $meta = $filterMetaByKey[normalizeFilterKey($rawFilter)] ?? null;
        $isPress = !empty($meta['press']);
        $isLargeDiameter = isset($meta['diametr_outer']) && $meta['diametr_outer'] !== null
            && (float)$meta['diametr_outer'] > 250
            && isset($meta['paper_width_mm']) && $meta['paper_width_mm'] !== null
            && (float)$meta['paper_width_mm'] > 400;
        $isWidth600 = isset($meta['paper_width_mm']) && $meta['paper_width_mm'] !== null && (float)$meta['paper_width_mm'] > 450;
        $normalizedFilter = normalizeFilterKey($rawFilter);

        foreach ($buildPlanDates as $planDate) {
            $dateKey = (string)$planDate;
            $planQty = (int)($planQtyByDate[$dateKey] ?? 0);
            if ($planQty <= 0) {
                continue;
            }
            if (!isset($dateIndicators[$dateKey])) {
                continue;
            }
            $dateIndicators[$dateKey]['total_qty'] += $planQty;
            if ($isPress) {
                $dateIndicators[$dateKey]['press_filters'][$normalizedFilter] = true;
            }
            if ($isLargeDiameter) {
                $dateIndicators[$dateKey]['diameter_qty'] += $planQty;
            }
            if ($isWidth600) {
                $dateIndicators[$dateKey]['w600_qty'] += $planQty;
            }
        }
    }
}

$pageTitle = 'Активные позиции';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — U3</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2457e6;
            --radius: 12px;
            --shadow: 0 2px 12px rgba(2, 8, 20, .06);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
        }
        .wrap {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 12px 10px 18px;
        }
        .top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .top h1 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 600;
        }
        .muted { color: var(--muted); font-size: 13px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: auto;
        }
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 10px;
        }
        .toolbar-btn {
            appearance: none;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--ink);
            border-radius: 8px;
            padding: 6px 10px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }
        .toolbar-btn:hover {
            border-color: #c7d2fe;
            background: #f8faff;
        }
        .toolbar-btn[aria-pressed="true"] {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        .toolbar-btn.secondary {
            font-weight: 500;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            line-height: 1.15;
        }
        th, td {
            padding: 2px 5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            white-space: nowrap;
            width: 1%;
        }
        th:last-child, td:last-child { border-right: 0; }
        th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 11px;
            color: #374151;
        }
        tr:last-child td { border-bottom: 0; }
        tr:hover td { background: #fafbfc; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        th.date-col, td.date-col { text-align: center; }

        /* Гистограмма выполнения в ячейке «Позиция» */
        td.pos-cell {
            position: relative;
            vertical-align: middle;
            min-width: 0;
            overflow: hidden;
        }
        td.pos-cell .pos-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--pct, 0%);
            pointer-events: none;
            border-radius: 0 4px 4px 0;
            transition: width 0.2s ease, opacity 0.15s;
        }
        td.pos-cell .pos-meta {
            position: relative;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px 6px;
        }
        td.pos-cell .pos-name {
            font-weight: 500;
            word-break: break-word;
        }
        td.pos-cell .pos-indicators {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            flex-wrap: wrap;
        }
        body.hide-row-indicators td.pos-cell .pos-indicators {
            display: none;
        }
        td.pos-cell .pos-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            line-height: 1;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #4b5563;
        }
        td.pos-cell .pos-indicator.p {
            border-color: #f59e0b;
            color: #b45309;
            background: #fffbeb;
        }
        td.pos-cell .pos-indicator.d {
            border-color: #8b5cf6;
            color: #6d28d9;
            background: #f5f3ff;
        }
        td.pos-cell .pos-indicator.w600 {
            border-color: #3b82f6;
            color: #1d4ed8;
            background: #eff6ff;
        }
        td.pos-cell .pos-pct {
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.75);
            padding: 1px 4px;
            border-radius: 4px;
            border: 1px solid var(--border);
        }
        .order-cell form { display: inline; margin: 0; }
        .order-cell button {
            appearance: none;
            border: 0;
            background: none;
            color: var(--accent);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
        }
        .order-cell button:hover { color: #1e47c5; }
        td.state-cell { white-space: normal; }
        .state-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.25;
        }
        .state-lag {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .state-ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .alert {
            padding: 14px 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius);
            color: #991b1b;
        }
        .date-head {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            min-width: 34px;
        }
        .date-indicators {
            display: none;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            color: #4b5563;
        }
        body.show-indicators .date-indicators {
            display: flex;
        }
        .date-indicator {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            border-radius: 4px;
            padding: 0 5px;
            font-size: 10px;
            line-height: 1.3;
            font-weight: 600;
            letter-spacing: .01em;
            text-align: center;
        }
        .date-indicator > .txt {
            position: relative;
            z-index: 1;
        }
        .date-indicator.active {
            opacity: 1;
        }
        .date-indicator.marker-p.active {
            border-color: #f59e0b;
            color: #b45309;
            background: #fffbeb;
        }
        .date-indicator.meter {
            position: relative;
            overflow: hidden;
            min-width: 26px;
        }
        .date-indicator.meter::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--fill, 0%);
            background: var(--meter-color, #dbeafe);
            opacity: .85;
            z-index: 0;
        }
        .date-indicator.marker-d.active {
            border-color: #8b5cf6;
            color: #6d28d9;
            background: #f5f3ff;
            --meter-color: #ddd6fe;
        }
        .date-indicator.marker-600.active {
            border-color: #3b82f6;
            color: #1d4ed8;
            background: #eff6ff;
            --meter-color: #bfdbfe;
        }
        .date-indicator.marker-total.active {
            border-color: #14b8a6;
            color: #0f766e;
            background: #f0fdfa;
            --meter-color: #99f6e4;
        }
        .date-indicator.over {
            border-color: #dc2626 !important;
            color: #ffffff !important;
            background: #ef4444 !important;
            --meter-color: rgba(127, 29, 29, 0.45);
        }
        .date-indicator.marker-600.over,
        .date-indicator.marker-d.over,
        .date-indicator.marker-total.over {
            --meter-color: rgba(127, 29, 29, 0.45);
        }
        .date-indicator:not(.active) {
            opacity: .45;
        }
        .date-indicator.slim {
            min-width: 14px;
            text-align: center;
            padding: 0 3px;
        }
        .ind-modal[hidden] { display: none; }
        .ind-modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, .45);
            padding: 16px;
        }
        .ind-modal__dialog {
            width: min(460px, 100%);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 14px 40px rgba(2, 8, 20, .25);
            overflow: hidden;
        }
        .ind-modal__head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 15px;
        }
        .ind-modal__body {
            padding: 12px 14px;
            display: grid;
            gap: 10px;
        }
        .ind-legend {
            margin-top: 4px;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f9fafb;
            display: grid;
            gap: 6px;
        }
        .ind-legend__title {
            font-size: 13px;
            font-weight: 700;
            color: #374151;
        }
        .ind-legend__line {
            font-size: 12px;
            color: #4b5563;
        }
        .ind-legend__item {
            display: grid;
            grid-template-columns: 32px 1fr;
            gap: 8px;
            align-items: start;
            font-size: 12px;
            color: #374151;
        }
        .ind-legend__marker {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            padding: 1px 5px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            background: #fff;
            font-weight: 700;
        }
        .ind-field {
            display: grid;
            grid-template-columns: 1fr 120px;
            align-items: center;
            gap: 8px;
        }
        .ind-field label {
            font-size: 13px;
            color: #374151;
        }
        .ind-field input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 6px 8px;
            font: inherit;
        }
        .ind-modal__foot {
            padding: 12px 14px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </div>
    <p class="muted" style="margin: 0 0 16px;">
        Позиции по заявкам без признака «скрыта», у которых заказано больше, чем изготовлено фильтров по данным выпуска.
        Показаны позиции с процентом выполнения не выше <?= (int) $maxPct ?>% (включительно).
        <br>
        Состояние «Отстаёт»: сумма плана сборки с сегодняшнего дня меньше остатка с допуском (не ниже <?= (int) $statePlanToleranceAbs ?> шт. и не ниже <?= (int) $statePlanTolerancePct ?>% от остатка).
    </p>

    <?php if (!empty($loadError)): ?>
        <div class="alert">Ошибка загрузки: <?= htmlspecialchars($loadError) ?></div>
    <?php else: ?>
        <div class="toolbar">
            <button type="button" id="toggleIndicatorsBtn" class="toolbar-btn" aria-pressed="false">Индикаторы</button>
            <button type="button" id="toggleRowIndicatorsBtn" class="toolbar-btn secondary" aria-pressed="true">Индикаторы у названий</button>
            <button type="button" id="openIndicatorSettingsBtn" class="toolbar-btn secondary">Настройка индикаторов</button>
        </div>
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Позиция</th>
                        <th class="num">Заказано</th>
                        <th class="num">Изготовлено</th>
                        <th class="num">Остаток</th>
                        <th>Состояние</th>
                        <th>Заявка</th>
                        <?php foreach ($buildPlanDates as $planDate):
                            $dateObj = DateTime::createFromFormat('Y-m-d', (string) $planDate);
                            $dateLabel = $dateObj ? $dateObj->format('d.m') : (string) $planDate;
                            $indicatorState = $dateIndicators[(string)$planDate] ?? ['press_filters' => [], 'diameter_qty' => 0, 'w600_qty' => 0, 'total_qty' => 0];
                            $pressCount = count($indicatorState['press_filters'] ?? []);
                            $pressClass = 'date-indicator slim marker-p';
                            $pressTitle = 'Нет позиций под пресс';
                            if ($pressCount === 1) {
                                $pressClass .= ' active';
                                $pressTitle = 'В смене 1 фильтр под пресс (норма)';
                            } elseif ($pressCount > 1) {
                                $pressClass .= ' active over';
                                $pressTitle = 'В смене ' . $pressCount . ' фильтра(ов) под пресс (должен быть только 1)';
                            }

                            $w600Qty = (int)($indicatorState['w600_qty'] ?? 0);
                            $w600Pct = $indicatorNormWidth600 > 0
                                ? min(100, ($w600Qty / (float)$indicatorNormWidth600) * 100)
                                : 0;
                            $w600Class = 'date-indicator marker-600 meter';
                            if ($w600Qty > 0) {
                                $w600Class .= ' active';
                            }
                            if ($w600Qty > (int)$indicatorNormWidth600) {
                                $w600Class .= ' over';
                            }
                            $w600Title = '600: ' . $w600Qty . ' шт из нормы ' . (int)$indicatorNormWidth600 . ' шт';

                            $diameterQty = (int)($indicatorState['diameter_qty'] ?? 0);
                            $diameterPct = $indicatorNormDiameter > 0
                                ? min(100, ($diameterQty / (float)$indicatorNormDiameter) * 100)
                                : 0;
                            $diameterClass = 'date-indicator slim marker-d meter';
                            if ($diameterQty > 0) {
                                $diameterClass .= ' active';
                            }
                            if ($diameterQty > (int)$indicatorNormDiameter) {
                                $diameterClass .= ' over';
                            }
                            $diameterTitle = 'D (>250 и >400): ' . $diameterQty . ' шт из нормы ' . (int)$indicatorNormDiameter . ' шт';

                            $totalQty = (int)($indicatorState['total_qty'] ?? 0);
                            $totalPct = $indicatorNormTotal > 0
                                ? min(100, ($totalQty / (float)$indicatorNormTotal) * 100)
                                : 0;
                            $totalClass = 'date-indicator marker-total meter' . ($totalQty > 0 ? ' active' : '');
                            if ($totalQty > (int)$indicatorNormTotal) {
                                $totalClass .= ' over';
                            }
                            $totalTitle = 'Всего в смену: ' . $totalQty . ' шт из нормы ' . (int)$indicatorNormTotal . ' шт';
                        ?>
                            <th class="num date-col" title="План сборки на <?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="date-head">
                                    <span><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="date-indicators" aria-hidden="true">
                                        <span class="<?= $pressClass ?>" data-kind="press" data-press-count="<?= $pressCount ?>" title="<?= htmlspecialchars($pressTitle, ENT_QUOTES, 'UTF-8') ?>"><span class="txt">П</span></span>
                                        <span
                                            class="<?= $diameterClass ?>"
                                            data-kind="d"
                                            data-qty="<?= $diameterQty ?>"
                                            style="--fill: <?= htmlspecialchars(number_format($diameterPct, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"
                                            title="<?= htmlspecialchars($diameterTitle, ENT_QUOTES, 'UTF-8') ?>"
                                        ><span class="txt">D</span></span>
                                        <span
                                            class="<?= $w600Class ?>"
                                            data-kind="w600"
                                            data-qty="<?= $w600Qty ?>"
                                            style="--fill: <?= htmlspecialchars(number_format($w600Pct, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"
                                            title="<?= htmlspecialchars($w600Title, ENT_QUOTES, 'UTF-8') ?>"
                                        ><span class="txt">600</span></span>
                                        <span
                                            class="<?= $totalClass ?>"
                                            data-kind="total"
                                            data-qty="<?= $totalQty ?>"
                                            style="--fill: <?= htmlspecialchars(number_format($totalPct, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>%;"
                                            title="<?= htmlspecialchars($totalTitle, ENT_QUOTES, 'UTF-8') ?>"
                                        ><span class="txt"><?= $totalQty ?></span></span>
                                    </span>
                                </span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= 6 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
                            Нет незакрытых позиций по активным заявкам.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $ord = htmlspecialchars((string)($r['order_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $fil = htmlspecialchars((string)($r['filter_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $rawOrder = (string)($r['order_number'] ?? '');
                        $rawFilter = (string)($r['filter_name'] ?? '');
                        $ordered = (int)($r['ordered'] ?? 0);
                        $produced = (int)($r['produced'] ?? 0);
                        $pct = $ordered > 0 ? min(100, ($produced / $ordered) * 100) : 0;
                        $pctStr = number_format(round($pct, 1), 1, ',', ' ');
                        $hue = (int) round($pct * 1.2);
                        $planKey = $rawOrder . '|' . normalizeFilterKey($rawFilter);
                        $planQtyByDate = $buildPlanMap[$planKey] ?? [];
                        $remaining = max(0, $ordered - $produced);
                        $plannedFromToday = 0;
                        foreach ($planQtyByDate as $pq) {
                            $plannedFromToday += (int) $pq;
                        }
                        $planTolerance = activePositionsPlanTolerance(
                            $remaining,
                            (int) $statePlanToleranceAbs,
                            (int) $statePlanTolerancePct
                        );
                        $planFloor = max(0, $remaining - $planTolerance);
                        $isLagging = $remaining > 0 && $plannedFromToday < $planFloor;
                        $stateTitle = 'План сборки с сегодня: ' . $plannedFromToday
                            . ' шт.; остаток: ' . $remaining
                            . ' шт.; допуск: ' . $planTolerance
                            . ' шт.; нужно не меньше ' . $planFloor . ' шт. в плане.';
                        $rowMeta = $filterMetaByKey[normalizeFilterKey($rawFilter)] ?? null;
                        $rowHasPress = !empty($rowMeta['press']);
                        $rowHasD = isset($rowMeta['diametr_outer']) && $rowMeta['diametr_outer'] !== null
                            && (float)$rowMeta['diametr_outer'] > 250
                            && isset($rowMeta['paper_width_mm']) && $rowMeta['paper_width_mm'] !== null
                            && (float)$rowMeta['paper_width_mm'] > 400;
                        $rowHas600 = isset($rowMeta['paper_width_mm']) && $rowMeta['paper_width_mm'] !== null
                            && (float)$rowMeta['paper_width_mm'] > 450;
                    ?>
                    <tr>
                        <td
                            class="pos-cell"
                            style="--pct: <?= htmlspecialchars((string) round($pct, 4), ENT_QUOTES, 'UTF-8') ?>%;"
                            title="Выполнение позиции: <?= htmlspecialchars($pctStr, ENT_QUOTES, 'UTF-8') ?>% (<?= (int) $produced ?> из <?= (int) $ordered ?>)"
                        >
                            <span class="pos-fill" style="background: hsla(<?= $hue ?>, 65%, 52%, 0.28);"></span>
                            <div class="pos-meta">
                                <span class="pos-name"><?= $fil !== '' ? $fil : '—' ?></span>
                                <span class="pos-indicators">
                                    <?php if ($rowHasD): ?><span class="pos-indicator d" title="Большой диаметр >250 и ширина бумаги >400">D</span><?php endif; ?>
                                    <?php if ($rowHasPress): ?><span class="pos-indicator p" title="Фильтр под пресс">П</span><?php endif; ?>
                                    <?php if ($rowHas600): ?><span class="pos-indicator w600" title="Ширина бумаги >450">600</span><?php endif; ?>
                                </span>
                                <span class="pos-pct"><?= htmlspecialchars($pctStr, ENT_QUOTES, 'UTF-8') ?>%</span>
                            </div>
                        </td>
                        <td class="num"><?= $ordered ?></td>
                        <td class="num"><?= $produced ?></td>
                        <td class="num"><?= $remaining ?></td>
                        <td class="state-cell" title="<?= htmlspecialchars($stateTitle, ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($isLagging): ?>
                                <span class="state-badge state-lag">Отстаёт</span>
                            <?php else: ?>
                                <span class="state-badge state-ok">В плане</span>
                            <?php endif; ?>
                        </td>
                        <td class="order-cell">
                            <form action="show_order.php" method="post" target="_blank" rel="noopener">
                                <input type="hidden" name="order_number" value="<?= $ord ?>">
                                <button type="submit"><?= $ord ?></button>
                            </form>
                        </td>
                        <?php foreach ($buildPlanDates as $planDate):
                            $planQty = (int)($planQtyByDate[$planDate] ?? 0);
                        ?>
                            <td class="num date-col"><?= $planQty > 0 ? $planQty : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<div id="indicatorSettingsModal" class="ind-modal" hidden>
    <div class="ind-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="indicatorSettingsTitle">
        <div id="indicatorSettingsTitle" class="ind-modal__head">Настройка индикаторов</div>
        <div class="ind-modal__body">
            <div class="ind-field">
                <label for="indMaxPressInput">Максимум фильтров под пресс (П)</label>
                <input id="indMaxPressInput" type="number" min="1" step="1" value="1">
            </div>
            <div class="ind-field">
                <label for="indNorm600Input">Норма для 600, шт/смена</label>
                <input id="indNorm600Input" type="number" min="1" step="1" value="<?= (int)$indicatorNormWidth600 ?>">
            </div>
            <div class="ind-field">
                <label for="indNormDInput">Норма для D, шт/смена</label>
                <input id="indNormDInput" type="number" min="1" step="1" value="<?= (int)$indicatorNormDiameter ?>">
            </div>
            <div class="ind-field">
                <label for="indNormTotalInput">Количество фильтров в смену, шт</label>
                <input id="indNormTotalInput" type="number" min="1" step="1" value="<?= (int)$indicatorNormTotal ?>">
            </div>
            <div class="ind-legend">
                <div class="ind-legend__title">Легенда</div>
                <div class="ind-legend__line">300 фильтров с ППУ.</div>
                <div class="ind-legend__line">150 фильтров с крышками.</div>
                <div class="ind-legend__line">1 фильтр под пресс П</div>
                <div class="ind-legend__item">
                    <span class="ind-legend__marker">В</span>
                    <span>Пластиковая вставка</span>
                </div>
                <div class="ind-legend__item">
                    <span class="ind-legend__marker">D</span>
                    <span>Большой диаметр &gt;250, ширина бумаги &gt;400 мм, алюминиевые формы (до 100 шт)</span>
                </div>
                <div class="ind-legend__item">
                    <span class="ind-legend__marker">600</span>
                    <span>Ширина бумаги &gt;450 (до 150 шт)</span>
                </div>
            </div>
        </div>
        <div class="ind-modal__foot">
            <button type="button" id="indicatorSettingsResetBtn" class="toolbar-btn secondary">Сброс</button>
            <button type="button" id="indicatorSettingsCancelBtn" class="toolbar-btn secondary">Отмена</button>
            <button type="button" id="indicatorSettingsSaveBtn" class="toolbar-btn">Сохранить</button>
        </div>
    </div>
</div>
<script>
    (function () {
        const btn = document.getElementById('toggleIndicatorsBtn');
        const rowIndicatorsBtn = document.getElementById('toggleRowIndicatorsBtn');
        const openSettingsBtn = document.getElementById('openIndicatorSettingsBtn');
        const modal = document.getElementById('indicatorSettingsModal');
        const saveBtn = document.getElementById('indicatorSettingsSaveBtn');
        const cancelBtn = document.getElementById('indicatorSettingsCancelBtn');
        const resetBtn = document.getElementById('indicatorSettingsResetBtn');
        const maxPressInput = document.getElementById('indMaxPressInput');
        const norm600Input = document.getElementById('indNorm600Input');
        const normDInput = document.getElementById('indNormDInput');
        const normTotalInput = document.getElementById('indNormTotalInput');
        if (!btn || !rowIndicatorsBtn || !modal || !openSettingsBtn || !saveBtn || !cancelBtn || !resetBtn || !maxPressInput || !norm600Input || !normDInput || !normTotalInput) {
            return;
        }
        const storageKey = 'activePositionsIndicatorSettings';
        const rowIndicatorsStorageKey = 'activePositionsRowIndicatorsVisible';
        const defaults = {
            maxPress: 1,
            norm600: <?= (int)$indicatorNormWidth600 ?>,
            normD: <?= (int)$indicatorNormDiameter ?>,
            normTotal: <?= (int)$indicatorNormTotal ?>,
        };

        function getSettings() {
            try {
                const raw = localStorage.getItem(storageKey);
                if (!raw) {
                    return { ...defaults };
                }
                const parsed = JSON.parse(raw);
                return {
                    maxPress: Math.max(1, parseInt(parsed.maxPress, 10) || defaults.maxPress),
                    norm600: Math.max(1, parseInt(parsed.norm600, 10) || defaults.norm600),
                    normD: Math.max(1, parseInt(parsed.normD, 10) || defaults.normD),
                    normTotal: Math.max(1, parseInt(parsed.normTotal, 10) || defaults.normTotal),
                };
            } catch (e) {
                return { ...defaults };
            }
        }

        function syncForm(settings) {
            maxPressInput.value = String(settings.maxPress);
            norm600Input.value = String(settings.norm600);
            normDInput.value = String(settings.normD);
            normTotalInput.value = String(settings.normTotal);
        }

        function setFill(el, pct) {
            el.style.setProperty('--fill', `${Math.max(0, Math.min(100, pct))}%`);
        }

        function applySettings(settings) {
            document.querySelectorAll('.date-indicator[data-kind="press"]').forEach(function (el) {
                const count = parseInt(el.getAttribute('data-press-count') || '0', 10) || 0;
                const isOver = count > settings.maxPress;
                el.classList.toggle('active', count > 0);
                el.classList.toggle('over', isOver);
                if (count <= 0) {
                    el.title = 'Нет позиций под пресс';
                } else if (isOver) {
                    el.title = `В смене ${count} фильтра(ов) под пресс (норма: до ${settings.maxPress})`;
                } else {
                    el.title = `В смене ${count} фильтра(ов) под пресс (норма: до ${settings.maxPress})`;
                }
            });

            document.querySelectorAll('.date-indicator[data-kind="w600"]').forEach(function (el) {
                const qty = parseInt(el.getAttribute('data-qty') || '0', 10) || 0;
                const pct = settings.norm600 > 0 ? (qty / settings.norm600) * 100 : 0;
                const isOver = qty > settings.norm600;
                el.classList.toggle('active', qty > 0);
                el.classList.toggle('over', isOver);
                setFill(el, pct);
                el.title = `600: ${qty} шт из нормы ${settings.norm600} шт`;
            });

            document.querySelectorAll('.date-indicator[data-kind="d"]').forEach(function (el) {
                const qty = parseInt(el.getAttribute('data-qty') || '0', 10) || 0;
                const pct = settings.normD > 0 ? (qty / settings.normD) * 100 : 0;
                const isOver = qty > settings.normD;
                el.classList.toggle('active', qty > 0);
                el.classList.toggle('over', isOver);
                setFill(el, pct);
                el.title = `D (>250 и >400): ${qty} шт из нормы ${settings.normD} шт`;
            });

            document.querySelectorAll('.date-indicator[data-kind="total"]').forEach(function (el) {
                const qty = parseInt(el.getAttribute('data-qty') || '0', 10) || 0;
                const pct = settings.normTotal > 0 ? (qty / settings.normTotal) * 100 : 0;
                const isOver = qty > settings.normTotal;
                el.classList.toggle('active', qty > 0);
                el.classList.toggle('over', isOver);
                setFill(el, pct);
                el.title = `Всего в смену: ${qty} шт из нормы ${settings.normTotal} шт`;
            });
        }

        function openModal() {
            syncForm(getSettings());
            modal.hidden = false;
        }

        function closeModal() {
            modal.hidden = true;
        }

        applySettings(getSettings());
        try {
            const rowVisibleRaw = localStorage.getItem(rowIndicatorsStorageKey);
            const rowVisible = rowVisibleRaw === null ? true : rowVisibleRaw === '1';
            document.body.classList.toggle('hide-row-indicators', !rowVisible);
            rowIndicatorsBtn.setAttribute('aria-pressed', rowVisible ? 'true' : 'false');
        } catch (e) {
            rowIndicatorsBtn.setAttribute('aria-pressed', 'true');
        }

        btn.addEventListener('click', function () {
            const visible = document.body.classList.toggle('show-indicators');
            btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
        });
        rowIndicatorsBtn.addEventListener('click', function () {
            const hidden = document.body.classList.toggle('hide-row-indicators');
            const visible = !hidden;
            rowIndicatorsBtn.setAttribute('aria-pressed', visible ? 'true' : 'false');
            try {
                localStorage.setItem(rowIndicatorsStorageKey, visible ? '1' : '0');
            } catch (e) {
                // ignore storage write errors
            }
        });

        openSettingsBtn.addEventListener('click', openModal);
        cancelBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
        resetBtn.addEventListener('click', function () {
            localStorage.removeItem(storageKey);
            const settings = { ...defaults };
            syncForm(settings);
            applySettings(settings);
        });
        saveBtn.addEventListener('click', function () {
            const settings = {
                maxPress: Math.max(1, parseInt(maxPressInput.value, 10) || defaults.maxPress),
                norm600: Math.max(1, parseInt(norm600Input.value, 10) || defaults.norm600),
                normD: Math.max(1, parseInt(normDInput.value, 10) || defaults.normD),
                normTotal: Math.max(1, parseInt(normTotalInput.value, 10) || defaults.normTotal),
            };
            localStorage.setItem(storageKey, JSON.stringify(settings));
            applySettings(settings);
            closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });
    })();
</script>
</body>
</html>
