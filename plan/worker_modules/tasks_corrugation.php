<?php
require_once __DIR__ . '/../../auth/includes/db.php';
require_once __DIR__ . '/worker_auth_lib.php';

$authInfo = worker_auth_current_user();
$currentUser = $authInfo['user'];
$isAuthenticated = $authInfo['authenticated'];

$pdo = getPdo('plan');
$date = $_GET['date'] ?? date('Y-m-d');

// убеждаемся, что таблица manufactured_corrugated_packages существует (как в У5)
$pdo->exec("CREATE TABLE IF NOT EXISTS manufactured_corrugated_packages (
    id INT(11) NOT NULL AUTO_INCREMENT,
    date_of_production DATE NOT NULL,
    order_number VARCHAR(50) NOT NULL DEFAULT '',
    filter_label TEXT NOT NULL,
    count INT(11) NOT NULL DEFAULT 0,
    bale_id INT(11) DEFAULT NULL,
    strip_no INT(11) DEFAULT NULL,
    team VARCHAR(50) DEFAULT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_date (date_of_production),
    INDEX idx_order (order_number),
    INDEX idx_date_order (date_of_production, order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// грузим сырые строки плана
$stmt = $pdo->prepare("
    SELECT id, order_number, plan_date, filter_label, `count`
    FROM corrugation_plan
    WHERE plan_date = ?
    ORDER BY order_number, id
");
$stmt->execute([$date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// группируем по (order_number, filter_label)
$groups = [];
foreach ($rows as $r) {
    $key = $r['order_number'].'|'.$r['filter_label'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'order_number' => $r['order_number'],
            'filter_label' => $r['filter_label'],
            'ids'          => [],
            'items'        => [],
            'plan_sum'     => 0,
        ];
    }
    $groups[$key]['ids'][] = (int)$r['id'];
    $groups[$key]['items'][] = [
        'id'         => (int)$r['id'],
        'count'      => (int)$r['count'],
    ];
    $groups[$key]['plan_sum'] += (int)$r['count'];
}
$group_list = array_values($groups);

// даты для стрелок
$dt       = new DateTime($date);
$prevDate = $dt->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
$today    = date('Y-m-d');

// получаем список активных заявок (из corrugation_plan за последние 30 дней)
$activeOrdersStmt = $pdo->prepare("
    SELECT DISTINCT order_number 
    FROM corrugation_plan 
    WHERE plan_date >= DATE_SUB(?, INTERVAL 30 DAY)
    ORDER BY order_number DESC
");
$activeOrdersStmt->execute([$date]);
$active_orders = $activeOrdersStmt->fetchAll(PDO::FETCH_COLUMN);

// получаем список всех уникальных фильтров для автодополнения
$filtersStmt = $pdo->prepare("
    SELECT DISTINCT filter_label 
    FROM corrugation_plan 
    WHERE filter_label IS NOT NULL AND filter_label != ''
    ORDER BY filter_label
");
$filtersStmt->execute();
$raw_filters = $filtersStmt->fetchAll(PDO::FETCH_COLUMN);
// Убираем дубли: "1601 [48] 199" и "1601 [h48] 199" показываем как одну позицию (без буквы h)
$normalized_seen = [];
$all_filters = [];
foreach ($raw_filters as $f) {
    $n = preg_replace('/\[h(\d+)\]/', '[$1]', $f);
    if (!in_array($n, $normalized_seen)) {
        $normalized_seen[] = $n;
        $all_filters[] = $n;
    }
}

// получаем выпущенные гофропакеты за день (из manufactured_corrugated_packages)
$manufacturedStmt = $pdo->prepare("
    SELECT id, order_number, filter_label, count, timestamp
    FROM manufactured_corrugated_packages
    WHERE date_of_production = ?
    ORDER BY timestamp DESC, order_number, filter_label
");
$manufacturedStmt->execute([$date]);
$manufactured_packages = $manufacturedStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <title>Задания гофромашины</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color:rgb(13, 209, 147);
            --secondary-dark:rgb(37, 216, 165);
            --accent-color: #dc2626;
            --accent-dark: #b91c1c;
            --success-color:rgb(33, 236, 108);
            --success-dark:rgb(31, 32, 31);
            --warning-color: #d97706;
            --warning-dark: #b45309;
            --info-color: #0891b2;
            --info-dark: #0e7490;
            --light-color: #f8fafc;
            --dark-color: #1e293b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --border-radius: 6px;
            --border-radius-sm: 4px;
            --border-radius-lg: 8px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --transition: all 0.15s ease-in-out;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
            color: var(--gray-800);
            line-height: 1.5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 12px;
            color: var(--gray-800);
            font-size: 1.25rem;
            font-weight: 600;
        }

        .section {
            background: white;
            padding: 12px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 16px;
            border: 1px solid var(--gray-200);
        }

        .nav {
            max-width: 900px;
            margin: 0 auto 16px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            background: white;
            padding: 12px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
        }

        .nav a, .nav button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav a:hover, .nav button:hover {
            background: var(--primary-dark);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: var(--border-radius);
            width: 100%;
            max-width: 380px;
            padding: 16px;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--gray-200);
        }
        .modal-box h3 { font-size: 1.05rem; margin-bottom: 12px; }
        .modal-row { margin-bottom: 10px; font-size: 14px; }
        .modal-row label { display: block; font-size: 12px; color: var(--gray-600); margin-bottom: 4px; }
        .modal-row .val { font-weight: 600; color: var(--gray-800); word-break: break-word; }
        .modal-row input[type="number"] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm); font-size: 16px;
        }
        .modal-actions { display: flex; gap: 8px; margin-top: 14px; }
        .modal-actions button {
            flex: 1; border: none; padding: 10px 12px; border-radius: var(--border-radius-sm);
            font-size: 14px; font-weight: 500; cursor: pointer;
        }
        .modal-actions .btn-primary { background: var(--primary-color); color: #fff; }
        .modal-actions .btn-secondary { background: var(--gray-200); color: var(--gray-800); }
        .modal-actions .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .modal-error {
            display: none;
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: var(--border-radius-sm);
            background: #fef2f2;
            color: #b91c1c;
            font-size: 13px;
            border: 1px solid #fecaca;
        }
        .modal-error.visible { display: block; }
        .modal-hint { font-size: 12px; color: var(--gray-500); margin-bottom: 12px; }
        .user-bar {
            max-width: 900px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            background: white;
            padding: 10px 14px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            font-size: 14px;
        }
        .user-bar .user-status { color: var(--gray-700); }
        .user-bar .user-status strong { color: var(--gray-900); }
        .user-bar .user-actions { display: flex; gap: 8px; align-items: center; }
        .user-bar button {
            border: none;
            padding: 7px 12px;
            border-radius: var(--border-radius-sm);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }
        .user-bar .btn-login { background: var(--primary-color); color: #fff; }
        .user-bar .btn-logout { background: var(--gray-200); color: var(--gray-800); }
        #loginModal .modal-row input[type="tel"],
        #loginModal .modal-row input[type="password"],
        #loginModal .modal-row input[type="text"] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm); font-size: 16px;
        }

        .nav input[type="date"] {
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--border-radius-sm);
            font-size: 14px;
            font-weight: 400;
            background: white;
            transition: var(--transition);
        }

        .nav input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
            background: white;
            border: 1px solid var(--gray-200);
        }

        th, td {
            border: 1px solid var(--gray-200);
            padding: 6px 8px;
            text-align: center;
        }

        thead th {
            background: var(--gray-100);
            font-weight: 600;
            color: var(--gray-700);
            font-size: 12px;
        }

        tbody tr:nth-child(even) {
            background: var(--gray-50);
        }

        tbody tr:hover {
            background: var(--gray-100);
        }

        button.delete-last-btn:hover {
            background: var(--accent-dark);
        }

        button.delete-last-btn:active {
            transform: scale(0.98);
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
            font-size: 16px;
            font-weight: 400;
        }

        #filterInput:focus, #countInput:focus, #orderSelect:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        #filterSuggestions {
            font-size: 14px;
        }

        .filter-suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-200);
            transition: var(--transition);
            color: var(--gray-800);
        }

        .filter-suggestion-item:last-child {
            border-bottom: none;
        }

        .filter-suggestion-item:hover,
        .filter-suggestion-item.highlighted {
            background: var(--primary-color);
            color: white;
        }

        #addProductionForm button:hover {
            background: var(--primary-dark);
        }

        .production-section {
            max-width: 100%;
        }

        @media (min-width: 769px) {
            .production-section {
                max-width: 1200px;
                margin: 0 auto;
            }
        }

        @media (max-width: 768px) {
            body { padding: 10px; }
            h2 { font-size: 1.25rem; margin-bottom: 16px; }
            .section { padding: 16px; }
            .nav { gap: 6px; padding: 12px; margin-bottom: 20px; }
            .nav a, .nav button { padding: 8px 12px; font-size: 12px; }
            table { font-size: 13px; }
            th, td { padding: 6px 4px; }
        }

        @media (max-width: 600px) {
            .section { padding: 12px; margin: 0 -10px 20px -10px; border-radius: 0; }
            .section.production-section { margin-left: 10px; margin-right: 10px; }
            table { width: 100%; font-size: 12px; }
            th, td { padding: 5px 3px; }
            #addProductionForm input { font-size: 13px; padding: 6px 10px; }
            #addProductionForm button { padding: 8px 16px; font-size: 13px; }
            #filterSuggestions { max-height: 150px; font-size: 13px; }
            .filter-suggestion-item { padding: 12px; font-size: 14px; }
            .production-section table { font-size: 12px; }
            .production-section th, .production-section td { padding: 5px 4px; }
        }

        .nav .btn-scan {
            background: var(--info-color);
            padding: 10px 12px;
            min-width: 44px;
            justify-content: center;
            font-size: 18px;
            line-height: 1;
        }
        .nav .btn-scan:hover { background: var(--info-dark); }

        #scanModal.modal-overlay { z-index: 2100; padding: 0; align-items: stretch; justify-content: stretch; }
        #scanModal .scan-box {
            background: #0f172a;
            color: #f8fafc;
            width: 100%;
            height: 100%;
            border-radius: 0;
            border: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #scanModal .scan-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: #1e293b;
            flex-shrink: 0;
        }
        #scanModal .scan-header h3 { margin: 0; font-size: 1.05rem; color: #f8fafc; }
        #scanModal .scan-close {
            border: none; background: #334155; color: #fff;
            width: 36px; height: 36px; border-radius: 8px;
            font-size: 18px; cursor: pointer; line-height: 1;
        }
        #scanModal .scan-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #scanModal .scan-stage { display: none; flex: 1; min-height: 0; flex-direction: column; }
        #scanModal .scan-stage.active { display: flex; }
        #scanModal .scan-query {
            margin: 12px 14px 8px;
            padding: 14px 16px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-align: center;
            min-height: 56px;
            font-family: ui-monospace, Consolas, monospace;
            color: #fde68a;
        }
        #scanModal .scan-query.empty { color: #64748b; letter-spacing: normal; font-size: 16px; font-weight: 500; }
        #scanModal .scan-hint {
            text-align: center; font-size: 13px; color: #94a3b8; margin: 0 14px 8px;
        }
        #scanModal .scan-filter-list {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 0 14px 8px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        #scanModal .scan-result-item {
            background: #1e293b; border: 1px solid #334155; border-radius: 10px;
            padding: 12px 14px; cursor: pointer; text-align: left;
        }
        #scanModal .scan-result-item:active { background: #334155; }
        #scanModal .scan-result-item .ord { font-weight: 700; color: #93c5fd; font-size: 15px; }
        #scanModal .scan-result-item .flt { color: #f8fafc; font-size: 15px; word-break: break-word; }
        #scanModal .scan-result-item .meta { color: #94a3b8; font-size: 12px; margin-top: 6px; }
        #scanModal .scan-empty { text-align: center; color: #94a3b8; padding: 20px 8px; font-size: 14px; }
        #scanModal .scan-keypad {
            flex-shrink: 0;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            padding: 10px 14px calc(12px + env(safe-area-inset-bottom, 0px));
            background: #1e293b;
            border-top: 1px solid #334155;
        }
        #scanModal .scan-key {
            border: none;
            background: #334155;
            color: #fff;
            border-radius: 12px;
            font-size: 26px;
            font-weight: 700;
            min-height: 56px;
            cursor: pointer;
            touch-action: manipulation;
        }
        #scanModal .scan-key:active { background: #475569; }
        #scanModal .scan-key.scan-key-action {
            background: #0f172a;
            font-size: 18px;
            font-weight: 600;
            color: #cbd5e1;
        }
        #scanModal .scan-key.scan-key-clear { color: #fca5a5; }
        #scanModal .scan-actions {
            display: flex; flex-wrap: wrap; gap: 8px; justify-content: center;
            padding: 12px 14px calc(12px + env(safe-area-inset-bottom, 0px));
        }
        #scanModal .scan-actions button {
            flex: 1 1 140px; max-width: 240px; border: none; padding: 14px 16px;
            border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer;
        }
        #scanModal .scan-btn-primary { background: #2563eb; color: #fff; }
        #scanModal .scan-btn-secondary { background: #334155; color: #fff; }
        #scanModal .scan-card-wrap {
            flex: 1; overflow-y: auto; padding: 12px 14px; -webkit-overflow-scrolling: touch;
        }
        #scanModal .scan-card {
            background: #1e293b; border-radius: 12px; padding: 14px; border: 1px solid #334155;
        }
        #scanModal .scan-card .card-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 4px; word-break: break-word; }
        #scanModal .scan-card .card-order { color: #93c5fd; font-size: 15px; margin-bottom: 12px; }
        #scanModal .scan-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;
        }
        #scanModal .scan-stat {
            background: #0f172a; border-radius: 8px; padding: 10px;
        }
        #scanModal .scan-stat .lbl { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }
        #scanModal .scan-stat .val { font-size: 18px; font-weight: 700; color: #f8fafc; margin-top: 2px; }
        #scanModal .scan-geom {
            font-size: 14px; color: #e2e8f0; line-height: 1.45; margin-bottom: 10px;
        }
        #scanModal .scan-geom div { margin-bottom: 2px; }
        #scanModal .scan-remarks {
            font-size: 13px; color: #fde68a; background: #422006; border-radius: 8px;
            padding: 10px; margin-top: 4px; white-space: pre-wrap;
        }
    </style>
    <script>
        function setDateAndReload(dStr){
            if(!dStr) return;
            const url = new URL(window.location.href);
            url.searchParams.set('date', dStr);
            window.location.href = url.toString();
        }
        function shiftDate(delta){
            const inp = document.getElementById('date-input');
            if(!inp.value) return;
            const d = new Date(inp.value + 'T00:00:00');
            d.setDate(d.getDate() + delta);
            const y = d.getFullYear();
            const m = String(d.getMonth()+1).padStart(2,'0');
            const day = String(d.getDate()).padStart(2,'0');
            setDateAndReload(y+'-'+m+'-'+day);
        }
        function onDateChange(e){ setDateAndReload(e.target.value); }
        document.addEventListener('keydown', (e)=>{
            const tag = (e.target && e.target.tagName || '').toLowerCase();
            if(tag === 'input' || tag === 'textarea') return;
            if(e.key === 'ArrowLeft') shiftDate(-1);
            if(e.key === 'ArrowRight') shiftDate(1);
        });
        document.addEventListener('DOMContentLoaded', ()=>{
            const di = document.getElementById('date-input');
            if (di) di.addEventListener('change', onDateChange);
        });
    </script>
