<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

$order = (string)($_GET['order'] ?? $_POST['order'] ?? '');
$isAjaxSave = ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save') && !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
if ($order === '') {
    if ($isAjaxSave) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Укажите номер заявки']);
        exit;
    }
    http_response_code(400);
    exit('Укажите номер заявки: ?order=...');
}
$startDateRaw = (string)($_GET['start_date'] ?? $_POST['start_date'] ?? '');
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
    $message = '';

    // Таблица плана порезки бухт (U3)
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

    // Совместимость со старой структурой roll_plans без колонки done
    $stCol = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'roll_plans'
          AND COLUMN_NAME = 'done'
    ");
    $hasDoneColumn = ((int)$stCol->fetchColumn() > 0);
    if (!$hasDoneColumn) {
        try {
            $pdo->exec("ALTER TABLE roll_plans ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
            $hasDoneColumn = true;
        } catch (Throwable $e) {
            // Если ALTER TABLE недоступен, продолжаем работу без done
            $hasDoneColumn = false;
        }
    }

    // Сохранение плана
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save')) {
        try {
            $planDates = $_POST['plan_dates'] ?? [];
            if (!is_array($planDates)) {
                $planDates = [];
            }

            $pdo->beginTransaction();

            // Выполненные бухты не перезаписываем
            $doneRows = [];
            if ($hasDoneColumn) {
                $stDone = $pdo->prepare("SELECT bale_id, work_date FROM roll_plans WHERE order_number = ? AND done = 1");
                $stDone->execute([$order]);
                while ($row = $stDone->fetch(PDO::FETCH_ASSOC)) {
                    $doneRows[(int)$row['bale_id']] = $row['work_date'];
                }
            }

            // Удаляем только незавершенные назначения и записываем заново
            if ($hasDoneColumn) {
                $pdo->prepare("DELETE FROM roll_plans WHERE order_number = ? AND (done IS NULL OR done = 0)")->execute([$order]);
                $ins = $pdo->prepare("
                    INSERT INTO roll_plans (order_number, bale_id, work_date, done)
                    VALUES (?, ?, ?, 0)
                ");
            } else {
                $pdo->prepare("DELETE FROM roll_plans WHERE order_number = ?")->execute([$order]);
                $ins = $pdo->prepare("
                    INSERT INTO roll_plans (order_number, bale_id, work_date)
                    VALUES (?, ?, ?)
                ");
            }

            foreach ($planDates as $baleIdRaw => $dateRaw) {
                $baleId = (int)$baleIdRaw;
                $date = trim((string)$dateRaw);

                if ($baleId <= 0 || $date === '' || isset($doneRows[$baleId])) {
                    continue;
                }

                $dt = DateTime::createFromFormat('Y-m-d', $date);
                if (!$dt || $dt->format('Y-m-d') !== $date) {
                    continue;
                }

                $ins->execute([$order, $baleId, $date]);
            }

            // Признак готовности этапа "План порезки бухт"
            $stCnt = $pdo->prepare("SELECT COUNT(*) FROM roll_plans WHERE order_number = ?");
            $stCnt->execute([$order]);
            $plannedCount = (int)$stCnt->fetchColumn();

            try {
                $stUpd = $pdo->prepare("UPDATE orders SET plan_ready = ? WHERE order_number = ?");
                $stUpd->execute([$plannedCount > 0 ? 1 : 0, $order]);
            } catch (Throwable $e) {
                // Если поля нет, не блокируем сохранение плана
            }

            $pdo->commit();
            $message = 'План порезки бухт сохранен.';

            if ($isAjaxSave) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message]);
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($isAjaxSave) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => 'Ошибка сохранения: ' . $e->getMessage()]);
                exit;
            }
            throw $e;
        }
    }

    // План сборки (верхняя таблица)
    $stBuild = $pdo->prepare("
        SELECT day_date, filter, SUM(qty) AS qty
        FROM build_plans
        WHERE order_number = ? AND shift = 'D'
        GROUP BY day_date, filter
        ORDER BY day_date, filter
    ");
    $stBuild->execute([$order]);
    $buildRows = $stBuild->fetchAll(PDO::FETCH_ASSOC);
    $buildDatesMap = [];
    $buildFiltersMap = [];
    $buildMatrix = [];
    foreach ($buildRows as $row) {
        $date = (string)($row['day_date'] ?? '');
        $filter = trim((string)($row['filter'] ?? ''));
        $filterKey = normalizeFilterKey($filter);
        $qty = (int)($row['qty'] ?? 0);
        if ($date === '' || $filterKey === '') {
            continue;
        }
        $buildDatesMap[$date] = true;
        if (!isset($buildFiltersMap[$filterKey])) {
            $buildFiltersMap[$filterKey] = $filter;
        }
        if (!isset($buildMatrix[$filterKey])) {
            $buildMatrix[$filterKey] = [];
        }
        $buildMatrix[$filterKey][$date] = ($buildMatrix[$filterKey][$date] ?? 0) + $qty;
    }
    ksort($buildDatesMap);
    asort($buildFiltersMap, SORT_NATURAL | SORT_FLAG_CASE);
    $buildDates = array_keys($buildDatesMap);
    $buildFilterKeys = array_keys($buildFiltersMap);

    // Бухты по заявке (нижняя таблица)
    $stBales = $pdo->prepare("
        SELECT
            bale_id,
            GROUP_CONCAT(DISTINCT TRIM(filter) ORDER BY TRIM(filter) SEPARATOR ', ') AS filters,
            GROUP_CONCAT(DISTINCT TRIM(height) ORDER BY height SEPARATOR ', ') AS heights,
            COUNT(*) AS strips_count,
            SUM(COALESCE(NULLIF(fact_length, 0), length)) AS total_length
        FROM cut_plans
        WHERE order_number = ?
        GROUP BY bale_id
        ORDER BY bale_id
    ");
    $stBales->execute([$order]);
    $bales = $stBales->fetchAll(PDO::FETCH_ASSOC);

    $stBaleFilterLen = $pdo->prepare("
        SELECT
            bale_id,
            TRIM(filter) AS filter_name,
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
        if ($baleId <= 0 || $fkey === '' || $len <= 0) {
            continue;
        }
        if (!isset($baleFilterLen[$baleId])) {
            $baleFilterLen[$baleId] = [];
        }
        $baleFilterLen[$baleId][$fkey] = ($baleFilterLen[$baleId][$fkey] ?? 0) + $len;
        $totalLenByFilter[$fkey] = ($totalLenByFilter[$fkey] ?? 0) + $len;
    }

    $buildTotalByFilter = [];
    foreach ($buildMatrix as $filterKey => $byDate) {
        $buildTotalByFilter[$filterKey] = array_sum($byDate);
    }

    $baleSupplyMap = [];
    $filtersBaleIds = [];
    foreach ($baleFilterLen as $baleId => $filters) {
        foreach ($filters as $fkey => $len) {
            $planTotal = (float)($buildTotalByFilter[$fkey] ?? 0);
            $lenTotal = (float)($totalLenByFilter[$fkey] ?? 0);
            $qtyShare = ($planTotal > 0 && $lenTotal > 0) ? ($planTotal * $len / $lenTotal) : 0;
            if ($qtyShare <= 0) {
                continue;
            }
            if (!isset($baleSupplyMap[$baleId])) {
                $baleSupplyMap[$baleId] = [];
            }
            $baleSupplyMap[$baleId][$fkey] = $qtyShare;

            if (!isset($filtersBaleIds[$fkey])) {
                $filtersBaleIds[$fkey] = [];
            }
            $filtersBaleIds[$fkey][] = $baleId;
        }
    }

    // Существующие назначения дат порезки
    $stRoll = $pdo->prepare(
        $hasDoneColumn
            ? "SELECT bale_id, work_date, done FROM roll_plans WHERE order_number = ?"
            : "SELECT bale_id, work_date, 0 AS done FROM roll_plans WHERE order_number = ?"
    );
    $stRoll->execute([$order]);
    $rollMap = [];
    while ($r = $stRoll->fetch(PDO::FETCH_ASSOC)) {
        $rollMap[(int)$r['bale_id']] = [
            'work_date' => $r['work_date'],
            'done' => (int)$r['done'] === 1
        ];
        if (!empty($r['work_date'])) {
            $buildDatesMap[(string)$r['work_date']] = true;
        }
    }
    $plannedBaleIds = [];
    foreach ($rollMap as $bid => $info) {
        if (!empty($info['work_date'])) {
            $plannedBaleIds[$bid] = true;
        }
    }

    // Единый диапазон дат: при загрузке без start_date — с самой ранней даты в плане; иначе — от указанной даты
    $planDatesList = array_keys($buildDatesMap);
    if (!$startDateExplicit && !empty($planDatesList)) {
        $rangeStartObj = DateTime::createFromFormat('Y-m-d', min($planDatesList));
        if ($rangeStartObj) {
            $startDateObj = $rangeStartObj;
            $startDate = $startDateObj->format('Y-m-d');
        }
    }
    $maxDateObj = clone $startDateObj;
    foreach (array_keys($buildDatesMap) as $d) {
        $dObj = DateTime::createFromFormat('Y-m-d', $d);
        if ($dObj && $dObj > $maxDateObj) {
            $maxDateObj = $dObj;
        }
    }
    $minDays = 10;
    $diffDays = (int)$startDateObj->diff($maxDateObj)->format('%r%a');
    if ($diffDays < 0) {
        $diffDays = 0;
    }
    $daysCount = max($minDays, $diffDays + 1);
    $columnDates = [];
    for ($i = 0; $i < $daysCount; $i++) {
        $columnDates[] = (clone $startDateObj)->modify('+' . $i . ' day')->format('Y-m-d');
    }

    // Количество бухт в порезку на день (по сохранённому плану)
    $balesPerDate = array_fill_keys($columnDates, 0);
    foreach ($rollMap as $info) {
        $wd = $info['work_date'] ?? '';
        if ($wd !== '' && isset($balesPerDate[$wd])) {
            $balesPerDate[$wd]++;
        }
    }

} catch (Throwable $e) {
    if (!empty($isAjaxSave)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Ошибка БД: ' . $e->getMessage()]);
        exit;
    }
    http_response_code(500);
    exit('Ошибка БД: ' . htmlspecialchars($e->getMessage()));
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План порезки бухт — <?= htmlspecialchars($order) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f6f8fb; color: #1f2937; }
        .container { width: 100%; max-width: none; margin: 0; padding: 0; box-sizing: border-box; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        .top-panel {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: nowrap;
        }
        .top-panel .title { margin: 0; font-size: 20px; font-weight: 700; }
        .top-panel .meta { margin: 0; color: #4b5563; font-size: 14px; }
        .top-panel .actions { margin: 0; margin-left: auto; }
        h1, h2 { margin: 0 0 12px; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; }
        .meta { margin-bottom: 12px; color: #4b5563; font-size: 14px; }
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: nowrap;
            align-items: center;
        }
        .date-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: nowrap;
        }
        .date-form label {
            font-size: 12px;
            color: #4b5563;
            display: flex;
            flex-direction: row;
            gap: 6px;
            white-space: nowrap;
        }
        .date-form input[type="date"] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn {
            display: inline-block; border: 0; border-radius: 8px; padding: 8px 12px;
            background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; font-size: 14px;
        }
        .btn.secondary { background: #6b7280; }
        .message {
            padding: 10px 12px;
            border-radius: 8px;
            margin-bottom: 12px;
            background: #dcfce7;
            color: #166534;
        }
        .table-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
        }
        .table-scroll-vertical {
            height: 30vh;
            overflow-y: auto;
        }
        .table-scroll-vertical table thead {
            position: sticky;
            top: 0;
            z-index: 4;
            background: #f3f4f6;
        }
        .table-scroll-vertical table thead th {
            background: #f3f4f6;
        }
        table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        th.date-header {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-align: center;
            white-space: nowrap;
            min-width: 2.5ch;
            width: 2.5ch;
            height: 10ch;
            padding: 4px 2px;
            vertical-align: middle;
        }
        .date-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        .center { text-align: center; }
        .small { font-size: 12px; color: #6b7280; }
        .zero { color: #9ca3af; }
        .bale-num {
            padding: 0 2px;
            border-radius: 2px;
        }
        .bale-num.planned {
            background: #fef08a;
            font-weight: 600;
        }
        .supply-cell {
            position: relative;
            overflow: hidden;
            min-width: 3ch;
            width: 3ch;
            text-align: center;
            --cov-fill: 0;
        }
        .supply-cell::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: calc(var(--cov-fill) * 1%);
            background: linear-gradient(to top, rgba(22, 163, 74, 0.45), rgba(74, 222, 128, 0.25));
            pointer-events: none;
        }
        .supply-cell .plan-val { position: relative; z-index: 1; font-weight: 600; line-height: 1.1; }
        .supply-cell .cov-val { display: none; }
        .build-plan-table tbody tr:hover td {
            background: #eff6ff;
        }
        .matrix-table .sticky-left {
            position: sticky;
            left: 0;
            background: #fff;
            z-index: 2;
            min-width: 90px;
        }
        .matrix-table th.sticky-left {
            background: #f3f4f6;
            z-index: 3;
        }
        .matrix-table tbody tr.bale-assigned > td.sticky-left {
            background: #e5e7eb;
            opacity: 0.85;
        }
        #balesPlanTable tbody tr:hover td {
            background: #eff6ff;
        }
        #balesPlanTable tbody tr {
            line-height: 1.2;
        }
        #balesPlanTable tbody td.sticky-left.bale-name-cell {
            padding: 2px 6px;
            font-size: 11px;
            line-height: 1.25;
        }
        #balesPlanTable tbody td.sticky-left.bale-name-cell .badge {
            font-size: 10px;
            padding: 1px 5px;
        }
        .plan-cell {
            min-width: 2.5ch;
            width: 2.5ch;
            cursor: pointer;
            background: #fff;
            transition: background-color .15s ease;
            padding: 4px 2px;
        }
        .plan-cell:hover { background: #eff6ff; }
        .plan-cell.selected {
            background-color: #dbeafe;
            background-image: repeating-linear-gradient(-45deg, rgba(37,99,235,0.35), rgba(37,99,235,0.35) 6px, rgba(37,99,235,0.10) 6px, rgba(37,99,235,0.10) 12px);
            outline: 2px solid #2563eb;
            outline-offset: -2px;
        }
        .plan-cell.done-lock {
            cursor: not-allowed;
            background: #ecfdf5;
            color: #065f46;
        }
        #balesPlanTable th.date-header {
            min-width: 2.5ch;
            width: 2.5ch;
            height: 10ch;
            padding: 4px 2px;
            font-size: 11px;
        }
        th.date-header.weekend,
        .bales-per-day-row th.count-cell.weekend {
            background: #e5e7eb;
        }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px;
            background: #d1fae5; color: #065f46;
        }
        .bales-per-day-row th {
            font-weight: 600;
            font-size: 11px;
            padding: 1px 4px;
            line-height: 1;
            vertical-align: middle;
        }
        .bales-per-day-row .count-cell {
            writing-mode: horizontal-tb;
            transform: none;
            height: 1.2em;
            min-height: 0;
            min-width: 2.5ch;
            width: 2.5ch;
        }
        .toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            max-width: 320px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #2563eb;
            color: #f9fafb;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.35);
            font-size: 13px;
            line-height: 1.3;
            opacity: 0;
            transform: translateY(8px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
            z-index: 50;
        }
        .toast--visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="top-panel">
            <h1 class="title">План порезки бухт</h1>
            <span class="meta">Заявка: <b><?= htmlspecialchars($order) ?></b></span>
            <div class="actions">
                <a class="btn secondary" href="NP_cut_index.php">Назад к этапам</a>
                <form method="get" class="date-form">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                    <label>
                        Дата начала планирования
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </label>
                    <button type="submit" class="btn">Показать</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>План сборки по заявке</h2>
        <?php if (empty($buildFilterKeys) || empty($buildDates)): ?>
            <div class="small">План сборки не найден.</div>
        <?php else: ?>
            <div class="table-scroll table-scroll-vertical">
                <table class="build-plan-table">
                    <thead>
                    <tr>
                        <th>Фильтр</th>
                        <?php foreach ($columnDates as $date): ?>
                            <?php $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                            <th class="date-header<?= $weekend ? ' weekend' : '' ?>"><span class="date-label"><?= htmlspecialchars($date) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($buildFilterKeys as $filterKey): ?>
                        <?php
                        $filterTitle = (string)$buildFiltersMap[$filterKey];
                        $baleIdsForFilter = array_unique($filtersBaleIds[$filterKey] ?? []);
                        sort($baleIdsForFilter);
                        ?>
                        <tr>
                            <td class="filter-cell">
                                <?= htmlspecialchars($filterTitle) ?>
                                <?php if (!empty($baleIdsForFilter)): ?>
                                    (<?php
                                    $parts = [];
                                    foreach ($baleIdsForFilter as $bid) {
                                        $planned = isset($plannedBaleIds[$bid]);
                                        $parts[] = '<span class="bale-num' . ($planned ? ' planned' : '') . '" data-bale-id="' . (int)$bid . '">' . (int)$bid . '</span>';
                                    }
                                    echo implode(', ', $parts);
                                    ?>)
                                <?php endif; ?>
                            </td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($buildMatrix[$filterKey][$date] ?? 0); ?>
                                <td
                                    class="center supply-cell <?= $qty === 0 ? 'zero' : '' ?>"
                                    data-plan-cell="1"
                                    data-filter-key="<?= htmlspecialchars($filterKey) ?>"
                                    data-date="<?= htmlspecialchars($date) ?>"
                                    data-plan="<?= $qty ?>"
                                >
                                    <div class="plan-val"><?= $qty !== 0 ? (int)$qty : '' ?></div>
                                    <div class="cov-val"></div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Бухты по заявке</h2>
        <div class="small" style="margin-bottom:10px;">Клик по ячейке выбирает дату порезки для бухты. Повторный клик снимает выбор.</div>
        <form id="rollPlanForm" method="post">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
            <div id="planHiddenInputs"></div>
            <?php if (empty($bales)): ?>
                <div class="small">По заявке нет данных раскроя.</div>
            <?php else: ?>
                <div class="table-scroll table-scroll-vertical">
                    <table class="matrix-table" id="balesPlanTable">
                        <thead>
                        <tr>
                            <th class="sticky-left">Бухта</th>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <th class="date-header<?= $weekend ? ' weekend' : '' ?>"><span class="date-label"><?= htmlspecialchars($date) ?></span></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="bales-per-day-row">
                            <th class="sticky-left">Бухт/день</th>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $d = DateTime::createFromFormat('Y-m-d', $date); $w = $d ? (int)$d->format('w') : -1; $weekend = ($w === 0 || $w === 6); ?>
                                <th class="date-header count-cell center<?= $weekend ? ' weekend' : '' ?>" data-date="<?= htmlspecialchars($date) ?>"><?= (int)($balesPerDate[$date] ?? 0) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bales as $bale): ?>
                            <?php
                            $baleId = (int)$bale['bale_id'];
                            $assigned = $rollMap[$baleId]['work_date'] ?? '';
                            $isDone = !empty($rollMap[$baleId]['done']);
                            ?>
                            <tr data-bale-id="<?= $baleId ?>" data-assigned-date="<?= htmlspecialchars((string)$assigned) ?>" data-done="<?= $isDone ? '1' : '0' ?>">
                                <td class="sticky-left bale-name-cell" title="<?= htmlspecialchars((string)$bale['filters']) ?>">
                                    <b>Бухта <?= $baleId ?></b>
                                    <?php if ($isDone): ?><span class="badge">Выполнено</span><?php endif; ?>
                                </td>
                                <?php foreach ($columnDates as $date): ?>
                                    <?php
                                    $selected = ($assigned === $date);
                                    $cellClass = 'plan-cell center';
                                    if ($selected) {
                                        $cellClass .= ' selected';
                                    }
                                    if ($isDone) {
                                        $cellClass .= ' done-lock';
                                    }
                                    ?>
                                    <td
                                        class="<?= $cellClass ?>"
                                        data-date="<?= htmlspecialchars($date) ?>"
                                        data-bale-id="<?= $baleId ?>"
                                    ></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 12px;">
                    <button class="btn" type="submit">Сохранить план порезки</button>
                </div>
            <?php endif; ?>
        </form>
    </div>

