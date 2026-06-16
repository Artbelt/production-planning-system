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
/** Макс. % выполнения для показа (как на active_positions). */
$gofroMaxCompletionPctDefault = 80;

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

/**
 * Просрочка не может превышать потребность, не покрытую планом с сегодня (FIFO по датам).
 *
 * @param list<array{date: string, qty: int}> $shifts
 * @return list<array{date: string, qty: int}>
 */
function capGofroDebtShiftsToUncoveredNeed(array $shifts, int $uncoveredNeed): array
{
    if ($uncoveredNeed <= 0 || $shifts === []) {
        return [];
    }
    $total = 0;
    foreach ($shifts as $shift) {
        $total += (int)($shift['qty'] ?? 0);
    }
    if ($total <= $uncoveredNeed) {
        return $shifts;
    }
    $remaining = $uncoveredNeed;
    $capped = [];
    foreach ($shifts as $shift) {
        $qty = (int)($shift['qty'] ?? 0);
        if ($qty <= 0 || $remaining <= 0) {
            continue;
        }
        $take = min($qty, $remaining);
        $capped[] = ['date' => (string)$shift['date'], 'qty' => $take];
        $remaining -= $take;
    }
    return $capped;
}

/**
 * Колонки таблицы — только с сегодня; непрерывный диапазон до последней даты.
 *
 * @param list<string> $dates
 * @return list<string>
 */