</head>
<body>
    <div class="container">
<div class="user-bar" id="userBar">
    <div class="user-status" id="userStatus">
        <?php if ($isAuthenticated): ?>
            Оператор: <strong id="userNameDisplay"><?= htmlspecialchars($currentUser['full_name'] ?: $currentUser['phone']) ?></strong>
        <?php else: ?>
            <span id="userNameDisplay">Вы не авторизованы</span>
        <?php endif; ?>
    </div>
    <div class="user-actions">
        <button type="button" class="btn-login" id="loginBtn" style="<?= $isAuthenticated ? 'display:none' : '' ?>" onclick="openLoginModal()">Войти</button>
        <button type="button" class="btn-logout" id="logoutBtn" style="<?= $isAuthenticated ? '' : 'display:none' ?>" onclick="logoutUser()">Сменить пользователя</button>
    </div>
</div>

<h2>Задания гофромашины на <?= htmlspecialchars($date) ?></h2>

<div class="nav">
    <a href="?date=<?= htmlspecialchars($prevDate) ?>" title="День назад">⬅️</a>
    <input id="date-input" type="date" value="<?= htmlspecialchars($date) ?>" />
    <a href="?date=<?= htmlspecialchars($nextDate) ?>" title="День вперёд">➡️</a>
    <a href="?date=<?= htmlspecialchars($today) ?>" title="Сегодня">Сегодня</a>
    <button type="button" class="btn-scan" id="openScanBtn" title="Поиск бухты" aria-label="Поиск бухты" onclick="openScanModal()">📷</button>
    <a href="label_print_queue.php" title="Очередь печати этикеток">Очередь этикеток</a>
