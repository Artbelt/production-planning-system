<?php
// NP_print_build_plan_2.php — просмотр/печать плана сборки (U2) с опцией "факт"
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../auth/includes/db.php';

$pdo = getPdo('plan');
$order = $_GET['order'] ?? '';
$showFact = isset($_GET['fact']) && $_GET['fact'] !== '' && $_GET['fact'] !== '0';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normFilter(string $s): string {
    $s = preg_replace('~\s*\[.*$~u', '', $s);     // убрать хвост вида " [..]"
    $s = preg_replace('/[●◩⏃]/u', '', $s);        // убрать тех. метки
    $s = preg_replace('~\s+~u', ' ', trim($s));   // нормализовать пробелы
    return $s;
}

// AJAX: список уникальных фильтров для панели "?"
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === 'get_filters') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $filters = [];
    $errors = [];
    try {
        $fromPlan = [];
        $fromFact = [];
        try {
            $fromPlan = $pdo->query("
                SELECT DISTINCT TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) AS base_filter
                FROM build_plan
                WHERE COALESCE(filter_label,'') != ''
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $errors[] = 'build_plan: ' . $e->getMessage();
        }
        try {
            $fromFact = $pdo->query("
                SELECT DISTINCT TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) AS base_filter
                FROM manufactured_production
                WHERE COALESCE(name_of_filter,'') != ''
            ")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $errors[] = 'manufactured_production: ' . $e->getMessage();
        }
        foreach (array_merge($fromPlan, $fromFact) as $name) {
            $base = normFilter((string)$name);
            if ($base !== '') $filters[$base] = true;
        }
        $filters = array_keys($filters);
        sort($filters, SORT_NATURAL | SORT_FLAG_CASE);
        $out = ['filters' => $filters];
        if (!empty($errors)) $out['warnings'] = $errors;
        echo json_encode($out);
    } catch (Exception $e) {
        echo json_encode(['filters' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// Обновление заявки в строке выпуска (для страницы filter_release)
if ($action === 'filter_release_update') {
    header('Content-Type: application/json; charset=utf-8');
    $filterName = trim((string)($_POST['filter'] ?? $_GET['filter'] ?? ''));
    $date = trim((string)($_POST['date'] ?? ''));
    $oldOrder = trim((string)($_POST['old_order'] ?? ''));
    $newOrder = trim((string)($_POST['new_order'] ?? ''));
    if ($filterName === '' || $date === '' || $oldOrder === '') {
        echo json_encode(['ok' => false, 'error' => 'Не указаны filter, date или old_order']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            UPDATE manufactured_production
            SET name_of_order = ?
            WHERE date_of_production = ?
              AND TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) = ?
              AND name_of_order = ?
        ");
        $stmt->execute([$newOrder, $date, $filterName, $oldOrder]);
        echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// AJAX: выпуск по выбранному фильтру за всё время — JSON для панели или CSV для выгрузки
if ($action === 'filter_release') {
    $filterName = $_GET['filter'] ?? $_POST['filter'] ?? '';
    $filterName = trim((string)$filterName);
    $asJson = isset($_GET['format']) && $_GET['format'] === 'json';
    if ($filterName === '') {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['rows' => [], 'error' => 'Не указано название фильтра.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Не указано название фильтра.";
        }
        exit;
    }
    $rows = [];
    try {
        $stmt = $pdo->prepare("
            SELECT date_of_production AS dt, name_of_order AS ord, SUM(count_of_filters) AS total
            FROM manufactured_production
            WHERE TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) = ?
            GROUP BY date_of_production, name_of_order
            ORDER BY date_of_production DESC, name_of_order
        ");
        $stmt->execute([$filterName]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'date' => $row['dt'] ?? '',
                'order' => $row['ord'] ?? '',
                'total' => (int)($row['total'] ?? 0)
            ];
        }
    } catch (Exception $e) {
        if ($asJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['rows' => [], 'error' => $e->getMessage()]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Ошибка: " . $e->getMessage();
        }
        exit;
    }
    if ($asJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['filter' => $filterName, 'rows' => $rows]);
        exit;
    }
    $orderList = [];
    try {
        $orderList = $pdo->query("SELECT DISTINCT order_number FROM build_plan WHERE order_number != '' ORDER BY order_number DESC")->fetchAll(PDO::FETCH_COLUMN);
        $fromProd = $pdo->query("SELECT DISTINCT name_of_order FROM manufactured_production WHERE name_of_order != '' ORDER BY name_of_order DESC")->fetchAll(PDO::FETCH_COLUMN);
        $orderList = array_values(array_unique(array_merge($orderList, $fromProd)));
        rsort($orderList, SORT_NATURAL);
    } catch (Exception $e) {
        // ignore
    }
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выпуск — <?= h($filterName) ?></title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #fff; }
        h1 { font-size: 18px; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; max-width: 520px; }
        th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
        th { background: #f0f0f0; }
        .btn-change { padding: 2px 8px; font-size: 12px; cursor: pointer; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.visible { display: flex; }
        .modal { background: #fff; padding: 16px; border-radius: 8px; max-width: 320px; width: 90%; max-height: 70vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .modal h3 { margin: 0 0 12px; font-size: 14px; }
        .modal-list { overflow-y: auto; list-style: none; margin: 0; padding: 0; }
        .modal-list li { padding: 8px 12px; cursor: pointer; border-radius: 4px; }
        .modal-list li:hover { background: #e8f0fe; }
        .modal-close { margin-top: 12px; padding: 6px 12px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Выпуск: <?= h($filterName) ?></h1>
    <p style="font-size: 12px; color: #666; margin-bottom: 12px;">Нажмите кнопку в начале строки, чтобы выбрать заявку из списка.</p>
    <table>
        <thead><tr><th></th><th>Дата</th><th>Заявка</th><th>Кол-во (шт)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr data-date="<?= h($r['date']) ?>" data-old-order="<?= h($r['order']) ?>">
                <td><button type="button" class="btn-change" title="Сменить заявку">…</button></td>
                <td><?= h($r['date']) ?></td>
                <td class="order-cell"><?= h($r['order']) ?></td>
                <td><?= (int)$r['total'] ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="4">Нет данных</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div id="orderModal" class="modal-overlay">
        <div class="modal">
            <h3>Выберите заявку</h3>
            <ul class="modal-list" id="orderModalList"></ul>
            <button type="button" class="modal-close" id="orderModalClose">Закрыть</button>
        </div>
    </div>
    <script>
        (function() {
            var filterName = <?= json_encode($filterName) ?>;
            var orderList = <?= json_encode($orderList) ?>;
            var modal = document.getElementById('orderModal');
            var listEl = document.getElementById('orderModalList');
            var closeBtn = document.getElementById('orderModalClose');
            var currentRow = null;

            function openModal(row) {
                currentRow = row;
                listEl.innerHTML = orderList.map(function(ord) {
                    return '<li data-order="' + (ord || '').replace(/"/g, '&quot;') + '">' + (ord || '—') + '</li>';
                }).join('');
                listEl.querySelectorAll('li').forEach(function(li) {
                    li.addEventListener('click', function() {
                        var newOrder = this.getAttribute('data-order') || '';
                        var date = currentRow.getAttribute('data-date');
                        var oldOrder = currentRow.getAttribute('data-old-order');
                        if (newOrder === oldOrder) { modal.classList.remove('visible'); return; }
                        var form = new FormData();
                        form.append('filter', filterName);
                        form.append('date', date);
                        form.append('old_order', oldOrder);
                        form.append('new_order', newOrder);
                        fetch('NP_print_build_plan_2.php?action=filter_release_update', { method: 'POST', body: form })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.ok) {
                                    currentRow.setAttribute('data-old-order', newOrder);
                                    currentRow.querySelector('.order-cell').textContent = newOrder;
                                }
                                modal.classList.remove('visible');
                            });
                    });
                });
                modal.classList.add('visible');
            }
            closeBtn.addEventListener('click', function() { modal.classList.remove('visible'); });
            modal.addEventListener('click', function(e) { if (e.target === modal) modal.classList.remove('visible'); });

            document.querySelectorAll('.btn-change').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = this.closest('tr');
                    if (row && row.getAttribute('data-date')) openModal(row);
                });
            });
        })();
    </script>
</body>
</html>
<?php
    exit;
}

// Берём список заявок для селектора
$activeOrders = [];
try {
    // Активные заявки: те, что НЕ скрыты (не отправлены в архив)
    $activeOrders = $pdo->query("
        SELECT DISTINCT order_number
        FROM orders
        WHERE order_number IS NOT NULL
          AND TRIM(order_number) <> ''
          AND (hide IS NULL OR hide = 0)
        ORDER BY order_number DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $activeOrders = [];
}

// Если заявка не указана — берём последнюю
if ($order === '') {
    $order = $activeOrders[0] ?? '';
    if ($order === '') {
        http_response_code(400);
        exit('Нет данных для отображения. В базе отсутствуют планы сборки.');
    }
}

// Слоты плана: даты → позиции (агрегировано по фильтру, без разделения по местам)
$planByDate = [];
$datesSorted = [];

// Факт по базовому имени фильтра
$factByBase = []; // [base] => ['planned'=>int, 'manufactured'=>int]
$slotFills = [];  // [date|base] => ['pct'=>float, 'done'=>int, 'total'=>int]

try {
    $stmt = $pdo->prepare("
        SELECT
            DATE(bp.assign_date) AS plan_date,
            bp.place,
            bp.filter_label,
            COALESCE(bp.`count`, 0) AS qty,
            ppp.p_p_height AS height
        FROM build_plan bp
        LEFT JOIN panel_filter_structure pfs
            ON TRIM(pfs.filter) = TRIM(SUBSTRING_INDEX(COALESCE(bp.filter_label,''), ' [', 1))
        LEFT JOIN paper_package_panel ppp
            ON ppp.p_p_name = pfs.paper_package
        WHERE bp.order_number = ?
        ORDER BY plan_date, bp.place, bp.id
    ");
    $stmt->execute([$order]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $date = $r['plan_date'] ?? '';
        if ($date === '') continue;
        $place = (int)($r['place'] ?? 0);

        $base = normFilter((string)($r['filter_label'] ?? ''));
        if ($base === '') continue;

        $qty = (int)($r['qty'] ?? 0);
        $height = $r['height'] !== null ? (float)$r['height'] : null;

        if (!isset($planByDate[$date])) $planByDate[$date] = [];
        if (!isset($planByDate[$date][$base])) {
            $planByDate[$date][$base] = [
                'filter' => $base,
                'count' => 0,
                'height' => $height,
                'places' => []
            ];
        }
        $planByDate[$date][$base]['count'] += $qty;
        if ($planByDate[$date][$base]['height'] === null && $height !== null) {
            $planByDate[$date][$base]['height'] = $height;
        }
        if ($place > 0) {
            $planByDate[$date][$base]['places'][$place] = true;
        }
    }

    ksort($planByDate);
    $datesSorted = array_keys($planByDate);

    // Факт выполнения (если включен)
    if ($showFact) {
        // План: сумма по базовому имени (по всем местам/дням)
        $plannedStmt = $pdo->prepare("
            SELECT
                TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) AS base_filter,
                SUM(COALESCE(`count`, 0)) AS total
            FROM build_plan
            WHERE order_number = ?
            GROUP BY base_filter
        ");
        $plannedStmt->execute([$order]);
        while ($r = $plannedStmt->fetch(PDO::FETCH_ASSOC)) {
            $base = normFilter((string)($r['base_filter'] ?? ''));
            if ($base === '') continue;
            $factByBase[$base] = ['planned' => (int)($r['total'] ?? 0), 'manufactured' => 0];
        }

        // Факт: изготовлено из manufactured_production (по базовому имени)
        $factStmt = $pdo->prepare("
            SELECT
                TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) AS base_filter,
                SUM(COALESCE(count_of_filters, 0)) AS total
            FROM manufactured_production
            WHERE name_of_order = ?
            GROUP BY base_filter
        ");
        $factStmt->execute([$order]);
        $manufactured = [];
        while ($r = $factStmt->fetch(PDO::FETCH_ASSOC)) {
            $base = normFilter((string)($r['base_filter'] ?? ''));
            if ($base === '') continue;
            $manufactured[$base] = (int)($r['total'] ?? 0);
        }

        foreach ($factByBase as $base => $v) {
            $factByBase[$base]['manufactured'] = $manufactured[$base] ?? 0;
        }

        // Последовательное закрашивание по порядку отображения (дата → алфавит)
        foreach ($factByBase as $base => $v) {
            $remaining = (int)($v['manufactured'] ?? 0);
            if ($remaining <= 0) continue;

            foreach ($planByDate as $date => $items) {
                if (!isset($items[$base])) continue;
                $total = (int)($items[$base]['count'] ?? 0);
                $slotKey = $date . '|' . $base;
                if ($total <= 0) {
                    $slotFills[$slotKey] = ['pct' => 0, 'done' => 0, 'total' => 0];
                    continue;
                }
                if ($remaining <= 0) break;
                $used = min($total, $remaining);
                $slotFills[$slotKey] = ['pct' => 100 * $used / $total, 'done' => $used, 'total' => $total];
                $remaining -= $used;
            }
        }
    }
} catch (Exception $e) {
    $planByDate = [];
    $datesSorted = [];
    $activeOrders = $activeOrders ?: [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План сборки<?= $showFact ? ' (факт)' : '' ?> — <?= h($order) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body{
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            background: white;
            color: #000;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 50px 12px 20px;
        }
        .scroll-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
        }
        .page-container { display: flex; flex-direction: column; width: fit-content; }
        .brigade-section { margin-bottom: 8px; display: flex; flex-direction: column; }
        .brigade-section:last-child { margin-bottom: 0; }
        .brigade-header { font-size: 16px; font-weight: 400; padding: 8px; text-align: center; margin-bottom: 4px; }

        .days-grid {
            display: grid;
            grid-template-columns: repeat(14, 110px);
            gap: 0;
            grid-auto-flow: row;
            border-top: 1px solid #000;
            border-left: 1px solid #000;
            min-width: fit-content;
        }
        .day-column { border-right: 1px solid #000; border-bottom: 1px solid #000; display: flex; flex-direction: column; }
        .day-header {
            font-size: 10px;
            font-weight: 400;
            padding: 6px 4px;
            background: #f5f5f5;
            border: 1px solid #000;
            text-align: center;
            line-height: 1.3;
        }
        .items-container { padding: 4px; display: flex; flex-direction: column; min-height: 60px; }
        .item {
            border: 1px solid #333;
            padding: 4px 6px;
            margin-bottom: 2px;
            position: relative;
            overflow: hidden;
        }
        .item-fact-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            background: rgba(34, 197, 94, 0.35);
            z-index: 0;
        }
        .item > .item-name, .item > .item-details { position: relative; z-index: 1; }
        .item-name { font-weight: 600; font-size: 12px; margin-bottom: 2px; }
        .item-details {
            font-size: 10px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
        }
        .item.item-highlighted {
            outline: 2px solid #2563eb;
            outline-offset: -1px;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.4);
        }
        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            gap: 8px;
            align-items: center;
            background: white;
            padding: 6px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        .btn:hover { background: #1d4ed8; }
        .order-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            background: white;
            min-width: 150px;
        }
        .order-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .page-load-progress {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #e5e7eb;
            z-index: 9999;
            overflow: hidden;
        }
        .page-load-progress::after {
            content: '';
            display: block;
            height: 100%;
            width: 0;
            background: #2563eb;
            animation: pageLoadProgress 1.2s ease-in-out forwards;
        }
        .page-load-progress.done::after {
            width: 100% !important;
            transition: width 0.15s ease-out;
        }
        .page-load-progress.hide {
            opacity: 0;
            transition: opacity 0.25s ease-out;
            pointer-events: none;
        }
        @keyframes pageLoadProgress {
            0% { width: 0; }
            20% { width: 25%; }
            50% { width: 60%; }
            80% { width: 85%; }
            100% { width: 92%; }
        }
        @media print {
            .page-load-progress { display: none !important; }
            body { background: white; padding-top: 0; }
            .no-print { display: none !important; }
            .brigade-section { page-break-inside: avoid; }
            .day-column { page-break-inside: avoid; break-inside: avoid; }
            .day-column.print-page-break { page-break-after: always; }
            .day-column.print-page-break:last-child { page-break-after: auto; }
            .item.item-highlighted { outline: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <div class="page-load-progress no-print" id="pageLoadProgress" aria-hidden="true"></div>
    <div class="no-print">
        <div style="display:flex; justify-content:center; align-items:center; gap:12px;">
            <label for="orderSelect" style="font-size: 13px; font-weight: 500;">Заявка:</label>
            <select id="orderSelect" class="order-select" onchange="changeOrder(this.value)">
                <?php foreach ($activeOrders as $activeOrder): ?>
                    <option value="<?= h($activeOrder) ?>" <?= $activeOrder === $order ? 'selected' : '' ?>>
                        <?= h($activeOrder) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px;">
                <input type="checkbox" id="showFact" <?= $showFact ? 'checked' : '' ?> onchange="toggleFact(this.checked)">
                Факт выполнения
            </label>
            <button class="btn btn-help" id="btnHelp" type="button" title="Выпуск по фильтру за всё время">?</button>
        </div>
    </div>

    <!-- Плавающая панель: ассортимент фильтров и выгрузка выпуска -->
    <div id="filterReleasePanel" class="filter-release-panel no-print" style="display: none;">
        <div class="filter-release-panel-header">
            <span>Выпуск по фильтру за всё время</span>
            <button type="button" class="filter-release-close" id="filterReleaseClose" aria-label="Закрыть">&times;</button>
        </div>
        <div class="filter-release-panel-body">
            <label class="filter-release-label">Найти фильтр:</label>
            <input type="text" id="filterReleaseSearch" class="filter-release-search" placeholder="Введите название..." autocomplete="off">
            <div class="filter-release-list-wrap">
                <ul id="filterReleaseList" class="filter-release-list"></ul>
            </div>
            <div id="filterReleaseResults" class="filter-release-results" style="display: none;">
                <div class="filter-release-results-head">
                    <strong id="filterReleaseResultsTitle"></strong>
                </div>
                <div class="filter-release-table-wrap">
                    <table class="filter-release-table">
                        <thead><tr><th>Дата</th><th>Заявка</th><th>Кол-во</th></tr></thead>
                        <tbody id="filterReleaseTableBody"></tbody>
                    </table>
                </div>
            </div>
            <p class="filter-release-hint" id="filterReleaseHint">Выберите позицию — выпуск за всё время отобразится ниже.</p>
        </div>
    </div>
    <div id="filterReleaseOverlay" class="filter-release-overlay no-print" style="display: none;"></div>

    <style>
        .btn-help {
            min-width: 36px;
            padding: 8px 12px;
            font-size: 18px;
            font-weight: bold;
        }
        .filter-release-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.3);
            z-index: 1998;
        }
        .filter-release-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 420px;
            max-height: 80vh;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            z-index: 1999;
            display: flex;
            flex-direction: column;
        }
        .filter-release-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 14px;
        }
        .filter-release-close {
            background: none;
            border: none;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
            padding: 0 4px;
        }
        .filter-release-close:hover { color: #111; }
        .filter-release-panel-body { padding: 16px; overflow: hidden; display: flex; flex-direction: column; }
        .filter-release-label { display: block; font-size: 12px; margin-bottom: 6px; color: #374151; }
        .filter-release-search {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .filter-release-search:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .filter-release-list-wrap {
            flex: 1;
            min-height: 120px;
            max-height: 280px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 10px;
            display: none;
            background: #fff;
        }
        .filter-release-list-wrap.visible {
            display: block;
        }
        .filter-release-list {
            list-style: none;
            margin: 0;
            padding: 4px;
        }
        .filter-release-list li {
            padding: 10px 12px;
            cursor: pointer;
            font-size: 13px;
            border-radius: 6px;
        }
        .filter-release-list li:hover { background: #eff6ff; }
        .filter-release-list li.selected { background: #dbeafe; font-weight: 500; }
        .filter-release-hint { font-size: 11px; color: #6b7280; margin: 0; }
        .filter-release-results { margin-top: 12px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .filter-release-results-head { font-size: 13px; margin-bottom: 8px; color: #374151; }
        .filter-release-table-wrap { max-height: 220px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; }
        .filter-release-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .filter-release-table th, .filter-release-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #eee; }
        .filter-release-table th { background: #f3f4f6; font-weight: 600; }
        .filter-release-table tbody tr:last-child td { border-bottom: none; }
    </style>

    <script>
        (function() {
            var bar = document.getElementById('pageLoadProgress');
            function finishProgress() {
                if (!bar) return;
                bar.classList.add('done');
                setTimeout(function() {
                    bar.classList.add('hide');
                    setTimeout(function() { bar.style.display = 'none'; }, 280);
                }, 120);
            }
            if (document.readyState === 'complete') finishProgress();
            else window.addEventListener('load', finishProgress);
        })();
        function changeOrder(orderNumber) {
            if (orderNumber) {
                const fact = document.getElementById('showFact')?.checked ? '1' : '';
                let url = '?order=' + encodeURIComponent(orderNumber);
                if (fact) url += '&fact=' + fact;
                window.location.href = url;
            }
        }
        function toggleFact(show) {
            const params = new URLSearchParams(window.location.search);
            const order = params.get('order') || document.getElementById('orderSelect')?.value || '';
            if (order) params.set('order', order);
            if (show) params.set('fact', '1'); else params.delete('fact');
            window.location.href = '?' + params.toString();
        }
        <?php if ($showFact): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const items = document.querySelectorAll('.item[data-filter]');
            items.forEach(function(el) {
                el.addEventListener('mouseenter', function() {
                    const filter = el.getAttribute('data-filter');
                    items.forEach(function(i) {
                        if (i.getAttribute('data-filter') === filter) i.classList.add('item-highlighted');
                    });
                });
                el.addEventListener('mouseleave', function() {
                    const filter = el.getAttribute('data-filter');
                    items.forEach(function(i) {
                        if (i.getAttribute('data-filter') === filter) i.classList.remove('item-highlighted');
                    });
                });
            });
        });
        <?php endif; ?>

        (function() {
            var panel = document.getElementById('filterReleasePanel');
            var overlay = document.getElementById('filterReleaseOverlay');
            var btnHelp = document.getElementById('btnHelp');
            var closeBtn = document.getElementById('filterReleaseClose');
            var searchInput = document.getElementById('filterReleaseSearch');
            var listEl = document.getElementById('filterReleaseList');
            var listWrap = document.querySelector('.filter-release-list-wrap');
            var resultsBlock = document.getElementById('filterReleaseResults');
            var resultsTitle = document.getElementById('filterReleaseResultsTitle');
            var tableBody = document.getElementById('filterReleaseTableBody');
            var hintEl = document.getElementById('filterReleaseHint');
            var allFilters = [];

            function openPanel() {
                panel.style.display = 'flex';
                overlay.style.display = 'block';
                searchInput.value = '';
                hideResults();
                if (listWrap) listWrap.classList.remove('visible');
                if (allFilters.length === 0) loadFilters();
                else renderList(allFilters);
                searchInput.focus();
            }
            function hideResults() {
                if (resultsBlock) resultsBlock.style.display = 'none';
                if (hintEl) hintEl.style.display = '';
            }
            function showResults(filterName, rows) {
                if (!resultsBlock || !resultsTitle || !tableBody) return;
                resultsTitle.textContent = 'Выпуск: ' + (filterName || '—');
                tableBody.innerHTML = '';
                if (rows && rows.length) {
                    rows.forEach(function(r) {
                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td>' + (r.date || '') + '</td><td>' + (r.order || '') + '</td><td>' + (r.total || 0) + ' шт</td>';
                        tableBody.appendChild(tr);
                    });
                } else {
                    tableBody.innerHTML = '<tr><td colspan="3" style="color:#6b7280;">Нет данных</td></tr>';
                }
                resultsBlock.style.display = 'block';
                if (hintEl) hintEl.style.display = 'none';
            }
            function closePanel() {
                panel.style.display = 'none';
                overlay.style.display = 'none';
            }
            function loadFilters() {
                listEl.innerHTML = '<li style="cursor:default; color:#6b7280;">Загрузка...</li>';
                var path = (window.location.pathname || '').split('?')[0];
                var url = (path || 'NP_print_build_plan_2.php') + '?action=get_filters';
                fetch(url)
                    .then(function(r) {
                        if (!r.ok) throw new Error(r.status + ' ' + r.statusText);
                        return r.text();
                    })
                    .then(function(text) {
                        var data;
                        try { data = JSON.parse(text); } catch (e) { throw new Error('Ответ не JSON'); }
                        if (data.error) throw new Error(data.error);
                        allFilters = data.filters || [];
                        // список покажем только при вводе текста
                        renderList(allFilters);
                    })
                    .catch(function(err) {
                        listEl.innerHTML = '<li style="cursor:default; color:#c00;">Ошибка загрузки списка: ' + (err && err.message ? err.message : '') + '</li>';
                    });
            }
            function renderList(filters) {
                if (filters.length === 0) {
                    listEl.innerHTML = '<li style="cursor:default; color:#6b7280;">Нет совпадений</li>';
                    return;
                }
                listEl.innerHTML = filters.map(function(name) {
                    var s = (name != null && name !== '') ? String(name) : '—';
                    return '<li data-filter="' + s.replace(/"/g, '&quot;') + '">' + s + '</li>';
                }).join('');
                listEl.querySelectorAll('li[data-filter]').forEach(function(li) {
                    li.addEventListener('click', function() {
                        var filter = this.getAttribute('data-filter');
                        if (!filter || filter === '—') return;
                        listEl.querySelectorAll('li').forEach(function(l) { l.classList.remove('selected'); });
                        this.classList.add('selected');
                        var path = (window.location.pathname || '').split('?')[0];
                        var url = (path || 'NP_print_build_plan_2.php') + '?action=filter_release&filter=' + encodeURIComponent(filter);
                        window.open(url, '_blank');
                        closePanel();
                    });
                });
            }
            function filterList() {
                var q = (searchInput.value || '').trim().toLowerCase();
                if (q === '') {
                    if (listWrap) listWrap.classList.remove('visible');
                    return;
                }
                var filtered = allFilters.filter(function(name) {
                    var s = (name != null) ? String(name) : '';
                    return s.toLowerCase().indexOf(q) !== -1;
                });
                renderList(filtered);
                if (listWrap) listWrap.classList.add('visible');
            }

            if (btnHelp) btnHelp.addEventListener('click', openPanel);
            if (closeBtn) closeBtn.addEventListener('click', closePanel);
            if (overlay) overlay.addEventListener('click', closePanel);
            if (searchInput) {
                searchInput.addEventListener('input', filterList);
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Escape') closePanel();
                    if (!searchInput.value && listWrap) listWrap.classList.remove('visible');
                });
            }
        })();
    </script>

    <?php if (empty($planByDate)): ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            Нет данных для отображения
        </div>
    <?php else:
        $daysPerPrintPage = 7;
        $dayIndex = 0;
    ?>
        <div class="scroll-wrapper">
        <div class="page-container">
            <div class="brigade-section" id="daysOnly">
                <div class="brigade-header">План сборки • Заявка: <?= h($order) ?><?= $showFact ? ' • Факт' : '' ?></div>
                <div class="days-grid" style="grid-template-columns: repeat(<?= max(1, count($datesSorted)) ?>, 110px);">
                    <?php foreach ($planByDate as $date => $items):
                        $dayIndex++;
                        $keys = array_keys($items);
                        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
                    ?>
                        <div class="day-column<?= ($dayIndex % $daysPerPrintPage === 0 && $dayIndex < count($datesSorted)) ? ' print-page-break' : '' ?>">
                            <div class="day-header">
                                <?= date('d.m.Y', strtotime($date)) ?><br>
                                <?= ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'][date('w', strtotime($date))] ?>
                            </div>
                            <div class="items-container">
                                <?php if (empty($keys)): ?>
                                    <div style="color: #ccc; font-size: 9px; text-align: center; padding: 10px;">—</div>
                                <?php else: ?>
                                    <?php foreach ($keys as $base):
                                        $it = $items[$base];
                                        $slotKey = $date . '|' . $base;
                                        $slot = $slotFills[$slotKey] ?? null;
                                        $pct = ($showFact && $slot && ($slot['total'] ?? 0) > 0) ? (float)$slot['pct'] : 0;
                                        $fact = $factByBase[$base] ?? null;
                                        $places = isset($it['places']) ? implode(',', array_keys($it['places'])) : '';
                                    ?>
                                        <div class="item"
                                             data-filter="<?= h($base) ?>"
                                             <?php if ($showFact && $fact): ?>title="Выполнено: <?= (int)$fact['manufactured'] ?> из <?= (int)$fact['planned'] ?><?= $places ? " • Места: {$places}" : '' ?>"<?php endif; ?>>
                                            <?php if ($showFact && $pct > 0): ?>
                                                <div class="item-fact-fill" style="width: <?= round($pct, 1) ?>%"></div>
                                            <?php endif; ?>
                                            <div class="item-name"><?= h($base) ?></div>
                                            <div class="item-details">
                                                <span><?= $it['height'] !== null ? round($it['height']) : '—' ?> мм</span>
                                                <span><strong><?= (int)$it['count'] ?> шт</strong></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        </div>
    <?php endif; ?>
</body>
</html>