</div>
<div id="rollPlanToast" class="toast"><?= !empty($message) ? htmlspecialchars($message) : '' ?></div>
<script>
    (function () {
        function showToast(text) {
            var toast = document.getElementById('rollPlanToast');
            if (!toast) return;
            toast.textContent = text;
            toast.classList.add('toast--visible');
            setTimeout(function () {
                toast.classList.remove('toast--visible');
            }, 4000);
        }

        var initialMessage = document.getElementById('rollPlanToast').textContent.trim();
        if (initialMessage) {
            requestAnimationFrame(function () { showToast(initialMessage); });
        }

        var form = document.getElementById('rollPlanForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.disabled = true; }
                var fd = new FormData(form);
                var url = form.getAttribute('action') || (window.location.pathname + window.location.search);
                fetch(url, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.text(); })
                    .then(function (text) {
                        var data;
                        try { data = JSON.parse(text); } catch (e) { data = null; }
                        if (data && data.ok) {
                            showToast(data.message || 'План порезки бухт сохранен.');
                        } else {
                            showToast((data && data.message) ? data.message : 'Ошибка сохранения.');
                        }
                    })
                    .catch(function (err) {
                        showToast('Ошибка сохранения.');
                    })
                    .finally(function () {
                        if (btn) { btn.disabled = false; }
                    });
            });
        }

        const table = document.getElementById('balesPlanTable');
        const hiddenWrap = document.getElementById('planHiddenInputs');
        const baleSupplyMap = <?= json_encode($baleSupplyMap, JSON_UNESCAPED_UNICODE) ?>;
        if (!table || !hiddenWrap) return;

        function updatePlannedHighlights() {
            const planned = {};
            table.querySelectorAll('tbody tr').forEach((row) => {
                const sel = row.querySelector('.plan-cell.selected');
                if (sel) {
                    const bid = row.getAttribute('data-bale-id');
                    if (bid) planned[bid] = true;
                }
            });
            document.querySelectorAll('.bale-num').forEach((span) => {
                const bid = span.getAttribute('data-bale-id');
                span.classList.toggle('planned', !!planned[bid]);
            });
        }

        function updateBaleAssignedState() {
            table.querySelectorAll('tbody tr').forEach((row) => {
                const hasSelected = row.querySelector('.plan-cell.selected');
                if (hasSelected) {
                    row.classList.add('bale-assigned');
                } else {
                    row.classList.remove('bale-assigned');
                }
            });
        }

        function updateBalesPerDayRow() {
            const countByDate = Object.create(null);
            table.querySelectorAll('tbody tr').forEach((row) => {
                const selected = row.querySelector('.plan-cell.selected');
                if (!selected) return;
                const date = selected.getAttribute('data-date');
                if (!date) return;
                countByDate[date] = (countByDate[date] || 0) + 1;
            });
            table.querySelectorAll('th.count-cell').forEach((th) => {
                const date = th.getAttribute('data-date');
                th.textContent = date ? (countByDate[date] || 0) : '';
            });
        }

        function rebuildHiddenInputs() {
            hiddenWrap.innerHTML = '';
            table.querySelectorAll('tbody tr').forEach((row) => {
                const baleId = row.getAttribute('data-bale-id');
                if (!baleId) return;
                const selected = row.querySelector('.plan-cell.selected');
                if (!selected) return;
                const date = selected.getAttribute('data-date');
                if (!date) return;

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'plan_dates[' + baleId + ']';
                input.value = date;
                hiddenWrap.appendChild(input);
            });
        }

        function updateCoverage() {
            const provided = Object.create(null); // key: filter|date -> qty
            table.querySelectorAll('tbody tr').forEach((row) => {
                const baleId = row.getAttribute('data-bale-id');
                if (!baleId) return;
                const selected = row.querySelector('.plan-cell.selected');
                if (!selected) return;
                const date = selected.getAttribute('data-date');
                if (!date) return;

                const filterQtyMap = baleSupplyMap[baleId] || {};
                Object.keys(filterQtyMap).forEach((filterKey) => {
                    const key = filterKey + '|' + date;
                    provided[key] = (provided[key] || 0) + Number(filterQtyMap[filterKey] || 0);
                });
            });

            const planCells = Array.from(document.querySelectorAll('[data-plan-cell="1"]'));
            const dateSet = new Set();
            const filterSet = new Set();
            const planExact = Object.create(null); // key: filter|date -> plan qty
            planCells.forEach((cell) => {
                const f = cell.getAttribute('data-filter-key') || '';
                const d = cell.getAttribute('data-date') || '';
                const p = Number(cell.getAttribute('data-plan') || 0);
                if (!f || !d) return;
                filterSet.add(f);
                dateSet.add(d);
                const key = f + '|' + d;
                planExact[key] = p;
            });

            const dates = Array.from(dateSet).sort();
            const filters = Array.from(filterSet);
            const cumPlan = Object.create(null); // key: filter|date -> cumulative plan
            const cumProvided = Object.create(null); // key: filter|date -> cumulative provided
            filters.forEach((f) => {
                let runPlan = 0;
                let runProvided = 0;
                dates.forEach((d) => {
                    const key = f + '|' + d;
                    runPlan += Number(planExact[key] || 0);
                    runProvided += Number(provided[key] || 0);
                    cumPlan[key] = runPlan;
                    cumProvided[key] = runProvided;
                });
            });

            planCells.forEach((cell) => {
                const filterName = cell.getAttribute('data-filter-key') || '';
                const date = cell.getAttribute('data-date') || '';
                const plan = Number(cell.getAttribute('data-plan') || 0);
                const key = filterName + '|' + date;
                const gotCum = Number(cumProvided[key] || 0);
                const needCum = Number(cumPlan[key] || 0);
                const pctRaw = needCum > 0 ? (gotCum / needCum) * 100 : 0;
                const pct = Math.max(0, pctRaw);

                // Дневная заливка: покрываем день после покрытия всех предыдущих дней.
                const prevNeed = needCum - plan;
                const dayShareRaw = plan > 0 ? ((gotCum - prevNeed) / plan) * 100 : 0;
                const dayShare = Math.max(0, Math.min(100, dayShareRaw));

                cell.style.setProperty('--cov-fill', String(plan > 0 ? dayShare.toFixed(1) : '0'));
                cell.title = 'План дня: ' + plan + ' | Покрытие дня: ' + dayShare.toFixed(1) + '% | Накоп. план: ' + needCum.toFixed(1) + ' | Накоп. обеспечено: ' + gotCum.toFixed(1) + ' (' + pct.toFixed(1) + '%)';
            });
        }

        table.addEventListener('click', (event) => {
            const cell = event.target.closest('.plan-cell');
            if (!cell) return;
            const row = cell.closest('tr');
            if (!row) return;
            if (row.getAttribute('data-done') === '1') return;
            if (cell.classList.contains('selected')) {
                cell.classList.remove('selected');
            } else {
                row.querySelectorAll('.plan-cell.selected').forEach((c) => c.classList.remove('selected'));
                cell.classList.add('selected');
            }

            rebuildHiddenInputs();
            updateBaleAssignedState();
            updatePlannedHighlights();
            updateBalesPerDayRow();
            updateCoverage();
        });

        rebuildHiddenInputs();
        updateBaleAssignedState();
        updatePlannedHighlights();
        updateBalesPerDayRow();
        updateCoverage();
    })();
</script>
</body>
</html>