</div>

<div class="section">
    <?php if ($group_list): ?>
        <table>
            <thead>
            <tr><th>Заявка</th><th>Фильтр</th><th>План</th></tr>
            </thead>
            <tbody>
            <?php foreach ($group_list as $g): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($g['order_number']) ?></strong></td>
                    <td><?= htmlspecialchars($g['filter_label']) ?></td>
                    <td><span style="font-weight: 600; color: var(--primary-color);"><?= (int)$g['plan_sum'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">Заданий на эту дату нет</div>
    <?php endif; ?>
</div>

<div class="section production-section">
    <h3 style="margin-bottom: 16px; font-size: 1.1rem; font-weight: 600; color: var(--gray-800);">Изготовленные гофропакеты</h3>

    <?php if (!empty($manufactured_packages)): ?>
        <div style="margin-bottom: 24px;">
            <table style="border-collapse: collapse; width: 100%; font-size: 13px; background: white; border: 1px solid var(--gray-200);">
                <thead>
                    <tr>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px; width: 1%; white-space: nowrap;"></th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Заявка</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Фильтр</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px;">Количество</th>
                        <th style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; background: var(--gray-100); font-weight: 600; color: var(--gray-700); font-size: 12px; width: 1%; white-space: nowrap;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_count = 0;
                    $is_first = true;
                    foreach ($manufactured_packages as $item):
                        $total_count += (int)$item['count'];
                    ?>
                        <tr style="border-bottom: 1px solid var(--gray-200);"
                            data-id="<?= (int)$item['id'] ?>"
                            data-order="<?= htmlspecialchars($item['order_number'], ENT_QUOTES) ?>"
                            data-filter="<?= htmlspecialchars($item['filter_label'], ENT_QUOTES) ?>"
                            data-count="<?= (int)$item['count'] ?>">
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; width: 1%; white-space: nowrap;">
                                <button type="button" class="print-label-btn" onclick="openPrintModal(this)" title="Печать этикеток" style="background: var(--info-color); color: white; border: none; padding: 0; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 13px; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;">🖨</button>
                            </td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center;"><strong><?= htmlspecialchars($item['order_number'] ?: '-') ?></strong></td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: left; padding-left: 12px;"><?= htmlspecialchars($item['filter_label']) ?></td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; font-weight: 600; color: var(--primary-color);"><?= (int)$item['count'] ?></td>
                            <td style="border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; width: 1%; white-space: nowrap;">
                                <?php if ($is_first): ?>
                                    <button class="delete-last-btn" onclick="deleteLastPackage('<?= htmlspecialchars($date) ?>')" style="background: var(--accent-color); color: white; border: none; padding: 6px 10px; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 14px; font-weight: 500; transition: var(--transition); width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;" title="Удалить последнюю внесенную позицию">✕</button>
                                <?php else: ?>
                                    <span style="color: var(--gray-400); font-size: 11px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php $is_first = false; endforeach; ?>
                    <tr style="background: var(--gray-50); font-weight: 600;">
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px;"></td>
                        <td colspan="2" style="border: 1px solid var(--gray-200); padding: 8px 12px; text-align: right;">Итого:</td>
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px; text-align: center; color: var(--primary-color);"><?= $total_count ?></td>
                        <td style="border: 1px solid var(--gray-200); padding: 8px 12px; width: 1%;"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="margin-bottom: 24px; text-align: center; color: var(--gray-500); font-size: 14px; padding: 20px;">Выпущенных гофропакетов за этот день нет</div>
    <?php endif; ?>

    <div style="padding-top: 24px; border-top: 1px solid var(--gray-200);">
        <form id="addProductionForm" style="display: flex; flex-direction: column; gap: 12px; max-width: 100%;">
            <div style="position: relative;">
                <label for="filterInput" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Имя фильтра:</label>
                <input type="text" id="filterInput" name="filter" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition);" placeholder="Введите имя фильтра" autocomplete="off">
                <div id="filterSuggestions" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--gray-300); border-top: none; border-radius: 0 0 var(--border-radius-sm) var(--border-radius-sm); max-height: 200px; overflow-y: auto; z-index: 1000; box-shadow: var(--shadow-md); margin-top: -1px;"></div>
            </div>
            <div>
                <label for="orderSelect" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Заявка:</label>
                <select id="orderSelect" name="order" required style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition); background: white;">
                    <option value="">Выберите заявку</option>
                </select>
            </div>
            <div>
                <label for="countInput" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: var(--gray-700);">Количество:</label>
                <input type="number" id="countInput" name="count" required min="1" style="width: 100%; padding: 8px 12px; border: 1px solid var(--gray-300); border-radius: var(--border-radius-sm); font-size: 14px; transition: var(--transition);" placeholder="Введите количество">
            </div>
            <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 10px 20px; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 14px; font-weight: 500; transition: var(--transition); margin-top: 4px;">Внести</button>
        </form>
    </div>
