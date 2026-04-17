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

/**
 * Применяет перенос из "долга" (непокрытого остатка) в план:
 * добавляет qty в целевую дату, не списывая источник из build_plans.
 */
function applyDebtPlanMove(
    PDO $pdo,
    string $order,
    string $filter,
    string $toDate,
    int $qty
): void {
    if ($qty <= 0) {
        throw new RuntimeException('Некорректное количество для переноса из долга.');
    }

    $qtyStmt = $pdo->prepare("
        SELECT COALESCE(SUM(qty), 0) AS qty
        FROM build_plans
        WHERE shift='D' AND order_number=? AND filter=? AND day_date=?
    ");
    $qtyStmt->execute([$order, $filter, $toDate]);
    $qtyTo = (int)($qtyStmt->fetchColumn() ?: 0);
    if ($qtyTo > 0) {
        throw new RuntimeException('Нельзя складывать смены одной позиции: целевая дата уже содержит план.');
    }

    $insStmt = $pdo->prepare("
        INSERT INTO build_plans (order_number, filter, day_date, shift, qty)
        VALUES (?, ?, ?, 'D', ?)
        ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
    ");
    $insStmt->execute([$order, $filter, $toDate, $qty]);
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
                    'moved_qty' => (int)($payload['moved_qty'] ?? 0),
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
                        'moved_qty' => (int)($oneMove['moved_qty'] ?? 0),
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
                $movedQty = max(0, (int)($move['moved_qty'] ?? 0));
                if (!in_array($mode, ['single', 'row', 'block', 'debt'], true)) {
                    $mode = 'single';
                }

                if ($order === '' || $filter === '' || !$isDate($fromDate) || !$isDate($toDate)) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': некорректные параметры переноса.');
                }
                if ($fromDate === $toDate && $mode !== 'debt') {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': дата источника и назначения совпадают.');
                }
                if ($toDate < $todayIso) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': перенос в прошлые даты недоступен.');
                }
                if ($fromDate < $todayIso && $mode !== 'single') {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': перенос из прошлых дат доступен только для одиночной смены.');
                }

                try {
                    if ($mode === 'debt') {
                        if ($movedQty <= 0) {
                            throw new RuntimeException('Операция #' . ($idx + 1) . ': не указано количество для переноса из долга.');
                        }
                        applyDebtPlanMove($pdo, $order, $filter, $toDate, $movedQty);
                    } else {
                        applyBuildPlanMove($pdo, $todayIso, $order, $filter, $fromDate, $toDate, $mode);
                    }
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
if (isset($_GET['max_pct']) && $_GET['max_pct'] !== '') {
    $fromUrl = (int) $_GET['max_pct'];
    if ($fromUrl >= 0 && $fromUrl <= 100) {
        $maxPct = $fromUrl;
    }
}

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

// Долг по позиции: что осталось сделать и не покрыто планом с сегодня.
if (!empty($rows)) {
    foreach ($rows as $row) {
        $rawOrder = (string)($row['order_number'] ?? '');
        $rawFilter = (string)($row['filter_name'] ?? '');
        if ($rawOrder === '' || $rawFilter === '') {
            continue;
        }
        $planKey = $rawOrder . '|' . normalizeFilterKey($rawFilter);
        $ordered = (int)($row['ordered'] ?? 0);
        $produced = (int)($row['produced'] ?? 0);
        $remaining = max(0, $ordered - $produced);
        if ($remaining <= 0) {
            continue;
        }
        $plannedFromToday = 0;
        foreach (($buildPlanMap[$planKey] ?? []) as $qty) {
            $plannedFromToday += (int)$qty;
        }
        $debtQty = max(0, $remaining - $plannedFromToday);
        if ($debtQty <= 0) {
            continue;
        }
        $debtShiftMap[$planKey] = [[
            'date' => $todayIso,
            'qty' => $debtQty,
        ]];
    }
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
                    rfs.analog AS analog,
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
                        'analog' => null,
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
                $metaAnalog = trim((string)($metaRow['analog'] ?? ''));
                if ($metaAnalog !== '' && $filterMetaByKey[$metaKey]['analog'] === null) {
                    $filterMetaByKey[$metaKey]['analog'] = $metaAnalog;
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
        .queue-panel__head-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        th.debt-col, td.debt-cell {
            width: 0.1%;
            min-width: 56px;
            max-width: 88px;
            text-align: center;
            box-sizing: border-box;
        }
        td.debt-cell {
            vertical-align: middle;
            padding: 1px 3px;
            height: 24px;
            max-height: 24px;
            min-height: 24px;
            overflow: hidden;
            position: relative;
            box-sizing: border-box;
        }
        td.debt-cell.debt-cell--warn {
            background: #fffbeb;
            padding-right: 14px;
        }
        td.debt-cell.debt-cell--warn::after {
            content: "⏳";
            position: absolute;
            right: 2px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            line-height: 1;
            opacity: 0.75;
            pointer-events: none;
        }
        .debt-list {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: center;
            gap: 2px;
            height: 22px;
            max-height: 22px;
            overflow: hidden;
            white-space: nowrap;
        }
        .debt-shift {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
            min-width: 24px;
            min-height: 18px;
            max-height: 20px;
            padding: 0 4px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: #f9fafb;
            color: #374151;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.2;
            cursor: default;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .debt-more {
            appearance: none;
            flex-shrink: 0;
            border: 1px dashed #94a3b8;
            border-radius: 4px;
            background: #f1f5f9;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            padding: 0 4px;
            min-height: 18px;
            max-height: 20px;
            cursor: pointer;
            white-space: nowrap;
        }
        .debt-more:hover {
            background: #e2e8f0;
        }
        .debt-popover[hidden] { display: none; }
        .debt-popover {
            position: fixed;
            z-index: 1150;
            min-width: 140px;
            max-width: 240px;
            max-height: min(60vh, 360px);
            overflow-y: auto;
            padding: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
        }
        .debt-popover__inner {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: stretch;
        }
        .debt-shift.debt-shift--popover {
            width: 100%;
            min-height: 22px;
            justify-content: center;
        }
        .debt-shift.drag-source-single {
            outline: 2px solid #93c5fd;
            outline-offset: -2px;
            background: #eff6ff;
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
        tr.plan-row.diameter-conflict-flash td.pos-cell,
        tr.plan-row.diameter-conflict-flash td.date-cell.diameter-flash-cell,
        tr.plan-row.diameter-conflict-flash td.date-cell[data-diameter-conflict="1"] {
            background: #fef2f2 !important;
            outline: 2px solid #f87171 !important;
            outline-offset: -2px;
            box-shadow: inset 0 0 0 1px rgba(248, 113, 113, 0.9);
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
        table.has-frozen-cols th,
        table.has-frozen-cols td {
            background-clip: padding-box;
        }
        .frozen-col {
            position: sticky !important;
            left: var(--sticky-left, 0px);
            z-index: 20;
            background: #fff;
        }
        thead .frozen-col,
        tfoot .frozen-col {
            background: #f9fafb;
            z-index: 40;
        }
        tbody tr:hover .frozen-col {
            background: #fafbfc;
        }
        .frozen-col.frozen-col--last {
            box-shadow: 1px 0 0 0 var(--border);
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
            position: relative;
        }
        td.date-cell.date-cell--locked,
        td.date-cell.locked {
            background: #e5e7eb !important;
            color: #374151;
            opacity: .92;
            cursor: not-allowed;
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
        td.analog-cell {
            cursor: pointer;
            user-select: none;
        }
        td.analog-cell.analog-match,
        td.date-cell.analog-shift-match {
            background: #fef08a !important;
            outline: 2px solid #eab308 !important;
            outline-offset: -2px;
            box-shadow: 0 0 10px rgba(234, 179, 8, 0.75);
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
        .date-indicator.level-info.active {
            border-color: #cbd5e1;
            color: #475569;
            background: #f8fafc;
            --meter-color: #cbd5e1;
        }
        .date-indicator.level-warning.active {
            border-color: #f59e0b;
            color: #92400e;
            background: #fef3c7;
            --meter-color: #fbbf24;
        }
        .date-indicator.level-critical.active {
            border-color: #dc2626;
            color: #ffffff;
            background: #ef4444;
            --meter-color: rgba(127, 29, 29, 0.45);
        }
        .date-indicator.priority-muted {
            opacity: .32 !important;
            filter: saturate(.45);
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
        .norm-modal[hidden] { display: none; }
        .norm-modal {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, .45);
            padding: 16px;
        }
        .norm-modal__dialog {
            width: min(760px, 100%);
            max-height: min(80vh, 760px);
            background: #fff;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, .22);
            display: grid;
            grid-template-rows: auto auto 1fr auto;
            overflow: hidden;
        }
        .norm-modal__head {
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            font-weight: 700;
            color: #1e3a8a;
            background: #f8faff;
        }
        .norm-modal__sub {
            padding: 8px 14px;
            font-size: 12px;
            color: #6b7280;
            border-bottom: 1px solid #eef2ff;
        }
        .norm-list {
            margin: 0;
            padding: 10px 12px;
            list-style: none;
            display: grid;
            gap: 6px;
            overflow: auto;
            align-content: start;
        }
        .norm-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fafafa;
            padding: 6px 8px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px;
            align-items: start;
        }
        .norm-item__text {
            font-size: 12px;
            line-height: 1.35;
            color: #111827;
        }
        .norm-item__reason {
            display: inline-block;
            margin-top: 2px;
            font-size: 11px;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 1px 6px;
        }
        .norm-modal__foot {
            padding: 10px 12px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-end;
            background: #f9fafb;
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
            <button type="button" id="addPlanDayBtn" class="toolbar-btn secondary" title="Добавить колонку следующего дня в конец таблицы (только в интерфейсе; в БД не пишется)">+ день</button>
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
                <div class="queue-panel__head-actions">
                    <button type="button" id="applyPendingMovesPanelBtn" class="toolbar-btn">Применить</button>
                    <button type="button" id="closeQueuePanelBtn" class="toolbar-btn secondary">Закрыть</button>
                </div>
            </div>
            <div class="queue-panel__body">
                <p id="moveQueueEmpty" class="queue-panel__empty">Очередь пуста.</p>
                <ul id="moveQueueList" class="queue-list"></ul>
            </div>
        </div>
        <div id="dragPreview" class="drag-preview" hidden></div>
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Позиция</th>
                        <th>Аналог</th>
                        <th class="num">Заказано</th>
                        <th class="num">Изготовлено</th>
                        <th class="num">Остаток</th>
                        <th>Состояние</th>
                        <th>Заявка</th>
                        <th class="debt-col">Долг</th>
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
                        <td colspan="<?= 8 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
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
                        $rowAnalog = trim((string)($rowMeta['analog'] ?? ''));
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
                        <td
                            class="analog-cell"
                            data-analog-key="<?= htmlspecialchars(normalizeFilterKey($rowAnalog), ENT_QUOTES, 'UTF-8') ?>"
                            title="<?= $rowAnalog !== '' ? 'Клик для подсветки одинаковых аналогов' : '' ?>"
                        ><?= $rowAnalog !== '' ? htmlspecialchars($rowAnalog, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                        <td class="num"><?= $ordered ?></td>
                        <td class="num"><?= $produced ?></td>
                        <td class="num"><?= $remaining ?></td>
                        <td class="state-cell" title="<?= htmlspecialchars($stateTitle, ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($isLagging): ?>
                                <span class="state-badge state-lag" title="<?= htmlspecialchars('Незапланировано: ' . $debtShiftQty . ' шт.', ENT_QUOTES, 'UTF-8') ?>">Отстаёт</span>
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
                        <td
                            class="debt-cell"
                            data-debt-key="<?= htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                            data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <div class="debt-list"></div>
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
                        <th>Аналог</th>
                        <th class="num">Заказано</th>
                        <th class="num">Изготовлено</th>
                        <th class="num">Остаток</th>
                        <th>Состояние</th>
                        <th>Заявка</th>
                        <th class="debt-col">Долг</th>
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
            <div class="ind-field">
                <label for="indMaxListPctInput">Макс. % выполнения для списка позиций (включительно)</label>
                <input id="indMaxListPctInput" type="number" min="0" max="100" step="1" value="<?= (int)$maxPct ?>">
                <div class="muted" style="margin-top:4px;font-size:12px;">Выше этого процента по выпуску позиция в таблице не показывается. При изменении порога страница перезагрузится.</div>
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
<div id="normalizePreviewModal" class="norm-modal" hidden>
    <div class="norm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="normalizePreviewTitle">
        <div id="normalizePreviewTitle" class="norm-modal__head">Предлагаемые изменения</div>
        <div class="norm-modal__sub">Выберите, какие переносы добавить в очередь.</div>
        <ul id="normalizePreviewList" class="norm-list"></ul>
        <div class="norm-modal__foot">
            <button type="button" id="normalizePreviewCancelBtn" class="toolbar-btn secondary">Отмена</button>
            <button type="button" id="normalizePreviewApplyBtn" class="toolbar-btn">Применить выбранные</button>
        </div>
    </div>
</div>
<div id="debtExpandPopover" class="debt-popover" hidden>
    <div id="debtExpandPopoverInner" class="debt-popover__inner"></div>
</div>
<script>
    (function () {
        const initialDebtShiftMap = <?= json_encode($debtShiftMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const pageTodayIso = <?= json_encode($todayIso, JSON_UNESCAPED_UNICODE) ?>;
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
        const maxListPctInput = document.getElementById('indMaxListPctInput');
        const normalizePreviewModal = document.getElementById('normalizePreviewModal');
        const normalizePreviewList = document.getElementById('normalizePreviewList');
        const normalizePreviewApplyBtn = document.getElementById('normalizePreviewApplyBtn');
        const normalizePreviewCancelBtn = document.getElementById('normalizePreviewCancelBtn');
        const pendingMovesBar = document.getElementById('pendingMovesBar');
        const pendingMovesText = document.getElementById('pendingMovesText');
        const toggleQueuePanelBtn = document.getElementById('toggleQueuePanelBtn');
        const moveQueuePanel = document.getElementById('moveQueuePanel');
        const closeQueuePanelBtn = document.getElementById('closeQueuePanelBtn');
        const applyPendingMovesPanelBtn = document.getElementById('applyPendingMovesPanelBtn');
        const moveQueueEmpty = document.getElementById('moveQueueEmpty');
        const moveQueueList = document.getElementById('moveQueueList');
        const dragPreview = document.getElementById('dragPreview');
        const debtExpandPopover = document.getElementById('debtExpandPopover');
        const debtExpandPopoverInner = document.getElementById('debtExpandPopoverInner');
        const normalizePlanBtn = document.getElementById('normalizePlanBtn');
        const applyPendingMovesBtn = document.getElementById('applyPendingMovesBtn');
        const undoPendingMoveBtn = document.getElementById('undoPendingMoveBtn');
        const clearPendingMovesBtn = document.getElementById('clearPendingMovesBtn');
        const addPlanDayBtn = document.getElementById('addPlanDayBtn');
        if (!btn || !rowIndicatorsBtn || !modal || !openSettingsBtn || !saveBtn || !cancelBtn || !resetBtn || !maxPressInput || !norm600Input || !normDInput || !normTotalInput || !maxListPctInput || !normalizePreviewModal || !normalizePreviewList || !normalizePreviewApplyBtn || !normalizePreviewCancelBtn || !pendingMovesBar || !pendingMovesText || !toggleQueuePanelBtn || !moveQueuePanel || !closeQueuePanelBtn || !applyPendingMovesPanelBtn || !moveQueueEmpty || !moveQueueList || !dragPreview || !debtExpandPopover || !debtExpandPopoverInner || !normalizePlanBtn || !applyPendingMovesBtn || !undoPendingMoveBtn || !clearPendingMovesBtn || !addPlanDayBtn) {
            return;
        }
        const storageKey = 'activePositionsIndicatorSettings';
        const indicatorsVisibleStorageKey = 'activePositionsHeaderIndicatorsVisible';
        const rowIndicatorsStorageKey = 'activePositionsRowIndicatorsVisible';
        const queuePanelStorageKey = 'activePositionsQueuePanelOpen';
        const lockStorageKey = `activePositionsLockedShifts:${window.location.pathname}`;
        const debtStateMap = Object.assign({}, initialDebtShiftMap || {});
        const serverDefaultMaxListPct = <?= (int)$activePositionsMaxCompletionPct ?>;
        const currentPageMaxListPct = <?= (int)$maxPct ?>;
        const defaults = {
            maxPress: 1,
            norm600: <?= (int)$indicatorNormWidth600 ?>,
            normD: <?= (int)$indicatorNormDiameter ?>,
            normTotal: <?= (int)$indicatorNormTotal ?>,
            maxListPct: serverDefaultMaxListPct,
        };

        function getSettings() {
            try {
                const raw = localStorage.getItem(storageKey);
                if (!raw) {
                    return {
                        ...defaults,
                        maxListPct: currentPageMaxListPct,
                    };
                }
                const parsed = JSON.parse(raw);
                let maxListPctVal;
                if (Object.prototype.hasOwnProperty.call(parsed, 'maxListPct')) {
                    maxListPctVal = Math.max(0, Math.min(100, parseInt(parsed.maxListPct, 10)));
                    if (Number.isNaN(maxListPctVal)) {
                        maxListPctVal = currentPageMaxListPct;
                    }
                } else {
                    maxListPctVal = currentPageMaxListPct;
                }
                return {
                    maxPress: Math.max(1, parseInt(parsed.maxPress, 10) || defaults.maxPress),
                    norm600: Math.max(1, parseInt(parsed.norm600, 10) || defaults.norm600),
                    normD: Math.max(1, parseInt(parsed.normD, 10) || defaults.normD),
                    normTotal: Math.max(1, parseInt(parsed.normTotal, 10) || defaults.normTotal),
                    maxListPct: maxListPctVal,
                };
            } catch (e) {
                return {
                    ...defaults,
                    maxListPct: currentPageMaxListPct,
                };
            }
        }

        function syncForm(settings) {
            maxPressInput.value = String(settings.maxPress);
            norm600Input.value = String(settings.norm600);
            normDInput.value = String(settings.normD);
            normTotalInput.value = String(settings.normTotal);
            maxListPctInput.value = String(settings.maxListPct);
        }

        function reloadWithMaxPct(pct) {
            const url = new URL(window.location.href);
            url.searchParams.set('max_pct', String(pct));
            window.location.href = url.toString();
        }

        function setFill(el, pct) {
            el.style.setProperty('--fill', `${Math.max(0, Math.min(100, pct))}%`);
        }

        function applySettings(settings) {
            function setIndicatorState(el, params) {
                if (!el) {
                    return;
                }
                const level = params.level || 'info';
                const active = !!params.active;
                const over = !!params.over;
                const muted = !!params.muted;
                el.classList.toggle('active', active);
                el.classList.toggle('over', over);
                el.classList.toggle('level-info', active && level === 'info');
                el.classList.toggle('level-warning', active && level === 'warning');
                el.classList.toggle('level-critical', active && level === 'critical');
                el.classList.toggle('priority-muted', muted);
                if (typeof params.pct === 'number') {
                    setFill(el, params.pct);
                }
                if (typeof params.title === 'string') {
                    el.title = params.title;
                }
            }

            document.querySelectorAll('th[data-plan-date]').forEach(function (th) {
                const pressEl = th.querySelector('.date-indicator[data-kind="press"]');
                const dEl = th.querySelector('.date-indicator[data-kind="d"]');
                const w600El = th.querySelector('.date-indicator[data-kind="w600"]');
                const totalEl = th.querySelector('.date-indicator[data-kind="total"]');

                const pressCount = parseInt((pressEl && pressEl.getAttribute('data-press-count')) || '0', 10) || 0;
                const dQty = parseInt((dEl && dEl.getAttribute('data-qty')) || '0', 10) || 0;
                const w600Qty = parseInt((w600El && w600El.getAttribute('data-qty')) || '0', 10) || 0;
                const totalQty = parseInt((totalEl && totalEl.getAttribute('data-qty')) || '0', 10) || 0;

                const pressCritical = pressCount > settings.maxPress;
                const dCritical = dQty > settings.normD;
                const totalWarning = totalQty > settings.normTotal;
                const w600Warning = w600Qty > settings.norm600;
                const hasCritical = pressCritical || dCritical;

                const pressTitle = pressCount <= 0
                    ? 'П: нет позиций под пресс.'
                    : pressCritical
                        ? `П: ${pressCount} прессовых фильтра при норме ${settings.maxPress} (critical: конфликт пресса).`
                        : `П: ${pressCount} прессовый фильтр(ов) при норме ${settings.maxPress}.`;
                setIndicatorState(pressEl, {
                    active: pressCount > 0,
                    over: pressCritical,
                    level: pressCritical ? 'critical' : 'info',
                    muted: hasCritical && !pressCritical,
                    title: pressTitle,
                });

                const dPct = settings.normD > 0 ? (dQty / settings.normD) * 100 : 0;
                const dTitle = dCritical
                    ? `D: ${dQty} шт при норме ${settings.normD} (critical: превышение на ${Math.max(0, dQty - settings.normD)} шт).`
                    : `D (>250 и >400): ${dQty} шт из нормы ${settings.normD} шт.`;
                setIndicatorState(dEl, {
                    active: dQty > 0,
                    over: dCritical,
                    level: dCritical ? 'critical' : 'info',
                    muted: hasCritical && !dCritical,
                    pct: dPct,
                    title: dTitle,
                });

                const totalPct = settings.normTotal > 0 ? (totalQty / settings.normTotal) * 100 : 0;
                const totalTitle = totalWarning
                    ? `Всего: ${totalQty} шт при норме ${settings.normTotal} (warning: перегруз на ${Math.max(0, totalQty - settings.normTotal)} шт).`
                    : `Всего в смену: ${totalQty} шт из нормы ${settings.normTotal} шт.`;
                setIndicatorState(totalEl, {
                    active: totalQty > 0,
                    over: totalWarning,
                    level: totalWarning ? 'warning' : 'info',
                    muted: hasCritical,
                    pct: totalPct,
                    title: totalTitle,
                });

                const w600Pct = settings.norm600 > 0 ? (w600Qty / settings.norm600) * 100 : 0;
                const w600Title = w600Warning
                    ? `600: ${w600Qty} шт при норме ${settings.norm600} (warning: превышение на ${Math.max(0, w600Qty - settings.norm600)} шт).`
                    : `600: ${w600Qty} шт из нормы ${settings.norm600} шт.`;
                setIndicatorState(w600El, {
                    active: w600Qty > 0,
                    over: w600Warning,
                    level: w600Warning ? 'warning' : 'info',
                    muted: hasCritical,
                    pct: w600Pct,
                    title: w600Title,
                });
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
            const indicatorsVisibleRaw = localStorage.getItem(indicatorsVisibleStorageKey);
            const indicatorsVisible = indicatorsVisibleRaw === '1';
            document.body.classList.toggle('show-indicators', indicatorsVisible);
            btn.setAttribute('aria-pressed', indicatorsVisible ? 'true' : 'false');
        } catch (e) {
            btn.setAttribute('aria-pressed', 'false');
        }
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
            try {
                localStorage.setItem(indicatorsVisibleStorageKey, visible ? '1' : '0');
            } catch (e) {
                // ignore storage write errors
            }
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

        let dateCells = [];
        function refreshDateCellsCache() {
            dateCells = Array.from(document.querySelectorAll('td.date-cell'));
        }
        refreshDateCellsCache();
        const FROZEN_COL_COUNT = 8;

        function applyFrozenColumns() {
            const table = document.querySelector('.panel table');
            if (!table) {
                return;
            }
            const headerRow = table.querySelector('thead tr');
            if (!headerRow) {
                return;
            }
            const headerCells = Array.from(headerRow.children || []);
            const freezeCount = Math.min(FROZEN_COL_COUNT, headerCells.length);
            const leftOffsets = [];
            let currentLeft = 0;
            for (let i = 0; i < freezeCount; i += 1) {
                leftOffsets[i] = currentLeft;
                currentLeft += headerCells[i].getBoundingClientRect().width;
            }
            table.classList.add('has-frozen-cols');
            table.querySelectorAll('tr').forEach(function (tr) {
                const cells = Array.from(tr.children || []);
                cells.forEach(function (cell, index) {
                    if (index < freezeCount) {
                        cell.classList.add('frozen-col');
                        cell.style.setProperty('--sticky-left', `${Math.round(leftOffsets[index])}px`);
                        cell.classList.toggle('frozen-col--last', index === freezeCount - 1);
                    } else {
                        cell.classList.remove('frozen-col', 'frozen-col--last');
                        cell.style.removeProperty('--sticky-left');
                    }
                });
            });
        }
        const lockedShiftKeys = loadLockedShiftKeys();
        let dragContext = null;
        let isMoving = false;
        let isApplyingPendingMoves = false;
        let isQueuePanelOpen = false;
        let previewedTargetCell = null;
        const pendingMoves = [];
        let debtPopoverAnchorCell = null;
        let activeAnalogKey = '';
        const DEBT_COMPACT_VISIBLE = 2;
        let normalizationDraftMoves = [];

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

        function parseIsoParts(iso) {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || '').trim());
            if (!m) {
                return null;
            }
            return { y: parseInt(m[1], 10), mo: parseInt(m[2], 10), d: parseInt(m[3], 10) };
        }

        function addDaysIso(iso, days) {
            const p = parseIsoParts(iso);
            if (!p) {
                return '';
            }
            const n = parseInt(days, 10) || 0;
            const dt = new Date(Date.UTC(p.y, p.mo - 1, p.d + n));
            const y = dt.getUTCFullYear();
            const mo = String(dt.getUTCMonth() + 1).padStart(2, '0');
            const d = String(dt.getUTCDate()).padStart(2, '0');
            return `${y}-${mo}-${d}`;
        }

        function formatDdMmFromIso(iso) {
            const p = parseIsoParts(iso);
            if (!p) {
                return String(iso || '');
            }
            return `${String(p.d).padStart(2, '0')}.${String(p.mo).padStart(2, '0')}`;
        }

        function buildPlanDateTh(iso) {
            const th = document.createElement('th');
            th.className = 'num date-col';
            th.setAttribute('data-plan-date', iso);
            th.setAttribute('title', 'План сборки на ' + iso);

            const head = document.createElement('span');
            head.className = 'date-head';

            const label = document.createElement('span');
            label.textContent = formatDdMmFromIso(iso);

            const wrap = document.createElement('span');
            wrap.className = 'date-indicators';
            wrap.setAttribute('aria-hidden', 'true');

            function appendInd(kind, classes, letter, attrs) {
                const el = document.createElement('span');
                el.className = classes;
                el.setAttribute('data-kind', kind);
                if (attrs) {
                    Object.keys(attrs).forEach(function (k) {
                        el.setAttribute(k, attrs[k]);
                    });
                }
                const t = document.createElement('span');
                t.className = 'txt';
                t.textContent = letter;
                el.appendChild(t);
                wrap.appendChild(el);
                return el;
            }

            appendInd('press', 'date-indicator slim marker-p', 'П', {
                'data-press-count': '0',
                title: 'Нет позиций под пресс',
            });
            const dEl = appendInd('d', 'date-indicator slim marker-d meter', 'D', {
                'data-qty': '0',
                title: 'D (>250 и >400): 0 шт из нормы ' + defaults.normD + ' шт',
            });
            dEl.style.setProperty('--fill', '0%');
            const wEl = appendInd('w600', 'date-indicator marker-600 meter', '600', {
                'data-qty': '0',
                title: '600: 0 шт из нормы ' + defaults.norm600 + ' шт',
            });
            wEl.style.setProperty('--fill', '0%');
            const totEl = appendInd('total', 'date-indicator marker-total meter', '0', {
                'data-qty': '0',
                title: 'Всего в смену: 0 шт из нормы ' + defaults.normTotal + ' шт',
            });
            totEl.style.setProperty('--fill', '0%');

            head.appendChild(label);
            head.appendChild(wrap);
            th.appendChild(head);
            return th;
        }

        function loadLockedShiftKeys() {
            try {
                const raw = localStorage.getItem(lockStorageKey);
                if (!raw) {
                    return new Set();
                }
                const parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) {
                    return new Set();
                }
                return new Set(parsed
                    .map(function (item) { return String(item || '').trim(); })
                    .filter(function (item) { return item !== ''; }));
            } catch (e) {
                return new Set();
            }
        }

        function persistLockedShiftKeys() {
            try {
                localStorage.setItem(lockStorageKey, JSON.stringify(Array.from(lockedShiftKeys)));
            } catch (e) {
                // ignore storage write errors
            }
        }

        function setCellQty(cell, qty) {
            const normalizedQty = Math.max(0, parseInt(qty, 10) || 0);
            cell.dataset.qty = String(normalizedQty);
            cell.textContent = normalizedQty > 0 ? String(normalizedQty) : '';
            refreshCellLockState(cell);
        }

        function getCellLockKey(cell) {
            if (!cell) {
                return '';
            }
            const order = String(cell.dataset.order || '');
            const filter = normalizeFilterKeyJs(cell.dataset.filter || '');
            const date = String(cell.dataset.date || '');
            if (!order || !filter || !date) {
                return '';
            }
            return `${order}|${filter}|${date}`;
        }

        function isCellLocked(cell) {
            const key = getCellLockKey(cell);
            return key !== '' && lockedShiftKeys.has(key);
        }

        function refreshCellLockState(cell) {
            if (!cell) {
                return;
            }
            const qty = Math.max(0, parseInt(cell.dataset.qty || '0', 10) || 0);
            const key = getCellLockKey(cell);
            let wasUpdated = false;
            if (qty <= 0 && key !== '') {
                wasUpdated = lockedShiftKeys.delete(key) || wasUpdated;
            }
            const locked = qty > 0 && key !== '' && lockedShiftKeys.has(key);
            cell.classList.toggle('date-cell--locked', locked);
            cell.classList.toggle('locked', locked);
            if (locked) {
                cell.dataset.locked = '1';
                cell.setAttribute('draggable', 'false');
                cell.title = 'Зафиксировано';
            } else {
                delete cell.dataset.locked;
                cell.setAttribute('draggable', qty > 0 ? 'true' : 'false');
                cell.title = getCellTitle(qty);
            }
            if (wasUpdated) {
                persistLockedShiftKeys();
            }
        }

        function toggleCellLock(cell) {
            if (!cell) {
                return;
            }
            const qty = Math.max(0, parseInt(cell.dataset.qty || '0', 10) || 0);
            if (qty <= 0) {
                return;
            }
            const key = getCellLockKey(cell);
            if (!key) {
                return;
            }
            if (lockedShiftKeys.has(key)) {
                lockedShiftKeys.delete(key);
            } else {
                lockedShiftKeys.add(key);
            }
            persistLockedShiftKeys();
            refreshCellLockState(cell);
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

        function rowHasDiameterFlag(row) {
            return (row.dataset.hasD || '') === '1' || !!row.querySelector('.pos-indicator.d');
        }

        function flashPressConflictForDate(date) {
            clearIndicatorConflictFlash();
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
                clearIndicatorConflictFlash();
                return;
            }
            window.setTimeout(clearIndicatorConflictFlash, 2200);
        }

        function flashDiameterConflictForDate(date) {
            clearIndicatorConflictFlash();
            const dateKey = String(date || '').trim();
            if (!dateKey) {
                return;
            }
            let count = 0;
            document.querySelectorAll('tr.plan-row').forEach(function (row) {
                if (!rowHasDiameterFlag(row)) {
                    return;
                }
                const conflictCell = Array.from(row.querySelectorAll('td.date-cell')).find(function (cell) {
                    const cellDate = String(cell.dataset.date || '').trim();
                    return cellDate === dateKey && (parseInt(cell.dataset.qty || '0', 10) || 0) > 0;
                });
                if (!conflictCell) {
                    return;
                }
                count += 1;
                row.classList.add('diameter-conflict-flash');
                conflictCell.classList.add('diameter-flash-cell');
                conflictCell.dataset.diameterConflict = '1';
            });
            if (count < 1) {
                clearIndicatorConflictFlash();
                return;
            }
            window.setTimeout(clearIndicatorConflictFlash, 2200);
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
            applyPendingMovesPanelBtn.disabled = disabled;
            undoPendingMoveBtn.disabled = disabled;
            clearPendingMovesBtn.disabled = disabled;
            toggleQueuePanelBtn.textContent = `Очередь (${count})`;
            if (isApplyingPendingMoves) {
                applyPendingMovesBtn.textContent = 'Применение...';
                applyPendingMovesPanelBtn.textContent = 'Применение...';
            } else {
                applyPendingMovesBtn.textContent = `Применить (${count})`;
                applyPendingMovesPanelBtn.textContent = `Применить (${count})`;
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

        function bindDebtShiftDrag(item) {
            item.addEventListener('dragstart', function (e) {
                const order = item.dataset.order || '';
                const filter = item.dataset.filter || '';
                const fromDate = item.dataset.date || '';
                const qty = Math.max(0, parseInt(item.dataset.qty || '0', 10) || 0);
                if (!order || !filter || !fromDate || qty <= 0) {
                    e.preventDefault();
                    return;
                }
                dragContext = {
                    mode: 'single',
                    sourceType: 'debt',
                    order: order,
                    filter: filter,
                    fromDate: fromDate,
                    movedQty: qty,
                    debtKey: getPlanKey(order, filter),
                };
                item.classList.add('drag-source-single');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', `${order}|${filter}|${fromDate}|debt`);
                }
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('drag-source-single');
                clearDragState();
            });
        }

        function closeDebtExpandPopover() {
            debtPopoverAnchorCell = null;
            debtExpandPopover.hidden = true;
            debtExpandPopoverInner.innerHTML = '';
        }

        function positionDebtExpandPopover(anchorCell) {
            if (!anchorCell || debtExpandPopover.hidden) {
                return;
            }
            const rect = anchorCell.getBoundingClientRect();
            const popRect = debtExpandPopover.getBoundingClientRect();
            const gap = 6;
            const vw = window.innerWidth || document.documentElement.clientWidth || 0;
            const vh = window.innerHeight || document.documentElement.clientHeight || 0;
            let left = rect.left + (rect.width - popRect.width) / 2;
            let top = rect.bottom + gap;
            if (left + popRect.width > vw - 8) {
                left = Math.max(8, vw - popRect.width - 8);
            }
            if (left < 8) {
                left = 8;
            }
            if (top + popRect.height > vh - 8) {
                top = Math.max(8, rect.top - popRect.height - gap);
            }
            if (top < 8) {
                top = 8;
            }
            debtExpandPopover.style.left = `${Math.round(left)}px`;
            debtExpandPopover.style.top = `${Math.round(top)}px`;
        }

        function fillDebtPopoverShifts(debtCell) {
            debtExpandPopoverInner.innerHTML = '';
            const planKey = debtCell.dataset.debtKey || '';
            const order = debtCell.dataset.order || '';
            const filter = debtCell.dataset.filter || '';
            const shifts = getDebtShiftsForKey(planKey);
            shifts.forEach(function (shift) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'debt-shift debt-shift--popover';
                item.draggable = true;
                item.dataset.order = order;
                item.dataset.filter = filter;
                item.dataset.date = shift.date;
                item.dataset.qty = String(shift.qty);
                item.title = `Долг ${toShortDate(shift.date)}: ${shift.qty} шт`;
                item.textContent = `${toShortDate(shift.date)} • ${shift.qty}`;
                bindDebtShiftDrag(item);
                debtExpandPopoverInner.appendChild(item);
            });
        }

        function openDebtExpandPopover(debtCell) {
            if (!debtCell) {
                return;
            }
            const planKey = debtCell.dataset.debtKey || '';
            const shifts = getDebtShiftsForKey(planKey);
            if (shifts.length === 0) {
                closeDebtExpandPopover();
                return;
            }
            debtPopoverAnchorCell = debtCell;
            fillDebtPopoverShifts(debtCell);
            debtExpandPopover.hidden = false;
            positionDebtExpandPopover(debtCell);
            window.requestAnimationFrame(function () {
                positionDebtExpandPopover(debtCell);
            });
        }

        function refreshDebtExpandPopoverIfOpen(debtCell) {
            if (!debtPopoverAnchorCell || debtCell !== debtPopoverAnchorCell) {
                return;
            }
            const planKey = debtCell.dataset.debtKey || '';
            const shifts = getDebtShiftsForKey(planKey);
            if (shifts.length === 0) {
                closeDebtExpandPopover();
                return;
            }
            fillDebtPopoverShifts(debtCell);
            positionDebtExpandPopover(debtCell);
        }

        function renderDebtCellByKey(planKey) {
            if (!planKey) {
                return;
            }
            const debtCell = document.querySelector(`td.debt-cell[data-debt-key="${CSS.escape(planKey)}"]`);
            if (!debtCell) {
                return;
            }
            const list = debtCell.querySelector('.debt-list');
            if (!list) {
                return;
            }
            const order = debtCell.dataset.order || '';
            const filter = debtCell.dataset.filter || '';
            const shifts = getDebtShiftsForKey(planKey);
            const total = shifts.length;
            debtCell.classList.toggle('debt-cell--warn', total > 3);
            if (total === 0) {
                debtCell.title = '';
            } else {
                debtCell.title = `Долг: ${total} смен`;
            }
            list.innerHTML = '';
            const visible = shifts.slice(0, DEBT_COMPACT_VISIBLE);
            visible.forEach(function (shift) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'debt-shift';
                item.draggable = true;
                item.dataset.order = order;
                item.dataset.filter = filter;
                item.dataset.date = shift.date;
                item.dataset.qty = String(shift.qty);
                item.title = `Долг ${toShortDate(shift.date)}: ${shift.qty} шт`;
                item.textContent = `${shift.qty}`;
                bindDebtShiftDrag(item);
                list.appendChild(item);
            });
            const hidden = total - DEBT_COMPACT_VISIBLE;
            if (hidden > 0) {
                const moreBtn = document.createElement('button');
                moreBtn.type = 'button';
                moreBtn.className = 'debt-more';
                moreBtn.textContent = `+${hidden}`;
                moreBtn.title = `Ещё ${hidden} смен — открыть список`;
                moreBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openDebtExpandPopover(debtCell);
                });
                list.appendChild(moreBtn);
            }
            refreshDebtExpandPopoverIfOpen(debtCell);
        }

        function clearQueuePreview() {
            dateCells.forEach(function (cell) {
                cell.classList.remove('queue-src', 'queue-dst');
            });
            document.querySelectorAll('tr.plan-row.queue-row').forEach(function (row) {
                row.classList.remove('queue-row');
            });
        }

        function clearIndicatorConflictFlash() {
            document.querySelectorAll('tr.plan-row.press-conflict-flash').forEach(function (row) {
                row.classList.remove('press-conflict-flash');
            });
            document.querySelectorAll('tr.plan-row.diameter-conflict-flash').forEach(function (row) {
                row.classList.remove('diameter-conflict-flash');
            });
            dateCells.forEach(function (cell) {
                delete cell.dataset.dateConflict;
                delete cell.dataset.diameterConflict;
                cell.classList.remove('diameter-flash-cell');
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
            if (dragContext.sourceType === 'debt') {
                return true;
            }
            return targetCell.dataset.date !== dragContext.fromDate;
        }

        function clearAnalogHighlight() {
            activeAnalogKey = '';
            document.querySelectorAll('td.analog-cell.analog-match').forEach(function (cell) {
                cell.classList.remove('analog-match');
            });
            document.querySelectorAll('td.date-cell.analog-shift-match').forEach(function (cell) {
                cell.classList.remove('analog-shift-match');
            });
        }

        function highlightAnalogKey(analogKey) {
            const key = String(analogKey || '').trim();
            clearAnalogHighlight();
            if (!key) {
                return;
            }
            const cells = Array.from(document.querySelectorAll(`td.analog-cell[data-analog-key="${CSS.escape(key)}"]`));
            if (cells.length < 2) {
                return;
            }
            activeAnalogKey = key;
            cells.forEach(function (cell) {
                cell.classList.add('analog-match');
                const row = cell.closest('tr.plan-row');
                if (!row) {
                    return;
                }
                row.querySelectorAll('td.date-cell').forEach(function (dc) {
                    const q = parseInt(dc.dataset.qty || '0', 10) || 0;
                    if (q > 0) {
                        dc.classList.add('analog-shift-match');
                    }
                });
            });
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
            if (blockCells.some(function (cell) { return isCellLocked(cell); })) {
                return { error: 'Нельзя перемещать зафиксированную смену.' };
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
            if (sourceCell && isCellLocked(sourceCell)) {
                return { error: 'Нельзя перемещать зафиксированную смену.' };
            }
            const order = drag.order || (sourceCell ? (sourceCell.dataset.order || '') : '');
            const filter = drag.filter || (sourceCell ? (sourceCell.dataset.filter || '') : '');
            const fromDate = drag.fromDate || (sourceCell ? (sourceCell.dataset.date || '') : '');
            const toDate = targetCell.dataset.date || '';
            const isDebtSource = drag.sourceType === 'debt';
            if (sourceQty <= 0 || order === '' || filter === '' || fromDate === '' || toDate === '' || (!isDebtSource && fromDate === toDate)) {
                return null;
            }
            if (targetQty > 0) {
                return { error: 'Нельзя складывать смены одной позиции: целевая дата уже занята.' };
            }
            const queuedMove = {
                payload: {
                    mode: drag.sourceType === 'debt' ? 'debt' : 'single',
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
                queuedMove.payload.moved_qty = sourceQty;
                queuedMove.debtChange = {
                    key: drag.debtKey || getPlanKey(order, filter),
                    shift: { date: fromDate, qty: sourceQty },
                    movedQty: sourceQty,
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

        function getCellQtyVirtual(cell, overrides) {
            if (overrides && overrides.has(cell)) {
                return Math.max(0, parseInt(overrides.get(cell), 10) || 0);
            }
            return Math.max(0, parseInt(cell.dataset.qty || '0', 10) || 0);
        }

        function getDateTotalQtyVirtual(date, overrides) {
            let total = 0;
            dateCells.forEach(function (cell) {
                if ((cell.dataset.date || '') !== date) {
                    return;
                }
                total += getCellQtyVirtual(cell, overrides);
            });
            return total;
        }

        function getDateDQtyVirtual(date, overrides) {
            let total = 0;
            dateCells.forEach(function (cell) {
                if ((cell.dataset.date || '') !== date) {
                    return;
                }
                const qty = getCellQtyVirtual(cell, overrides);
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

        function buildQueuedSingleMoveVirtual(sourceCell, targetCell, overrides) {
            if (!sourceCell || !targetCell) {
                return null;
            }
            if (isCellLocked(sourceCell)) {
                return { error: 'Нельзя перемещать зафиксированную смену.' };
            }
            const sourceQty = getCellQtyVirtual(sourceCell, overrides);
            const targetQty = getCellQtyVirtual(targetCell, overrides);
            const order = sourceCell.dataset.order || '';
            const filter = sourceCell.dataset.filter || '';
            const fromDate = sourceCell.dataset.date || '';
            const toDate = targetCell.dataset.date || '';
            if (sourceQty <= 0 || order === '' || filter === '' || fromDate === '' || toDate === '' || fromDate === toDate) {
                return null;
            }
            if (targetQty > 0) {
                return { error: 'Нельзя складывать смены одной позиции: целевая дата уже занята.' };
            }
            return {
                payload: {
                    mode: 'single',
                    order_number: order,
                    filter_name: filter,
                    from_date: fromDate,
                    to_date: toDate,
                },
                movedQty: sourceQty,
                changes: [
                    { cell: sourceCell, prev: sourceQty, next: 0 },
                    { cell: targetCell, prev: targetQty, next: targetQty + sourceQty },
                ],
            };
        }

        function closeNormalizePreviewModal() {
            normalizationDraftMoves = [];
            normalizePreviewList.innerHTML = '';
            normalizePreviewModal.hidden = true;
        }

        function renderNormalizePreviewModal(draftMoves) {
            normalizePreviewList.innerHTML = '';
            draftMoves.forEach(function (draft, idx) {
                const item = document.createElement('li');
                item.className = 'norm-item';
                const check = document.createElement('input');
                check.type = 'checkbox';
                check.checked = true;
                check.dataset.idx = String(idx);
                const text = document.createElement('div');
                text.className = 'norm-item__text';
                const payload = draft.payload || {};
                const order = payload.order_number || '—';
                const filter = payload.filter_name || '—';
                const fromDate = toShortDate(payload.from_date || '');
                const toDate = toShortDate(payload.to_date || '');
                text.innerHTML = `${order} • ${filter}: ${fromDate} -> ${toDate}, ${draft.movedQty} шт<br><span class="norm-item__reason">${draft.reason}</span>`;
                item.appendChild(check);
                item.appendChild(text);
                normalizePreviewList.appendChild(item);
            });
            normalizePreviewModal.hidden = false;
        }

        function applySelectedNormalizationDraftMoves() {
            const selectedIndexes = Array.from(normalizePreviewList.querySelectorAll('input[type="checkbox"][data-idx]:checked'))
                .map(function (el) { return parseInt(el.dataset.idx || '-1', 10); })
                .filter(function (idx) { return !Number.isNaN(idx) && idx >= 0 && idx < normalizationDraftMoves.length; });
            if (selectedIndexes.length === 0) {
                closeNormalizePreviewModal();
                return;
            }
            const skipped = [];
            selectedIndexes.forEach(function (idx) {
                const draft = normalizationDraftMoves[idx];
                if (!draft || !draft.payload) {
                    return;
                }
                const payload = draft.payload;
                const rowCells = getRowDateCellsByOrderFilter(payload.order_number || '', payload.filter_name || '');
                if (rowCells.length === 0) {
                    skipped.push(`${payload.order_number || '—'} / ${payload.filter_name || '—'} (${toShortDate(payload.from_date || '')} -> ${toShortDate(payload.to_date || '')})`);
                    return;
                }
                const sourceCell = getCellByDate(rowCells, payload.from_date || '');
                const targetCell = getCellByDate(rowCells, payload.to_date || '');
                const queuedMove = buildQueuedSingleMove(sourceCell, targetCell);
                if (!queuedMove || queuedMove.error) {
                    skipped.push(`${payload.order_number || '—'} / ${payload.filter_name || '—'} (${toShortDate(payload.from_date || '')} -> ${toShortDate(payload.to_date || '')})`);
                    return;
                }
                pushPendingMove(queuedMove);
            });
            closeNormalizePreviewModal();
            if (skipped.length > 0) {
                alert(`Часть предложений не применена: ${skipped.join(', ')}`);
            }
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

            const virtualQty = new Map();
            const draftMoves = [];
            const unresolved = new Set();
            let guard = 0;
            for (let i = 0; i < dates.length; i += 1) {
                const date = dates[i];
                while (true) {
                    const overloadState = {
                        totalOver: Math.max(0, getDateTotalQtyVirtual(date, virtualQty) - normTotal),
                        dOver: Math.max(0, getDateDQtyVirtual(date, virtualQty) - normD),
                    };
                    overloadState.hasOverload = overloadState.totalOver > 0 || overloadState.dOver > 0;
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
                        if (isCellLocked(cell)) {
                            return false;
                        }
                        const qty = getCellQtyVirtual(cell, virtualQty);
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
                        const qty = getCellQtyVirtual(sourceCell, virtualQty);
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
                            if (getCellQtyVirtual(targetCell, virtualQty) > 0) {
                                continue;
                            }
                            if (getDateTotalQtyVirtual(targetDate, virtualQty) + qty > normTotal) {
                                continue;
                            }
                            if (sourceIsD && (getDateDQtyVirtual(targetDate, virtualQty) + qty > normD)) {
                                continue;
                            }
                            const queuedMove = buildQueuedSingleMoveVirtual(sourceCell, targetCell, virtualQty);
                            if (!queuedMove) {
                                continue;
                            }
                            if (queuedMove.error) {
                                continue;
                            }
                            queuedMove.changes.forEach(function (change) {
                                virtualQty.set(change.cell, change.next);
                            });
                            const reason = overloadState.dOver > 0 ? 'конфликт D' : 'перегруз total';
                            draftMoves.push({
                                payload: queuedMove.payload,
                                movedQty: queuedMove.movedQty,
                                reason: reason,
                            });
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

            if (draftMoves.length === 0 && unresolved.size === 0) {
                alert('Нормализация: перегрузов по нормам total/D не найдено.');
                return;
            }
            if (draftMoves.length > 0) {
                normalizationDraftMoves = draftMoves;
                renderNormalizePreviewModal(draftMoves);
            }
            if (draftMoves.length > 0 && unresolved.size === 0) {
                return;
            }
            const unresolvedList = Array.from(unresolved).join(', ');
            alert(`Нормализация: найдено ${draftMoves.length} предложений, но остались перегрузы по датам: ${unresolvedList}.`);
        }

        function applyQueuedMoveChanges(queuedMove, direction) {
            queuedMove.changes.forEach(function (change) {
                setCellQty(change.cell, direction === 'forward' ? change.next : change.prev);
            });
            if (queuedMove.debtChange) {
                const debtKey = queuedMove.debtChange.key || '';
                const shift = queuedMove.debtChange.shift || null;
                const debtMoved = Math.max(0, parseInt(queuedMove.debtChange.movedQty || queuedMove.movedQty || 0, 10) || 0);
                if (debtKey && shift && shift.date && debtMoved > 0) {
                    const shifts = getDebtShiftsForKey(debtKey);
                    const dateRef = shift.date;
                    if (direction === 'forward') {
                        let remaining = debtMoved;
                        const next = [];
                        shifts.forEach(function (item) {
                            if (remaining > 0 && item.date === dateRef) {
                                const q = Math.max(0, parseInt(item.qty || 0, 10) || 0);
                                const take = Math.min(q, remaining);
                                remaining -= take;
                                const left = q - take;
                                if (left > 0) {
                                    next.push({ date: item.date, qty: left });
                                }
                            } else {
                                next.push({ date: item.date, qty: item.qty });
                            }
                        });
                        setDebtShiftsForKey(debtKey, next);
                    } else {
                        let merged = false;
                        const next = shifts.map(function (item) {
                            if (item.date === dateRef) {
                                merged = true;
                                return { date: item.date, qty: (Math.max(0, parseInt(item.qty || 0, 10) || 0)) + debtMoved };
                            }
                            return { date: item.date, qty: item.qty };
                        });
                        if (!merged) {
                            next.push({ date: dateRef, qty: debtMoved });
                        }
                        setDebtShiftsForKey(debtKey, next);
                    }
                    renderDebtCellByKey(debtKey);
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
                pendingMoves.length = 0;
                clearQueuePreview();
                renderMoveQueuePanel();
                recalcHeaderIndicatorsFromTable();
                closeDebtExpandPopover();
            } catch (err) {
                alert((err && err.message) ? err.message : 'Ошибка применения изменений');
            } finally {
                isApplyingPendingMoves = false;
                updatePendingBarState();
            }
        }

        function promptDebtTransferQty(maxQty) {
            const cap = Math.max(0, parseInt(maxQty, 10) || 0);
            if (cap <= 0) {
                return null;
            }
            if (cap === 1) {
                return 1;
            }
            const raw = window.prompt(`Сколько штук перенести из долга? (от 1 до ${cap})`, String(cap));
            if (raw === null) {
                return null;
            }
            const n = parseInt(String(raw).trim().replace(/\s+/g, ''), 10);
            if (Number.isNaN(n) || n < 1 || n > cap) {
                alert(`Введите целое число от 1 до ${cap}.`);
                return null;
            }
            return n;
        }

        function queueMoveFromDrop(targetCell) {
            if (!dragContext || isMoving || isApplyingPendingMoves) {
                return;
            }
            if (dragContext.sourceType === 'debt') {
                const maxDebt = Math.max(0, parseInt(dragContext.movedQty || 0, 10) || 0);
                const chosen = promptDebtTransferQty(maxDebt);
                if (chosen === null) {
                    clearDragState();
                    return;
                }
                dragContext.movedQty = chosen;
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

        function bindPressIndicatorConflictClick(th) {
            const indicator = th.querySelector('.date-indicator[data-kind="press"]');
            if (!indicator) {
                return;
            }
            indicator.addEventListener('click', function () {
                if (!indicator.classList.contains('over')) {
                    return;
                }
                const date = th.getAttribute('data-plan-date') || '';
                flashPressConflictForDate(date);
            });
        }

        function resetNewPlanDateHeader(th, iso) {
            th.setAttribute('data-plan-date', iso);
            th.setAttribute('title', 'План сборки на ' + iso);
            const labelSpan = th.querySelector('.date-head > span:first-child');
            if (labelSpan) {
                labelSpan.textContent = formatDdMmFromIso(iso);
            }
            th.querySelectorAll('.date-indicator').forEach(function (el) {
                const kind = el.getAttribute('data-kind');
                if (kind === 'press') {
                    el.setAttribute('data-press-count', '0');
                }
                if (kind === 'd' || kind === 'w600' || kind === 'total') {
                    el.setAttribute('data-qty', '0');
                    el.style.setProperty('--fill', '0%');
                }
                if (kind === 'total') {
                    const txt = el.querySelector('.txt');
                    if (txt) {
                        txt.textContent = '0';
                    }
                }
            });
        }

        function addPlanDayColumn() {
            const table = document.querySelector('.panel table');
            if (!table) {
                return;
            }
            const rowList = Array.from(document.querySelectorAll('tbody tr.plan-row'));
            if (rowList.length === 0) {
                alert('Нет строк плана — добавлять колонку не к чему.');
                return;
            }
            const dates = getUniquePlanDates();
            let nextIso;
            if (dates.length > 0) {
                nextIso = addDaysIso(dates[dates.length - 1], 1);
            } else {
                nextIso = pageTodayIso;
            }
            if (!nextIso || !parseIsoParts(nextIso)) {
                return;
            }
            if (dates.indexOf(nextIso) !== -1) {
                alert('Колонка для этой даты уже есть.');
                return;
            }
            const theadRow = table.querySelector('thead tr');
            const tfootRow = table.querySelector('tfoot tr');
            if (!theadRow || !tfootRow) {
                return;
            }
            const lastHeadDateTh = theadRow.querySelector('th[data-plan-date]:last-of-type');
            let newHeadTh;
            if (lastHeadDateTh) {
                newHeadTh = lastHeadDateTh.cloneNode(true);
            } else {
                newHeadTh = buildPlanDateTh(nextIso);
            }
            resetNewPlanDateHeader(newHeadTh, nextIso);
            const newFootTh = newHeadTh.cloneNode(true);
            theadRow.appendChild(newHeadTh);
            tfootRow.appendChild(newFootTh);
            bindPressIndicatorConflictClick(newHeadTh);
            bindPressIndicatorConflictClick(newFootTh);

            rowList.forEach(function (tr) {
                const order = tr.getAttribute('data-order') || '';
                const filter = tr.getAttribute('data-filter') || '';
                const td = document.createElement('td');
                td.className = 'num date-col date-cell';
                td.dataset.order = order;
                td.dataset.filter = filter;
                td.dataset.date = nextIso;
                td.dataset.qty = '0';
                td.setAttribute('draggable', 'false');
                td.title = getCellTitle(0);
                td.textContent = '';
                tr.appendChild(td);
                bindDateCellInteractions(td);
                refreshCellLockState(td);
            });

            refreshDateCellsCache();
            recalcHeaderIndicatorsFromTable();
            applyFrozenColumns();
        }

        function bindDateCellInteractions(cell) {
            refreshCellLockState(cell);
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
                if (qty <= 0 || isCellLocked(cell)) {
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
                    if (blockCells.some(function (oneCell) { return isCellLocked(oneCell); })) {
                        e.preventDefault();
                        dragContext = null;
                        return;
                    }
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
            cell.addEventListener('click', function () {
                if (dragContext || isMoving || isApplyingPendingMoves) {
                    return;
                }
                toggleCellLock(cell);
            });
        }

        dateCells.forEach(bindDateCellInteractions);

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
        addPlanDayBtn.addEventListener('click', function () {
            if (isApplyingPendingMoves) {
                return;
            }
            addPlanDayColumn();
        });
        normalizePlanBtn.addEventListener('click', function () {
            normalizePlanIntoQueue();
        });
        normalizePreviewApplyBtn.addEventListener('click', function () {
            applySelectedNormalizationDraftMoves();
        });
        normalizePreviewCancelBtn.addEventListener('click', function () {
            closeNormalizePreviewModal();
        });
        normalizePreviewModal.addEventListener('click', function (e) {
            if (e.target === normalizePreviewModal) {
                closeNormalizePreviewModal();
            }
        });
        applyPendingMovesBtn.addEventListener('click', function () {
            applyPendingMovesToServer();
        });
        applyPendingMovesPanelBtn.addEventListener('click', function () {
            applyPendingMovesToServer();
        });
        document.querySelectorAll('th[data-plan-date]').forEach(bindPressIndicatorConflictClick);
        toggleQueuePanelBtn.addEventListener('click', function () {
            setQueuePanelOpen(!isQueuePanelOpen);
        });
        closeQueuePanelBtn.addEventListener('click', function () {
            setQueuePanelOpen(false);
            clearQueuePreview();
        });
        updatePendingBarState();
        recalcHeaderIndicatorsFromTable();
        renderMoveQueuePanel();
        applyFrozenColumns();
        document.querySelectorAll('td.debt-cell[data-debt-key]').forEach(function (cell) {
            renderDebtCellByKey(cell.dataset.debtKey || '');
        });
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
            const url = new URL(window.location.href);
            if (url.searchParams.has('max_pct')) {
                url.searchParams.delete('max_pct');
                window.location.href = url.toString();
            }
        });
        saveBtn.addEventListener('click', function () {
            const newMaxListPct = Math.max(0, Math.min(100, parseInt(maxListPctInput.value, 10) || serverDefaultMaxListPct));
            const settings = {
                maxPress: Math.max(1, parseInt(maxPressInput.value, 10) || defaults.maxPress),
                norm600: Math.max(1, parseInt(norm600Input.value, 10) || defaults.norm600),
                normD: Math.max(1, parseInt(normDInput.value, 10) || defaults.normD),
                normTotal: Math.max(1, parseInt(normTotalInput.value, 10) || defaults.normTotal),
                maxListPct: newMaxListPct,
            };
            localStorage.setItem(storageKey, JSON.stringify(settings));
            applySettings(settings);
            closeModal();
            if (newMaxListPct !== currentPageMaxListPct) {
                reloadWithMaxPct(newMaxListPct);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) {
                closeModal();
                return;
            }
            if (e.key === 'Escape' && !normalizePreviewModal.hidden) {
                closeNormalizePreviewModal();
                return;
            }
            if (e.key === 'Escape' && !debtExpandPopover.hidden) {
                closeDebtExpandPopover();
            }
        });
        document.addEventListener('click', function (e) {
            const analogCell = e.target.closest('td.analog-cell');
            if (analogCell) {
                const analogKey = String(analogCell.dataset.analogKey || '').trim();
                if (!analogKey) {
                    clearAnalogHighlight();
                    return;
                }
                if (activeAnalogKey === analogKey) {
                    clearAnalogHighlight();
                    return;
                }
                highlightAnalogKey(analogKey);
                return;
            }
            if (activeAnalogKey) {
                clearAnalogHighlight();
            }
            const dInd = e.target.closest('.date-indicator[data-kind="d"]');
            const dTh = dInd ? dInd.closest('th[data-plan-date]') : null;
            if (dInd && dTh) {
                const dQty = parseInt(dInd.getAttribute('data-qty') || '0', 10) || 0;
                const normD = Math.max(1, parseInt(getSettings().normD, 10) || 1);
                if (dQty > normD) {
                    const planDate = String(dTh.getAttribute('data-plan-date') || '').trim();
                    flashDiameterConflictForDate(planDate);
                }
            }
            if (!debtExpandPopover.hidden) {
                if (debtExpandPopover.contains(e.target)) {
                    return;
                }
                const clickedCell = e.target.closest('td.debt-cell');
                if (clickedCell && clickedCell === debtPopoverAnchorCell && !e.target.closest('.debt-shift')) {
                    closeDebtExpandPopover();
                    return;
                }
                closeDebtExpandPopover();
            }
            const debtCell = e.target.closest('td.debt-cell');
            if (!debtCell) {
                return;
            }
            if (e.target.closest('.debt-shift')) {
                return;
            }
            if (e.target.closest('.debt-more')) {
                return;
            }
            const planKey = debtCell.dataset.debtKey || '';
            if (getDebtShiftsForKey(planKey).length === 0) {
                return;
            }
            openDebtExpandPopover(debtCell);
        });
        window.addEventListener('resize', function () {
            applyFrozenColumns();
            if (previewedTargetCell) {
                positionDragPreview(previewedTargetCell);
            }
            if (!debtExpandPopover.hidden && debtPopoverAnchorCell) {
                positionDebtExpandPopover(debtPopoverAnchorCell);
            }
        });
        window.addEventListener('scroll', function () {
            if (previewedTargetCell) {
                positionDragPreview(previewedTargetCell);
            }
            if (!debtExpandPopover.hidden && debtPopoverAnchorCell) {
                positionDebtExpandPopover(debtPopoverAnchorCell);
            }
        }, true);
    })();
</script>
</body>
</html>