function filterPlanDatesFromToday(array $dates, string $todayIso): array
{
    $filtered = [];
    foreach ($dates as $date) {
        $d = (string)$date;
        if ($d !== '' && $d >= $todayIso) {
            $filtered[] = $d;
        }
    }
    if ($filtered === []) {
        return [];
    }
    sort($filtered);
    $fullDateRange = [];
    $cursor = new DateTimeImmutable((string)$filtered[0]);
    $lastDate = new DateTimeImmutable((string)$filtered[count($filtered) - 1]);
    while ($cursor <= $lastDate) {
        $fullDateRange[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $fullDateRange;
}

/** Шапка колонки даты: дата, суммы ножевой и ротационной (подсказка на цифрах). */
function renderGofroPlanDateHeaderTh(string $planDate): void
{
    $d = DateTime::createFromFormat('Y-m-d', $planDate);
    $isWeekend = $d ? in_array((int)$d->format('N'), [6, 7], true) : false;
    $dateColClass = $isWeekend ? 'date-col weekend' : 'date-col';
    $label = $d ? $d->format('d.m') : $planDate;
    ?>
    <th class="<?= htmlspecialchars($dateColClass, ENT_QUOTES, 'UTF-8') ?>" data-date-total="<?= htmlspecialchars($planDate, ENT_QUOTES, 'UTF-8') ?>" title="Суммарно г/п на дату">
        <span class="date-head-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="date-total-lines">
            <span class="date-total-line date-total-line--knife"><span class="date-total-knife" title="Ножевая">0</span></span>
            <span class="date-total-line date-total-line--rotary"><span class="date-total-rotary" title="Ротационная">0</span></span>
        </span>
    </th>
    <?php
}

function renderGofroPlanFixedHeaderCells(): void
{
    ?>
    <th class="filter-col col-compact">Фильтр</th>
    <th class="col-compact">Аналог</th>
    <th class="order-col col-compact">Заявка</th>
    <th class="num-metric-col" title="Остаток фильтров">Остаток<br>фильтров</th>
    <th class="num-metric-col" title="Г/п изготовлено">Г/п<br>изг.</th>
    <th class="num-metric-col" title="Г/п доступно">Г/п<br>дост.</th>
    <th class="num-metric-col" title="Потребность в г/п">Потребн.<br>г/п</th>
    <th class="debt-col">Долг</th>
    <?php
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

/**
 * Записать агрегированное количество г/п на дату (одна логическая ячейка).
 */
function applyGofroPlanSetCell(
    PDO $pdo,
    string $rowKey,
    string $order,
    string $filterName,
    string $packageKey,
    string $packageName,
    string $planDate,
    int $qty,
    ?int $userId
): void {
    $delStmt = $pdo->prepare('DELETE FROM corrugation_plan_v2 WHERE source_row_key = ? AND plan_date = ?');
    $delStmt->execute([$rowKey, $planDate]);
    if ($qty <= 0) {
        return;
    }
    $groupId = 'G-' . substr(hash('sha256', $rowKey . '|' . $planDate), 0, 16);
    $stripId = 'GENSYNC-' . substr(hash('sha256', $rowKey . '|' . $planDate . '|qty'), 0, 20);
    $insStmt = $pdo->prepare('
        INSERT INTO corrugation_plan_v2
        (source_row_key, order_number, filter_name, package_key, package_name, plan_date, group_id, strip_id, qty, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $insStmt->execute([
        mb_substr($rowKey, 0, 255, 'UTF-8'),
        mb_substr($order !== '' ? $order : '-', 0, 64, 'UTF-8'),
        mb_substr($filterName !== '' ? $filterName : '-', 0, 255, 'UTF-8'),
        mb_substr($packageKey !== '' ? $packageKey : '-', 0, 255, 'UTF-8'),
        mb_substr($packageName !== '' ? $packageName : '-', 0, 255, 'UTF-8'),
        $planDate,
        mb_substr($groupId, 0, 128, 'UTF-8'),
        mb_substr($stripId, 0, 128, 'UTF-8'),
        $qty,
        $userId,
    ]);
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
    if ($action === 'apply_moves') {
        header('Content-Type: application/json; charset=utf-8');
        $isDate = static function (string $value): bool {
            return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
        };
        try {
            $rawMoves = $payload['moves'] ?? [];
            if (!is_array($rawMoves) || empty($rawMoves)) {
                throw new RuntimeException('Нет операций для применения.');
            }
            ensureCorrugationPlanV2Table($pdo);
            $userId = isset($session['user_id']) ? (int)$session['user_id'] : null;
            $pdo->beginTransaction();
            foreach ($rawMoves as $idx => $move) {
                if (!is_array($move)) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': некорректный формат.');
                }
                $rowKey = trim((string)($move['source_row_key'] ?? ''));
                $order = trim((string)($move['order_number'] ?? ''));
                $filterName = trim((string)($move['filter_name'] ?? ''));
                $packageKey = trim((string)($move['package_key'] ?? ''));
                $packageName = trim((string)($move['package_name'] ?? ''));
                $planDate = trim((string)($move['plan_date'] ?? $move['to_date'] ?? $move['from_date'] ?? ''));
                $mode = trim((string)($move['mode'] ?? 'set_qty'));
                $qty = max(0, (int)($move['qty'] ?? $move['moved_qty'] ?? 0));
                if ($rowKey === '' || !$isDate($planDate)) {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': некорректные параметры.');
                }
                if ($mode === 'debt') {
                    $fromDate = trim((string)($move['from_date'] ?? ''));
                    $toDate = trim((string)($move['to_date'] ?? ''));
                    if (!$isDate($fromDate) || !$isDate($toDate)) {
                        throw new RuntimeException('Операция #' . ($idx + 1) . ': некорректные даты долга.');
                    }
                    if ($toDate < $todayIso) {
                        throw new RuntimeException('Операция #' . ($idx + 1) . ': перенос долга только на сегодня или будущие даты.');
                    }
                    if ($qty <= 0) {
                        throw new RuntimeException('Операция #' . ($idx + 1) . ': не указано количество для переноса из долга.');
                    }
                    if ($fromDate < $todayIso) {
                        applyGofroPlanSetCell($pdo, $rowKey, $order, $filterName, $packageKey, $packageName, $fromDate, 0, $userId);
                    }
                    applyGofroPlanSetCell($pdo, $rowKey, $order, $filterName, $packageKey, $packageName, $toDate, $qty, $userId);
                } elseif ($mode === 'clear_cell') {
                    if ($planDate < $todayIso) {
                        throw new RuntimeException('Операция #' . ($idx + 1) . ': изменение плана в прошлых датах недоступно.');
                    }
                    applyGofroPlanSetCell($pdo, $rowKey, $order, $filterName, $packageKey, $packageName, $planDate, 0, $userId);
                } elseif ($mode === 'set_qty') {
                    if ($planDate < $todayIso) {
                        throw new RuntimeException('Операция #' . ($idx + 1) . ': изменение плана в прошлых датах недоступно.');
                    }
                    applyGofroPlanSetCell($pdo, $rowKey, $order, $filterName, $packageKey, $packageName, $planDate, $qty, $userId);
                } else {
                    throw new RuntimeException('Операция #' . ($idx + 1) . ': неизвестный режим.');
                }
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'applied' => count($rawMoves)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
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

$maxPct = max(0, min(100, (int)$gofroMaxCompletionPctDefault));
if (isset($_GET['max_pct']) && $_GET['max_pct'] !== '') {
    $fromUrl = (int)$_GET['max_pct'];
    if ($fromUrl >= 0 && $fromUrl <= 100) {
        $maxPct = $fromUrl;
    }
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
          AND COALESCE(prod.produced, 0) * 100 <= {$maxPct} * agg.ordered
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
    try {
        ensureCorrugationPlanV2Table($pdo);
        $stmtV2Dates = $pdo->query("SELECT DISTINCT plan_date FROM corrugation_plan_v2 WHERE qty > 0 AND plan_date >= " . $pdo->quote($todayIso));
        if ($stmtV2Dates) {
            while ($rowV2 = $stmtV2Dates->fetch(PDO::FETCH_ASSOC)) {
                $pd = trim((string)($rowV2['plan_date'] ?? ''));
                if ($pd !== '' && $pd >= $todayIso) {
                    $buildPlanDates[$pd] = true;
                }
            }
        }
    } catch (Throwable $eV2) {
        /* игнорируем — до первого сохранения таблицы может не быть */
    }
    $buildPlanDates = filterPlanDatesFromToday(array_keys($buildPlanDates), $todayIso);
} catch (Throwable $e) {
    $buildPlanMap = [];
    $buildPlanDates = [];
}

/** Сохранённый план гофропакетов: [rowKey][date] => qty */
$gofroPlanMap = [];
try {
    ensureCorrugationPlanV2Table($pdo);
    $stmtGofroPlan = $pdo->query("
        SELECT source_row_key, plan_date, SUM(qty) AS qty
        FROM corrugation_plan_v2
        WHERE qty > 0
        GROUP BY source_row_key, plan_date
    ");
    $dateSet = [];
    foreach ($buildPlanDates as $d) {
        $dateSet[(string)$d] = true;
    }
    foreach ($stmtGofroPlan->fetchAll(PDO::FETCH_ASSOC) as $gp) {
        $rk = trim((string)($gp['source_row_key'] ?? ''));
        $pd = trim((string)($gp['plan_date'] ?? ''));
        $q = (int)($gp['qty'] ?? 0);
        if ($rk === '' || $pd === '' || $q <= 0) {
            continue;
        }
        if (!isset($gofroPlanMap[$rk])) {
            $gofroPlanMap[$rk] = [];
        }
        $gofroPlanMap[$rk][$pd] = ($gofroPlanMap[$rk][$pd] ?? 0) + $q;
        if ($pd >= $todayIso) {
            $dateSet[$pd] = true;
        }
    }
    $buildPlanDates = filterPlanDatesFromToday(array_merge($buildPlanDates, array_keys($dateSet)), $todayIso);
} catch (Throwable $e) {
    $gofroPlanMap = [];
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
                    rfs.analog AS analog,
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
                        'analog' => null,
                        'filter_package' => null,
                        'paper_width_mm' => null,
                        'fold_height' => null,
                        'fold_count' => null,
                    ];
                }
                $metaAnalog = trim((string)($metaRow['analog'] ?? ''));
                if ($metaAnalog !== '' && $filterMetaByKey[$metaKey]['analog'] === null) {
                    $filterMetaByKey[$metaKey]['analog'] = $metaAnalog;
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
            /*
             * Позиция из заказа может не совпасть с полем filter в round_filter_structure (другой текст / analog).
             * Дополнительный поиск по совпадению названия или колонке analog.
             */
            foreach ($rawFilterList as $rawFilterTry) {
                $targetKey = normalizeFilterKeyLocal((string)$rawFilterTry);
                if ($targetKey === '') {
                    continue;
                }
                $existingPkg = trim((string)($filterMetaByKey[$targetKey]['filter_package'] ?? ''));
                if ($existingPkg !== '') {
                    continue;
                }
                $stmtAlt = $pdo->prepare("
                    SELECT
                        rfs.filter AS filter_name,
                        rfs.analog AS analog,
                        rfs.filter_package AS filter_package,
                        ppr.p_p_paper_width AS paper_width_mm,
                        ppr.p_p_fold_height AS fold_height,
                        ppr.p_p_fold_count AS fold_count
                    FROM round_filter_structure rfs
                    LEFT JOIN paper_package_round ppr
                        ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                    WHERE UPPER(TRIM(rfs.filter)) = UPPER(TRIM(?))
                       OR UPPER(TRIM(rfs.analog)) = UPPER(TRIM(?))
                    LIMIT 8
                ");
                $stmtAlt->execute([$rawFilterTry, $rawFilterTry]);
                foreach ($stmtAlt->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                    $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                    if ($pkg === '') {
                        continue;
                    }
                    if (!isset($filterMetaByKey[$targetKey])) {
                        $filterMetaByKey[$targetKey] = [
                            'analog' => null,
                            'filter_package' => null,
                            'paper_width_mm' => null,
                            'fold_height' => null,
                            'fold_count' => null,
                        ];
                    }
                    $metaAnalog = trim((string)($metaRow['analog'] ?? ''));
                    if ($metaAnalog !== '' && $filterMetaByKey[$targetKey]['analog'] === null) {
                        $filterMetaByKey[$targetKey]['analog'] = $metaAnalog;
                    }
                    $filterMetaByKey[$targetKey]['filter_package'] = $pkg;
                    if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$targetKey]['paper_width_mm'] === null) {
                        $filterMetaByKey[$targetKey]['paper_width_mm'] = (float)$metaRow['paper_width_mm'];
                    }
                    if ($metaRow['fold_height'] !== null && $filterMetaByKey[$targetKey]['fold_height'] === null) {
                        $filterMetaByKey[$targetKey]['fold_height'] = (float)$metaRow['fold_height'];
                    }
                    if ($metaRow['fold_count'] !== null && $filterMetaByKey[$targetKey]['fold_count'] === null) {
                        $filterMetaByKey[$targetKey]['fold_count'] = (float)$metaRow['fold_count'];
                    }
                    break;
                }
            }
            /*
             * Расширенный поиск по подстроке (разный пробел, суффиксы, опечатки в заказе vs справочнике).
             */
            foreach ($rawFilterList as $rawFilterTry) {
                $targetKey = normalizeFilterKeyLocal((string)$rawFilterTry);
                if ($targetKey === '') {
                    continue;
                }
                $existingPkg = trim((string)($filterMetaByKey[$targetKey]['filter_package'] ?? ''));
                if ($existingPkg !== '') {
                    continue;
                }
                $needle = trim((string)$rawFilterTry);
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                    continue;
                }
                $stmtLike = $pdo->prepare("
                    SELECT
                        rfs.filter AS filter_name,
                        rfs.analog AS analog,
                        rfs.filter_package AS filter_package,
                        ppr.p_p_paper_width AS paper_width_mm,
                        ppr.p_p_fold_height AS fold_height,
                        ppr.p_p_fold_count AS fold_count
                    FROM round_filter_structure rfs
                    LEFT JOIN paper_package_round ppr
                        ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                    WHERE UPPER(TRIM(rfs.filter)) LIKE CONCAT('%', UPPER(TRIM(?)), '%')
                       OR UPPER(TRIM(rfs.analog)) LIKE CONCAT('%', UPPER(TRIM(?)), '%')
                    LIMIT 12
                ");
                $stmtLike->execute([$needle, $needle]);
                foreach ($stmtLike->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                    $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                    if ($pkg === '') {
                        continue;
                    }
                    if (!isset($filterMetaByKey[$targetKey])) {
                        $filterMetaByKey[$targetKey] = [
                            'analog' => null,
                            'filter_package' => null,
                            'paper_width_mm' => null,
                            'fold_height' => null,
                            'fold_count' => null,
                        ];
                    }
                    $metaAnalog = trim((string)($metaRow['analog'] ?? ''));
                    if ($metaAnalog !== '' && $filterMetaByKey[$targetKey]['analog'] === null) {
                        $filterMetaByKey[$targetKey]['analog'] = $metaAnalog;
                    }
                    $filterMetaByKey[$targetKey]['filter_package'] = $pkg;
                    if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$targetKey]['paper_width_mm'] === null) {
                        $filterMetaByKey[$targetKey]['paper_width_mm'] = (float)$metaRow['paper_width_mm'];
                    }
                    if ($metaRow['fold_height'] !== null && $filterMetaByKey[$targetKey]['fold_height'] === null) {
                        $filterMetaByKey[$targetKey]['fold_height'] = (float)$metaRow['fold_height'];
                    }
                    if ($metaRow['fold_count'] !== null && $filterMetaByKey[$targetKey]['fold_count'] === null) {
                        $filterMetaByKey[$targetKey]['fold_count'] = (float)$metaRow['fold_count'];
                    }
                    break;
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

/** Долг по г/п: [rowKey] => [['date' => Y-m-d, 'qty' => int], ...] */
$gofroDebtShiftMap = [];
if (!empty($rows)) {
    foreach ($rows as $rowDebt) {
        $rawOrderDebt = (string)($rowDebt['order_number'] ?? '');
        $rawFilterDebt = (string)($rowDebt['filter_name'] ?? '');
        if ($rawOrderDebt === '' || $rawFilterDebt === '') {
            continue;
        }
        $orderedDebt = (int)($rowDebt['ordered'] ?? 0);
        $producedDebt = (int)($rowDebt['produced'] ?? 0);
        $remainingDebt = max(0, $orderedDebt - $producedDebt);
        $rowKeyDebt = $rawOrderDebt . '|' . normalizeTextKeyLocal($rawFilterDebt);
        $metaDebt = $filterMetaByKey[normalizeFilterKeyLocal($rawFilterDebt)] ?? null;
        $packageDebt = trim((string)($metaDebt['filter_package'] ?? ''));
        $packageKeyDebt = normalizeTextKeyLocal($packageDebt);
        $gofroProducedDebt = $packageKeyDebt !== ''
            ? (int)($gofroProducedByOrderPackage[$rawOrderDebt][$packageKeyDebt] ?? 0)
            : 0;
        $gofroAvailableDebt = $gofroProducedDebt - $producedDebt;
        $gofroNeedDebt = max(0, $remainingDebt - $gofroAvailableDebt);

        // Просрочка: г/п, запланированные на прошлую дату и ещё не закрытые переносом.
        if ($gofroNeedDebt <= 0) {
            continue;
        }
        $qtyByDate = $gofroPlanMap[$rowKeyDebt] ?? [];
        $shifts = [];
        $plannedFromToday = 0;
        foreach ($qtyByDate as $dateKey => $qtyVal) {
            $qtyVal = (int)$qtyVal;
            if ($qtyVal <= 0) {
                continue;
            }
            if ($dateKey < $todayIso) {
                $shifts[] = ['date' => (string)$dateKey, 'qty' => $qtyVal];
            } else {
                $plannedFromToday += $qtyVal;
            }
        }
        if ($shifts !== []) {
            usort($shifts, static function ($a, $b) {
                return strcmp((string)$a['date'], (string)$b['date']);
            });
            // Уже изготовленные г/п учтены в gofroNeed; в долге — только непокрытая потребность.
            $uncoveredNeed = max(0, $gofroNeedDebt - $plannedFromToday);
            $shifts = capGofroDebtShiftsToUncoveredNeed($shifts, $uncoveredNeed);
        }
        if (!empty($shifts)) {
            $gofroDebtShiftMap[$rowKeyDebt] = $shifts;
        }
    }
}

/** План сборки фильтров по строке таблицы: [rowKey][date] => qty */
$buildPlanMapByRowKey = [];
if (!empty($rows)) {
    foreach ($rows as $rowBp) {
        $orderBp = (string)($rowBp['order_number'] ?? '');
        $filterBp = (string)($rowBp['filter_name'] ?? '');
        if ($orderBp === '' || $filterBp === '') {
            continue;
        }
        $rowKeyBp = $orderBp . '|' . normalizeTextKeyLocal($filterBp);
        $planKeyBp = $orderBp . '|' . normalizeFilterKeyLocal($filterBp);
        $buildPlanMapByRowKey[$rowKeyBp] = $buildPlanMap[$planKeyBp] ?? [];
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
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .panel.panel--gofro-table {
            width: fit-content;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: visible;
        }
        table.gofro-plan-table {
            width: max-content;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
            table-layout: auto;
        }
        table.gofro-plan-table th,
        table.gofro-plan-table td {
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 2px 3px;
        }
        table.gofro-plan-table th.col-compact,
        table.gofro-plan-table td.col-compact {
            width: 1%;
            white-space: nowrap;
        }
        table.gofro-plan-table th.filter-col,
        table.gofro-plan-table td.filter-name-cell {
            width: 1%;
            max-width: 168px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.gofro-plan-table th.order-col,
        table.gofro-plan-table td.order-col {
            width: 1%;
            white-space: nowrap;
        }
        table.gofro-plan-table td.order-cell form {
            display: inline;
            margin: 0;
        }
        table.gofro-plan-table td.order-cell button {
            appearance: none;
            border: 0;
            background: none;
            color: var(--accent);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
            font-variant-numeric: tabular-nums;
        }
        table.gofro-plan-table td.order-cell button:hover {
            color: #1e47c5;
        }
        table.gofro-plan-table th.num-metric-col,
        table.gofro-plan-table td.num-metric-col {
            width: 1%;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        table.gofro-plan-table th.num-metric-col {
            white-space: normal;
            line-height: 1.15;
            text-align: right;
            vertical-align: bottom;
            font-size: 10px;
            padding: 3px 4px;
        }
        table.gofro-plan-table td.num-metric-col {
            text-align: right;
        }
        table.gofro-plan-table td.num-metric-col.num-metric-col--left {
            text-align: left;
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
        table.gofro-plan-table th.date-col,
        table.gofro-plan-table td.date-cell {
            width: 1%;
            min-width: 40px;
            padding: 2px 3px;
            box-sizing: border-box;
            text-align: center;
            white-space: nowrap;
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
        thead th.date-col.date-hover,
        tfoot th.date-col.date-hover {
            z-index: 7;
            background: #e8ecfd;
            outline: 2px solid rgba(36, 87, 230, 0.5);
            outline-offset: -2px;
        }
        thead th.date-col.drag-target-hover {
            z-index: 8;
            background: #dbeafe;
            outline: 2px solid rgba(37, 99, 235, 0.9);
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
        .supply-action-menu {
            position: fixed;
            z-index: 12000;
            width: max-content;
            min-width: 0;
            max-width: 320px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.2);
            padding: 6px;
            display: none;
        }
        .supply-action-menu.open {
            display: block;
        }
        .supply-action-btn {
            width: auto;
            min-width: 100%;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #0f172a;
            border-radius: 6px;
            padding: 6px 8px;
            text-align: left;
            font-size: 12px;
            cursor: pointer;
            margin: 0;
            white-space: nowrap;
        }
        .supply-action-btn + .supply-action-btn {
            margin-top: 6px;
        }
        .supply-action-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        .supply-action-btn--danger {
            color: #b91c1c;
        }
        .supply-action-caption {
            font-size: 11px;
            color: #475569;
            margin: 0 0 6px;
            padding: 0 2px;
        }
        .coverage-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 14px;
            margin: 0 0 10px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
            font-size: 12px;
        }
        .coverage-legend[hidden] { display: none; }
        .coverage-legend__title {
            font-weight: 600;
            color: var(--ink);
        }
        .coverage-legend__item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .coverage-legend__swatch {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            flex-shrink: 0;
        }
        .coverage-legend__swatch--stock { background: #dcfce7; }
        .coverage-legend__swatch--plan { background: #dbeafe; }
        .coverage-legend__swatch--gap { background: #fee2e2; }
        .coverage-legend__hint {
            color: var(--muted);
            font-size: 11px;
        }
        .fold-height-panel {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 10px;
            margin: 0 0 10px;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #f8fafc;
            font-size: 12px;
        }
        .fold-height-panel[hidden] {
            display: none;
        }
        .fold-height-panel__title {
            font-weight: 600;
            color: var(--ink);
            margin-right: 4px;
        }
        .fold-height-panel__buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .fold-height-chip {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }
        .fold-height-chip:hover {
            border-color: #6366f1;
            background: #eef2ff;
        }
        .fold-height-chip.active {
            border-color: #d97706;
            background: #f59e0b;
            color: #fff;
            box-shadow: 0 1px 2px rgba(217, 119, 6, 0.35);
        }
        .fold-height-chip--none {
            font-weight: 500;
            color: #64748b;
        }
        .fold-height-chip--none.active {
            color: #fff;
        }
        .fold-height-panel__hint {
            color: var(--muted);
            font-size: 11px;
            flex: 1 1 100%;
            margin: 0;
        }
        /*
         * Фильтр по высоте: подсветка всей строки, кроме ячеек с заливкой
         * (покрытие г/п, очередь изменений).
         */
        table.gofro-plan-table.fold-height-filter-active tbody tr[data-row-key]:not(.fold-height-match) td:not(.gofro-coverage-cell):not(.gofro-coverage-cell-partial):not(.gofro-coverage-planned-cell):not(.gofro-coverage-planned-cell-partial):not(.gofro-coverage-gap):not(.gofro-coverage-gap-partial):not(.queue-pending) {
            opacity: 0.42;
        }
        table.gofro-plan-table.fold-height-filter-active tbody tr[data-row-key].fold-height-match td:not(.gofro-coverage-cell):not(.gofro-coverage-cell-partial):not(.gofro-coverage-planned-cell):not(.gofro-coverage-planned-cell-partial):not(.gofro-coverage-gap):not(.gofro-coverage-gap-partial):not(.queue-pending) {
            background-color: #fffbeb;
        }
        table.gofro-plan-table.fold-height-filter-active tbody tr[data-row-key].fold-height-match:hover td:not(.gofro-coverage-cell):not(.gofro-coverage-cell-partial):not(.gofro-coverage-planned-cell):not(.gofro-coverage-planned-cell-partial):not(.gofro-coverage-gap):not(.gofro-coverage-gap-partial):not(.queue-pending) {
            background-color: #fef3c7;
        }
        table.gofro-plan-table.fold-height-filter-active tbody tr[data-row-key].fold-height-match td.filter-name-cell:not(.gofro-coverage-cell):not(.gofro-coverage-cell-partial):not(.gofro-coverage-planned-cell):not(.gofro-coverage-planned-cell-partial):not(.gofro-coverage-gap):not(.gofro-coverage-gap-partial):not(.queue-pending) {
            box-shadow: inset 4px 0 0 #f59e0b;
        }
        td.date-cell.gofro-coverage-cell {
            background: #dcfce7;
            box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.35);
        }
        td.date-cell.gofro-coverage-cell-partial {
            background: linear-gradient(to top, #dcfce7 0%, #dcfce7 55%, #fff 55%, #fff 100%);
            box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.35);
        }
        td.date-cell.gofro-coverage-planned-cell {
            background: #dbeafe;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.35);
        }
        td.date-cell.gofro-coverage-planned-cell-partial {
            background: linear-gradient(to top, #dbeafe 0%, #dbeafe 55%, #fff 55%, #fff 100%);
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.35);
        }
        td.date-cell.gofro-coverage-cell-partial.gofro-coverage-planned-cell-partial {
            background: linear-gradient(to top, #dbeafe 0%, #dbeafe 40%, #dcfce7 40%, #dcfce7 100%);
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.35);
        }
        td.date-cell.gofro-coverage-gap {
            background: #fee2e2;
            box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.35);
        }
        td.date-cell.gofro-coverage-gap-partial {
            background: linear-gradient(to top, #fee2e2 0%, #fee2e2 45%, #fff 45%, #fff 100%);
            box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.35);
        }
        .muted {
            color: var(--muted);
        }
        .muted-light {
            color: #d1d5db;
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
        .machine-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 14px;
            height: 14px;
            margin-right: 6px;
            border-radius: 999px;
            border: 1px solid #60a5fa;
            color: #3b82f6;
            font-size: 9px;
            font-weight: 700;
            line-height: 1;
            vertical-align: middle;
            background: transparent;
            cursor: help;
            user-select: none;
        }
        .date-total-lines {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 1px;
            margin-top: 2px;
            font-size: 9px;
            line-height: 1.15;
            color: #64748b;
            white-space: nowrap;
        }
        tr.has-overdue-debt td.filter-name-cell {
            background: #fff7ed;
            box-shadow: inset 3px 0 0 #ea580c;
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
            opacity: 0.75;
            pointer-events: none;
        }
        .debt-list {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: center;
            gap: 1px;
            height: 20px;
            max-height: 20px;
            overflow: hidden;
            white-space: nowrap;
        }
        .debt-shift {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            min-height: 16px;
            max-height: 18px;
            padding: 0 2px;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            background: #f9fafb;
            color: #374151;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.15;
            cursor: grab;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .debt-shift.drag-source-single {
            outline: 2px solid #93c5fd;
            outline-offset: -2px;
            background: #eff6ff;
        }
        .debt-more {
            appearance: none;
            flex-shrink: 0;
            border: 1px dashed #94a3b8;
            border-radius: 3px;
            background: #f1f5f9;
            color: #475569;
            font-size: 9px;
            font-weight: 700;
            line-height: 1.15;
            padding: 0 2px;
            min-height: 16px;
            max-height: 18px;
            cursor: pointer;
            white-space: nowrap;
        }
        .debt-more:hover { background: #e2e8f0; }
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
            min-height: 20px;
            justify-content: center;
            cursor: grab;
        }
        td.date-cell.drag-drop-target {
            outline: 2px dashed #60a5fa;
            outline-offset: -2px;
            background: #e0f2fe !important;
        }
        table.gofro-plan-table td.analog-cell {
            max-width: 96px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 10px;
            flex-wrap: wrap;
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
        }
        .toolbar-btn.secondary { font-weight: 500; }
        .toolbar-btn:hover { border-color: #c7d2fe; background: #f8faff; }
        a.toolbar-btn {
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            box-sizing: border-box;
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
        .pending-text { font-size: 12px; color: #1e3a8a; margin-right: auto; }
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
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
        }
        .queue-panel__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }
        .queue-panel__head-actions { display: flex; gap: 8px; }
        .queue-panel__body { overflow: auto; padding: 8px 12px 12px; }
        .queue-panel__empty { margin: 0; font-size: 12px; color: var(--muted); }
        .queue-list { list-style: none; margin: 0; padding: 0; }
        .queue-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            margin-bottom: 6px;
        }
        table.has-frozen-cols th,
        table.has-frozen-cols td { background-clip: padding-box; }
        .frozen-col {
            position: sticky !important;
            left: var(--sticky-left, 0px);
            z-index: 20;
            background: #fff;
        }
        thead .frozen-col, tfoot .frozen-col { background: #f9fafb; z-index: 40; }
        tbody tr:hover .frozen-col { background: #fafbfc; }
        .frozen-col.frozen-col--last { box-shadow: 1px 0 0 0 var(--border); }
        table.gofro-plan-table td.date-cell {
            position: relative;
            font-variant-numeric: tabular-nums;
            cursor: pointer;
            min-height: 30px;
            vertical-align: middle;
        }
        table.gofro-plan-table td.date-cell:not(:empty) {
            padding-top: 4px;
            padding-bottom: 4px;
        }
        table.gofro-plan-table th.date-col {
            font-size: 11px;
            line-height: 1.15;
            white-space: normal;
            vertical-align: bottom;
        }
        table.gofro-plan-table tfoot th {
            position: sticky;
            bottom: 0;
            z-index: 5;
            background: #f9fafb;
            box-shadow: 0 -1px 0 0 var(--border);
        }
        table.gofro-plan-table tfoot .frozen-col {
            background: #f9fafb;
            z-index: 25;
        }
        .date-head-label {
            display: block;
            font-weight: 600;
            white-space: nowrap;
        }
        .date-total-lines {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1px;
            margin-top: 2px;
            font-size: 9px;
            line-height: 1.15;
            font-weight: 500;
            white-space: nowrap;
        }
        .date-total-line {
            display: block;
        }
        .date-total-line--knife {
            color: #15803d;
        }
        .date-total-line--rotary {
            color: #1d4ed8;
        }
        .date-total-knife,
        .date-total-rotary {
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            cursor: help;
        }
        td.date-cell.queue-pending {
            outline: 2px solid #f59e0b;
            outline-offset: -2px;
            background: #fffbeb !important;
        }
        .cell-plan-hint,
        .cell-gofro-qty {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            white-space: nowrap;
        }
        .cell-plan-hint {
            position: absolute;
            left: 2px;
            top: 2px;
            z-index: 1;
            min-width: 15px;
            height: 15px;
            padding: 0 3px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 700;
            color: #475569;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
            pointer-events: none;
        }
        .cell-gofro-qty {
            min-width: 22px;
            height: 22px;
            padding: 0 5px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            background: #fff;
            border: 1.5px solid #64748b;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.1);
        }
        td.date-cell.gofro-coverage-cell .cell-gofro-qty,
        td.date-cell.gofro-coverage-cell-partial .cell-gofro-qty {
            border-color: #16a34a;
            background: #f0fdf4;
        }
        td.date-cell.gofro-coverage-planned-cell .cell-gofro-qty,
        td.date-cell.gofro-coverage-planned-cell-partial .cell-gofro-qty {
            border-color: #2563eb;
            background: #eff6ff;
        }
        td.date-cell.gofro-coverage-gap .cell-gofro-qty,
        td.date-cell.gofro-coverage-gap-partial .cell-gofro-qty {
            border-color: #dc2626;
            background: #fef2f2;
        }
        td.date-cell.queue-pending .cell-gofro-qty {
            border-color: #d97706;
            box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.35);
        }
        .gofro-plan-table--hide-filter-plan-digits .cell-plan-hint {
            display: none !important;
        }
        .gofro-cell-picker {
            position: fixed;
            z-index: 12000;
            min-width: 200px;
            max-width: 280px;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
            padding: 8px;
        }
        .gofro-cell-picker[hidden] { display: none; }
        .ind-modal[hidden] { display: none; }
        .ind-modal {
            position: fixed;
            inset: 0;
            z-index: 10002;
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
        .ind-field {
            display: grid;
            gap: 6px;
        }
        .ind-field label {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
        }
        .ind-field input {
            width: 100%;
            max-width: 120px;
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
            flex-wrap: wrap;
        }
        .ind-modal__dialog--orders {
            width: min(520px, 100%);
            max-height: min(85vh, 720px);
            display: flex;
            flex-direction: column;
        }
        .ind-modal__body--orders {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 0;
        }
        .ind-modal__foot--orders {
            justify-content: flex-start;
            flex-wrap: wrap;
        }
        .ind-modal__foot-spacer {
            flex: 1 1 auto;
            min-width: 8px;
        }
        .orders-badge {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: #fee2e2;
            color: #991b1b;
            vertical-align: middle;
        }
        .hidden-orders-search {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 6px 8px;
            font: inherit;
        }
        .hidden-orders-list {
            max-height: min(48vh, 420px);
            overflow: auto;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #fafafa;
        }
        .hidden-orders-row {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            cursor: pointer;
            color: #1f2937;
        }
        .hidden-orders-row.hidden-orders-row--filtered {
            display: none !important;
        }
        .hidden-orders-row input {
            flex: 0 0 auto;
        }
        .gofro-cell-picker__title {
            font-size: 12px;
            font-weight: 600;
            margin: 0 0 6px;
            color: #334155;
        }
        .gofro-cell-picker__warn {
            font-size: 11px;
            color: #b45309;
            margin: 0 0 6px;
        }
        .gofro-cell-picker__grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }
        .gofro-pick-btn {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 8px;
            padding: 6px 4px;
            font-size: 11px;
            cursor: pointer;
            text-align: center;
            line-height: 1.25;
        }
        .gofro-pick-btn:hover { border-color: #93c5fd; background: #eff6ff; }
        .gofro-pick-btn strong { display: block; font-size: 13px; }
        .gofro-cell-picker__actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .gofro-cell-picker__actions button {
            flex: 1;
            min-width: 70px;
            border: 1px solid #e2e8f0;
            background: #fff;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 11px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1 style="margin:0 0 8px; font-size:20px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted" style="margin:0 0 10px;">
        Позиции с выполнением не выше <?= (int)$maxPct ?>%. Клик по ячейке даты — выбор г/п полурулонами (×1, ×2…).
        Колонка «Долг»: перетащите чип на дату. Мелкая цифра в ячейке — план сборки фильтров на день; крупная — план г/п.
    </p>
    <div class="toolbar">
        <button type="button" id="add-plan-day-btn" class="toolbar-btn secondary" title="Добавить колонку следующего дня в конец таблицы (только в интерфейсе)">+ день</button>
        <button type="button" id="toggle-gofro-coverage-btn" class="toolbar-btn secondary" aria-pressed="true">Покрытие г/п: вкл</button>
        <button type="button" id="toggle-filter-plan-digits-btn" class="toolbar-btn secondary" aria-pressed="true" title="Мелкие цифры в ячейке даты — план сборки фильтров на день">Фильтры: вкл</button>
        <button type="button" id="task-sheet-btn" class="toolbar-btn secondary">Задание</button>
        <a class="toolbar-btn secondary" href="plan_roll_cutting.php">Порезка бухт</a>
        <a class="toolbar-btn secondary" href="wireframe_build_plan.php?max_pct=<?= (int)$maxPct ?>">Каркасы</a>
        <button type="button" id="open-max-pct-btn" class="toolbar-btn secondary" title="Порог % выполнения позиции для отображения в таблице">Выполнение ≤ <?= (int)$maxPct ?>%</button>
        <button type="button" id="open-hidden-orders-btn" class="toolbar-btn secondary">Заявки <span id="hidden-orders-badge" class="orders-badge" hidden></span></button>
        <button type="button" id="toggle-fold-height-panel-btn" class="toolbar-btn secondary" aria-pressed="false">Высота бумаги</button>
        <button type="button" id="overdue-alert-btn" class="toolbar-btn secondary" style="display:none; color:#b91c1c; border-color:#fecaca;">Просрочено</button>
        <span id="apply-status" class="muted" style="font-size:12px; margin-left:4px;"></span>
    </div>
    <div id="gofro-coverage-legend" class="coverage-legend">
        <span class="coverage-legend__title">Покрытие плана сборки фильтров:</span>
        <span class="coverage-legend__item"><span class="coverage-legend__swatch coverage-legend__swatch--stock"></span>запас г/п</span>
        <span class="coverage-legend__item"><span class="coverage-legend__swatch coverage-legend__swatch--plan"></span>план г/п</span>
        <span class="coverage-legend__item"><span class="coverage-legend__swatch coverage-legend__swatch--gap"></span>не хватает</span>
        <span class="coverage-legend__hint">По мелкой цифре в ячейке, слева направо</span>
    </div>
    <div id="fold-height-panel" class="fold-height-panel" hidden>
        <span class="fold-height-panel__title">Высота ребра, мм</span>
        <div id="fold-height-buttons" class="fold-height-panel__buttons" role="group" aria-label="Фильтр по высоте ребра"></div>
        <button type="button" id="fold-height-clear-btn" class="toolbar-btn secondary" style="display:none;">Сбросить</button>
        <p id="fold-height-panel-hint" class="fold-height-panel__hint">Нажмите высоту — подсветятся строки с таким ребром (из справочника paper_package_round). Повторный клик снимает фильтр.</p>
    </div>
    <div id="pendingMovesBar" class="pending-bar">
        <span id="pendingMovesText" class="pending-text">Изменений в очереди: 0</span>
        <button type="button" id="toggleQueuePanelBtn" class="toolbar-btn secondary" aria-pressed="false">Очередь</button>
        <button type="button" id="undoPendingMoveBtn" class="toolbar-btn secondary">Отменить последнее</button>
        <button type="button" id="clearPendingMovesBtn" class="toolbar-btn secondary">Сбросить все</button>
        <button type="button" id="applyPendingMovesBtn" class="toolbar-btn">Применить</button>
    </div>
    <div id="moveQueuePanel" class="queue-panel" hidden>
        <div class="queue-panel__head">
            <span>Очередь изменений</span>
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
    <?php if ($loadError !== ''): ?>
        <div class="panel" style="padding:12px;">Ошибка загрузки: <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="panel panel--gofro-table">
            <table class="gofro-plan-table">
                <thead>
                <tr>
                    <?php renderGofroPlanFixedHeaderCells(); ?>
                    <?php foreach ($buildPlanDates as $planDate) {
                        renderGofroPlanDateHeaderTh((string)$planDate);
                    } ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= 8 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:12px;">
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
                        $gofroQtyByDate = $gofroPlanMap[$rowKey] ?? [];

                        $meta = $filterMetaByKey[normalizeFilterKeyLocal($rawFilter)] ?? null;
                        $rowAnalog = trim((string)($meta['analog'] ?? ''));
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
                        $ribHeightRounded = (int)round($foldHeight);
                        $isRotaryMachine = in_array($ribHeightRounded, [23, 29, 36, 40, 45, 55], true);
                        $machineType = $isRotaryMachine ? 'rotary' : 'knife';
                        $packLengthM = ($foldHeight > 0 && $foldCount > 0)
                            ? (($foldHeight * 2 + 1) * $foldCount) / 1000
                            : 0.0;
                        $packagesPerRoll = $packLengthM > 0 ? (int)floor(600 / $packLengthM) : 0;
                        $packagesPerRollFallback = false;
                        if ($packageKey !== '' && $gofroNeed > 0 && $packagesPerRoll <= 0) {
                            $packagesPerRoll = max(1, min($gofroNeed, 500));
                            $packagesPerRollFallback = true;
                        }
                        $poolSynthetic = false;
                        if ($packageKey === '' && $gofroNeed > 0) {
                            $poolSynthetic = true;
                            $packageKey = 'Z_FALLBACK_' . substr(hash('sha256', $rowKey), 0, 22);
                            if ($package === '') {
                                $package = 'Г/п (нет в справочнике)';
                            }
                            if ($packagesPerRoll <= 0) {
                                $packagesPerRoll = max(1, min($gofroNeed, 500));
                            }
                            $packagesPerRollFallback = true;
                        }
                        $poolHintReason = '';
                        if ($poolSynthetic) {
                            $poolHintReason = 'Фильтр не найден в round_filter_structure — в пул добавлены полосы с техническим ключом пакета. Добавьте/исправьте строку в справочнике, чтобы совпали названия.';
                        } elseif ($packageKey === '') {
                            $poolHintReason = 'В пул не попадет: для фильтра не задан гофропакет в справочнике round_filter_structure.';
                        } elseif ($gofroNeed <= 0) {
                            $poolHintReason = 'В пул не попадет: потребность в г/п равна 0 (доступного г/п уже хватает).';
                        } elseif ($packagesPerRoll <= 0) {
                            $poolHintReason = 'В пул не попадет: не рассчитано количество г/п с рулона (проверьте параметры в paper_package_round: высота/число ребер).';
                        } elseif ($packagesPerRollFallback) {
                            $poolHintReason = 'В paper_package_round нет данных для расчёта длины гофропакета; используется упрощённая ёмкость полурулона. Уточните параметры в справочнике.';
                        }
                        $qtyPerHalfRoll = $packagesPerRoll > 0 ? max(1, (int)ceil($packagesPerRoll / 2)) : 0;
                        $rowHintReason = $poolHintReason;

                    ?>
                        <tr
                            data-row-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                            data-filter-name="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                            data-paper-width-mm="<?= htmlspecialchars((string)$paperWidthMm, ENT_QUOTES, 'UTF-8') ?>"
                            data-fold-height="<?= htmlspecialchars((string)$foldHeight, ENT_QUOTES, 'UTF-8') ?>"
                            data-fold-height-mm="<?= $ribHeightRounded > 0 ? (int)$ribHeightRounded : '' ?>"
                            data-fold-count="<?= htmlspecialchars((string)$foldCount, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-key="<?= htmlspecialchars($packageKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-package-name="<?= htmlspecialchars($package, ENT_QUOTES, 'UTF-8') ?>"
                            data-base-available="<?= (int)$gofroAvailable ?>"
                            data-gofro-need="<?= (int)$gofroNeed ?>"
                            data-packages-per-roll="<?= (int)$packagesPerRoll ?>"
                            data-qty-per-half-roll="<?= (int)$qtyPerHalfRoll ?>"
                            data-machine-type="<?= htmlspecialchars($machineType, ENT_QUOTES, 'UTF-8') ?>"
                            data-row-hint="<?= htmlspecialchars($rowHintReason, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <td class="filter-name-cell col-compact" title="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($machineType === 'knife'): ?>
                                    <span class="machine-badge" title="Позиция гофрируется на ножевой машине" aria-label="Ножевая машина">Н</span>
                                <?php endif; ?>
                                <?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($poolHintReason !== ''): ?>
                                    <span class="row-pool-hint" title="<?= htmlspecialchars($poolHintReason, ENT_QUOTES, 'UTF-8') ?>">i</span>
                                <?php endif; ?>
                            </td>
                            <td class="analog-cell col-compact" title="<?= $rowAnalog !== '' ? htmlspecialchars('Аналог: ' . $rowAnalog, ENT_QUOTES, 'UTF-8') : '' ?>"><?= $rowAnalog !== '' ? htmlspecialchars($rowAnalog, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>' ?></td>
                            <td class="order-col order-cell col-compact">
                                <?php if ($rawOrder !== ''): ?>
                                    <form action="show_order.php" method="post" target="_blank" rel="noopener">
                                        <input type="hidden" name="order_number" value="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"><?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num-metric-col num-metric-col--left col-compact" title="Остаток к производству из заказанного по позиции"><?= (int)$remaining ?><span class="muted-light">/<?= (int)$ordered ?></span></td>
                            <td class="num-metric-col col-compact"><?= $gofroProduced ?></td>
                            <td class="num-metric-col col-compact"><?= $gofroAvailable ?></td>
                            <td
                                class="num-metric-col col-compact"
                                title="Остаток фильтров: <?= (int)$remaining ?> из <?= (int)$ordered ?>; доступно г/п: <?= (int)$gofroAvailable ?>; потребность: <?= (int)$gofroNeed ?>"
                            ><?= (int)$gofroNeed ?></td>
                            <td
                                class="debt-cell"
                                data-debt-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                                data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                                data-filter-name="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <div class="debt-list"></div>
                            </td>
                            <?php foreach ($buildPlanDates as $idx => $planDate):
                                $buildQty = (int)($planQtyByDate[$planDate] ?? 0);
                                $gofroQty = (int)($gofroQtyByDate[$planDate] ?? 0);
                                $classes = ['date-col', 'date-cell'];
                            ?>
                                <td class="<?= htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') ?>"
                                    data-date="<?= htmlspecialchars((string)$planDate, ENT_QUOTES, 'UTF-8') ?>"
                                    data-plan-qty="<?= (int)$buildQty ?>"
                                    data-gofro-qty="<?= (int)$gofroQty ?>"
                                    title="<?= $buildQty > 0 ? 'План сборки фильтров: ' . (int)$buildQty . ' шт' : 'Клик: задать г/п на дату' ?>">
                                    <?php if ($buildQty > 0): ?><span class="cell-plan-hint"><?= (int)$buildQty ?></span><?php endif; ?>
                                    <?php if ($gofroQty > 0): ?><span class="cell-gofro-qty"><?= (int)$gofroQty ?></span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <tfoot>
                <tr>
                    <?php renderGofroPlanFixedHeaderCells(); ?>
                    <?php foreach ($buildPlanDates as $planDate) {
                        renderGofroPlanDateHeaderTh((string)$planDate);
                    } ?>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
<div id="debtExpandPopover" class="debt-popover" hidden>
    <div id="debtExpandPopoverInner" class="debt-popover__inner"></div>
</div>
<div id="gofroCellPicker" class="gofro-cell-picker" hidden></div>
<div id="overdue-alert-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:10001; align-items:center; justify-content:center; padding:14px;">
    <div style="background:#fff; border-radius:12px; width:min(720px, 100%); max-width:100%; max-height:min(80vh, 560px); box-shadow:0 16px 40px rgba(2,6,23,.25); border:1px solid #e2e8f0; display:flex; flex-direction:column;">
        <div style="padding:12px 14px; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:14px; color:#991b1b;">Просроченные позиции</div>
        <div style="padding:10px 14px 0; font-size:12px; color:#64748b;">
            Запланировано к гофрированию на дату раньше «сегодня» и по расчёту ещё не закрыто текущей потребностью в г/п.
        </div>
        <div id="overdue-alert-body" style="padding:12px 14px; overflow:auto; flex:1;"></div>
        <div style="padding:10px 14px; display:flex; justify-content:flex-end; gap:8px; border-top:1px solid #e2e8f0;">
            <button id="overdue-alert-close" type="button" style="border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:8px; padding:6px 14px; font-size:12px; cursor:pointer;">Закрыть</button>
        </div>
    </div>
</div>
<div id="task-sheet-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:10000; align-items:center; justify-content:center; padding:14px;">
    <div style="background:#fff; border-radius:12px; width:min(420px, 100%); max-width:100%; box-shadow:0 16px 40px rgba(2,6,23,.25); border:1px solid #e2e8f0;">
        <div style="padding:12px; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:14px;">Печать задания сборщицам</div>
        <div style="padding:12px; display:grid; gap:10px;">
            <fieldset style="border:0; padding:0; margin:0;">
                <legend style="font-size:12px; color:#334155; margin-bottom:6px;">Форма бланка</legend>
                <div style="display:flex; flex-direction:column; gap:6px; font-size:12px; color:#334155;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="task-print-mode" id="task-print-mode-period" value="period" checked>
                        Ведомость за период
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="task-print-mode" id="task-print-mode-day" value="day">
                        Сменной наряд на одну дату
                    </label>
                </div>
            </fieldset>
            <div id="task-period-dates" style="display:grid; gap:8px;">
                <label style="font-size:12px; color:#334155;">
                    С даты
                    <input id="task-date-from" type="date" style="display:block; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:6px 8px; margin-top:4px;">
                </label>
                <label style="font-size:12px; color:#334155;">
                    По дату
                    <input id="task-date-to" type="date" style="display:block; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:6px 8px; margin-top:4px;">
                </label>
            </div>
            <div id="task-day-date-wrap" style="display:none;">
                <label style="font-size:12px; color:#334155;">
                    Дата наряда
                    <input id="task-date-one" type="date" style="display:block; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:6px 8px; margin-top:4px;">
                </label>
            </div>
        </div>
        <div style="padding:10px 12px; display:flex; justify-content:flex-end; gap:8px; border-top:1px solid #e2e8f0;">
            <button id="task-sheet-cancel" type="button" style="border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">Отмена</button>
            <button id="task-sheet-print" type="button" style="border:1px solid #0f766e; background:#0f766e; color:#fff; border-radius:8px; padding:6px 10px; font-size:12px; cursor:pointer;">Сформировать и печатать</button>
        </div>
    </div>
</div>
<div id="maxPctModal" class="ind-modal" hidden>
    <div class="ind-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="maxPctModalTitle">
        <div id="maxPctModalTitle" class="ind-modal__head">Порог выполнения позиций</div>
        <div class="ind-modal__body">
            <div class="ind-field">
                <label for="maxPctInput">Макс. % выполнения для списка (включительно)</label>
                <input id="maxPctInput" type="number" min="0" max="100" step="1" value="<?= (int)$maxPct ?>">
                <p class="muted" style="margin:0; font-size:12px;">
                    Позиции с выпуском фильтров выше этого процента не показываются. При сохранении страница перезагрузится.
                    Значение по умолчанию — <?= (int)$gofroMaxCompletionPctDefault ?>%.
                </p>
            </div>
        </div>
        <div class="ind-modal__foot">
            <button type="button" id="maxPctResetBtn" class="toolbar-btn secondary">Сброс (<?= (int)$gofroMaxCompletionPctDefault ?>%)</button>
            <button type="button" id="maxPctCancelBtn" class="toolbar-btn secondary">Отмена</button>
            <button type="button" id="maxPctSaveBtn" class="toolbar-btn">Сохранить</button>
        </div>
    </div>
</div>
<div id="hiddenOrdersModal" class="ind-modal" hidden>
    <div class="ind-modal__dialog ind-modal__dialog--orders" role="dialog" aria-modal="true" aria-labelledby="hiddenOrdersModalTitle">
        <div id="hiddenOrdersModalTitle" class="ind-modal__head">Видимость заявок</div>
        <div class="ind-modal__body ind-modal__body--orders">
            <p class="muted" style="margin:0;font-size:12px;">Отметьте заявки, которые нужно <strong>скрыть</strong> в таблице (только интерфейс; данные не меняются). Состояние сохраняется в браузере.</p>
            <label class="ind-field" style="margin:0;">
                <span>Поиск по номеру</span>
                <input type="search" id="hiddenOrdersSearchInput" class="hidden-orders-search" placeholder="Например, 12345" autocomplete="off">
            </label>
            <div id="hiddenOrdersList" class="hidden-orders-list" role="group" aria-label="Список заявок"></div>
        </div>
        <div class="ind-modal__foot ind-modal__foot--orders">
            <button type="button" id="hiddenOrdersShowAllBtn" class="toolbar-btn secondary">Показать все</button>
            <div class="ind-modal__foot-spacer"></div>
            <button type="button" id="hiddenOrdersCancelBtn" class="toolbar-btn secondary">Отмена</button>
            <button type="button" id="hiddenOrdersApplyBtn" class="toolbar-btn">Применить</button>
        </div>
    </div>
</div>
<?php if ($loadError === ''): ?>
<script>
(() => {
    const REAL_TODAY_ISO = <?= json_encode($todayIso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const serverDefaultMaxPct = <?= (int)$gofroMaxCompletionPctDefault ?>;
    const currentPageMaxPct = <?= (int)$maxPct ?>;
    const GOFRO_COVERAGE_VISIBLE_STORAGE_KEY = 'gofroBuildPlanCoverageVisible';
    const FILTER_PLAN_DIGITS_VISIBLE_STORAGE_KEY = 'gofroBuildPlanFilterPlanDigitsVisible';
    const FOLD_HEIGHT_PANEL_OPEN_STORAGE_KEY = 'gofroBuildPlanFoldHeightPanelOpen';
    const FOLD_HEIGHT_NONE_KEY = '__none__';
    const MAX_PCT_STORAGE_KEY = `gofroBuildPlanMaxListPct:${window.location.pathname}`;
    const HIDDEN_ORDERS_STORAGE_KEY = `gofroBuildPlanHiddenOrders:${window.location.pathname}`;
    const FROZEN_COL_COUNT = 8;
    const DEBT_COMPACT_VISIBLE = 3;
    const initialGofroDebtShiftMap = <?= json_encode($gofroDebtShiftMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
    const initialGofroPlanMap = <?= json_encode($gofroPlanMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
    const initialBuildPlanMap = <?= json_encode($buildPlanMapByRowKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
    const urlParams = new URLSearchParams(window.location.search);
    let currentTodayIso = REAL_TODAY_ISO;
    if (urlParams.has('test_date') && /^\d{4}-\d{2}-\d{2}$/.test(urlParams.get('test_date') || '')) {
        currentTodayIso = String(urlParams.get('test_date'));
    }

    const gofroCellPicker = document.getElementById('gofroCellPicker');
    const pendingMovesBar = document.getElementById('pendingMovesBar');
    const pendingMovesText = document.getElementById('pendingMovesText');
    const applyPendingMovesBtn = document.getElementById('applyPendingMovesBtn');
    const applyPendingMovesPanelBtn = document.getElementById('applyPendingMovesPanelBtn');
    const undoPendingMoveBtn = document.getElementById('undoPendingMoveBtn');
    const clearPendingMovesBtn = document.getElementById('clearPendingMovesBtn');
    const toggleQueuePanelBtn = document.getElementById('toggleQueuePanelBtn');
    const moveQueuePanel = document.getElementById('moveQueuePanel');
    const closeQueuePanelBtn = document.getElementById('closeQueuePanelBtn');
    const moveQueueEmpty = document.getElementById('moveQueueEmpty');
    const moveQueueList = document.getElementById('moveQueueList');
    const applyStatus = document.getElementById('apply-status');
    const taskSheetBtn = document.getElementById('task-sheet-btn');
    const taskSheetModal = document.getElementById('task-sheet-modal');
    const taskDateFrom = document.getElementById('task-date-from');
    const taskDateTo = document.getElementById('task-date-to');
    const taskDateOne = document.getElementById('task-date-one');
    const taskPeriodDates = document.getElementById('task-period-dates');
    const taskDayDateWrap = document.getElementById('task-day-date-wrap');
    const taskPrintModePeriod = document.getElementById('task-print-mode-period');
    const taskPrintModeDay = document.getElementById('task-print-mode-day');
    const taskSheetCancel = document.getElementById('task-sheet-cancel');
    const taskSheetPrint = document.getElementById('task-sheet-print');
    const addPlanDayBtn = document.getElementById('add-plan-day-btn');
    const toggleGofroCoverageBtn = document.getElementById('toggle-gofro-coverage-btn');
    const toggleFilterPlanDigitsBtn = document.getElementById('toggle-filter-plan-digits-btn');
    const gofroCoverageLegend = document.getElementById('gofro-coverage-legend');
    const overdueAlertBtn = document.getElementById('overdue-alert-btn');
    const overdueAlertModal = document.getElementById('overdue-alert-modal');
    const overdueAlertBody = document.getElementById('overdue-alert-body');
    const overdueAlertClose = document.getElementById('overdue-alert-close');
    const debtExpandPopover = document.getElementById('debtExpandPopover');
    const debtExpandPopoverInner = document.getElementById('debtExpandPopoverInner');
    const openMaxPctBtn = document.getElementById('open-max-pct-btn');
    const maxPctModal = document.getElementById('maxPctModal');
    const maxPctInput = document.getElementById('maxPctInput');
    const maxPctSaveBtn = document.getElementById('maxPctSaveBtn');
    const maxPctCancelBtn = document.getElementById('maxPctCancelBtn');
    const maxPctResetBtn = document.getElementById('maxPctResetBtn');
    const openHiddenOrdersBtn = document.getElementById('open-hidden-orders-btn');
    const hiddenOrdersModal = document.getElementById('hiddenOrdersModal');
    const hiddenOrdersSearchInput = document.getElementById('hiddenOrdersSearchInput');
    const hiddenOrdersList = document.getElementById('hiddenOrdersList');
    const hiddenOrdersShowAllBtn = document.getElementById('hiddenOrdersShowAllBtn');
    const hiddenOrdersApplyBtn = document.getElementById('hiddenOrdersApplyBtn');
    const hiddenOrdersCancelBtn = document.getElementById('hiddenOrdersCancelBtn');
    const hiddenOrdersBadge = document.getElementById('hidden-orders-badge');
    const toggleFoldHeightPanelBtn = document.getElementById('toggle-fold-height-panel-btn');
    const foldHeightPanel = document.getElementById('fold-height-panel');
    const foldHeightButtons = document.getElementById('fold-height-buttons');
    const foldHeightClearBtn = document.getElementById('fold-height-clear-btn');
    const foldHeightPanelHint = document.getElementById('fold-height-panel-hint');
    const planTable = document.querySelector('.panel table');

    if (!gofroCellPicker || !planTable) {
        return;
    }

    const rowStateMap = new Map();
    const pendingMoves = [];
    let isApplyingPendingMoves = false;

    function loadHiddenOrdersFromStorage() {
        try {
            const raw = localStorage.getItem(HIDDEN_ORDERS_STORAGE_KEY);
            if (!raw) {
                return new Set();
            }
            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) {
                return new Set();
            }
            return new Set(parsed.map((x) => String(x || '').trim()).filter(Boolean));
        } catch (_) {
            return new Set();
        }
    }

    function persistHiddenOrders() {
        try {
            localStorage.setItem(HIDDEN_ORDERS_STORAGE_KEY, JSON.stringify(Array.from(hiddenOrdersSet)));
        } catch (_) { /* ignore */ }
    }

    function getUniqueOrdersFromTable() {
        const unique = new Set();
        planTable.querySelectorAll('tbody tr[data-order]').forEach((row) => {
            const o = String(row.getAttribute('data-order') || '').trim();
            if (o) {
                unique.add(o);
            }
        });
        return Array.from(unique).sort((a, b) => {
            const na = parseInt(a, 10);
            const nb = parseInt(b, 10);
            if (!Number.isNaN(na) && !Number.isNaN(nb) && String(na) === a && String(nb) === b) {
                return na - nb;
            }
            return a.localeCompare(b, undefined, { numeric: true });
        });
    }

    function pruneHiddenOrdersNotInTable() {
        const valid = new Set(getUniqueOrdersFromTable());
        let changed = false;
        Array.from(hiddenOrdersSet).forEach((o) => {
            if (!valid.has(o)) {
                hiddenOrdersSet.delete(o);
                changed = true;
            }
        });
        if (changed) {
            persistHiddenOrders();
        }
    }

    let hiddenOrdersSet = loadHiddenOrdersFromStorage();

    function applyOrderRowVisibility() {
        planTable.querySelectorAll('tbody tr[data-row-key]').forEach((row) => {
            const order = String(row.getAttribute('data-order') || '').trim();
            row.hidden = order !== '' && hiddenOrdersSet.has(order);
        });
        applyFrozenColumns();
        applyFoldHeightRowHighlight();
        if (isFoldHeightPanelOpen) {
            buildFoldHeightPanelButtons();
        }
    }

    function updateHiddenOrdersBadge() {
        if (!hiddenOrdersBadge) {
            return;
        }
        const n = hiddenOrdersSet.size;
        if (n <= 0) {
            hiddenOrdersBadge.hidden = true;
            hiddenOrdersBadge.textContent = '';
        } else {
            hiddenOrdersBadge.hidden = false;
            hiddenOrdersBadge.textContent = String(n);
        }
    }

    function filterHiddenOrdersModalList() {
        if (!hiddenOrdersList || !hiddenOrdersSearchInput) {
            return;
        }
        const q = String(hiddenOrdersSearchInput.value || '').trim().toLowerCase();
        hiddenOrdersList.querySelectorAll('.hidden-orders-row').forEach((row) => {
            const order = String(row.getAttribute('data-order') || '').trim();
            const show = !q || order.toLowerCase().includes(q);
            row.classList.toggle('hidden-orders-row--filtered', !show);
        });
    }

    function buildHiddenOrdersModalList() {
        if (!hiddenOrdersList) {
            return;
        }
        hiddenOrdersList.innerHTML = '';
        getUniqueOrdersFromTable().forEach((order) => {
            const row = document.createElement('label');
            row.className = 'hidden-orders-row';
            row.setAttribute('data-order', order);
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.setAttribute('data-order', order);
            cb.checked = hiddenOrdersSet.has(order);
            const span = document.createElement('span');
            span.textContent = `Скрыть заявку ${order}`;
            row.appendChild(cb);
            row.appendChild(span);
            hiddenOrdersList.appendChild(row);
        });
    }

    function openHiddenOrdersModal() {
        if (isApplyingPendingMoves || !hiddenOrdersModal) {
            return;
        }
        pruneHiddenOrdersNotInTable();
        if (hiddenOrdersSearchInput) {
            hiddenOrdersSearchInput.value = '';
        }
        buildHiddenOrdersModalList();
        hiddenOrdersModal.hidden = false;
        if (hiddenOrdersSearchInput) {
            hiddenOrdersSearchInput.focus();
        }
    }

    function closeHiddenOrdersModal() {
        if (hiddenOrdersModal) {
            hiddenOrdersModal.hidden = true;
        }
    }

    function applyHiddenOrdersFromModal() {
        const next = new Set();
        if (hiddenOrdersList) {
            hiddenOrdersList.querySelectorAll('.hidden-orders-row input[type="checkbox"]').forEach((cb) => {
                if (cb.checked) {
                    const o = String(cb.getAttribute('data-order') || '').trim();
                    if (o) {
                        next.add(o);
                    }
                }
            });
        }
        hiddenOrdersSet = next;
        persistHiddenOrders();
        applyOrderRowVisibility();
        updateCoverage();
        updateHiddenOrdersBadge();
        closeHiddenOrdersModal();
    }

    function showAllHiddenOrdersInTable() {
        hiddenOrdersSet = new Set();
        persistHiddenOrders();
        applyOrderRowVisibility();
        updateCoverage();
        updateHiddenOrdersBadge();
        if (hiddenOrdersModal && !hiddenOrdersModal.hidden) {
            buildHiddenOrdersModalList();
            filterHiddenOrdersModalList();
        }
    }
    let overdueModalAutoOpened = false;
    let isGofroCoverageVisible = true;
    let isFilterPlanDigitsVisible = true;
    let isFoldHeightPanelOpen = false;
    let activeFoldHeightKey = null;
    try {
        const storedCoverage = localStorage.getItem(GOFRO_COVERAGE_VISIBLE_STORAGE_KEY);
        if (storedCoverage === '0') {
            isGofroCoverageVisible = false;
        }
    } catch (_) { /* ignore */ }
    try {
        const storedFilterDigits = localStorage.getItem(FILTER_PLAN_DIGITS_VISIBLE_STORAGE_KEY);
        if (storedFilterDigits === '0') {
            isFilterPlanDigitsVisible = false;
        }
    } catch (_) { /* ignore */ }

    function setGofroCoverageVisible(visible) {
        isGofroCoverageVisible = !!visible;
        if (toggleGofroCoverageBtn) {
            toggleGofroCoverageBtn.setAttribute('aria-pressed', isGofroCoverageVisible ? 'true' : 'false');
            toggleGofroCoverageBtn.textContent = isGofroCoverageVisible ? 'Покрытие г/п: вкл' : 'Покрытие г/п: выкл';
        }
        if (gofroCoverageLegend) {
            gofroCoverageLegend.hidden = !isGofroCoverageVisible;
        }
        try {
            localStorage.setItem(GOFRO_COVERAGE_VISIBLE_STORAGE_KEY, isGofroCoverageVisible ? '1' : '0');
        } catch (_) { /* ignore */ }
        updateCoverage();
    }

    function updateMaxPctBtnLabel() {
        if (openMaxPctBtn) {
            openMaxPctBtn.textContent = `Выполнение ≤ ${currentPageMaxPct}%`;
        }
    }

    function reloadWithMaxPct(pct) {
        const url = new URL(window.location.href);
        if (pct === serverDefaultMaxPct) {
            url.searchParams.delete('max_pct');
        } else {
            url.searchParams.set('max_pct', String(pct));
        }
        window.location.href = url.toString();
    }

    function openMaxPctModal() {
        if (!maxPctModal || !maxPctInput) {
            return;
        }
        maxPctInput.value = String(currentPageMaxPct);
        maxPctModal.hidden = false;
    }

    function closeMaxPctModal() {
        if (maxPctModal) {
            maxPctModal.hidden = true;
        }
    }

    try {
        if (!urlParams.has('max_pct')) {
            const savedMaxPct = localStorage.getItem(MAX_PCT_STORAGE_KEY);
            if (savedMaxPct !== null) {
                const parsedSaved = Math.max(0, Math.min(100, parseInt(savedMaxPct, 10)));
                if (!Number.isNaN(parsedSaved) && parsedSaved !== currentPageMaxPct) {
                    reloadWithMaxPct(parsedSaved);
                    return;
                }
            }
        }
    } catch (_) { /* ignore */ }

    function setFilterPlanDigitsVisible(visible) {
        isFilterPlanDigitsVisible = !!visible;
        if (toggleFilterPlanDigitsBtn) {
            toggleFilterPlanDigitsBtn.setAttribute('aria-pressed', isFilterPlanDigitsVisible ? 'true' : 'false');
            toggleFilterPlanDigitsBtn.textContent = isFilterPlanDigitsVisible ? 'Фильтры: вкл' : 'Фильтры: выкл';
        }
        if (planTable) {
            planTable.classList.toggle('gofro-plan-table--hide-filter-plan-digits', !isFilterPlanDigitsVisible);
        }
        try {
            localStorage.setItem(FILTER_PLAN_DIGITS_VISIBLE_STORAGE_KEY, isFilterPlanDigitsVisible ? '1' : '0');
        } catch (_) { /* ignore */ }
    }

    function getRowFoldHeightKey(row) {
        const mm = String(row.dataset.foldHeightMm || '').trim();
        if (mm !== '' && /^\d+$/.test(mm)) {
            return mm;
        }
        const raw = parseFloat(String(row.dataset.foldHeight || '').replace(',', '.'));
        if (Number.isFinite(raw) && raw > 0) {
            return String(Math.round(raw));
        }
        return FOLD_HEIGHT_NONE_KEY;
    }

    function collectFoldHeightStats() {
        const stats = new Map();
        planTable.querySelectorAll('tbody tr[data-row-key]').forEach((row) => {
            if (row.hidden) {
                return;
            }
            const key = getRowFoldHeightKey(row);
            stats.set(key, (stats.get(key) || 0) + 1);
        });
        return stats;
    }

    function updateFoldHeightPanelHint() {
        if (!foldHeightPanelHint) {
            return;
        }
        if (activeFoldHeightKey === null) {
            foldHeightPanelHint.textContent = 'Нажмите высоту — подсветятся строки с таким ребром (из справочника paper_package_round). Повторный клик снимает фильтр.';
            return;
        }
        const label = activeFoldHeightKey === FOLD_HEIGHT_NONE_KEY
            ? 'без данных о высоте'
            : `${activeFoldHeightKey} мм`;
        const matched = planTable.querySelectorAll('tbody tr[data-row-key].fold-height-match').length;
        foldHeightPanelHint.textContent = `Подсвечено: ${matched} поз. · высота ${label}`;
    }

    function applyFoldHeightRowHighlight() {
        const filterOn = activeFoldHeightKey !== null;
        planTable.classList.toggle('fold-height-filter-active', filterOn);
        planTable.querySelectorAll('tbody tr[data-row-key]').forEach((row) => {
            const match = filterOn && getRowFoldHeightKey(row) === activeFoldHeightKey;
            row.classList.toggle('fold-height-match', match);
        });
        if (foldHeightClearBtn) {
            foldHeightClearBtn.style.display = filterOn ? '' : 'none';
        }
        if (foldHeightButtons) {
            foldHeightButtons.querySelectorAll('.fold-height-chip').forEach((chip) => {
                const key = String(chip.dataset.foldHeightKey || '');
                chip.classList.toggle('active', filterOn && key === activeFoldHeightKey);
            });
        }
        updateFoldHeightPanelHint();
    }

    function clearFoldHeightFilter() {
        activeFoldHeightKey = null;
        applyFoldHeightRowHighlight();
    }

    function selectFoldHeightFilter(key) {
        const nextKey = String(key || '');
        if (nextKey === '') {
            return;
        }
        activeFoldHeightKey = activeFoldHeightKey === nextKey ? null : nextKey;
        applyFoldHeightRowHighlight();
    }

    function buildFoldHeightPanelButtons() {
        if (!foldHeightButtons) {
            return;
        }
        const stats = collectFoldHeightStats();
        foldHeightButtons.innerHTML = '';
        const numericKeys = Array.from(stats.keys())
            .filter((k) => k !== FOLD_HEIGHT_NONE_KEY)
            .sort((a, b) => parseInt(a, 10) - parseInt(b, 10));
        const keys = numericKeys.slice();
        if (stats.has(FOLD_HEIGHT_NONE_KEY)) {
            keys.push(FOLD_HEIGHT_NONE_KEY);
        }
        if (keys.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'muted';
            empty.textContent = 'Нет позиций в таблице';
            foldHeightButtons.appendChild(empty);
            return;
        }
        keys.forEach((key) => {
            const count = stats.get(key) || 0;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fold-height-chip' + (key === FOLD_HEIGHT_NONE_KEY ? ' fold-height-chip--none' : '');
            btn.dataset.foldHeightKey = key;
            btn.textContent = key === FOLD_HEIGHT_NONE_KEY
                ? `— (${count})`
                : `${key} (${count})`;
            btn.title = key === FOLD_HEIGHT_NONE_KEY
                ? 'Позиции без высоты ребра в справочнике'
                : `Высота ребра ${key} мм · ${count} поз.`;
            if (activeFoldHeightKey === key) {
                btn.classList.add('active');
            }
            btn.addEventListener('click', () => {
                selectFoldHeightFilter(key);
            });
            foldHeightButtons.appendChild(btn);
        });
    }

    function setFoldHeightPanelOpen(open) {
        isFoldHeightPanelOpen = !!open;
        if (foldHeightPanel) {
            foldHeightPanel.hidden = !isFoldHeightPanelOpen;
        }
        if (toggleFoldHeightPanelBtn) {
            toggleFoldHeightPanelBtn.setAttribute('aria-pressed', isFoldHeightPanelOpen ? 'true' : 'false');
        }
        try {
            localStorage.setItem(FOLD_HEIGHT_PANEL_OPEN_STORAGE_KEY, isFoldHeightPanelOpen ? '1' : '0');
        } catch (_) { /* ignore */ }
        if (isFoldHeightPanelOpen) {
            buildFoldHeightPanelButtons();
            applyFoldHeightRowHighlight();
        }
    }

    function buildFilterCoverageTitle(filterQty, stockPart, planPart, date) {
        const lines = [`План сборки фильтров: ${filterQty} шт`];
        if (stockPart > 0) {
            lines.push(`Покрыто из запаса г/п: ${stockPart} шт`);
        }
        if (planPart > 0) {
            lines.push(`Покрыто планом г/п: ${planPart} шт`);
        }
        const gap = Math.max(0, filterQty - stockPart - planPart);
        if (gap > 0) {
            lines.push(`Не хватает г/п: ${gap} шт`);
        }
        return lines.join('\n');
    }

    function applyGofroCoverageForRow(state) {
        if (!state) {
            return;
        }
        const coverageClasses = [
            'gofro-coverage-cell',
            'gofro-coverage-cell-partial',
            'gofro-coverage-planned-cell',
            'gofro-coverage-planned-cell-partial',
            'gofro-coverage-gap',
            'gofro-coverage-gap-partial',
        ];
        let baseRemaining = Math.max(0, state.baseAvailable);
        let plannedTotal = 0;
        state.dateCells.forEach((cell) => {
            const d = String(cell.dataset.date || '');
            plannedTotal += Math.max(0, parseInt(state.allocatedByDate[d] || '0', 10) || 0);
        });
        let poolRemaining = baseRemaining + plannedTotal;

        state.dateCells.forEach((cell) => {
            cell.classList.remove(...coverageClasses);
            if (cell.dataset.coverageBaseTitle === undefined) {
                cell.dataset.coverageBaseTitle = cell.getAttribute('title') || '';
            }
            const baseTitle = cell.dataset.coverageBaseTitle || '';
            const date = String(cell.dataset.date || '');
            const filterQty = Math.max(0, parseInt(cell.dataset.planQty || '0', 10) || 0);

            if (!isGofroCoverageVisible || filterQty <= 0) {
                cell.title = baseTitle;
                return;
            }

            let stockUsed = 0;
            let planUsed = 0;

            if (poolRemaining <= 0) {
                cell.classList.add('gofro-coverage-gap');
                cell.title = baseTitle
                    ? `${baseTitle}\n${buildFilterCoverageTitle(filterQty, 0, 0, date)}`
                    : buildFilterCoverageTitle(filterQty, 0, 0, date);
                return;
            }

            stockUsed = Math.min(baseRemaining, filterQty);
            if (stockUsed > 0) {
                if (stockUsed >= filterQty) {
                    cell.classList.add('gofro-coverage-cell');
                } else {
                    cell.classList.add('gofro-coverage-cell-partial');
                }
                baseRemaining -= stockUsed;
                poolRemaining -= stockUsed;
            }

            const leftover = filterQty - stockUsed;
            if (leftover > 0 && poolRemaining > 0) {
                planUsed = Math.min(poolRemaining, leftover);
                if (planUsed >= leftover) {
                    cell.classList.add('gofro-coverage-planned-cell');
                } else {
                    cell.classList.add('gofro-coverage-planned-cell-partial');
                }
                poolRemaining -= planUsed;
            }

            const uncovered = filterQty - stockUsed - planUsed;
            if (uncovered > 0) {
                if (stockUsed + planUsed > 0) {
                    cell.classList.add('gofro-coverage-gap-partial');
                } else {
                    cell.classList.add('gofro-coverage-gap');
                }
            }

            const coverageTitle = buildFilterCoverageTitle(filterQty, stockUsed, planUsed, date);
            cell.title = baseTitle ? `${baseTitle}\n${coverageTitle}` : coverageTitle;
        });
    }
    let pickerTarget = null;
    let dragContext = null;
    let debtPopoverAnchorCell = null;
    const debtStateMap = Object.assign({}, initialGofroDebtShiftMap || {});
    const dateHeaderTotals = {};

    function registerDateHeaderTotals() {
        Object.keys(dateHeaderTotals).forEach((k) => delete dateHeaderTotals[k]);
        document.querySelectorAll('th.date-col[data-date-total]').forEach((th) => {
            const date = String(th.dataset.dateTotal || '');
            const rotaryEl = th.querySelector('.date-total-rotary');
            const knifeEl = th.querySelector('.date-total-knife');
            if (!date || !rotaryEl || !knifeEl) {
                return;
            }
            if (!dateHeaderTotals[date]) {
                dateHeaderTotals[date] = { knifeEls: [], rotaryEls: [], ths: [] };
            }
            dateHeaderTotals[date].knifeEls.push(knifeEl);
            dateHeaderTotals[date].rotaryEls.push(rotaryEl);
            dateHeaderTotals[date].ths.push(th);
        });
    }
    registerDateHeaderTotals();

    let crosshairHoverCell = null;

    function clearTableCrosshairHighlight() {
        crosshairHoverCell = null;
        planTable.querySelectorAll('th.date-col.date-hover').forEach((el) => {
            el.classList.remove('date-hover');
        });
        planTable.querySelectorAll('td.filter-name-cell.name-hover').forEach((el) => {
            el.classList.remove('name-hover');
        });
    }

    function applyTableCrosshairHighlight(cell) {
        if (!cell || cell === crosshairHoverCell) {
            return;
        }
        crosshairHoverCell = cell;
        planTable.querySelectorAll('th.date-col.date-hover').forEach((el) => {
            el.classList.remove('date-hover');
        });
        planTable.querySelectorAll('td.filter-name-cell.name-hover').forEach((el) => {
            el.classList.remove('name-hover');
        });

        const tr = cell.closest('tr');
        const dataRow = tr && tr.hasAttribute('data-row-key') ? tr : null;
        if (dataRow) {
            const nameCell = dataRow.querySelector('td.filter-name-cell');
            if (nameCell) {
                nameCell.classList.add('name-hover');
            }
        }

        let dateIso = String(cell.dataset.date || cell.dataset.dateTotal || '');
        if (!dateIso && typeof cell.cellIndex === 'number' && cell.cellIndex >= 0) {
            const headRow = planTable.querySelector('thead tr');
            const headCell = headRow ? headRow.children[cell.cellIndex] : null;
            if (headCell && headCell.dataset.dateTotal) {
                dateIso = String(headCell.dataset.dateTotal);
            }
        }
        if (dateIso) {
            planTable.querySelectorAll(`thead th.date-col[data-date-total="${dateIso}"], tfoot th.date-col[data-date-total="${dateIso}"]`).forEach((th) => {
                th.classList.add('date-hover');
            });
        }
    }

    planTable.addEventListener('mouseover', (e) => {
        const cell = e.target.closest('td, th');
        if (!cell || !planTable.contains(cell)) {
            return;
        }
        applyTableCrosshairHighlight(cell);
    });
    planTable.addEventListener('mouseleave', (e) => {
        if (!e.relatedTarget || !planTable.contains(e.relatedTarget)) {
            clearTableCrosshairHighlight();
        }
    });

    function getTodayIso() {
        return currentTodayIso;
    }

    function addCalendarDaysIso(iso, deltaDays) {
        const parts = String(iso).split('-').map((v) => parseInt(v, 10));
        if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
            return String(iso);
        }
        const dt = new Date(parts[0], parts[1] - 1, parts[2]);
        dt.setDate(dt.getDate() + deltaDays);
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function htmlEscape(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatDateRu(iso) {
        const p = String(iso || '').split('-');
        if (p.length !== 3) {
            return iso;
        }
        return `${p[2]}.${p[1]}.${p[0]}`;
    }

    function toShortDate(iso) {
        const p = String(iso || '').split('-');
        if (p.length !== 3) {
            return iso;
        }
        return `${p[2]}.${p[1]}`;
    }

    function addDaysIso(iso, days) {
        const p = String(iso || '').split('-').map((x) => parseInt(x, 10));
        if (p.length !== 3 || p.some((n) => Number.isNaN(n))) {
            return '';
        }
        const dt = new Date(p[0], p[1] - 1, p[2]);
        dt.setDate(dt.getDate() + (parseInt(days, 10) || 0));
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function getUniquePlanDates() {
        return Array.from(planTable.querySelectorAll('th.date-col[data-date-total]'))
            .map((th) => String(th.dataset.dateTotal || ''))
            .filter(Boolean)
            .sort();
    }

    function buildDateHeaderTh(dateIso) {
        const th = document.createElement('th');
        th.className = 'date-col';
        const dt = new Date(`${dateIso}T12:00:00`);
        const dow = dt.getDay();
        if (dow === 0 || dow === 6) {
            th.classList.add('weekend');
        }
        th.dataset.dateTotal = dateIso;
        th.title = 'Суммарно г/п на дату';
        const label = formatDateRu(dateIso).slice(0, 5);
        th.innerHTML = `<span class="date-head-label">${htmlEscape(label)}</span><span class="date-total-lines"><span class="date-total-line date-total-line--knife"><span class="date-total-knife" title="Ножевая">0</span></span><span class="date-total-line date-total-line--rotary"><span class="date-total-rotary" title="Ротационная">0</span></span></span>`;
        return th;
    }

    function createDateCellForRow(state, dateIso) {
        const rowPlan = initialBuildPlanMap[state.rowKey] || {};
        const buildQty = Math.max(0, parseInt(rowPlan[dateIso] || '0', 10) || 0);
        const gofroQty = Math.max(0, parseInt((state.planByDate && state.planByDate[dateIso]) || '0', 10) || 0);
        const cell = document.createElement('td');
        cell.className = 'date-col date-cell';
        cell.dataset.date = dateIso;
        cell.dataset.planQty = String(buildQty);
        cell.dataset.gofroQty = String(gofroQty);
        cell.title = buildQty > 0
            ? `План сборки фильтров: ${buildQty} шт`
            : 'Клик: задать г/п на дату';
        refreshCellDom(cell, gofroQty);
        if (gofroQty > 0 && dateIso >= getTodayIso()) {
            state.allocatedByDate[dateIso] = gofroQty;
        }
        return cell;
    }

    function bindDateCellInteractions(cell) {
        cell.addEventListener('click', (e) => {
            if (dragContext) {
                return;
            }
            if (e.target.closest('.gofro-cell-picker')) {
                return;
            }
            const row = cell.closest('tr[data-row-key]');
            if (!row) {
                return;
            }
            const state = rowStateMap.get(String(row.dataset.rowKey || ''));
            if (!state) {
                return;
            }
            openGofroCellPicker(cell, state);
        });
        cell.addEventListener('dragover', (e) => {
            if (!dragContext || dragContext.sourceType !== 'debt') {
                return;
            }
            const row = cell.closest('tr[data-row-key]');
            const state = row ? rowStateMap.get(String(row.dataset.rowKey || '')) : null;
            if (canDropDebtOnCell(cell, state)) {
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
                cell.classList.add('drag-drop-target');
            }
        });
        cell.addEventListener('dragleave', () => {
            cell.classList.remove('drag-drop-target');
        });
        cell.addEventListener('drop', (e) => {
            if (!dragContext || dragContext.sourceType !== 'debt') {
                return;
            }
            const row = cell.closest('tr[data-row-key]');
            const state = row ? rowStateMap.get(String(row.dataset.rowKey || '')) : null;
            cell.classList.remove('drag-drop-target');
            if (!canDropDebtOnCell(cell, state)) {
                return;
            }
            e.preventDefault();
            const toDate = String(cell.dataset.date || '');
            queueDebtMove(state, dragContext.fromDate, toDate, dragContext.movedQty);
            dragContext = null;
        });
    }

    function addPlanDayColumn() {
        const rows = Array.from(planTable.querySelectorAll('tbody tr[data-row-key]'));
        if (rows.length === 0) {
            alert('Нет строк — добавлять колонку не к чему.');
            return;
        }
        const dates = getUniquePlanDates();
        let nextIso;
        if (dates.length > 0) {
            nextIso = addDaysIso(dates[dates.length - 1], 1);
        } else {
            nextIso = getTodayIso();
        }
        if (!nextIso) {
            return;
        }
        if (dates.includes(nextIso)) {
            alert('Колонка для этой даты уже есть.');
            return;
        }
        const theadRow = planTable.querySelector('thead tr');
        const tfootRow = planTable.querySelector('tfoot tr');
        if (!theadRow || !tfootRow) {
            return;
        }
        const newHeadTh = buildDateHeaderTh(nextIso);
        const newFootTh = newHeadTh.cloneNode(true);
        theadRow.appendChild(newHeadTh);
        tfootRow.appendChild(newFootTh);
        registerDateHeaderTotals();

        rows.forEach((row) => {
            const rowKey = String(row.dataset.rowKey || '');
            const state = rowStateMap.get(rowKey);
            if (!state) {
                return;
            }
            const cell = createDateCellForRow(state, nextIso);
            row.appendChild(cell);
            bindDateCellInteractions(cell);
            state.dateCells.push(cell);
        });

        applyFrozenColumns();
        updateCoverage();
        setApplyStatus(`Добавлена колонка ${formatDateRu(nextIso)}`, false);
    }

    function cloneDebtShifts(shifts) {
        return (Array.isArray(shifts) ? shifts : []).map((s) => ({
            date: String(s.date || ''),
            qty: Math.max(0, parseInt(s.qty || 0, 10) || 0),
        })).filter((s) => s.date && s.qty > 0);
    }

    function getDebtShiftsForKey(rowKey) {
        return cloneDebtShifts(debtStateMap[rowKey] || []);
    }

    function setDebtShiftsForKey(rowKey, shifts) {
        const next = cloneDebtShifts(shifts).sort((a, b) => a.date.localeCompare(b.date));
        if (next.length === 0) {
            delete debtStateMap[rowKey];
        } else {
            debtStateMap[rowKey] = next;
        }
    }

    function getDebtQtyForRow(rowKey) {
        return getDebtShiftsForKey(rowKey).reduce((sum, s) => sum + s.qty, 0);
    }

    function getOverdueDebtQtyForRow(rowKey) {
        const todayIso = getTodayIso();
        return getDebtShiftsForKey(rowKey)
            .filter((s) => s.date && s.date < todayIso)
            .reduce((sum, s) => sum + s.qty, 0);
    }

    function capDebtShiftsFifo(shifts, uncoveredNeed) {
        const cap = Math.max(0, parseInt(uncoveredNeed, 10) || 0);
        if (cap <= 0 || !shifts.length) {
            return [];
        }
        const total = shifts.reduce((s, it) => s + it.qty, 0);
        if (total <= cap) {
            return shifts.map((s) => ({ date: s.date, qty: s.qty }));
        }
        let remaining = cap;
        const out = [];
        shifts.forEach((item) => {
            if (remaining <= 0) {
                return;
            }
            const take = Math.min(item.qty, remaining);
            if (take > 0) {
                out.push({ date: item.date, qty: take });
                remaining -= take;
            }
        });
        return out;
    }

    function reconcileDebtForRow(state) {
        if (!state) {
            return;
        }
        const todayIso = getTodayIso();
        const existing = getDebtShiftsForKey(state.rowKey);
        const nonPast = existing.filter((s) => s.date >= todayIso);
        let plannedFromToday = 0;
        const pastFromPlan = [];
        const planByDate = state.planByDate || {};
        Object.keys(planByDate).forEach((d) => {
            const q = parseInt(planByDate[d] || '0', 10) || 0;
            if (q <= 0) {
                return;
            }
            if (d < todayIso) {
                pastFromPlan.push({ date: d, qty: q });
            } else {
                plannedFromToday += q;
            }
        });
        pastFromPlan.sort((a, b) => a.date.localeCompare(b.date));
        const uncovered = Math.max(0, state.gofroNeed - plannedFromToday);
        const cappedPast = capDebtShiftsFifo(pastFromPlan, uncovered);
        setDebtShiftsForKey(state.rowKey, cappedPast.concat(nonPast));
    }

    function bindDebtShiftDrag(item, state) {
        item.addEventListener('dragstart', (e) => {
            const fromDate = item.dataset.date || '';
            const qty = Math.max(0, parseInt(item.dataset.qty || '0', 10) || 0);
            if (!fromDate || qty <= 0 || !state) {
                e.preventDefault();
                return;
            }
            dragContext = {
                sourceType: 'debt',
                rowKey: state.rowKey,
                order: state.order,
                filterName: state.filterName,
                fromDate,
                movedQty: qty,
            };
            item.classList.add('drag-source-single');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', `${state.rowKey}|${fromDate}|debt`);
            }
        });
        item.addEventListener('dragend', () => {
            item.classList.remove('drag-source-single');
            dragContext = null;
            planTable.querySelectorAll('td.date-cell.drag-drop-target').forEach((el) => {
                el.classList.remove('drag-drop-target');
            });
        });
    }

    function closeDebtExpandPopover() {
        debtPopoverAnchorCell = null;
        if (debtExpandPopover) {
            debtExpandPopover.hidden = true;
        }
        if (debtExpandPopoverInner) {
            debtExpandPopoverInner.innerHTML = '';
        }
    }

    function positionDebtExpandPopover(anchorCell) {
        if (!anchorCell || !debtExpandPopover || debtExpandPopover.hidden) {
            return;
        }
        const rect = anchorCell.getBoundingClientRect();
        const gap = 6;
        const vw = window.innerWidth || 800;
        const vh = window.innerHeight || 600;
        debtExpandPopover.style.left = `${Math.round(rect.left)}px`;
        debtExpandPopover.style.top = `${Math.round(rect.bottom + gap)}px`;
        const popRect = debtExpandPopover.getBoundingClientRect();
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
        debtExpandPopover.style.left = `${Math.round(left)}px`;
        debtExpandPopover.style.top = `${Math.round(top)}px`;
    }

    function fillDebtPopoverShifts(debtCell, state) {
        if (!debtExpandPopoverInner || !state) {
            return;
        }
        debtExpandPopoverInner.innerHTML = '';
        getDebtShiftsForKey(state.rowKey).forEach((shift) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'debt-shift debt-shift--popover';
            item.draggable = true;
            item.dataset.date = shift.date;
            item.dataset.qty = String(shift.qty);
            item.title = `Долг ${toShortDate(shift.date)}: ${shift.qty} шт — перетащите на дату`;
            item.textContent = `${toShortDate(shift.date)} • ${shift.qty}`;
            bindDebtShiftDrag(item, state);
            debtExpandPopoverInner.appendChild(item);
        });
    }

    function openDebtExpandPopover(debtCell, state) {
        if (!debtCell || !state || getDebtShiftsForKey(state.rowKey).length === 0) {
            closeDebtExpandPopover();
            return;
        }
        debtPopoverAnchorCell = debtCell;
        fillDebtPopoverShifts(debtCell, state);
        if (debtExpandPopover) {
            debtExpandPopover.hidden = false;
            positionDebtExpandPopover(debtCell);
            window.requestAnimationFrame(() => positionDebtExpandPopover(debtCell));
        }
    }

    function renderDebtCellByKey(rowKey) {
        const state = rowStateMap.get(rowKey);
        if (!state) {
            return;
        }
        const debtCell = state.row.querySelector('td.debt-cell');
        if (!debtCell) {
            return;
        }
        const list = debtCell.querySelector('.debt-list');
        if (!list) {
            return;
        }
        const shifts = getDebtShiftsForKey(rowKey);
        const total = shifts.length;
        const debtQty = shifts.reduce((s, it) => s + it.qty, 0);
        const overdueQty = getOverdueDebtQtyForRow(rowKey);
        state.row.dataset.debtQty = String(debtQty);
        debtCell.classList.toggle('debt-cell--warn', total > DEBT_COMPACT_VISIBLE);
        debtCell.title = total > 0 ? `Долг: ${debtQty} шт в ${total} смен(ах). Перетащите на дату в плане.` : '';
        state.row.classList.toggle('has-overdue-debt', overdueQty > 0);
        list.innerHTML = '';
        shifts.slice(0, DEBT_COMPACT_VISIBLE).forEach((shift) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'debt-shift';
            item.draggable = true;
            item.dataset.date = shift.date;
            item.dataset.qty = String(shift.qty);
            item.title = `Долг ${toShortDate(shift.date)}: ${shift.qty} шт`;
            item.textContent = String(shift.qty);
            bindDebtShiftDrag(item, state);
            list.appendChild(item);
        });
        const hidden = total - DEBT_COMPACT_VISIBLE;
        if (hidden > 0) {
            const moreBtn = document.createElement('button');
            moreBtn.type = 'button';
            moreBtn.className = 'debt-more';
            moreBtn.textContent = `+${hidden}`;
            moreBtn.title = `Ещё ${hidden} — открыть список`;
            moreBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                openDebtExpandPopover(debtCell, state);
            });
            list.appendChild(moreBtn);
        }
        if (debtPopoverAnchorCell === debtCell) {
            fillDebtPopoverShifts(debtCell, state);
            positionDebtExpandPopover(debtCell);
        }
    }

    function adjustDebtForPlanDelta(state, deltaPlan, fallbackDate) {
        if (!state || !deltaPlan) {
            return;
        }
        const rowKey = state.rowKey;
        const shifts = getDebtShiftsForKey(rowKey);
        const next = [];
        if (deltaPlan > 0) {
            let consume = deltaPlan;
            shifts.forEach((item) => {
                const qty = item.qty;
                if (qty <= 0) {
                    return;
                }
                if (consume <= 0) {
                    next.push({ date: item.date, qty });
                    return;
                }
                const take = Math.min(qty, consume);
                consume -= take;
                const left = qty - take;
                if (left > 0) {
                    next.push({ date: item.date, qty: left });
                }
            });
        } else {
            const addQty = Math.abs(deltaPlan);
            const dateRef = fallbackDate || getTodayIso();
            let merged = false;
            shifts.forEach((item) => {
                const qty = item.qty;
                if (qty <= 0) {
                    return;
                }
                if (!merged && item.date === dateRef) {
                    next.push({ date: item.date, qty: qty + addQty });
                    merged = true;
                } else {
                    next.push({ date: item.date, qty });
                }
            });
            if (!merged && addQty > 0) {
                next.push({ date: dateRef, qty: addQty });
            }
        }
        setDebtShiftsForKey(rowKey, next);
        renderDebtCellByKey(rowKey);
    }

    function applyDebtChange(debtChange, direction) {
        const rowKey = debtChange.rowKey || '';
        const shift = debtChange.shift || null;
        const debtMoved = Math.max(0, parseInt(debtChange.movedQty || 0, 10) || 0);
        if (!rowKey || !shift || !shift.date || debtMoved <= 0) {
            return;
        }
        const shifts = getDebtShiftsForKey(rowKey);
        const dateRef = shift.date;
        if (direction === 'forward') {
            let remaining = debtMoved;
            const next = [];
            shifts.forEach((item) => {
                if (remaining > 0 && item.date === dateRef) {
                    const take = Math.min(item.qty, remaining);
                    remaining -= take;
                    const left = item.qty - take;
                    if (left > 0) {
                        next.push({ date: item.date, qty: left });
                    }
                } else {
                    next.push({ date: item.date, qty: item.qty });
                }
            });
            setDebtShiftsForKey(rowKey, next);
        } else {
            let merged = false;
            const next = shifts.map((item) => {
                if (item.date === dateRef) {
                    merged = true;
                    return { date: item.date, qty: item.qty + debtMoved };
                }
                return { date: item.date, qty: item.qty };
            });
            if (!merged) {
                next.push({ date: dateRef, qty: debtMoved });
            }
            setDebtShiftsForKey(rowKey, next);
        }
        renderDebtCellByKey(rowKey);
    }

    function canDropDebtOnCell(cell, state) {
        if (!dragContext || dragContext.sourceType !== 'debt' || !cell || !state) {
            return false;
        }
        if (dragContext.rowKey !== state.rowKey) {
            return false;
        }
        const toDate = String(cell.dataset.date || '');
        if (!toDate || toDate < getTodayIso()) {
            return false;
        }
        const targetQty = parseInt(cell.dataset.gofroQty || '0', 10) || 0;
        return targetQty <= 0;
    }

    function queueDebtMove(state, fromDate, toDate, qty) {
        const movedQty = Math.max(0, parseInt(qty, 10) || 0);
        if (movedQty <= 0) {
            return;
        }
        const move = {
            label: `[Долг] ${formatDateRu(fromDate)} → ${formatDateRu(toDate)}, ${movedQty} шт`,
            beforeQty: 0,
            payload: {
                mode: 'debt',
                source_row_key: state.rowKey,
                order_number: state.order,
                filter_name: state.filterName,
                package_key: state.packageKey,
                package_name: state.packageName,
                from_date: fromDate,
                to_date: toDate,
                plan_date: toDate,
                qty: movedQty,
            },
            debtChange: {
                rowKey: state.rowKey,
                shift: { date: fromDate, qty: movedQty },
                movedQty,
            },
        };
        pendingMoves.push(move);
        applyMoveLocally(move);
        refreshPendingUi();
    }

    function setApplyStatus(text, isError) {
        if (!applyStatus) {
            return;
        }
        applyStatus.textContent = text || '';
        applyStatus.style.color = isError ? '#b91c1c' : '#64748b';
    }

    function syncPlanQty(state, date, qty) {
        const d = String(date || '');
        if (!d || !state) {
            return;
        }
        const nextQty = Math.max(0, parseInt(qty, 10) || 0);
        if (!state.planByDate) {
            state.planByDate = {};
        }
        if (nextQty > 0) {
            state.planByDate[d] = nextQty;
            if (d >= getTodayIso()) {
                state.allocatedByDate[d] = nextQty;
            }
        } else {
            delete state.planByDate[d];
            delete state.allocatedByDate[d];
        }
    }

    Array.from(document.querySelectorAll('tbody tr[data-row-key]')).forEach((row) => {
        const rowKey = String(row.dataset.rowKey || '');
        const planByDate = Object.assign({}, initialGofroPlanMap[rowKey] || {});
        const allocatedByDate = {};
        row.querySelectorAll('td.date-cell[data-date]').forEach((cell) => {
            const d = String(cell.dataset.date || '');
            const q = parseInt(cell.dataset.gofroQty || '0', 10) || 0;
            if (d && q > 0) {
                planByDate[d] = q;
                allocatedByDate[d] = q;
            }
        });
        const state = {
            row,
            rowKey,
            order: String(row.dataset.order || ''),
            filterName: String(row.dataset.filterName || ''),
            packageKey: String(row.dataset.packageKey || ''),
            packageName: String(row.dataset.packageName || ''),
            qtyPerHalfRoll: Math.max(0, parseInt(row.dataset.qtyPerHalfRoll || '0', 10) || 0),
            packagesPerRoll: Math.max(0, parseInt(row.dataset.packagesPerRoll || '0', 10) || 0),
            baseAvailable: parseInt(row.dataset.baseAvailable || '0', 10) || 0,
            gofroNeed: Math.max(0, parseInt(row.dataset.gofroNeed || '0', 10) || 0),
            machineType: String(row.dataset.machineType || 'knife'),
            rowHint: String(row.dataset.rowHint || ''),
            filterCell: row.querySelector('td.filter-name-cell'),
            dateCells: Array.from(row.querySelectorAll('td.date-cell[data-date]')),
            allocatedByDate,
            planByDate,
            overdueDebtQty: 0,
            overduePlannedQty: 0,
        };
        rowStateMap.set(rowKey, state);
    });

    function applyFrozenColumns() {
        const table = planTable;
        if (!table) {
            return;
        }
        const headRow = table.querySelector('thead tr');
        if (!headRow) {
            return;
        }
        const cols = Array.from(headRow.children);
        let left = 0;
        table.classList.add('has-frozen-cols');
        cols.forEach((th, index) => {
            const isFrozen = index < FROZEN_COL_COUNT;
            const selector = `tr > *:nth-child(${index + 1})`;
            table.querySelectorAll(selector).forEach((cell) => {
                if (isFrozen) {
                    cell.classList.add('frozen-col');
                    cell.style.setProperty('--sticky-left', `${left}px`);
                    cell.classList.toggle('frozen-col--last', index === FROZEN_COL_COUNT - 1);
                } else {
                    cell.classList.remove('frozen-col', 'frozen-col--last');
                    cell.style.removeProperty('--sticky-left');
                }
            });
            if (isFrozen) {
                left += th.getBoundingClientRect().width;
            }
        });
    }

    function refreshCellDom(cell, gofroQty) {
        const planQty = parseInt(cell.dataset.planQty || '0', 10) || 0;
        const qty = Math.max(0, parseInt(gofroQty, 10) || 0);
        cell.dataset.gofroQty = String(qty);
        let hint = cell.querySelector('.cell-plan-hint');
        if (planQty > 0) {
            if (!hint) {
                hint = document.createElement('span');
                hint.className = 'cell-plan-hint';
                cell.insertBefore(hint, cell.firstChild);
            }
            hint.textContent = String(planQty);
        } else if (hint) {
            hint.remove();
        }
        cell.querySelectorAll('.cell-gofro-qty').forEach((el) => el.remove());
        const textNodes = [];
        cell.childNodes.forEach((n) => {
            if (n.nodeType === Node.TEXT_NODE && String(n.textContent || '').trim() !== '') {
                textNodes.push(n);
            }
        });
        textNodes.forEach((n) => n.remove());
        if (qty > 0) {
            const main = document.createElement('span');
            main.className = 'cell-gofro-qty';
            main.textContent = String(qty);
            cell.appendChild(main);
        }
    }

    function updateCoverage() {
        const dailyTotals = {};
        rowStateMap.forEach((state) => {
            const todayIso = getTodayIso();
            Object.keys(state.allocatedByDate).forEach((d) => {
                const qty = parseInt(state.allocatedByDate[d] || 0, 10) || 0;
                if (qty > 0 && d >= todayIso) {
                    if (!dailyTotals[d]) {
                        dailyTotals[d] = { total: 0, rotary: 0, knife: 0 };
                    }
                    dailyTotals[d].total += qty;
                    if (state.machineType === 'rotary') {
                        dailyTotals[d].rotary += qty;
                    } else {
                        dailyTotals[d].knife += qty;
                    }
                }
            });
            reconcileDebtForRow(state);
            renderDebtCellByKey(state.rowKey);

            state.dateCells.forEach((cell) => {
                const gofroQty = parseInt(state.allocatedByDate[cell.dataset.date || ''] || 0, 10) || 0;
                refreshCellDom(cell, gofroQty);
            });
            applyGofroCoverageForRow(state);
        });

        Object.keys(dateHeaderTotals).forEach((date) => {
            const totals = dailyTotals[date] || { total: 0, rotary: 0, knife: 0 };
            const entry = dateHeaderTotals[date];
            if (!entry) {
                return;
            }
            entry.knifeEls.forEach((el) => {
                el.textContent = String(totals.knife);
                el.title = `Ножевая: ${totals.knife} шт.`;
            });
            entry.rotaryEls.forEach((el) => {
                el.textContent = String(totals.rotary);
                el.title = `Ротационная: ${totals.rotary} шт.`;
            });
            entry.ths.forEach((th) => {
                th.title = `Суммарно г/п на дату: ${totals.total} шт.`;
            });
        });
        refreshOverdueAlertModal();
        markPendingCells();
    }

    function markPendingCells() {
        planTable.querySelectorAll('td.date-cell.queue-pending').forEach((c) => c.classList.remove('queue-pending'));
        pendingMoves.forEach((move) => {
            const rowKey = move.payload.source_row_key;
            const date = move.payload.to_date || move.payload.plan_date;
            const state = rowStateMap.get(rowKey);
            if (!state) {
                return;
            }
            const cell = state.dateCells.find((c) => String(c.dataset.date) === date);
            if (cell) {
                cell.classList.add('queue-pending');
            }
        });
    }

    function buildMovePayload(state, date, mode, qty) {
        return {
            source_row_key: state.rowKey,
            order_number: state.order,
            filter_name: state.filterName,
            package_key: state.packageKey,
            package_name: state.packageName,
            plan_date: date,
            from_date: date,
            to_date: date,
            mode,
            qty,
        };
    }

    function applyMoveLocally(move) {
        const state = rowStateMap.get(move.payload.source_row_key);
        if (!state) {
            return;
        }
        if (move.debtChange) {
            applyDebtChange(move.debtChange, 'forward');
            const fromDate = String(move.debtChange.shift.date || '');
            const toDate = String(move.payload.to_date || move.payload.plan_date || '');
            const qty = Math.max(0, parseInt(move.payload.qty, 10) || 0);
            const movedQty = Math.max(0, parseInt(move.debtChange.movedQty, 10) || 0);
            if (fromDate && movedQty > 0 && state.planByDate[fromDate] != null) {
                const left = (parseInt(state.planByDate[fromDate], 10) || 0) - movedQty;
                if (left > 0) {
                    state.planByDate[fromDate] = left;
                } else {
                    delete state.planByDate[fromDate];
                }
            }
            if (toDate && qty > 0) {
                state.planByDate[toDate] = qty;
                state.allocatedByDate[toDate] = qty;
            }
            updateCoverage();
            return;
        }
        const date = move.payload.plan_date;
        if (move.payload.mode === 'clear_cell') {
            delete state.allocatedByDate[date];
            delete state.planByDate[date];
        } else {
            const nextQty = Math.max(0, parseInt(move.payload.qty, 10) || 0);
            if (nextQty > 0) {
                state.planByDate[date] = nextQty;
                state.allocatedByDate[date] = nextQty;
            } else {
                delete state.planByDate[date];
                delete state.allocatedByDate[date];
            }
        }
        updateCoverage();
    }

    function revertMoveLocally(move) {
        const state = rowStateMap.get(move.payload.source_row_key);
        if (!state) {
            return;
        }
        if (move.debtChange) {
            applyDebtChange(move.debtChange, 'backward');
            const fromDate = String(move.debtChange.shift.date || '');
            const toDate = String(move.payload.to_date || move.payload.plan_date || '');
            const movedQty = Math.max(0, parseInt(move.debtChange.movedQty, 10) || 0);
            delete state.allocatedByDate[toDate];
            delete state.planByDate[toDate];
            if (fromDate && movedQty > 0) {
                state.planByDate[fromDate] = (parseInt(state.planByDate[fromDate], 10) || 0) + movedQty;
            }
            updateCoverage();
            return;
        }
        const date = move.payload.plan_date;
        if (move.beforeQty > 0) {
            state.planByDate[date] = move.beforeQty;
            state.allocatedByDate[date] = move.beforeQty;
        } else {
            delete state.planByDate[date];
            delete state.allocatedByDate[date];
        }
        updateCoverage();
    }

    function queueSetCell(state, date, qty) {
        const beforeQty = parseInt(state.allocatedByDate[date] || 0, 10) || 0;
        const nextQty = Math.max(0, parseInt(qty, 10) || 0);
        if (beforeQty === nextQty) {
            return;
        }
        const mode = nextQty > 0 ? 'set_qty' : 'clear_cell';
        const move = {
            label: `${state.filterName} / ${formatDateRu(date)}: ${beforeQty || '—'} → ${nextQty || '—'} шт`,
            beforeQty,
            payload: buildMovePayload(state, date, mode, nextQty),
        };
        pendingMoves.push(move);
        applyMoveLocally(move);
        const delta = nextQty - beforeQty;
        if (delta !== 0) {
            adjustDebtForPlanDelta(state, delta, date);
        }
        refreshPendingUi();
    }

    function refreshPendingUi() {
        const count = pendingMoves.length;
        if (pendingMovesText) {
            pendingMovesText.textContent = `Изменений в очереди: ${count}`;
        }
        const disabled = count === 0 || isApplyingPendingMoves;
        [applyPendingMovesBtn, applyPendingMovesPanelBtn, undoPendingMoveBtn, clearPendingMovesBtn].forEach((btn) => {
            if (btn) {
                btn.disabled = disabled;
            }
        });
        if (applyPendingMovesBtn) {
            applyPendingMovesBtn.textContent = isApplyingPendingMoves ? 'Применение...' : (count > 0 ? `Применить (${count})` : 'Применить');
        }
        if (applyPendingMovesPanelBtn) {
            applyPendingMovesPanelBtn.textContent = isApplyingPendingMoves ? 'Применение...' : (count > 0 ? `Применить (${count})` : 'Применить');
        }
        if (moveQueueEmpty && moveQueueList) {
            moveQueueEmpty.hidden = count > 0;
            moveQueueList.innerHTML = '';
            pendingMoves.forEach((move, idx) => {
                const li = document.createElement('li');
                li.className = 'queue-item';
                li.textContent = `${idx + 1}. ${move.label}`;
                moveQueueList.appendChild(li);
            });
        }
        markPendingCells();
    }

    async function applyPendingMovesToServer() {
        if (pendingMoves.length === 0 || isApplyingPendingMoves) {
            return;
        }
        isApplyingPendingMoves = true;
        refreshPendingUi();
        setApplyStatus('Сохраняем...', false);
        try {
            const res = await fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'apply_moves',
                    moves: pendingMoves.map((m) => m.payload),
                }),
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Ошибка сохранения');
            }
            pendingMoves.length = 0;
            refreshPendingUi();
            setApplyStatus(`Сохранено: ${data.applied || 0} операций`, false);
        } catch (err) {
            setApplyStatus(String(err.message || err), true);
        } finally {
            isApplyingPendingMoves = false;
            refreshPendingUi();
        }
    }

    function closeGofroCellPicker() {
        gofroCellPicker.hidden = true;
        pickerTarget = null;
    }

    function openGofroCellPicker(cell, state) {
        const date = String(cell.dataset.date || '');
        if (!date || date < getTodayIso()) {
            return;
        }
        pickerTarget = { cell, state, date };
        const half = state.qtyPerHalfRoll;
        const maxButtons = 6;
        let buttonsHtml = '';
        if (half > 0) {
            for (let k = 1; k <= maxButtons; k += 1) {
                const qty = k * half;
                buttonsHtml += `<button type="button" class="gofro-pick-btn" data-qty="${qty}"><strong>×${k}</strong>${qty} шт</button>`;
            }
        }
        const warn = half <= 0
            ? `<p class="gofro-cell-picker__warn">${htmlEscape(state.rowHint || 'Не рассчитан полурулон — введите количество вручную.')}</p>`
            : '';
        gofroCellPicker.innerHTML = `
            <p class="gofro-cell-picker__title">${htmlEscape(state.filterName)}<br>${htmlEscape(formatDateRu(date))}</p>
            ${warn}
            <div class="gofro-cell-picker__grid">${buttonsHtml}</div>
            <div class="gofro-cell-picker__actions">
                <button type="button" data-action="clear">Очистить</button>
                <button type="button" data-action="custom">Другое…</button>
            </div>
        `;
        const pickerGrid = gofroCellPicker.querySelector('.gofro-cell-picker__grid');
        if (pickerGrid) {
            pickerGrid.querySelectorAll('.gofro-pick-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    queueSetCell(state, date, parseInt(btn.dataset.qty || '0', 10));
                    closeGofroCellPicker();
                });
            });
        }
        gofroCellPicker.querySelector('[data-action="clear"]')?.addEventListener('click', () => {
            queueSetCell(state, date, 0);
            closeGofroCellPicker();
        });
        gofroCellPicker.querySelector('[data-action="custom"]')?.addEventListener('click', () => {
            const raw = window.prompt('Количество г/п (шт):', String(parseInt(state.allocatedByDate[date] || '0', 10) || ''));
            if (raw === null) {
                return;
            }
            const val = parseInt(String(raw).trim(), 10);
            if (Number.isNaN(val) || val < 0) {
                alert('Введите неотрицательное целое число.');
                return;
            }
            queueSetCell(state, date, val);
            closeGofroCellPicker();
        });
        gofroCellPicker.hidden = false;
        const rect = cell.getBoundingClientRect();
        let x = rect.left;
        let y = rect.bottom + 4;
        gofroCellPicker.style.left = `${Math.round(x)}px`;
        gofroCellPicker.style.top = `${Math.round(y)}px`;
        const pr = gofroCellPicker.getBoundingClientRect();
        const vw = window.innerWidth || 800;
        const vh = window.innerHeight || 600;
        if (pr.right > vw - 8) {
            x = Math.max(8, vw - pr.width - 8);
            gofroCellPicker.style.left = `${Math.round(x)}px`;
        }
        if (pr.bottom > vh - 8) {
            y = Math.max(8, rect.top - pr.height - 4);
            gofroCellPicker.style.top = `${Math.round(y)}px`;
        }
    }

    /** Н — ножевая, Р — ротационная машина гофрирования. */
    function gofroMachineLabel(machineType) {
        return String(machineType || '') === 'rotary' ? 'Р' : 'Н';
    }

    /** Одно числовое поле ребра (высота или количество), без объединения. */
    function formatRibNumber(n) {
        const val = Number(n);
        if (!Number.isFinite(val) || val <= 0) {
            return '—';
        }
        const s = String(val);
        return s.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    }

    function formatWidthMm(w) {
        const n = Number(w);
        if (!Number.isFinite(n) || n <= 0) {
            return '—';
        }
        const s = String(Math.round(n * 100) / 100);
        return s.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    }

    /** Убирает из названия слово «гофропакет» (в типичных падежах) для колонки печати. */
    function stripGofropaketWordForPrint(name) {
        const raw = String(name || '').trim();
        if (!raw) {
            return '';
        }
        const word = /гофропакет(?:а|ов|ы|у|ом|е|ами|ам|ах)?/gi;
        const s = raw.replace(word, ' ').replace(/\s{2,}/g, ' ').trim();
        return s || raw;
    }

    function collectTaskSheetRows(dateFrom, dateTo) {
        const rowsOut = [];
        rowStateMap.forEach((state) => {
            Object.keys(state.allocatedByDate).forEach((date) => {
                if (date < dateFrom || date > dateTo) {
                    return;
                }
                const qty = parseInt(state.allocatedByDate[date] || 0, 10) || 0;
                if (qty <= 0) {
                    return;
                }
                rowsOut.push({
                    date,
                    order: state.order,
                    filter: state.filterName,
                    qty,
                    paperWidthMm: parseFloat(state.row.dataset.paperWidthMm || 0) || 0,
                    foldHeight: parseFloat(state.row.dataset.foldHeight || 0) || 0,
                    foldCount: parseFloat(state.row.dataset.foldCount || 0) || 0,
                    packageName: state.packageName,
                    machineType: state.machineType,
                });
            });
        });
        rowsOut.sort((a, b) => {
            if (a.date !== b.date) {
                return a.date.localeCompare(b.date);
            }
            if (a.order !== b.order) {
                return a.order.localeCompare(b.order, 'ru');
            }
            return a.filter.localeCompare(b.filter, 'ru');
        });
        return rowsOut;
    }

    function syncTaskPrintModeUi() {
        const isDay = taskPrintModeDay && taskPrintModeDay.checked;
        if (taskPeriodDates) {
            taskPeriodDates.style.display = isDay ? 'none' : 'grid';
        }
        if (taskDayDateWrap) {
            taskDayDateWrap.style.display = isDay ? 'block' : 'none';
        }
    }

    function openTaskSheetModal() {
        if (!taskSheetModal || !taskDateFrom || !taskDateTo) {
            return;
        }
        const todayIso = getTodayIso();
        taskDateFrom.value = taskDateFrom.value || todayIso;
        taskDateTo.value = taskDateTo.value || addCalendarDaysIso(todayIso, 6);
        if (taskDateOne) {
            taskDateOne.value = taskDateOne.value || todayIso;
        }
        if (taskPrintModePeriod) {
            taskPrintModePeriod.checked = true;
        }
        if (taskPrintModeDay) {
            taskPrintModeDay.checked = false;
        }
        syncTaskPrintModeUi();
        taskSheetModal.style.display = 'flex';
    }

    function closeTaskSheetModal() {
        if (taskSheetModal) {
            taskSheetModal.style.display = 'none';
        }
    }

    function printTaskSheet(mode, dateFrom, dateTo) {
        const rowsForPrint = collectTaskSheetRows(dateFrom, dateTo);
        const totalQty = rowsForPrint.reduce((acc, row) => acc + row.qty, 0);
        const isDay = mode === 'day';
        let printHtml;
        if (isDay) {
            const dayStr = formatDateRu(dateFrom);
            const bodyRows = rowsForPrint.length > 0
                ? rowsForPrint.map((row) => `
                <tr>
                    <td>${htmlEscape(row.order)}</td>
                    <td>${htmlEscape(stripGofropaketWordForPrint(row.packageName))}</td>
                    <td style="text-align:center;">${htmlEscape(gofroMachineLabel(row.machineType))}</td>
                    <td style="text-align:right;">${htmlEscape(formatWidthMm(row.paperWidthMm))}</td>
                    <td style="text-align:right;">${htmlEscape(formatRibNumber(row.foldHeight))}</td>
                    <td style="text-align:right;">${htmlEscape(formatRibNumber(row.foldCount))}</td>
                    <td style="text-align:right;">${row.qty}</td>
                    <td></td>
                </tr>`).join('')
                : '<tr><td colspan="8" style="text-align:center;color:#64748b;">Нет распланированных позиций на эту дату</td></tr>';
            printHtml = `<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Сменной наряд</title>
            <style>
                @page { size: A4 portrait; margin: 12mm; }
                body { font: 12px Arial, sans-serif; margin: 0; color: #0f172a; }
                h1 { font-size: 16px; margin: 0 0 8px; }
                .meta { margin: 0 0 12px; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #94a3b8; padding: 5px 6px; vertical-align: top; }
                th { background: #f1f5f9; font-weight: 700; font-size: 11px; }
                td { font-size: 11px; }
                .sign { margin-top: 22px; font-size: 11px; line-height: 1.85; color: #334155; }
            </style>
            </head><body>
            <h1>Сменной наряд на сборку гофропакетов</h1>
            <p class="meta">Дата: <strong>${htmlEscape(dayStr)}</strong>. По плану г/п: <strong>${totalQty}</strong> шт.</p>
            <table>
            <thead><tr>
                <th>Заявка</th><th>Гофропакет</th><th>Н/Р</th><th>Шир., мм</th>
                <th>h ребра</th><th>Кол-во ребер</th>
                <th>План, шт</th><th>Примечание</th>
            </tr></thead>
            <tbody>${bodyRows}</tbody>
            </table>
            <div class="sign">
                <p>Выдал задание _____________________________ / _______________</p>
                <p>Приняла старшая смены ____________________ / _______________</p>
                <p>Задание выполнено, сдало смена _____________ / _______________ &nbsp; дата __________</p>
            </div>
            </body></html>`;
        } else {
            const bodyRows = rowsForPrint.length > 0
                ? rowsForPrint.map((row) => `
                <tr>
                    <td>${htmlEscape(formatDateRu(row.date))}</td>
                    <td>${htmlEscape(row.order)}</td>
                    <td>${htmlEscape(stripGofropaketWordForPrint(row.packageName))}</td>
                    <td style="text-align:center;">${htmlEscape(gofroMachineLabel(row.machineType))}</td>
                    <td style="text-align:right;">${htmlEscape(formatWidthMm(row.paperWidthMm))}</td>
                    <td style="text-align:right;">${htmlEscape(formatRibNumber(row.foldHeight))}</td>
                    <td style="text-align:right;">${htmlEscape(formatRibNumber(row.foldCount))}</td>
                    <td style="text-align:right;">${row.qty}</td>
                    <td></td>
                </tr>`).join('')
                : '<tr><td colspan="9" style="text-align:center;color:#64748b;">Нет распланированных позиций за выбранный период</td></tr>';
            printHtml = `<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Ведомость задания</title>
            <style>
                @page { size: A4 portrait; margin: 10mm; }
                body { font: 11px Arial, sans-serif; margin: 0; color: #0f172a; }
                h1 { font-size: 15px; margin: 0 0 8px; }
                .meta { margin: 0 0 10px; font-size: 11px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #94a3b8; padding: 4px 5px; vertical-align: top; }
                th { background: #f1f5f9; font-weight: 700; font-size: 10px; }
                td { font-size: 10px; }
            </style>
            </head><body>
            <h1>Ведомость задания сборщицам (гофропакеты)</h1>
            <p class="meta">Период: ${htmlEscape(formatDateRu(dateFrom))} — ${htmlEscape(formatDateRu(dateTo))}. Всего г/п: <strong>${totalQty}</strong> шт.</p>
            <table>
            <thead><tr>
                <th>Дата</th><th>Заявка</th><th>Гофропакет</th><th>Н/Р</th><th>Шир., мм</th>
                <th>h ребра</th><th>Кол-во ребер</th>
                <th>План, шт</th><th>Примечание</th>
            </tr></thead>
            <tbody>${bodyRows}</tbody>
            </table>
            </body></html>`;
        }
        let frame = document.getElementById('task-sheet-print-frame');
        if (!frame) {
            frame = document.createElement('iframe');
            frame.id = 'task-sheet-print-frame';
            frame.style.cssText = 'position:fixed;width:0;height:0;border:0';
            document.body.appendChild(frame);
        }
        const doc = frame.contentWindow.document;
        doc.open();
        doc.write(printHtml);
        doc.close();
        setTimeout(() => {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        }, 100);
    }

    function refreshOverdueAlertModal() {
        if (!overdueAlertBtn || !overdueAlertBody) {
            return;
        }
        const rowsList = [];
        rowStateMap.forEach((state) => {
            const debt = getOverdueDebtQtyForRow(state.rowKey);
            if (debt <= 0) {
                return;
            }
            rowsList.push({
                order: state.order,
                filter: state.filterName,
                debt,
            });
        });
        if (rowsList.length === 0) {
            overdueModalAutoOpened = false;
            overdueAlertBtn.style.display = 'none';
            return;
        }
        overdueAlertBtn.style.display = 'inline-block';
        overdueAlertBtn.textContent = `Просрочено (${rowsList.length})`;
        overdueAlertBody.innerHTML = `<table style="width:100%;font-size:12px;border-collapse:collapse">
            <tr><th style="text-align:left;border:1px solid #e2e8f0;padding:6px">Заявка</th>
            <th style="text-align:left;border:1px solid #e2e8f0;padding:6px">Фильтр</th>
            <th style="text-align:right;border:1px solid #e2e8f0;padding:6px">Долг</th></tr>
            ${rowsList.map((r) => `<tr><td style="border:1px solid #e2e8f0;padding:6px">${htmlEscape(r.order)}</td>
            <td style="border:1px solid #e2e8f0;padding:6px">${htmlEscape(r.filter)}</td>
            <td style="border:1px solid #e2e8f0;padding:6px;text-align:right;font-weight:700">${r.debt}</td></tr>`).join('')}
            </table>`;
        if (!overdueModalAutoOpened) {
            overdueModalAutoOpened = true;
            if (overdueAlertModal) {
                overdueAlertModal.style.display = 'flex';
            }
        }
    }

    planTable.querySelectorAll('td.date-cell[data-date]').forEach((cell) => {
        bindDateCellInteractions(cell);
    });

    document.addEventListener('click', (e) => {
        if (!gofroCellPicker.hidden && !gofroCellPicker.contains(e.target)) {
            const onCell = e.target.closest('td.date-cell');
            if (!onCell) {
                closeGofroCellPicker();
            }
        }
        if (debtExpandPopover && !debtExpandPopover.hidden) {
            const inPopover = debtExpandPopover.contains(e.target);
            const onDebtCell = e.target.closest('td.debt-cell');
            if (!inPopover && !onDebtCell) {
                closeDebtExpandPopover();
            }
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
            return;
        }
        if (!gofroCellPicker.hidden) {
            e.preventDefault();
            closeGofroCellPicker();
        }
    });

    if (applyPendingMovesBtn) {
        applyPendingMovesBtn.addEventListener('click', applyPendingMovesToServer);
    }
    if (applyPendingMovesPanelBtn) {
        applyPendingMovesPanelBtn.addEventListener('click', applyPendingMovesToServer);
    }
    if (undoPendingMoveBtn) {
        undoPendingMoveBtn.addEventListener('click', () => {
            const last = pendingMoves.pop();
            if (last) {
                revertMoveLocally(last);
                refreshPendingUi();
            }
        });
    }
    if (clearPendingMovesBtn) {
        clearPendingMovesBtn.addEventListener('click', () => {
            while (pendingMoves.length > 0) {
                revertMoveLocally(pendingMoves.pop());
            }
            refreshPendingUi();
        });
    }
    if (toggleQueuePanelBtn && moveQueuePanel) {
        toggleQueuePanelBtn.addEventListener('click', () => {
            const open = moveQueuePanel.hidden;
            moveQueuePanel.hidden = !open;
            toggleQueuePanelBtn.setAttribute('aria-pressed', open ? 'true' : 'false');
        });
    }
    if (closeQueuePanelBtn && moveQueuePanel) {
        closeQueuePanelBtn.addEventListener('click', () => {
            moveQueuePanel.hidden = true;
            if (toggleQueuePanelBtn) {
                toggleQueuePanelBtn.setAttribute('aria-pressed', 'false');
            }
        });
    }
    if (addPlanDayBtn) {
        addPlanDayBtn.addEventListener('click', () => {
            if (isApplyingPendingMoves) {
                return;
            }
            addPlanDayColumn();
        });
    }
    if (toggleGofroCoverageBtn) {
        setGofroCoverageVisible(isGofroCoverageVisible);
        toggleGofroCoverageBtn.addEventListener('click', () => {
            setGofroCoverageVisible(!isGofroCoverageVisible);
        });
    }
    if (toggleFilterPlanDigitsBtn) {
        setFilterPlanDigitsVisible(isFilterPlanDigitsVisible);
        toggleFilterPlanDigitsBtn.addEventListener('click', () => {
            setFilterPlanDigitsVisible(!isFilterPlanDigitsVisible);
        });
    }
    try {
        const storedFoldHeightPanel = localStorage.getItem(FOLD_HEIGHT_PANEL_OPEN_STORAGE_KEY);
        if (storedFoldHeightPanel === '1') {
            setFoldHeightPanelOpen(true);
        }
    } catch (_) { /* ignore */ }
    if (toggleFoldHeightPanelBtn) {
        toggleFoldHeightPanelBtn.addEventListener('click', () => {
            setFoldHeightPanelOpen(!isFoldHeightPanelOpen);
        });
    }
    if (foldHeightClearBtn) {
        foldHeightClearBtn.addEventListener('click', clearFoldHeightFilter);
    }
    if (taskSheetBtn) {
        taskSheetBtn.addEventListener('click', openTaskSheetModal);
    }
    if (taskSheetCancel) {
        taskSheetCancel.addEventListener('click', closeTaskSheetModal);
    }
    if (taskPrintModePeriod) {
        taskPrintModePeriod.addEventListener('change', syncTaskPrintModeUi);
    }
    if (taskPrintModeDay) {
        taskPrintModeDay.addEventListener('change', syncTaskPrintModeUi);
    }
    if (taskSheetPrint) {
        taskSheetPrint.addEventListener('click', () => {
            const modeEl = document.querySelector('input[name="task-print-mode"]:checked');
            const mode = modeEl && modeEl.value === 'day' ? 'day' : 'period';
            if (mode === 'day') {
                const d = taskDateOne ? taskDateOne.value : '';
                if (!d) {
                    alert('Укажите дату наряда.');
                    return;
                }
                closeTaskSheetModal();
                printTaskSheet('day', d, d);
                return;
            }
            const df = taskDateFrom ? taskDateFrom.value : '';
            const dt = taskDateTo ? taskDateTo.value : '';
            if (!df || !dt) {
                alert('Укажите период.');
                return;
            }
            if (df > dt) {
                alert('Дата «с» не может быть позже даты «по».');
                return;
            }
            closeTaskSheetModal();
            printTaskSheet('period', df, dt);
        });
    }
    if (overdueAlertBtn) {
        overdueAlertBtn.addEventListener('click', () => {
            if (overdueAlertModal) {
                overdueAlertModal.style.display = 'flex';
            }
        });
    }
    if (overdueAlertClose) {
        overdueAlertClose.addEventListener('click', () => {
            if (overdueAlertModal) {
                overdueAlertModal.style.display = 'none';
            }
        });
    }
    if (openMaxPctBtn) {
        updateMaxPctBtnLabel();
        openMaxPctBtn.addEventListener('click', openMaxPctModal);
    }
    if (maxPctCancelBtn) {
        maxPctCancelBtn.addEventListener('click', closeMaxPctModal);
    }
    if (maxPctModal) {
        maxPctModal.addEventListener('click', (e) => {
            if (e.target === maxPctModal) {
                closeMaxPctModal();
            }
        });
    }
    if (maxPctSaveBtn && maxPctInput) {
        maxPctSaveBtn.addEventListener('click', () => {
            const newPct = Math.max(0, Math.min(100, parseInt(maxPctInput.value, 10) || serverDefaultMaxPct));
            try {
                localStorage.setItem(MAX_PCT_STORAGE_KEY, String(newPct));
            } catch (_) { /* ignore */ }
            closeMaxPctModal();
            if (newPct !== currentPageMaxPct) {
                reloadWithMaxPct(newPct);
            }
        });
    }
    if (maxPctResetBtn) {
        maxPctResetBtn.addEventListener('click', () => {
            try {
                localStorage.removeItem(MAX_PCT_STORAGE_KEY);
            } catch (_) { /* ignore */ }
            closeMaxPctModal();
            if (currentPageMaxPct !== serverDefaultMaxPct) {
                reloadWithMaxPct(serverDefaultMaxPct);
            }
        });
    }
    if (openHiddenOrdersBtn) {
        openHiddenOrdersBtn.addEventListener('click', openHiddenOrdersModal);
    }
    if (hiddenOrdersCancelBtn) {
        hiddenOrdersCancelBtn.addEventListener('click', closeHiddenOrdersModal);
    }
    if (hiddenOrdersApplyBtn) {
        hiddenOrdersApplyBtn.addEventListener('click', () => {
            if (isApplyingPendingMoves) {
                return;
            }
            applyHiddenOrdersFromModal();
        });
    }
    if (hiddenOrdersShowAllBtn) {
        hiddenOrdersShowAllBtn.addEventListener('click', () => {
            if (isApplyingPendingMoves) {
                return;
            }
            showAllHiddenOrdersInTable();
        });
    }
    if (hiddenOrdersModal) {
        hiddenOrdersModal.addEventListener('click', (e) => {
            if (e.target === hiddenOrdersModal) {
                closeHiddenOrdersModal();
            }
        });
    }
    if (hiddenOrdersSearchInput) {
        hiddenOrdersSearchInput.addEventListener('input', filterHiddenOrdersModalList);
    }
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
            return;
        }
        if (activeFoldHeightKey !== null) {
            clearFoldHeightFilter();
            return;
        }
        if (hiddenOrdersModal && !hiddenOrdersModal.hidden) {
            closeHiddenOrdersModal();
            return;
        }
        if (maxPctModal && !maxPctModal.hidden) {
            closeMaxPctModal();
        }
    });

    pruneHiddenOrdersNotInTable();
    updateHiddenOrdersBadge();
    applyOrderRowVisibility();

    applyFrozenColumns();
    window.addEventListener('resize', applyFrozenColumns);
    rowStateMap.forEach((state, rowKey) => {
        renderDebtCellByKey(rowKey);
    });
    updateCoverage();
    refreshPendingUi();
})();

</script>
<?php endif; ?>
</body>
</html>