</div>

<div id="scanModal" class="modal-overlay" aria-hidden="true">
    <div class="scan-box" role="dialog" aria-modal="true" aria-labelledby="scanModalTitle">
        <div class="scan-header">
            <h3 id="scanModalTitle">Поиск бухты</h3>
            <button type="button" class="scan-close" onclick="closeScanModal()" title="Закрыть">✕</button>
        </div>
        <div class="scan-body">
            <div class="scan-stage active" id="scanStageKeypad">
                <div class="scan-query empty" id="scanQueryDisplay">Наберите номер с бухты</div>
                <p class="scan-hint" id="scanFilterHint">Позиции появятся по мере ввода</p>
                <div class="scan-filter-list" id="scanFilterList"></div>
                <div class="scan-keypad" id="scanKeypad">
                    <button type="button" class="scan-key" data-key="1">1</button>
                    <button type="button" class="scan-key" data-key="2">2</button>
                    <button type="button" class="scan-key" data-key="3">3</button>
                    <button type="button" class="scan-key" data-key="4">4</button>
                    <button type="button" class="scan-key" data-key="5">5</button>
                    <button type="button" class="scan-key" data-key="6">6</button>
                    <button type="button" class="scan-key" data-key="7">7</button>
                    <button type="button" class="scan-key" data-key="8">8</button>
                    <button type="button" class="scan-key" data-key="9">9</button>
                    <button type="button" class="scan-key scan-key-action scan-key-clear" data-key="clear">Сброс</button>
                    <button type="button" class="scan-key" data-key="0">0</button>
                    <button type="button" class="scan-key scan-key-action" data-key="back">⌫</button>
                </div>
            </div>

            <div class="scan-stage" id="scanStageResults">
                <p class="scan-hint" id="scanResultsHint">Выберите заявку</p>
                <div class="scan-filter-list" id="scanResultsList"></div>
                <div class="scan-actions">
                    <button type="button" class="scan-btn-secondary" onclick="scanShowStage('keypad')">Назад</button>
                </div>
            </div>

            <div class="scan-stage" id="scanStageCard">
                <div class="scan-card-wrap">
                    <div class="scan-card" id="scanCardBody"></div>
                </div>
                <div class="scan-actions">
                    <button type="button" class="scan-btn-secondary" onclick="scanBackFromCard()">Назад</button>
                    <button type="button" class="scan-btn-primary" onclick="scanUseInForm()">Внести выпуск</button>
                    <button type="button" class="scan-btn-secondary" onclick="closeScanModal()">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="printModal" class="modal-overlay" onclick="if(event.target===this)closePrintModal()">
    <div class="modal-box" role="dialog" aria-modal="true">
        <h3>Печать этикеток</h3>
        <div class="modal-row">
            <label>Заявка</label>
            <div class="val" id="printModalOrder">—</div>
        </div>
        <div class="modal-row">
            <label>Фильтр</label>
            <div class="val" id="printModalFilter">—</div>
        </div>
        <div class="modal-row">
            <label>Партия (шт.)</label>
            <div class="val" id="printModalBatch">—</div>
        </div>
        <div class="modal-row">
            <label for="printCopies">Количество копий этикетки</label>
            <input type="number" id="printCopies" min="1" max="500" value="2" inputmode="numeric">
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closePrintModal()">Отмена</button>
            <button type="button" class="btn-primary" id="printSubmitBtn" onclick="submitPrintJob()">В очередь</button>
        </div>
    </div>
