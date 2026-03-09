<?php
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

$order = (string)($_GET['order'] ?? $_POST['order'] ?? '');
if ($order === '') {
    http_response_code(400);
    exit('Укажите номер заявки: ?order=...');
}
$startDateRaw = (string)($_GET['start_date'] ?? $_POST['start_date'] ?? '');
$startDateObj = DateTime::createFromFormat('Y-m-d', $startDateRaw);
if (!$startDateObj || $startDateObj->format('Y-m-d') !== $startDateRaw) {
    $startDateObj = new DateTime('today');
}
$startDate = $startDateObj->format('Y-m-d');

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
        $qty = (int)($row['qty'] ?? 0);
        if ($date === '' || $filter === '') {
            continue;
        }
        $buildDatesMap[$date] = true;
        $buildFiltersMap[$filter] = true;
        if (!isset($buildMatrix[$filter])) {
            $buildMatrix[$filter] = [];
        }
        $buildMatrix[$filter][$date] = ($buildMatrix[$filter][$date] ?? 0) + $qty;
    }
    ksort($buildDatesMap);
    ksort($buildFiltersMap, SORT_NATURAL | SORT_FLAG_CASE);
    $buildDates = array_keys($buildDatesMap);
    $buildFilters = array_keys($buildFiltersMap);

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

    // Единый диапазон дат для обеих таблиц от даты начала планирования
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
    <title>План порезки бухт — <?= htmlspecialchars($order) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f6f8fb; color: #1f2937; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
        h1, h2 { margin: 0 0 12px; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; }
        .meta { margin-bottom: 12px; color: #4b5563; font-size: 14px; }
        .actions { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; align-items: end; }
        .date-form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
        .date-form label { font-size: 12px; color: #4b5563; display: flex; flex-direction: column; gap: 4px; }
        .date-form input[type="date"] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn {
            display: inline-block; border: 0; border-radius: 8px; padding: 8px 12px;
            background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; font-size: 14px;
        }
        .btn.secondary { background: #6b7280; }
        .message { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; background: #dcfce7; color: #166534; }
        .table-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
        }
        table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        th.date-header {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-align: center;
            white-space: nowrap;
            min-width: 42px;
            width: 42px;
            height: 140px;
            padding: 6px 4px;
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
        .plan-cell {
            min-width: 28px;
            width: 28px;
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
            min-width: 28px;
            width: 28px;
            height: 120px;
            padding: 4px 2px;
            font-size: 11px;
        }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px;
            background: #d1fae5; color: #065f46;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>План порезки бухт</h1>
        <div class="meta">Заявка: <b><?= htmlspecialchars($order) ?></b></div>
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
        <?php if (!empty($message)): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>План сборки по заявке</h2>
        <?php if (empty($buildFilters) || empty($buildDates)): ?>
            <div class="small">План сборки не найден.</div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Фильтр</th>
                        <?php foreach ($columnDates as $date): ?>
                            <th class="date-header"><span class="date-label"><?= htmlspecialchars($date) ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($buildFilters as $filter): ?>
                        <tr>
                            <td><?= htmlspecialchars($filter) ?></td>
                            <?php foreach ($columnDates as $date): ?>
                                <?php $qty = (int)($buildMatrix[$filter][$date] ?? 0); ?>
                                <td class="center <?= $qty === 0 ? 'zero' : '' ?>"><?= $qty ?></td>
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
        <form method="post">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
            <div id="planHiddenInputs"></div>
            <?php if (empty($bales)): ?>
                <div class="small">По заявке нет данных раскроя.</div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="matrix-table" id="balesPlanTable">
                        <thead>
                        <tr>
                            <th class="sticky-left">Бухта</th>
                            <?php foreach ($columnDates as $date): ?>
                                <th class="date-header"><span class="date-label"><?= htmlspecialchars($date) ?></span></th>
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
                                <td class="sticky-left">
                                    <b>Бухта <?= $baleId ?></b>
                                    <div class="small"><?= htmlspecialchars((string)$bale['filters']) ?></div>
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
<script>
    (function () {
        const table = document.getElementById('balesPlanTable');
        const hiddenWrap = document.getElementById('planHiddenInputs');
        if (!table || !hiddenWrap) return;

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
        });

        rebuildHiddenInputs();
    })();
</script>
</body>
</html>
