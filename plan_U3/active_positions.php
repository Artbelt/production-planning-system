<?php
/**
 * Активные позиции: строки по незакрытым позициям (заказано > изготовлено) в активных заявках.
 */

/** Макс. % выполнения для показа (включительно). Строго выше — строка не выводится; порог потом можно завязать на настройки. */
$activePositionsMaxCompletionPct = 80;

/** Погрешность при сравнении «сумма плана с сегодня» vs «остаток»: не хуже max(абс., % от остатка), но не больше остатка−1. */
$statePlanToleranceAbs = 2;
$statePlanTolerancePct = 5;

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
        .date-indicator.slim {
            min-width: 14px;
            text-align: center;
            padding: 0 3px;
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
                        ?>
                            <th class="num date-col" title="План сборки на <?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>">
                                <span class="date-head">
                                    <span><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="date-indicators" aria-hidden="true">
                                        <span class="date-indicator slim">П</span>
                                        <span class="date-indicator slim">D</span>
                                        <span class="date-indicator">600</span>
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
<script>
    (function () {
        const btn = document.getElementById('toggleIndicatorsBtn');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            const visible = document.body.classList.toggle('show-indicators');
            btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
        });
    })();
</script>
</body>
</html>