</div>

<div id="loginModal" class="modal-overlay<?= $isAuthenticated ? '' : ' open' ?>">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">
        <h3 id="loginModalTitle">Вход оператора</h3>
        <p class="modal-hint">Войдите, чтобы вносить выпуск и печатать этикетки со своим именем.</p>
        <div class="modal-error" id="loginError"></div>
        <form id="loginForm" autocomplete="on">
            <div class="modal-row">
                <label for="loginPhone">Номер телефона</label>
                <input type="tel" id="loginPhone" name="phone" placeholder="+380..." required autofocus>
            </div>
            <div class="modal-row">
                <label for="loginPassword">Пароль</label>
                <input type="password" id="loginPassword" name="password" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="loginLaterBtn" onclick="closeLoginModal(true)">Позже</button>
                <button type="submit" class="btn-primary" id="loginSubmitBtn">Войти</button>
            </div>
        </form>
    </div>
</div>

    <script>
        const AUTH_LOGIN_URL = '../../auth/api/login.php';
        const AUTH_LOGOUT_URL = '../../auth/api/logout.php';
        let currentUser = <?= json_encode($isAuthenticated ? $currentUser : null, JSON_UNESCAPED_UNICODE) ?>;
        const allFilters = <?= json_encode($all_filters, JSON_UNESCAPED_UNICODE) ?>;
        let printJobContext = null;

        function isLoggedIn() {
            return !!(currentUser && currentUser.id);
        }
        function updateUserBar() {
            const nameEl = document.getElementById('userNameDisplay');
            const loginBtn = document.getElementById('loginBtn');
            const logoutBtn = document.getElementById('logoutBtn');
            if (isLoggedIn()) {
                nameEl.innerHTML = 'Оператор: <strong>' + escapeHtml(currentUser.full_name || currentUser.phone || '') + '</strong>';
                loginBtn.style.display = 'none';
                logoutBtn.style.display = '';
            } else {
                nameEl.textContent = 'Вы не авторизованы';
                loginBtn.style.display = '';
                logoutBtn.style.display = 'none';
            }
        }
        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        function openLoginModal() {
            document.getElementById('loginError').classList.remove('visible');
            document.getElementById('loginError').textContent = '';
            document.getElementById('loginModal').classList.add('open');
            const phone = document.getElementById('loginPhone');
            if (phone) { phone.focus(); }
        }
        function closeLoginModal(allowWithoutAuth) {
            if (!isLoggedIn() && !allowWithoutAuth) return;
            document.getElementById('loginModal').classList.remove('open');
        }
        function requireAuth() {
            if (isLoggedIn()) return true;
            openLoginModal();
            return false;
        }
        async function logoutUser() {
            try {
                await fetch(AUTH_LOGOUT_URL, { method: 'POST', credentials: 'same-origin' });
            } catch (e) { /* ignore */ }
            currentUser = null;
            updateUserBar();
            openLoginModal();
        }
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const phone = document.getElementById('loginPhone').value.trim();
            const password = document.getElementById('loginPassword').value;
            const errEl = document.getElementById('loginError');
            const btn = document.getElementById('loginSubmitBtn');
            errEl.classList.remove('visible');
            errEl.textContent = '';
            if (!phone || !password) {
                errEl.textContent = 'Заполните все поля';
                errEl.classList.add('visible');
                return;
            }
            btn.disabled = true;
            try {
                const res = await fetch(AUTH_LOGIN_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone, password, department: 'U2' })
                });
                const data = await res.json();
                if (!data.success) {
                    errEl.textContent = data.error || 'Ошибка входа';
                    errEl.classList.add('visible');
                    return;
                }
                currentUser = data.user;
                document.getElementById('loginPassword').value = '';
                updateUserBar();
                document.getElementById('loginModal').classList.remove('open');
            } catch (err) {
                console.error(err);
                errEl.textContent = 'Ошибка сети при входе';
                errEl.classList.add('visible');
            } finally {
                btn.disabled = false;
            }
        });

        function openPrintModal(btn) {
            if (!requireAuth()) return;
            const tr = btn.closest('tr');
            if (!tr) return;
            printJobContext = {
                manufactured_id: tr.getAttribute('data-id'),
                order_number: tr.getAttribute('data-order') || '',
                filter_label: tr.getAttribute('data-filter') || '',
                batch_count: tr.getAttribute('data-count') || '0'
            };
            document.getElementById('printModalOrder').textContent = printJobContext.order_number || '—';
            document.getElementById('printModalFilter').textContent = printJobContext.filter_label || '—';
            document.getElementById('printModalBatch').textContent = printJobContext.batch_count || '—';
            document.getElementById('printCopies').value = '2';
            document.getElementById('printModal').classList.add('open');
            document.getElementById('printCopies').focus();
            document.getElementById('printCopies').select();
        }
        function closePrintModal() {
            document.getElementById('printModal').classList.remove('open');
            printJobContext = null;
        }
        async function submitPrintJob() {
            if (!requireAuth()) return;
            if (!printJobContext) return;
            const copies = parseInt(document.getElementById('printCopies').value, 10);
            if (!copies || copies < 1) { alert('Укажите количество копий'); return; }
            const btn = document.getElementById('printSubmitBtn');
            btn.disabled = true;
            try {
                const body = new URLSearchParams({
                    manufactured_id: printJobContext.manufactured_id,
                    order_number: printJobContext.order_number,
                    filter_label: printJobContext.filter_label,
                    batch_count: printJobContext.batch_count,
                    copies: String(copies)
                });
                const res = await fetch('create_label_print_job.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                });
                const data = await res.json();
                if (data.auth_required) { openLoginModal(); return; }
                if (data.success) {
                    closePrintModal();
                } else {
                    alert('Ошибка: ' + (data.message || 'неизвестно'));
                }
            } catch (e) {
                console.error(e);
                alert('Ошибка сети при постановке в очередь');
            } finally {
                btn.disabled = false;
            }
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('printModal').classList.contains('open')) {
                closePrintModal();
            }
            if (e.key === 'Escape' && document.getElementById('scanModal').classList.contains('open')) {
                closeScanModal();
            }
        });
        let currentFilterOrders = [];
        let filterSuggestionsTimeout = null;
        let currentHighlightIndex = -1;
        const filterInput = document.getElementById('filterInput');
        const orderSelect = document.getElementById('orderSelect');
        const filterSuggestions = document.getElementById('filterSuggestions');

        function searchFilters(query) {
            const trimmedQuery = query.trim().toLowerCase();
            if (trimmedQuery.length < 1) { hideFilterSuggestions(); return; }
            const matched = allFilters.filter(f => f.toLowerCase().includes(trimmedQuery)).slice(0, 10);
            if (matched.length > 0) showFilterSuggestions(matched); else hideFilterSuggestions();
        }
        function showFilterSuggestions(filters) {
            filterSuggestions.innerHTML = '';
            filters.forEach((filter, index) => {
                const item = document.createElement('div');
                item.className = 'filter-suggestion-item';
                item.textContent = filter;
                item.onclick = () => selectFilter(filter);
                item.onmouseover = () => highlightSuggestion(index);
                filterSuggestions.appendChild(item);
            });
            filterSuggestions.style.display = 'block';
            currentHighlightIndex = -1;
        }
        function hideFilterSuggestions() { setTimeout(() => { filterSuggestions.style.display = 'none'; }, 200); }
        function highlightSuggestion(index) {
            const items = filterSuggestions.querySelectorAll('.filter-suggestion-item');
            items.forEach((item, i) => item.classList.toggle('highlighted', i === index));
            currentHighlightIndex = index;
        }
        function selectFilter(filterName) {
            filterInput.value = filterName;
            hideFilterSuggestions();
            loadOrdersForFilter(filterName);
        }
        filterInput.addEventListener('input', function() {
            if (filterSuggestionsTimeout) clearTimeout(filterSuggestionsTimeout);
            filterSuggestionsTimeout = setTimeout(() => searchFilters(this.value), 150);
        });
        filterInput.addEventListener('focus', function() { if (this.value.trim().length > 0) searchFilters(this.value); });
        document.addEventListener('click', function(e) {
            if (e.target !== filterInput && !filterInput.contains(e.target) && e.target !== filterSuggestions && !filterSuggestions.contains(e.target)) hideFilterSuggestions();
        });
        filterInput.addEventListener('keydown', function(e) {
            const items = filterSuggestions.querySelectorAll('.filter-suggestion-item');
            if (filterSuggestions.style.display !== 'block' || items.length === 0) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1); highlightSuggestion(currentHighlightIndex); items[currentHighlightIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); currentHighlightIndex = Math.max(currentHighlightIndex - 1, -1); if (currentHighlightIndex === -1) items.forEach(item => item.classList.remove('highlighted')); else { highlightSuggestion(currentHighlightIndex); items[currentHighlightIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } }
            else if (e.key === 'Enter') { e.preventDefault(); if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) selectFilter(items[currentHighlightIndex].textContent); else if (items.length > 0) selectFilter(items[0].textContent); }
            else if (e.key === 'Escape') hideFilterSuggestions();
        });

        async function loadOrdersForFilter(filter) {
            const trimmedFilter = filter.trim();
            orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
            orderSelect.value = '';
            currentFilterOrders = [];
            if (!trimmedFilter) return;
            try {
                const response = await fetch('get_orders_for_filter.php?filter=' + encodeURIComponent(trimmedFilter));
                const data = await response.json();
                if (data.success && data.orders && data.orders.length > 0) {
                    currentFilterOrders = data.orders;
                    data.orders.forEach(order => { const o = document.createElement('option'); o.value = order; o.textContent = order; orderSelect.appendChild(o); });
                }
            } catch (err) { console.error(err); }
        }
        filterInput.addEventListener('blur', function() {
            setTimeout(() => { const v = this.value.trim(); if (v) loadOrdersForFilter(v); }, 300);
        });
        orderSelect.addEventListener('change', function() {
            const order = this.value.trim(), filter = filterInput.value.trim();
            if (!order || !filter) return;
            if (!currentFilterOrders.some(o => o === order) && currentFilterOrders.length > 0) { alert('Эта заявка не найдена для выбранного фильтра. Выберите заявку из списка.'); this.focus(); }
        });

        async function deleteLastPackage(date) {
            if (!requireAuth()) return;
            if (!confirm('Вы уверены, что хотите удалить последнюю внесенную позицию?')) return;
            try {
                const response = await fetch('delete_last_manufactured_package.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ date_of_production: date }) });
                const data = await response.json();
                if (data.auth_required) { openLoginModal(); return; }
                if (data.success) { alert('Последняя позиция успешно удалена'); window.location.reload(); } else alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            } catch (err) { console.error(err); alert('Ошибка при удалении записи'); }
        }

        document.getElementById('addProductionForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            if (!requireAuth()) return;
            const order = orderSelect.value.trim();
            const filter = filterInput.value.trim();
            const count = parseInt(document.getElementById('countInput').value, 10);
            const date = '<?= htmlspecialchars($date) ?>';
            if (!order || !filter || !count || count <= 0) { alert('Заполните все поля корректно'); return; }
            if (currentFilterOrders.length > 0 && !currentFilterOrders.some(o => o === order)) { alert('Эта заявка не найдена для выбранного фильтра. Выберите заявку из списка.'); orderSelect.focus(); return; }
            try {
                const response = await fetch('save_manufactured_corrugated_packages.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ date_of_production: date, order_number: order, filter_label: filter, count: count }) });
                const data = await response.json();
                if (data.auth_required) { openLoginModal(); return; }
                if (data.success) {
                    alert('Продукция внесена успешно');
                    filterInput.value = '';
                    document.getElementById('countInput').value = '';
                    orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
                    orderSelect.value = '';
                    currentFilterOrders = [];
                    filterInput.focus();
                    window.location.reload();
                } else alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
            } catch (err) { console.error(err); alert('Ошибка при сохранении данных'); }
        });

        /* ---- Поиск бухты по цифрам ---- */
        let scanQuery = '';
        let scanSelected = null;
        let scanResultsCache = [];
        let scanPickedFilter = '';

        function scanShowStage(name) {
            const map = {
                keypad: 'scanStageKeypad',
                results: 'scanStageResults',
                card: 'scanStageCard'
            };
            Object.keys(map).forEach(k => {
                const el = document.getElementById(map[k]);
                if (el) el.classList.toggle('active', k === name);
            });
        }

        function openScanModal() {
            scanQuery = '';
            scanSelected = null;
            scanResultsCache = [];
            scanPickedFilter = '';
            document.getElementById('scanModal').classList.add('open');
            document.getElementById('scanModal').setAttribute('aria-hidden', 'false');
            scanUpdateQueryUi();
            scanRenderFilterMatches();
            scanShowStage('keypad');
        }

        function closeScanModal() {
            document.getElementById('scanModal').classList.remove('open');
            document.getElementById('scanModal').setAttribute('aria-hidden', 'true');
        }

        function scanUpdateQueryUi() {
            const el = document.getElementById('scanQueryDisplay');
            if (!scanQuery) {
                el.textContent = 'Наберите номер с бухты';
                el.classList.add('empty');
            } else {
                el.textContent = scanQuery;
                el.classList.remove('empty');
            }
        }

        function scanNormDigits(s) {
            return String(s || '').replace(/\D+/g, '');
        }

        function scanNormCompact(s) {
            return String(s || '').toUpperCase().replace(/\s+/g, '');
        }

        function scanGetMatchedFilters() {
            const q = scanQuery;
            if (!q) return [];
            const matched = allFilters.filter(function(f) {
                const digits = scanNormDigits(f);
                const compact = scanNormCompact(f);
                return digits.indexOf(q) !== -1 || compact.indexOf(q) !== -1;
            });
            // Более точные совпадения выше
            matched.sort(function(a, b) {
                const da = scanNormDigits(a);
                const db = scanNormDigits(b);
                const score = function(d, label) {
                    if (d === q) return 0;
                    if (d.indexOf(q) === 0) return 1;
                    if (scanNormCompact(label).indexOf(q) !== -1) return 2;
                    return 3;
                };
                const sa = score(da, a);
                const sb = score(db, b);
                if (sa !== sb) return sa - sb;
                return a.localeCompare(b, 'ru');
            });
            return matched.slice(0, 40);
        }

        function scanRenderFilterMatches() {
            const list = document.getElementById('scanFilterList');
            const hint = document.getElementById('scanFilterHint');
            list.innerHTML = '';
            if (!scanQuery) {
                hint.textContent = 'Позиции появятся по мере ввода';
                list.innerHTML = '<div class="scan-empty">Например: 1956</div>';
                return;
            }
            const matched = scanGetMatchedFilters();
            if (!matched.length) {
                hint.textContent = 'Нет совпадений';
                list.innerHTML = '<div class="scan-empty">Ничего не найдено по «' + escapeHtml(scanQuery) + '»</div>';
                return;
            }
            hint.textContent = 'Найдено: ' + matched.length + ' — выберите позицию';
            matched.forEach(function(filterName) {
                const div = document.createElement('div');
                div.className = 'scan-result-item';
                div.innerHTML = '<div class="flt">' + escapeHtml(filterName) + '</div>';
                div.onclick = function() { scanPickFilter(filterName); };
                list.appendChild(div);
            });
        }

        function scanKeyPress(key) {
            if (key === 'clear') {
                scanQuery = '';
            } else if (key === 'back') {
                scanQuery = scanQuery.slice(0, -1);
            } else if (/^[0-9]$/.test(key)) {
                if (scanQuery.length >= 12) return;
                scanQuery += key;
            } else {
                return;
            }
            scanUpdateQueryUi();
            scanRenderFilterMatches();
        }

        document.getElementById('scanKeypad').addEventListener('click', function(e) {
            const btn = e.target.closest('[data-key]');
            if (!btn) return;
            scanKeyPress(btn.getAttribute('data-key'));
        });

        async function scanPickFilter(filterName) {
            scanPickedFilter = filterName;
            scanSelected = null;
            const hint = document.getElementById('scanResultsHint');
            const list = document.getElementById('scanResultsList');
            hint.textContent = 'Загрузка…';
            list.innerHTML = '<div class="scan-empty">Ищем заявки…</div>';
            scanShowStage('results');
            try {
                const res = await fetch('scan_coil_lookup.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ text: filterName })
                });
                const data = await res.json();
                scanResultsCache = (data && data.results) ? data.results : [];
                list.innerHTML = '';
                if (!scanResultsCache.length) {
                    hint.textContent = 'Заявки не найдены';
                    list.innerHTML = '<div class="scan-empty">Позиция найдена в справочнике, но активных заявок нет.</div>';
                    // Карточка без заявки — только геометрия через lookup пустой; покажем минимальную карточку
                    scanSelected = {
                        order_number: '',
                        filter_label: filterName,
                        geometry: {},
                        remarks: [],
                        corrugated: { plan: 0, fact: 0 },
                        built: { plan: 0, fact: 0 }
                    };
                    scanOpenCard(-1);
                    return;
                }
                if (scanResultsCache.length === 1) {
                    scanOpenCard(0);
                    return;
                }
                hint.textContent = filterName + ' — выберите заявку';
                scanResultsCache.forEach(function(item, idx) {
                    const div = document.createElement('div');
                    div.className = 'scan-result-item';
                    const corr = item.corrugated || {};
                    const built = item.built || {};
                    div.innerHTML =
                        '<div class="ord">Заявка ' + escapeHtml(item.order_number || '') + '</div>' +
                        '<div class="flt">' + escapeHtml(item.filter_label || '') + '</div>' +
                        '<div class="meta">Гофра ' + (corr.fact || 0) + '/' + (corr.plan || 0) +
                        ' · Сборка ' + (built.fact || 0) + '/' + (built.plan || 0) + '</div>';
                    div.onclick = function() { scanOpenCard(idx); };
                    list.appendChild(div);
                });
            } catch (err) {
                console.error(err);
                hint.textContent = 'Ошибка загрузки';
                list.innerHTML = '<div class="scan-empty">Не удалось загрузить данные позиции</div>';
            }
        }

        function fmtNum(v) {
            if (v === null || v === undefined || v === '') return '—';
            return String(v);
        }

        function scanOpenCard(idx) {
            if (idx >= 0) {
                scanSelected = scanResultsCache[idx] || null;
            }
            if (!scanSelected) return;
            const g = scanSelected.geometry || {};
            const corr = scanSelected.corrugated || {};
            const built = scanSelected.built || {};
            const remarks = (scanSelected.remarks || []).filter(Boolean);
            let geomHtml = '';
            geomHtml += '<div><strong>Длина:</strong> ' + fmtNum(g.length_mm) + ' мм</div>';
            geomHtml += '<div><strong>Ширина:</strong> ' + fmtNum(g.width_mm) + ' мм</div>';
            geomHtml += '<div><strong>Высота:</strong> ' + fmtNum(g.height_mm) + ' мм</div>';
            geomHtml += '<div><strong>Рёбра:</strong> ' + fmtNum(g.pleats) + '</div>';
            geomHtml += '<div><strong>Усилители:</strong> ' + fmtNum(g.amplifiers) + '</div>';
            if (g.glueing) geomHtml += '<div><strong>Проливка:</strong> ' + escapeHtml(String(g.glueing)) + '</div>';
            if (g.prefilter) geomHtml += '<div><strong>Предфильтр:</strong> ' + escapeHtml(String(g.prefilter)) + '</div>';

            let html = '';
            html += '<div class="card-title">' + escapeHtml(scanSelected.filter_label || '') + '</div>';
            html += '<div class="card-order">' + (scanSelected.order_number
                ? ('Заявка ' + escapeHtml(scanSelected.order_number))
                : 'Заявка не найдена') + '</div>';
            html += '<div class="scan-grid">';
            html += '<div class="scan-stat"><div class="lbl">Сгофрировано</div><div class="val">' + (corr.fact || 0) + ' / ' + (corr.plan || 0) + '</div></div>';
            html += '<div class="scan-stat"><div class="lbl">Собрано фильтров</div><div class="val">' + (built.fact || 0) + ' / ' + (built.plan || 0) + '</div></div>';
            html += '</div>';
            html += '<div class="scan-geom">' + geomHtml + '</div>';
            if (remarks.length) {
                html += '<div class="scan-remarks"><strong>Особенности:</strong><br>' + escapeHtml(remarks.join('\n')) + '</div>';
            }
            document.getElementById('scanCardBody').innerHTML = html;
            scanShowStage('card');
        }

        function scanBackFromCard() {
            if (scanResultsCache.length > 1) {
                scanShowStage('results');
            } else {
                scanShowStage('keypad');
            }
        }

        function scanUseInForm() {
            if (!scanSelected) return;
            const filter = scanSelected.filter_label || '';
            const order = scanSelected.order_number || '';
            closeScanModal();
            filterInput.value = filter;
            loadOrdersForFilter(filter).then(function() {
                if (order) {
                    const exists = Array.from(orderSelect.options).some(o => o.value === order);
                    if (!exists) {
                        const o = document.createElement('option');
                        o.value = order;
                        o.textContent = order;
                        orderSelect.appendChild(o);
                        currentFilterOrders.push(order);
                    }
                    orderSelect.value = order;
                }
                document.getElementById('countInput').focus();
            });
            const section = document.querySelector('.production-section');
            if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    </script>
</body>
</html>
