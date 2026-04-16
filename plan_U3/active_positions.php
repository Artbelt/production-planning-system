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

$todayIso = (new DateTime())->format('Y-m-d');

/**
 * AJAX: перенос распланированных позиций между датами.
 * mode=single — переносит количество выбранной смены;
 * mode=block — сдвигает только непрерывный блок смен вокруг выбранной даты.
 */
function applyBuildPlanMove(
    PDO $pdo,
    string $todayIso,
    string $order,
    string $filter,
    string $fromDate,
    string $toDate,
    string $mode
): void {
    if ($mode === 'single') {
        $qtyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(qty), 0) AS qty
            FROM build_plans
            WHERE shift='D' AND order_number=? AND filter=? AND day_date=?
        ");
        $qtyStmt->execute([$order, $filter, $fromDate]);
        $qtyFrom = (int)($qtyStmt->fetchColumn() ?: 0);
        if ($qtyFrom <= 0) {
            throw new RuntimeException('В исходной смене нет количества для переноса.');
        }
        $qtyStmt->execute([$order, $filter, $toDate]);
        $qtyTo = (int)($qtyStmt->fetchColumn() ?: 0);
        if ($qtyTo > 0) {
            throw new RuntimeException('Нельзя складывать смены одной позиции: целевая дата уже содержит план.');
        }

        $delStmt = $pdo->prepare("
            DELETE FROM build_plans
            WHERE shift='D' AND order_number=? AND filter=? AND day_date=?
        ");
        $delStmt->execute([$order, $filter, $fromDate]);

        $insStmt = $pdo->prepare("
            INSERT INTO build_plans (order_number, filter, day_date, shift, qty)
            VALUES (?, ?, ?, 'D', ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        $insStmt->execute([$order, $filter, $toDate, $qtyFrom]);
        return;
    }

    if ($mode === 'block') {
        $allStmt = $pdo->prepare("
            SELECT day_date, SUM(qty) AS qty
            FROM build_plans
            WHERE shift='D' AND order_number=? AND filter=? AND day_date>=?
            GROUP BY day_date
            ORDER BY day_date
        ");
        $allStmt->execute([$order, $filter, $todayIso]);
        $allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($allRows)) {
            throw new RuntimeException('Нет данных для сдвига блока.');
        }

        $allMap = [];
        foreach ($allRows as $row) {
            $date = (string)($row['day_date'] ?? '');
            $qty = (int)($row['qty'] ?? 0);
            if ($date === '' || $qty <= 0) {
                continue;
            }
            $allMap[$date] = $qty;
        }
        $sourceQty = (int)($allMap[$fromDate] ?? 0);
        if ($sourceQty <= 0) {
            throw new RuntimeException('В выбранной смене нет количества для сдвига блока.');
        }

        $fromDt = new DateTimeImmutable($fromDate);
        $toDt = new DateTimeImmutable($toDate);
        $offsetDays = (int)$fromDt->diff($toDt)->format('%r%a');
        if ($offsetDays === 0) {
            throw new RuntimeException('Для сдвига блока нужна другая целевая дата.');
        }

        $blockDates = [];
        $cursor = $fromDt;
        while (isset($allMap[$cursor->format('Y-m-d')]) && (int)$allMap[$cursor->format('Y-m-d')] > 0) {
            array_unshift($blockDates, $cursor->format('Y-m-d'));
            $cursor = $cursor->modify('-1 day');
        }
        $cursor = $fromDt->modify('+1 day');
        while (isset($allMap[$cursor->format('Y-m-d')]) && (int)$allMap[$cursor->format('Y-m-d')] > 0) {
            $blockDates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        if (empty($blockDates)) {
            throw new RuntimeException('Не удалось определить блок смен для сдвига.');
        }

        $resultMap = $allMap;
        $shiftedBlock = [];
        foreach ($blockDates as $date) {
            $qty = (int)($allMap[$date] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            unset($resultMap[$date]);
            $targetDate = (new DateTimeImmutable($date))
                ->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')
                ->format('Y-m-d');
            if ($targetDate < $todayIso) {
                throw new RuntimeException('Сдвиг блока уводит часть позиции в прошлые даты.');
            }
            if (isset($resultMap[$targetDate]) && (int)$resultMap[$targetDate] > 0) {
                throw new RuntimeException('Нельзя складывать смены одной позиции: при сдвиге блока возникло наложение на дату ' . $targetDate . '.');
            }
            if (!isset($shiftedBlock[$targetDate])) {
                $shiftedBlock[$targetDate] = 0;
            }
            $shiftedBlock[$targetDate] += $qty;
        }
        foreach ($shiftedBlock as $date => $qty) {
            if (!isset($resultMap[$date])) {
                $resultMap[$date] = 0;
            }
            $resultMap[$date] += (int)$qty;
        }

        $delAllStmt = $pdo->prepare("
            DELETE FROM build_plans
            WHERE shift='D' AND order_number=? AND filter=? AND day_date>=?
        ");
        $delAllStmt->execute([$order, $filter, $todayIso]);

        $insStmt = $pdo->prepare("
            INSERT INTO build_plans (order_number, filter, day_date, shift, qty)
            VALUES (?, ?, ?, 'D', ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        ksort($resultMap);
        foreach ($resultMap as $date => $qty) {
            $qtyInt = (int)$qty;
            if ($qtyInt <= 0) {
                continue;
            }
            $insStmt->execute([$order, $filter, (string)$date, $qtyInt]);
        }
        return;
    }

    $srcStmt = $pdo->prepare("
        SELECT day_date, SUM(qty) AS qty
        FROM build_plans
        WHERE shift='D' AND order_number=? AND filter=? AND day_date>=?
        GROUP BY day_date
        ORDER BY day_date
    ");
    $srcStmt->execute([$order, $filter, $todayIso]);
    $sourceRows = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sourceRows)) {
        throw new RuntimeException('Нет данных для сдвига по позиции.');
    }

    $fromDt = new DateTimeImmutable($fromDate);
    $toDt = new DateTimeImmutable($toDate);
    $offsetDays = (int)$fromDt->diff($toDt)->format('%r%a');
    if ($offsetDays === 0) {
        throw new RuntimeException('Для сдвига позиции нужна другая целевая дата.');
    }

    $shifted = [];
    foreach ($sourceRows as $src) {
        $srcDate = (string)($src['day_date'] ?? '');
        $qty = (int)($src['qty'] ?? 0);
        if ($srcDate === '' || $qty <= 0) {
            continue;
        }
        $targetDate = (new DateTimeImmutable($srcDate))
            ->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')
            ->format('Y-m-d');
        if ($targetDate < $todayIso) {
            throw new RuntimeException('Сдвиг уводит часть позиции в прошлые даты.');
        }
        if (!isset($shifted[$targetDate])) {
            $shifted[$targetDate] = 0;
        }
        $shifted[$targetDate] += $qty;
    }
    if (empty($shifted)) {
        throw new RuntimeException('После сдвига не осталось данных для записи.');
    }

    $delAllStmt = $pdo->prepare("
        DELETE FROM build_plans
        WHERE shift='D' AND order_number=? AND filter=? AND day_date>=?
    ");
    $delAllStmt->execute([$order, $filter, $todayIso]);

    $insStmt = $pdo->prepare("
        INSERT INTO build_plans (order_number, filter, day_date, shift, qty)
        VALUES (?, ?, ?, 'D', ?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ");
    foreach ($shifted as $targetDate => $qty) {
        $insStmt->execute([$order, $filter, (string)$targetDate, (int)$qty]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = [];
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if (empty($payload)) {
        $payload = $_POST;
    }
    $action = (string)($payload['action'] ?? '');
    if ($action === 'move_plan' || $action === 'apply_moves') {
        header('Content-Type: application/json; charset=utf-8');

        $isDate = static function (string $value): bool {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
        };

        try {
            $moves = [];
            if ($action === 'move_plan') {
                $moves[] = [
                    'order_number' => (string)($payload['order_number'] ?? ''),
                    'filter_name' => (string)($payload['filter_name'] ?? ''),
                    'from_date' => (string)($payload['from_date'] ?? ''),
                    'to_date' => (string)($payload['to_date'] ?? ''),
                    'mode' => (string)($payload['mode'] ?? 'single'),
                ];
            } else {
                $rawMoves = $payload['moves'] ?? [];
                if (!is_array($rawMoves) || empty($rawMoves)) {
                    throw new RuntimeException('Нет операций для применения.');
                }
                foreach ($rawMoves as $oneMove) {
                    if (!is_array($oneMove)) {
                        throw new RuntimeException('Некорректный формат списка операций.');
                    }
                    $moves[] = [
                        'order_number' => (string)($oneMove['order_number'] ?? ''),
                        'filter_name' => (string)($oneMove['filter_name'] ?? ''),
                        'from_date' => (string)($oneMove['from_date'] ?? ''),
                        'to_date' => (string)($oneMove['to_date'] ?? ''),
                        'mode' => (string)($oneMove['mode'] ?? 'single'),
                    ];
                }
            }

            $pdo->beginTransaction();
            foreach ($moves as $idx => $move) {
                $order = trim((string)($move['order_number'] ?? ''));
                $filter = trim((string)($move['filter_name'] ?? ''));
                $fromDate = trim((string)($move['from_date'] ?? ''));
                $toDate = trim((string)($move['to_date'] ?? ''));
                $mode = trim((string)($move['mode'] ?? 'single'));
                if (!in_array($mode, ['single', 'row', 'block'], true)) {
                    $mode = 'single';
                }

                if ($order === '' || $filter === '' || !$isDate($fromDate) || !$isDate($toDate)) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': некорректные параметры переноса.');
                }
                if ($fromDate === $toDate) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': дата источника и назначения совпадают.');
                }
                if ($toDate < $todayIso) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': перенос в прошлые даты недоступен.');
                }
                if ($fromDate < $todayIso && $mode !== 'single') {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': перенос из прошлых дат доступен только для одиночной смены.');
                }

                try {
                    applyBuildPlanMove($pdo, $todayIso, $order, $filter, $fromDate, $toDate, $mode);
                } catch (Throwable $moveError) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': ' . $moveError->getMessage(), 0, $moveError);
                }
            }
            $pdo->commit();

            echo json_encode(['ok' => true, 'applied' => count($moves)], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
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
$debtShiftMap = [];
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
        if ($order === '' || $filter === '' || $date === '') {
            continue;
        }
        $key = $order . '|' . normalizeFilterKey($filter);
        if (!isset($buildPlanMap[$key])) {
            $buildPlanMap[$key] = [];
        }
        if ($date < $todayIso) {
            if (!isset($debtShiftMap[$key])) {
                $debtShiftMap[$key] = [];
            }
            $debtShiftMap[$key][] = [
                'date' => $date,
                'qty' => $qty,
            ];
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
    $debtShiftMap = [];
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
        .pending-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 10px;
            padding: 8px 10px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            border-radius: 10px;
        }
        .pending-bar[hidden] { display: none; }
        .pending-text {
            font-size: 12px;
            color: #1e3a8a;
            margin-right: auto;
        }
        .queue-panel[hidden] { display: none; }
        .queue-panel {
            position: fixed;
            right: 14px;
            bottom: 14px;
            width: min(520px, calc(100vw - 28px));
            max-height: min(55vh, 520px);
            z-index: 1100;
            display: grid;
            grid-template-rows: auto 1fr;
            border: 1px solid #c7d2fe;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.22);
            overflow: hidden;
        }
        .queue-panel__head {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            background: #f8faff;
            font-size: 13px;
            font-weight: 700;
            color: #1e3a8a;
        }
        .queue-panel__body {
            padding: 8px 10px;
            overflow: auto;
            background: #fff;
        }
        .queue-panel__empty {
            margin: 4px 0;
            font-size: 12px;
            color: #6b7280;
        }
        .queue-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 6px;
        }
        .queue-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 6px 8px;
            background: #fafafa;
            font-size: 12px;
            line-height: 1.35;
            color: #111827;
            word-break: break-word;
        }
        .queue-item__row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .queue-item__text {
            flex: 1 1 auto;
            min-width: 0;
        }
        .queue-item__remove {
            flex: 0 0 auto;
            appearance: none;
            border: 1px solid #fecaca;
            border-radius: 6px;
            background: #fff1f2;
            color: #9f1239;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.2;
            padding: 4px 6px;
            cursor: pointer;
        }
        .queue-item__remove:hover {
            background: #ffe4e6;
        }
        .queue-group {
            padding: 2px 4px 0;
            font-size: 11px;
            font-weight: 700;
            color: #4b5563;
        }
        tr.plan-row.queue-row {
            outline: 2px solid #93c5fd;
            outline-offset: -2px;
            background: #f0f9ff !important;
        }
        .debt-panel[hidden] { display: none; }
        .debt-panel {
            position: fixed;
            left: 0;
            top: 0;
            width: min(130px, calc(100vw - 20px));
            max-height: min(40vh, 280px);
            z-index: 1090;
            display: grid;
            grid-template-rows: auto 1fr;
            border: 1px solid #fbcfe8;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16);
            overflow: hidden;
        }
        .debt-panel__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            padding: 6px 8px;
            border-bottom: 1px solid #f3e8ff;
            background: #fff7fb;
            font-size: 11px;
            font-weight: 700;
            color: #9d174d;
        }
        .debt-panel__sub {
            display: none;
            padding: 8px 12px;
            border-bottom: 1px solid #f3e8ff;
            font-size: 12px;
            color: #6b7280;
        }
        .debt-panel__body {
            padding: 6px;
            overflow: auto;
            display: grid;
            gap: 4px;
            align-content: start;
        }
        .debt-panel__empty {
            margin: 0;
            font-size: 11px;
            color: #6b7280;
        }
        .debt-item {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid #fbcfe8;
            border-radius: 4px;
            background: #fff1f2;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.25;
            color: #881337;
            cursor: grab;
            white-space: nowrap;
        }
        .debt-item:hover {
            background: #ffe4e6;
        }
        .debt-item__meta {
            color: #9f1239;
            font-weight: 600;
        }
        .drag-preview[hidden] { display: none; }
        .drag-preview {
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1100;
            min-width: 168px;
            max-width: 220px;
            padding: 6px 8px;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
            font-size: 11px;
            line-height: 1.3;
            pointer-events: none;
        }
        .drag-preview.is-conflict {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #991b1b;
        }
        .drag-preview__title {
            font-weight: 700;
            margin-bottom: 4px;
        }
        .drag-preview__line + .drag-preview__line {
            margin-top: 2px;
        }
        .queue-item:hover {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        td.date-cell.queue-src {
            outline: 2px solid #f59e0b !important;
            outline-offset: -2px;
            background: #fffbeb !important;
        }
        td.date-cell.queue-dst {
            outline: 2px solid #3b82f6 !important;
            outline-offset: -2px;
            background: #eff6ff !important;
        }
        tr.plan-row.press-conflict-flash td.pos-cell,
        tr.plan-row.press-conflict-flash td.date-cell[data-date-conflict="1"] {
            background: #fff7ed !important;
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
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
        td.date-cell {
            cursor: default;
            transition: background-color .12s ease, outline-color .12s ease;
        }
        td.date-cell.hover-dnd-center {
            outline: 2px solid #818cf8;
            outline-offset: -2px;
            background: #eef2ff !important;
        }
        td.date-cell.hover-dnd-block {
            outline: 1px solid #fbbf24;
            outline-offset: -1px;
            background: #fffbeb !important;
        }
        td.date-cell.drag-source-single,
        td.date-cell.drag-source-row {
            outline: 2px solid #93c5fd;
            outline-offset: -2px;
            background: #eff6ff !important;
        }
        td.date-cell.drag-drop-target {
            outline: 2px dashed #60a5fa;
            outline-offset: -2px;
            background: #e0f2fe !important;
        }
        td.date-cell.drag-busy {
            opacity: .6;
            pointer-events: none;
        }

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
        .state-action-btn {
            appearance: none;
            cursor: pointer;
            margin: 0;
            vertical-align: baseline;
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
        <div id="pendingMovesBar" class="pending-bar">
            <span id="pendingMovesText" class="pending-text">Изменений в очереди: 0</span>
            <button type="button" id="toggleQueuePanelBtn" class="toolbar-btn secondary" aria-pressed="false">Очередь</button>
            <button type="button" id="normalizePlanBtn" class="toolbar-btn secondary">Нормализовать план</button>
            <button type="button" id="undoPendingMoveBtn" class="toolbar-btn secondary">Отменить последнее</button>
            <button type="button" id="clearPendingMovesBtn" class="toolbar-btn secondary">Сбросить все</button>
            <button type="button" id="applyPendingMovesBtn" class="toolbar-btn">Применить</button>
        </div>
        <div id="moveQueuePanel" class="queue-panel" hidden>
            <div class="queue-panel__head">
                <span>Очередь переносов</span>
                <button type="button" id="closeQueuePanelBtn" class="toolbar-btn secondary">Закрыть</button>
            </div>
            <div class="queue-panel__body">
                <p id="moveQueueEmpty" class="queue-panel__empty">Очередь пуста.</p>
                <ul id="moveQueueList" class="queue-list"></ul>
            </div>
        </div>
        <div id="debtPanel" class="debt-panel" hidden>
            <div class="debt-panel__head">
                <span id="debtPanelTitle">Долг по позиции</span>
                <button type="button" id="closeDebtPanelBtn" class="toolbar-btn secondary">Закрыть</button>
            </div>
            <div id="debtPanelSub" class="debt-panel__sub">Перетащите просроченную смену в нужную будущую дату.</div>
            <div id="debtPanelBody" class="debt-panel__body">
                <p id="debtPanelEmpty" class="debt-panel__empty">Просроченных смен нет.</p>
            </div>
        </div>
        <div id="dragPreview" class="drag-preview" hidden></div>
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
                            <th class="num date-col" data-plan-date="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>" title="План сборки на <?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>">
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
                        $debtShifts = $debtShiftMap[$planKey] ?? [];
                        $debtShiftCount = count($debtShifts);
                        $debtShiftQty = 0;
                        foreach ($debtShifts as $debtShift) {
                            $debtShiftQty += (int)($debtShift['qty'] ?? 0);
                        }
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
                    <tr
                        class="plan-row"
                        data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                        data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                        data-has-press="<?= $rowHasPress ? '1' : '0' ?>"
                        data-has-d="<?= $rowHasD ? '1' : '0' ?>"
                        data-has-600="<?= $rowHas600 ? '1' : '0' ?>"
                        data-priority="<?= $isLagging ? 'A' : 'C' ?>"
                    >
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
                                <button
                                    type="button"
                                    class="state-badge state-lag state-action-btn"
                                    title="<?= htmlspecialchars('Открыть долг по позиции: ' . $debtShiftCount . ' просроч. смен, ' . $debtShiftQty . ' шт.', ENT_QUOTES, 'UTF-8') ?>"
                                >Отстаёт</button>
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
                            <td
                                class="num date-col date-cell"
                                data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                                data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                                data-date="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>"
                                data-qty="<?= (int)$planQty ?>"
                                draggable="<?= $planQty > 0 ? 'true' : 'false' ?>"
                                title="<?= $planQty > 0 ? 'Перетащите: обычный перенос — одна смена; Shift — сдвиг непрерывного блока смен по датам' : 'Сюда можно перенести план этой позиции' ?>"
                            ><?= $planQty > 0 ? $planQty : '' ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <tfoot>
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
                            <th class="num date-col" data-plan-date="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>" title="План сборки на <?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>">
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
                </tfoot>
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
        const initialDebtShiftMap = <?= json_encode($debtShiftMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
        const pendingMovesBar = document.getElementById('pendingMovesBar');
        const pendingMovesText = document.getElementById('pendingMovesText');
        const toggleQueuePanelBtn = document.getElementById('toggleQueuePanelBtn');
        const moveQueuePanel = document.getElementById('moveQueuePanel');
        const closeQueuePanelBtn = document.getElementById('closeQueuePanelBtn');
        const moveQueueEmpty = document.getElementById('moveQueueEmpty');
        const moveQueueList = document.getElementById('moveQueueList');
        const debtPanel = document.getElementById('debtPanel');
        const debtPanelTitle = document.getElementById('debtPanelTitle');
        const debtPanelSub = document.getElementById('debtPanelSub');
        const debtPanelBody = document.getElementById('debtPanelBody');
        const debtPanelEmpty = document.getElementById('debtPanelEmpty');
        const closeDebtPanelBtn = document.getElementById('closeDebtPanelBtn');
        const dragPreview = document.getElementById('dragPreview');
        const normalizePlanBtn = document.getElementById('normalizePlanBtn');
        const applyPendingMovesBtn = document.getElementById('applyPendingMovesBtn');
        const undoPendingMoveBtn = document.getElementById('undoPendingMoveBtn');
        const clearPendingMovesBtn = document.getElementById('clearPendingMovesBtn');
        if (!btn || !rowIndicatorsBtn || !modal || !openSettingsBtn || !saveBtn || !cancelBtn || !resetBtn || !maxPressInput || !norm600Input || !normDInput || !normTotalInput || !pendingMovesBar || !pendingMovesText || !toggleQueuePanelBtn || !moveQueuePanel || !closeQueuePanelBtn || !moveQueueEmpty || !moveQueueList || !debtPanel || !debtPanelTitle || !debtPanelSub || !debtPanelBody || !debtPanelEmpty || !closeDebtPanelBtn || !dragPreview || !normalizePlanBtn || !applyPendingMovesBtn || !undoPendingMoveBtn || !clearPendingMovesBtn) {
            return;
        }
        const storageKey = 'activePositionsIndicatorSettings';
        const rowIndicatorsStorageKey = 'activePositionsRowIndicatorsVisible';
        const queuePanelStorageKey = 'activePositionsQueuePanelOpen';
        const debtStateMap = Object.assign({}, initialDebtShiftMap || {});
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

        const dateCells = Array.from(document.querySelectorAll('td.date-cell'));
        let dragContext = null;
        let isMoving = false;
        let isApplyingPendingMoves = false;
        let isQueuePanelOpen = false;
        let selectedDebtKey = '';
        let debtPanelAnchor = null;
        let previewedTargetCell = null;
        const pendingMoves = [];

        function normalizeFilterKeyJs(value) {
            return String(value || '')
                .replace(/\s+/g, ' ')
                .trim()
                .toUpperCase();
        }

        function getPlanKey(order, filter) {
            return `${String(order || '')}|${normalizeFilterKeyJs(filter)}`;
        }

        function cloneDebtShifts(shifts) {
            return Array.isArray(shifts)
                ? shifts.map(function (shift) {
                    return {
                        date: String(shift && shift.date ? shift.date : ''),
                        qty: Math.max(0, parseInt(shift && shift.qty ? shift.qty : 0, 10) || 0),
                    };
                }).filter(function (shift) {
                    return shift.date !== '' && shift.qty > 0;
                })
                : [];
        }

        function getCellTitle(qty) {
            if (qty > 0) {
                return 'Перетащите: обычный перенос — одна смена; Shift — сдвиг непрерывного блока смен по датам';
            }
            return 'Сюда можно перенести план этой позиции';
        }

        function setCellQty(cell, qty) {
            const normalizedQty = Math.max(0, parseInt(qty, 10) || 0);
            cell.dataset.qty = String(normalizedQty);
            cell.textContent = normalizedQty > 0 ? String(normalizedQty) : '';
            cell.setAttribute('draggable', normalizedQty > 0 ? 'true' : 'false');
            cell.title = getCellTitle(normalizedQty);
        }

        function recalcHeaderIndicatorsFromTable() {
            const byDate = new Map();
            dateCells.forEach(function (cell) {
                const date = cell.dataset.date || '';
                if (!date) {
                    return;
                }
                if (!byDate.has(date)) {
                    byDate.set(date, {
                        totalQty: 0,
                        w600Qty: 0,
                        dQty: 0,
                        pressFilters: new Set(),
                    });
                }
                const qty = parseInt(cell.dataset.qty || '0', 10) || 0;
                if (qty <= 0) {
                    return;
                }
                const state = byDate.get(date);
                const row = cell.closest('tr.plan-row');
                if (!state || !row) {
                    return;
                }
                const rowHas600 = (row.dataset.has600 || '') === '1' || !!row.querySelector('.pos-indicator.w600');
                const rowHasD = (row.dataset.hasD || '') === '1' || !!row.querySelector('.pos-indicator.d');
                const rowHasPress = (row.dataset.hasPress || '') === '1' || !!row.querySelector('.pos-indicator.p');
                state.totalQty += qty;
                if (rowHas600) {
                    state.w600Qty += qty;
                }
                if (rowHasD) {
                    state.dQty += qty;
                }
                if (rowHasPress) {
                    state.pressFilters.add(((row.dataset.filter || '').trim()).toUpperCase());
                }
            });

            document.querySelectorAll('th[data-plan-date]').forEach(function (th) {
                const date = th.getAttribute('data-plan-date') || '';
                const state = byDate.get(date) || {
                    totalQty: 0,
                    w600Qty: 0,
                    dQty: 0,
                    pressFilters: new Set(),
                };
                const pressEl = th.querySelector('.date-indicator[data-kind="press"]');
                const dEl = th.querySelector('.date-indicator[data-kind="d"]');
                const w600El = th.querySelector('.date-indicator[data-kind="w600"]');
                const totalEl = th.querySelector('.date-indicator[data-kind="total"]');

                if (pressEl) {
                    pressEl.setAttribute('data-press-count', String(state.pressFilters.size));
                }
                if (dEl) {
                    dEl.setAttribute('data-qty', String(state.dQty));
                }
                if (w600El) {
                    w600El.setAttribute('data-qty', String(state.w600Qty));
                }
                if (totalEl) {
                    totalEl.setAttribute('data-qty', String(state.totalQty));
                    const txt = totalEl.querySelector('.txt');
                    if (txt) {
                        txt.textContent = String(state.totalQty);
                    }
                }
            });

            applySettings(getSettings());
        }

        function flashPressConflictForDate(date) {
            clearPressConflictFlash();
            if (!date) {
                return;
            }
            let count = 0;
            document.querySelectorAll('tr.plan-row').forEach(function (row) {
                if ((row.dataset.hasPress || '') !== '1') {
                    return;
                }
                const conflictCell = Array.from(row.querySelectorAll('td.date-cell')).find(function (cell) {
                    return (cell.dataset.date || '') === date && (parseInt(cell.dataset.qty || '0', 10) || 0) > 0;
                });
                if (!conflictCell) {
                    return;
                }
                count += 1;
                row.classList.add('press-conflict-flash');
                conflictCell.dataset.dateConflict = '1';
            });
            if (count < 2) {
                clearPressConflictFlash();
                return;
            }
            window.setTimeout(clearPressConflictFlash, 2200);
        }

        function clearHoverPreview() {
            dateCells.forEach(function (cell) {
                cell.classList.remove('hover-dnd-center', 'hover-dnd-block');
            });
        }

        function hideDragPreview() {
            previewedTargetCell = null;
            dragPreview.hidden = true;
            dragPreview.classList.remove('is-conflict');
            dragPreview.innerHTML = '';
        }

        function positionDragPreview(targetCell) {
            if (!targetCell || dragPreview.hidden) {
                return;
            }
            const rect = targetCell.getBoundingClientRect();
            const panelRect = dragPreview.getBoundingClientRect();
            const gap = 8;
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            let left = rect.right + gap;
            let top = rect.top - 2;
            if (left + panelRect.width > viewportWidth - 8) {
                left = rect.left - panelRect.width - gap;
            }
            if (left < 8) {
                left = Math.max(8, viewportWidth - panelRect.width - 8);
            }
            if (top + panelRect.height > viewportHeight - 8) {
                top = Math.max(8, viewportHeight - panelRect.height - 8);
            }
            if (top < 8) {
                top = 8;
            }
            dragPreview.style.left = `${Math.round(left)}px`;
            dragPreview.style.top = `${Math.round(top)}px`;
        }

        function getDateStateWithOverrides(date, changeMap) {
            const state = {
                totalQty: 0,
                dQty: 0,
                pressFilters: new Set(),
            };
            dateCells.forEach(function (cell) {
                if ((cell.dataset.date || '') !== date) {
                    return;
                }
                const nextQty = changeMap.has(cell)
                    ? Math.max(0, parseInt(changeMap.get(cell), 10) || 0)
                    : (parseInt(cell.dataset.qty || '0', 10) || 0);
                if (nextQty <= 0) {
                    return;
                }
                const row = cell.closest('tr.plan-row');
                if (!row) {
                    return;
                }
                state.totalQty += nextQty;
                if ((row.dataset.hasD || '') === '1' || !!row.querySelector('.pos-indicator.d')) {
                    state.dQty += nextQty;
                }
                if ((row.dataset.hasPress || '') === '1' || !!row.querySelector('.pos-indicator.p')) {
                    state.pressFilters.add(normalizeFilterKeyJs(row.dataset.filter || ''));
                }
            });
            return state;
        }

        function renderDragPreview(targetCell, previewState) {
            const hasConflict = !!previewState.hasConflict;
            const lines = [
                `<div class="drag-preview__title">${hasConflict ? 'Конфликт' : 'Перенос возможен'}</div>`,
                `<div class="drag-preview__line">Итого: ${previewState.totalQty} / ${previewState.normTotal}${previewState.totalOver ? ' • перегруз' : ''}</div>`,
                `<div class="drag-preview__line">D: ${previewState.dQty} / ${previewState.normD}${previewState.dConflict ? ' • конфликт' : ''}</div>`,
                `<div class="drag-preview__line">П: ${previewState.pressCount} / ${previewState.maxPress}${previewState.pressConflict ? ' • конфликт' : ''}</div>`,
            ];
            if (previewState.message) {
                lines.push(`<div class="drag-preview__line">${previewState.message}</div>`);
            }
            dragPreview.classList.toggle('is-conflict', hasConflict);
            dragPreview.innerHTML = lines.join('');
            dragPreview.hidden = false;
            positionDragPreview(targetCell);
        }

        function updateDragPreview(targetCell) {
            if (!dragContext || !targetCell || !canDropOn(targetCell)) {
                hideDragPreview();
                return;
            }
            if (previewedTargetCell === targetCell) {
                positionDragPreview(targetCell);
                return;
            }
            const queuedMove = buildQueuedMove(targetCell);
            if (!queuedMove) {
                hideDragPreview();
                return;
            }
            const settings = getSettings();
            if (queuedMove.error) {
                previewedTargetCell = targetCell;
                renderDragPreview(targetCell, {
                    hasConflict: true,
                    totalQty: getDateTotalQty(targetCell.dataset.date || ''),
                    normTotal: Math.max(1, parseInt(settings.normTotal, 10) || 1),
                    totalOver: 0,
                    dQty: getDateDQty(targetCell.dataset.date || ''),
                    normD: Math.max(1, parseInt(settings.normD, 10) || 1),
                    dConflict: false,
                    pressCount: 0,
                    maxPress: Math.max(1, parseInt(settings.maxPress, 10) || 1),
                    pressConflict: true,
                    message: queuedMove.error,
                });
                return;
            }
            const changeMap = new Map();
            queuedMove.changes.forEach(function (change) {
                changeMap.set(change.cell, change.next);
            });
            const targetDate = targetCell.dataset.date || '';
            const nextState = getDateStateWithOverrides(targetDate, changeMap);
            const normTotal = Math.max(1, parseInt(settings.normTotal, 10) || 1);
            const normD = Math.max(1, parseInt(settings.normD, 10) || 1);
            const maxPress = Math.max(1, parseInt(settings.maxPress, 10) || 1);
            const totalOver = Math.max(0, nextState.totalQty - normTotal);
            const dConflict = nextState.dQty > normD;
            const pressCount = nextState.pressFilters.size;
            const pressConflict = pressCount > maxPress;
            previewedTargetCell = targetCell;
            renderDragPreview(targetCell, {
                hasConflict: totalOver > 0 || dConflict || pressConflict,
                totalQty: nextState.totalQty,
                normTotal: normTotal,
                totalOver: totalOver,
                dQty: nextState.dQty,
                normD: normD,
                dConflict: dConflict,
                pressCount: pressCount,
                maxPress: maxPress,
                pressConflict: pressConflict,
            });
        }

        function clearDragState() {
            clearHoverPreview();
            hideDragPreview();
            dateCells.forEach(function (cell) {
                cell.classList.remove('drag-source-single', 'drag-source-row', 'drag-drop-target', 'drag-busy');
            });
            dragContext = null;
        }

        function updatePendingBarState() {
            const count = pendingMoves.length;
            pendingMovesBar.hidden = false;
            pendingMovesText.textContent = `Изменений в очереди: ${count}`;
            const disabled = count === 0 || isApplyingPendingMoves;
            normalizePlanBtn.disabled = isApplyingPendingMoves;
            applyPendingMovesBtn.disabled = disabled;
            undoPendingMoveBtn.disabled = disabled;
            clearPendingMovesBtn.disabled = disabled;
            toggleQueuePanelBtn.textContent = `Очередь (${count})`;
            if (isApplyingPendingMoves) {
                applyPendingMovesBtn.textContent = 'Применение...';
            } else {
                applyPendingMovesBtn.textContent = `Применить (${count})`;
            }
        }

        function toShortDate(isoDate) {
            if (typeof isoDate !== 'string' || isoDate.length < 10) {
                return isoDate || '';
            }
            return `${isoDate.slice(8, 10)}.${isoDate.slice(5, 7)}`;
        }

        function modeLabel(mode) {
            if (mode === 'block') {
                return 'Блок';
            }
            if (mode === 'row') {
                return 'Позиция';
            }
            return 'Смена';
        }

        function getDebtShiftsForKey(planKey) {
            return cloneDebtShifts(debtStateMap[planKey] || []);
        }

        function setDebtShiftsForKey(planKey, shifts) {
            debtStateMap[planKey] = cloneDebtShifts(shifts).sort(function (a, b) {
                return a.date.localeCompare(b.date);
            });
        }

        function closeDebtPanel() {
            selectedDebtKey = '';
            debtPanelAnchor = null;
            debtPanel.hidden = true;
        }

        function positionDebtPanel() {
            if (debtPanel.hidden || !debtPanelAnchor) {
                return;
            }
            const rect = debtPanelAnchor.getBoundingClientRect();
            const panelRect = debtPanel.getBoundingClientRect();
            const gap = 6;
            const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            let left = rect.left;
            let top = rect.top - panelRect.height - gap;

            if (left + panelRect.width > viewportWidth - 8) {
                left = Math.max(8, viewportWidth - panelRect.width - 8);
            }
            if (left < 8) {
                left = 8;
            }
            if (top < 8) {
                top = Math.min(viewportHeight - panelRect.height - 8, rect.bottom + gap);
            }
            if (top < 8) {
                top = 8;
            }

            debtPanel.style.left = `${Math.round(left)}px`;
            debtPanel.style.top = `${Math.round(top)}px`;
        }

        function renderDebtPanel() {
            debtPanelBody.innerHTML = '';
            const selectedRow = selectedDebtKey
                ? Array.from(document.querySelectorAll('tr.plan-row')).find(function (oneRow) {
                    return getPlanKey(oneRow.dataset.order || '', oneRow.dataset.filter || '') === selectedDebtKey;
                })
                : null;

            if (!selectedDebtKey || !selectedRow) {
                debtPanelTitle.textContent = 'Долг по позиции';
                debtPanelSub.textContent = 'Перетащите просроченную смену в нужную будущую дату.';
                debtPanelEmpty.hidden = false;
                debtPanelBody.appendChild(debtPanelEmpty);
                return;
            }

            const order = selectedRow.dataset.order || '';
            const filter = selectedRow.dataset.filter || '';
            const shifts = getDebtShiftsForKey(selectedDebtKey);
            debtPanelTitle.textContent = `Долг: ${order} / ${filter}`;
            debtPanelSub.textContent = 'Список просроченных смен из прошлых дат. Перетащите нужную смену в свободную ячейку этой позиции.';

            if (shifts.length === 0) {
                debtPanelEmpty.hidden = false;
                debtPanelBody.appendChild(debtPanelEmpty);
                return;
            }

            debtPanelEmpty.hidden = true;
            shifts.forEach(function (shift) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'debt-item';
                item.draggable = true;
                item.dataset.order = order;
                item.dataset.filter = filter;
                item.dataset.date = shift.date;
                item.dataset.qty = String(shift.qty);
                item.innerHTML = `<span>${toShortDate(shift.date)}</span><span class="debt-item__meta">${shift.qty} шт</span>`;
                item.addEventListener('dragstart', function (e) {
                    dragContext = {
                        mode: 'single',
                        sourceType: 'debt',
                        order: order,
                        filter: filter,
                        fromDate: shift.date,
                        movedQty: shift.qty,
                        debtKey: selectedDebtKey,
                    };
                    item.classList.add('drag-source-single');
                    if (e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', `${order}|${filter}|${shift.date}|debt`);
                    }
                });
                item.addEventListener('dragend', function () {
                    item.classList.remove('drag-source-single');
                    clearDragState();
                });
                debtPanelBody.appendChild(item);
            });
            positionDebtPanel();
        }

        function openDebtPanelForRow(row, anchorBtn) {
            if (!row || !anchorBtn) {
                return;
            }
            selectedDebtKey = getPlanKey(row.dataset.order || '', row.dataset.filter || '');
            debtPanelAnchor = anchorBtn;
            debtPanel.hidden = false;
            renderDebtPanel();
            positionDebtPanel();
        }

        function clearQueuePreview() {
            dateCells.forEach(function (cell) {
                cell.classList.remove('queue-src', 'queue-dst');
            });
            document.querySelectorAll('tr.plan-row.queue-row').forEach(function (row) {
                row.classList.remove('queue-row');
            });
        }

        function clearPressConflictFlash() {
            document.querySelectorAll('tr.plan-row.press-conflict-flash').forEach(function (row) {
                row.classList.remove('press-conflict-flash');
            });
            dateCells.forEach(function (cell) {
                delete cell.dataset.dateConflict;
            });
        }

        function getRowDateCellsByOrderFilter(order, filter) {
            const row = document.querySelector(`tr.plan-row[data-order="${CSS.escape(order)}"][data-filter="${CSS.escape(filter)}"]`);
            if (!row) {
                return [];
            }
            return Array.from(row.querySelectorAll('td.date-cell'));
        }

        function getCellByDate(cells, date) {
            return cells.find(function (c) {
                return (c.dataset.date || '') === date;
            }) || null;
        }

        function previewQueueMove(queuedMove) {
            clearQueuePreview();
            const payload = queuedMove && queuedMove.payload ? queuedMove.payload : null;
            if (!payload) {
                return;
            }
            const order = payload.order_number || '';
            const filter = payload.filter_name || '';
            const fromDate = payload.from_date || '';
            const toDate = payload.to_date || '';
            if (!order || !filter || !fromDate || !toDate) {
                return;
            }
            const rowCells = getRowDateCellsByOrderFilter(order, filter);
            if (rowCells.length === 0) {
                return;
            }
            const row = rowCells[0].closest('tr.plan-row');
            if (row) {
                row.classList.add('queue-row');
            }
            if ((payload.mode || '') === 'block') {
                const srcCell = getCellByDate(rowCells, fromDate);
                if (!srcCell) {
                    return;
                }
                const sourceBlock = getContiguousBlockCells(srcCell);
                const shift = getDateShiftInDays(fromDate, toDate);
                sourceBlock.forEach(function (cell) {
                    cell.classList.add('queue-src');
                    const date = cell.dataset.date || '';
                    const dstDate = addDaysIso(date, shift);
                    const dstCell = getCellByDate(rowCells, dstDate);
                    if (dstCell) {
                        dstCell.classList.add('queue-dst');
                    }
                });
                return;
            }

            const srcCell = getCellByDate(rowCells, fromDate);
            const dstCell = getCellByDate(rowCells, toDate);
            if (srcCell) {
                srcCell.classList.add('queue-src');
            }
            if (dstCell) {
                dstCell.classList.add('queue-dst');
            }
        }

        function scrollToQueuedMove(queuedMove) {
            const payload = queuedMove && queuedMove.payload ? queuedMove.payload : null;
            if (!payload) {
                return;
            }
            const order = payload.order_number || '';
            const filter = payload.filter_name || '';
            const toDate = payload.to_date || '';
            if (!order || !filter || !toDate) {
                return;
            }
            const rowCells = getRowDateCellsByOrderFilter(order, filter);
            if (rowCells.length === 0) {
                return;
            }
            const targetCell = getCellByDate(rowCells, toDate);
            if (targetCell) {
                targetCell.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                return;
            }
            rowCells[0].scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        }

        function renderMoveQueuePanel() {
            moveQueueList.innerHTML = '';
            if (pendingMoves.length === 0) {
                moveQueueEmpty.hidden = false;
                return;
            }
            moveQueueEmpty.hidden = true;
            let prevGroupKey = '';
            pendingMoves.forEach(function (queuedMove, idx) {
                const payload = queuedMove.payload || {};
                const order = payload.order_number || '—';
                const filter = payload.filter_name || '—';
                const groupKey = `${order}|${filter}`;
                if (groupKey !== prevGroupKey) {
                    const groupHeader = document.createElement('li');
                    groupHeader.className = 'queue-group';
                    groupHeader.textContent = `${order} • ${filter}`;
                    moveQueueList.appendChild(groupHeader);
                    prevGroupKey = groupKey;
                }
                const item = document.createElement('li');
                item.className = 'queue-item';
                const fromDate = toShortDate(payload.from_date || '');
                const toDate = toShortDate(payload.to_date || '');
                const mode = queuedMove.debtChange ? 'Долг' : modeLabel(payload.mode || 'single');
                const movedQty = Math.max(0, parseInt(queuedMove.movedQty || 0, 10) || 0);
                const rowWrap = document.createElement('div');
                rowWrap.className = 'queue-item__row';
                const text = document.createElement('div');
                text.className = 'queue-item__text';
                text.textContent = `${idx + 1}. [${mode}] ${fromDate} -> ${toDate}, ${movedQty} шт`;
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'queue-item__remove';
                removeBtn.textContent = 'Отменить';
                removeBtn.title = 'Отменить этот перенос';
                removeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removePendingMoveAt(idx);
                });
                rowWrap.appendChild(text);
                rowWrap.appendChild(removeBtn);
                item.appendChild(rowWrap);
                item.addEventListener('mouseenter', function () {
                    previewQueueMove(queuedMove);
                });
                item.addEventListener('mouseleave', function () {
                    clearQueuePreview();
                });
                item.addEventListener('click', function () {
                    previewQueueMove(queuedMove);
                    scrollToQueuedMove(queuedMove);
                });
                moveQueueList.appendChild(item);
            });
        }

        function setQueuePanelOpen(open) {
            isQueuePanelOpen = !!open;
            moveQueuePanel.hidden = !isQueuePanelOpen;
            toggleQueuePanelBtn.setAttribute('aria-pressed', isQueuePanelOpen ? 'true' : 'false');
            try {
                localStorage.setItem(queuePanelStorageKey, isQueuePanelOpen ? '1' : '0');
            } catch (e) {
                // ignore storage write errors
            }
        }

        function getContiguousBlockCells(cell) {
            const tr = cell.closest('tr.plan-row');
            if (!tr) {
                return [cell];
            }
            const cells = Array.from(tr.querySelectorAll('td.date-cell'));
            const idx = cells.indexOf(cell);
            if (idx < 0) {
                return [cell];
            }
            const qtys = cells.map(function (c) {
                return parseInt(c.dataset.qty || '0', 10) || 0;
            });
            if (qtys[idx] <= 0) {
                return [cell];
            }
            let left = idx;
            while (left > 0 && qtys[left - 1] > 0) {
                left -= 1;
            }
            let right = idx;
            while (right < cells.length - 1 && qtys[right + 1] > 0) {
                right += 1;
            }
            return cells.slice(left, right + 1);
        }

        let lastHoveredDateCell = null;

        function applyDateCellHover(cell, shiftKey) {
            if (dragContext || isMoving || !cell) {
                return;
            }
            clearHoverPreview();
            if (shiftKey) {
                const block = getContiguousBlockCells(cell);
                block.forEach(function (c) {
                    c.classList.add('hover-dnd-block');
                });
            } else {
                cell.classList.add('hover-dnd-center');
            }
        }

        function canDropOn(targetCell) {
            if (!dragContext || !targetCell) {
                return false;
            }
            if (targetCell.dataset.order !== dragContext.order || targetCell.dataset.filter !== dragContext.filter) {
                return false;
            }
            return targetCell.dataset.date !== dragContext.fromDate;
        }

        function getDateShiftInDays(fromDate, toDate) {
            const from = new Date(fromDate + 'T00:00:00');
            const to = new Date(toDate + 'T00:00:00');
            return Math.round((to.getTime() - from.getTime()) / 86400000);
        }

        function addDaysIso(dateIso, days) {
            const date = new Date(dateIso + 'T00:00:00');
            date.setDate(date.getDate() + days);
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function buildQueuedMove(targetCell) {
            if (!dragContext) {
                return null;
            }
            const sourceCell = dragContext.sourceCell;
            const toDate = targetCell.dataset.date || '';
            const payload = {
                mode: dragContext.mode,
                order_number: dragContext.order,
                filter_name: dragContext.filter,
                from_date: dragContext.fromDate,
                to_date: toDate,
            };

            if (dragContext.mode === 'single') {
                return buildQueuedSingleMove(sourceCell, targetCell, dragContext);
            }

            const sourceRow = sourceCell.closest('tr.plan-row');
            if (!sourceRow) {
                return null;
            }
            const rowCells = Array.from(sourceRow.querySelectorAll('td.date-cell'));
            const qtyByDate = {};
            const cellByDate = {};
            rowCells.forEach(function (cell) {
                const date = cell.dataset.date || '';
                cellByDate[date] = cell;
                qtyByDate[date] = parseInt(cell.dataset.qty || '0', 10) || 0;
            });
            const blockCells = getContiguousBlockCells(sourceCell);
            const blockDates = blockCells
                .map(function (cell) { return cell.dataset.date || ''; })
                .filter(function (date) { return date !== ''; });
            if (blockDates.length === 0) {
                return null;
            }
            const blockDateSet = new Set(blockDates);
            const offsetDays = getDateShiftInDays(dragContext.fromDate, toDate);
            if (offsetDays === 0) {
                return null;
            }

            const nextQtyByDate = {};
            Object.keys(qtyByDate).forEach(function (date) {
                nextQtyByDate[date] = qtyByDate[date];
            });

            for (const date of blockDates) {
                const qty = parseInt(qtyByDate[date] || '0', 10) || 0;
                if (qty <= 0) {
                    continue;
                }
                nextQtyByDate[date] = Math.max(0, (nextQtyByDate[date] || 0) - qty);
                const targetDate = addDaysIso(date, offsetDays);
                if (!Object.prototype.hasOwnProperty.call(nextQtyByDate, targetDate)) {
                    return { error: 'Сдвиг блока выводит часть позиции за пределы текущего диапазона дат. Примените изменения или расширьте диапазон.' };
                }
                if (!blockDateSet.has(targetDate) && (parseInt(qtyByDate[targetDate] || '0', 10) || 0) > 0) {
                    return { error: 'Нельзя складывать смены одной позиции: при сдвиге блока целевая дата уже занята.' };
                }
                nextQtyByDate[targetDate] += qty;
            }

            const changes = [];
            Object.keys(qtyByDate).forEach(function (date) {
                const prev = qtyByDate[date];
                const next = nextQtyByDate[date];
                if (prev !== next) {
                    changes.push({ cell: cellByDate[date], prev: prev, next: next });
                }
            });
            if (changes.length === 0) {
                return null;
            }
            let movedQty = 0;
            blockDates.forEach(function (date) {
                movedQty += parseInt(qtyByDate[date] || '0', 10) || 0;
            });
            return { payload, movedQty: movedQty, changes };
        }

        function buildQueuedSingleMove(sourceCell, targetCell, context) {
            const drag = context || dragContext || {};
            const targetQty = parseInt(targetCell.dataset.qty || '0', 10) || 0;
            const sourceQty = drag.sourceType === 'debt'
                ? Math.max(0, parseInt(drag.movedQty || 0, 10) || 0)
                : (sourceCell ? (parseInt(sourceCell.dataset.qty || '0', 10) || 0) : 0);
            const order = drag.order || (sourceCell ? (sourceCell.dataset.order || '') : '');
            const filter = drag.filter || (sourceCell ? (sourceCell.dataset.filter || '') : '');
            const fromDate = drag.fromDate || (sourceCell ? (sourceCell.dataset.date || '') : '');
            const toDate = targetCell.dataset.date || '';
            if (sourceQty <= 0 || order === '' || filter === '' || fromDate === '' || toDate === '' || fromDate === toDate) {
                return null;
            }
            if (targetQty > 0) {
                return { error: 'Нельзя складывать смены одной позиции: целевая дата уже занята.' };
            }
            const queuedMove = {
                payload: {
                    mode: 'single',
                    order_number: order,
                    filter_name: filter,
                    from_date: fromDate,
                    to_date: toDate,
                },
                movedQty: sourceQty,
                changes: [
                    { cell: targetCell, prev: targetQty, next: targetQty + sourceQty },
                ],
            };
            if (drag.sourceType === 'debt') {
                queuedMove.debtChange = {
                    key: drag.debtKey || getPlanKey(order, filter),
                    shift: { date: fromDate, qty: sourceQty },
                };
            } else if (sourceCell) {
                queuedMove.changes.unshift({ cell: sourceCell, prev: sourceQty, next: 0 });
            }
            return queuedMove;
        }

        function getUniquePlanDates() {
            const unique = new Set();
            dateCells.forEach(function (cell) {
                const date = cell.dataset.date || '';
                if (date !== '') {
                    unique.add(date);
                }
            });
            return Array.from(unique).sort();
        }

        function getDateTotalQty(date) {
            let total = 0;
            dateCells.forEach(function (cell) {
                if ((cell.dataset.date || '') !== date) {
                    return;
                }
                total += parseInt(cell.dataset.qty || '0', 10) || 0;
            });
            return total;
        }

        function getDateDQty(date) {
            let total = 0;
            dateCells.forEach(function (cell) {
                if ((cell.dataset.date || '') !== date) {
                    return;
                }
                const qty = parseInt(cell.dataset.qty || '0', 10) || 0;
                if (qty <= 0) {
                    return;
                }
                const row = cell.closest('tr.plan-row');
                if (!row) {
                    return;
                }
                if ((row.dataset.hasD || '') === '1') {
                    total += qty;
                }
            });
            return total;
        }

        function getDateOverload(date, normTotal, normD) {
            const totalOver = Math.max(0, getDateTotalQty(date) - normTotal);
            const dOver = Math.max(0, getDateDQty(date) - normD);
            return {
                totalOver: totalOver,
                dOver: dOver,
                hasOverload: totalOver > 0 || dOver > 0,
            };
        }

        function normalizePlanIntoQueue() {
            if (isApplyingPendingMoves) {
                return;
            }
            const settings = getSettings();
            const normTotal = Math.max(1, parseInt(settings.normTotal, 10) || 1);
            const normD = Math.max(1, parseInt(settings.normD, 10) || 1);
            const dates = getUniquePlanDates();
            if (dates.length === 0) {
                return;
            }

            const unresolved = new Set();
            let createdMoves = 0;
            let guard = 0;
            for (let i = 0; i < dates.length; i += 1) {
                const date = dates[i];
                while (true) {
                    const overloadState = getDateOverload(date, normTotal, normD);
                    if (!overloadState.hasOverload) {
                        break;
                    }
                    guard += 1;
                    if (guard > 5000) {
                        unresolved.add(date);
                        break;
                    }

                    const candidates = dateCells.filter(function (cell) {
                        if ((cell.dataset.date || '') !== date) {
                            return false;
                        }
                        const qty = parseInt(cell.dataset.qty || '0', 10) || 0;
                        if (qty <= 0) {
                            return false;
                        }
                        const row = cell.closest('tr.plan-row');
                        return !!row && (row.dataset.priority || 'C') === 'C';
                    }).sort(function (a, b) {
                        return (parseInt(b.dataset.qty || '0', 10) || 0) - (parseInt(a.dataset.qty || '0', 10) || 0);
                    });

                    // Если перегружен индикатор D, сначала можно вытеснять только D-позиции.
                    if (overloadState.dOver > 0) {
                        const dCandidates = candidates.filter(function (cell) {
                            const row = cell.closest('tr.plan-row');
                            return !!row && (row.dataset.hasD || '') === '1';
                        });
                        if (dCandidates.length > 0) {
                            candidates.splice(0, candidates.length, ...dCandidates);
                        } else {
                            unresolved.add(date);
                            break;
                        }
                    }

                    if (candidates.length === 0) {
                        unresolved.add(date);
                        break;
                    }

                    let moved = false;
                    for (const sourceCell of candidates) {
                        const qty = parseInt(sourceCell.dataset.qty || '0', 10) || 0;
                        if (qty <= 0) {
                            continue;
                        }
                        const row = sourceCell.closest('tr.plan-row');
                        if (!row) {
                            continue;
                        }
                        const sourceIsD = (row.dataset.hasD || '') === '1';
                        const rowCells = Array.from(row.querySelectorAll('td.date-cell'));
                        for (let j = i + 1; j < dates.length; j += 1) {
                            const targetDate = dates[j];
                            const targetCell = rowCells.find(function (c) {
                                return (c.dataset.date || '') === targetDate;
                            });
                            if (!targetCell) {
                                continue;
                            }
                            if ((parseInt(targetCell.dataset.qty || '0', 10) || 0) > 0) {
                                continue;
                            }
                            if (getDateTotalQty(targetDate) + qty > normTotal) {
                                continue;
                            }
                            if (sourceIsD && (getDateDQty(targetDate) + qty > normD)) {
                                continue;
                            }
                            const queuedMove = buildQueuedSingleMove(sourceCell, targetCell);
                            if (!queuedMove) {
                                continue;
                            }
                            if (queuedMove.error) {
                                continue;
                            }
                            pushPendingMove(queuedMove);
                            createdMoves += 1;
                            moved = true;
                            break;
                        }
                        if (moved) {
                            break;
                        }
                    }

                    if (!moved) {
                        unresolved.add(date);
                        break;
                    }
                }
            }

            if (createdMoves === 0 && unresolved.size === 0) {
                alert('Нормализация: перегрузов по нормам total/D не найдено.');
                return;
            }
            if (createdMoves > 0 && unresolved.size === 0) {
                alert(`Нормализация: в очередь добавлено ${createdMoves} переносов.`);
                return;
            }
            const unresolvedList = Array.from(unresolved).join(', ');
            alert(`Нормализация: добавлено ${createdMoves} переносов, но остались перегрузы по датам: ${unresolvedList}.`);
        }

        function applyQueuedMoveChanges(queuedMove, direction) {
            queuedMove.changes.forEach(function (change) {
                setCellQty(change.cell, direction === 'forward' ? change.next : change.prev);
            });
            if (queuedMove.debtChange) {
                const debtKey = queuedMove.debtChange.key || '';
                const shift = queuedMove.debtChange.shift || null;
                if (debtKey && shift && shift.date) {
                    const shifts = getDebtShiftsForKey(debtKey);
                    if (direction === 'forward') {
                        setDebtShiftsForKey(debtKey, shifts.filter(function (item) {
                            return !(item.date === shift.date && item.qty === shift.qty);
                        }));
                    } else {
                        shifts.push({ date: shift.date, qty: shift.qty });
                        setDebtShiftsForKey(debtKey, shifts);
                    }
                    if (selectedDebtKey === debtKey) {
                        renderDebtPanel();
                    }
                }
            }
            recalcHeaderIndicatorsFromTable();
        }

        function pushPendingMove(queuedMove) {
            applyQueuedMoveChanges(queuedMove, 'forward');
            pendingMoves.push(queuedMove);
            updatePendingBarState();
            renderMoveQueuePanel();
        }

        function undoLastPendingMove() {
            if (pendingMoves.length === 0) {
                return;
            }
            const lastMove = pendingMoves.pop();
            applyQueuedMoveChanges(lastMove, 'backward');
            updatePendingBarState();
            renderMoveQueuePanel();
        }

        function removePendingMoveAt(index) {
            const removeIdx = parseInt(index, 10);
            if (isApplyingPendingMoves || Number.isNaN(removeIdx) || removeIdx < 0 || removeIdx >= pendingMoves.length) {
                return;
            }
            const tail = [];
            while (pendingMoves.length - 1 >= removeIdx) {
                const move = pendingMoves.pop();
                tail.push(move);
                applyQueuedMoveChanges(move, 'backward');
            }
            if (tail.length === 0) {
                return;
            }
            // Удаляем выбранный перенос, остальные возвращаем обратно в исходном порядке.
            tail.pop();
            for (let i = tail.length - 1; i >= 0; i -= 1) {
                const move = tail[i];
                applyQueuedMoveChanges(move, 'forward');
                pendingMoves.push(move);
            }
            updatePendingBarState();
            renderMoveQueuePanel();
            clearQueuePreview();
        }

        function clearPendingMoves() {
            while (pendingMoves.length > 0) {
                const oneMove = pendingMoves.pop();
                applyQueuedMoveChanges(oneMove, 'backward');
            }
            updatePendingBarState();
            renderMoveQueuePanel();
        }

        async function applyPendingMovesToServer() {
            if (pendingMoves.length === 0 || isApplyingPendingMoves) {
                return;
            }
            isApplyingPendingMoves = true;
            updatePendingBarState();
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'fetch',
                    },
                    body: JSON.stringify({
                        action: 'apply_moves',
                        moves: pendingMoves.map(function (move) { return move.payload; }),
                    }),
                });
                const data = await response.json();
                if (!response.ok || !data || !data.ok) {
                    throw new Error((data && data.error) ? data.error : 'Не удалось применить пакет изменений.');
                }
                window.location.reload();
            } catch (err) {
                alert((err && err.message) ? err.message : 'Ошибка применения изменений');
                isApplyingPendingMoves = false;
                updatePendingBarState();
            }
        }

        function queueMoveFromDrop(targetCell) {
            if (!dragContext || isMoving || isApplyingPendingMoves) {
                return;
            }
            const queuedMove = buildQueuedMove(targetCell);
            if (!queuedMove) {
                clearDragState();
                return;
            }
            if (queuedMove.error) {
                alert(queuedMove.error);
                clearDragState();
                return;
            }
            pushPendingMove(queuedMove);
            clearDragState();
        }

        dateCells.forEach(function (cell) {
            cell.addEventListener('mousemove', function (e) {
                if (dragContext || isMoving) {
                    return;
                }
                lastHoveredDateCell = cell;
                applyDateCellHover(cell, e.shiftKey);
            });

            cell.addEventListener('mouseleave', function () {
                if (!dragContext && !isMoving) {
                    clearHoverPreview();
                    lastHoveredDateCell = null;
                }
            });

            cell.addEventListener('dragstart', function (e) {
                clearHoverPreview();
                const qty = parseInt(cell.dataset.qty || '0', 10) || 0;
                if (qty <= 0) {
                    e.preventDefault();
                    return;
                }
                const mode = e.shiftKey ? 'block' : 'single';
                dragContext = {
                    mode: mode === 'block' ? 'block' : 'single',
                    order: cell.dataset.order || '',
                    filter: cell.dataset.filter || '',
                    fromDate: cell.dataset.date || '',
                    sourceCell: cell,
                };

                if (dragContext.mode === 'block') {
                    const blockCells = getContiguousBlockCells(cell);
                    blockCells.forEach(function (oneCell) {
                        oneCell.classList.add('drag-source-row');
                    });
                } else {
                    cell.classList.add('drag-source-single');
                }
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `${dragContext.order}|${dragContext.filter}|${dragContext.fromDate}`);
                }
            });

            cell.addEventListener('dragover', function (e) {
                if (!canDropOn(cell)) {
                    hideDragPreview();
                    return;
                }
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
                cell.classList.add('drag-drop-target');
                updateDragPreview(cell);
            });

            cell.addEventListener('dragenter', function (e) {
                if (!canDropOn(cell)) {
                    hideDragPreview();
                    return;
                }
                e.preventDefault();
                cell.classList.add('drag-drop-target');
                updateDragPreview(cell);
            });

            cell.addEventListener('dragleave', function () {
                cell.classList.remove('drag-drop-target');
                if (previewedTargetCell === cell) {
                    hideDragPreview();
                }
            });

            cell.addEventListener('drop', function (e) {
                if (!canDropOn(cell)) {
                    return;
                }
                e.preventDefault();
                hideDragPreview();
                queueMoveFromDrop(cell);
            });

            cell.addEventListener('dragend', function () {
                clearDragState();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Shift' || !lastHoveredDateCell || dragContext || isMoving) {
                return;
            }
            applyDateCellHover(lastHoveredDateCell, true);
        });
        document.addEventListener('keyup', function (e) {
            if (e.key !== 'Shift' || !lastHoveredDateCell || dragContext || isMoving) {
                return;
            }
            applyDateCellHover(lastHoveredDateCell, false);
        });

        undoPendingMoveBtn.addEventListener('click', function () {
            if (isApplyingPendingMoves) {
                return;
            }
            undoLastPendingMove();
        });
        clearPendingMovesBtn.addEventListener('click', function () {
            if (isApplyingPendingMoves) {
                return;
            }
            clearPendingMoves();
        });
        normalizePlanBtn.addEventListener('click', function () {
            normalizePlanIntoQueue();
        });
        applyPendingMovesBtn.addEventListener('click', function () {
            applyPendingMovesToServer();
        });
        document.querySelectorAll('th[data-plan-date] .date-indicator[data-kind="press"]').forEach(function (indicator) {
            indicator.addEventListener('click', function () {
                if (!indicator.classList.contains('over')) {
                    return;
                }
                const th = indicator.closest('th[data-plan-date]');
                const date = th ? (th.getAttribute('data-plan-date') || '') : '';
                flashPressConflictForDate(date);
            });
        });
        document.querySelectorAll('.state-action-btn').forEach(function (btnEl) {
            btnEl.addEventListener('click', function () {
                const row = btnEl.closest('tr.plan-row');
                if (!row) {
                    return;
                }
                openDebtPanelForRow(row, btnEl);
            });
        });
        toggleQueuePanelBtn.addEventListener('click', function () {
            setQueuePanelOpen(!isQueuePanelOpen);
        });
        closeQueuePanelBtn.addEventListener('click', function () {
            setQueuePanelOpen(false);
            clearQueuePreview();
        });
        closeDebtPanelBtn.addEventListener('click', function () {
            closeDebtPanel();
        });
        updatePendingBarState();
        recalcHeaderIndicatorsFromTable();
        renderMoveQueuePanel();
        try {
            const savedQueuePanelState = localStorage.getItem(queuePanelStorageKey);
            setQueuePanelOpen(savedQueuePanelState === '1');
        } catch (e) {
            setQueuePanelOpen(false);
        }

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
                return;
            }
            if (e.key === 'Escape' && !debtPanel.hidden) {
                closeDebtPanel();
            }
        });
        window.addEventListener('resize', positionDebtPanel);
        window.addEventListener('scroll', positionDebtPanel, true);
        window.addEventListener('resize', function () {
            if (previewedTargetCell) {
                positionDragPreview(previewedTargetCell);
            }
        });
        window.addEventListener('scroll', function () {
            if (previewedTargetCell) {
                positionDragPreview(previewedTargetCell);
            }
        }, true);
    })();
</script>
</body>
</html>
