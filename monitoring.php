<?php
/**
 * Страница мониторинга по участкам за вчерашний день
 * Показывает: выпуск продукции, гофропакеты, движение крышек (У3), порезка бухт, отчеты лазера и пресса
 */

// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once 'auth/includes/config.php';
require_once 'auth/includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    header('Location: auth/login.php');
    exit;
}

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

// Настройки подключений к базам данных всех участков
$databases = [
    'U2' => ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'plan'],
    'U3' => ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'plan_u3'],
    'U4' => ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'plan_u4'],
    'U5' => ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'plan_u5']
];

// Вчерашний день
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Функция для подключения к БД участка
function getConnection($dbConfig) {
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4",
            $dbConfig['user'],
            $dbConfig['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Ошибка подключения к БД: " . $e->getMessage());
        return null;
    }
}

// Получаем данные по выпуску продукции
function getProductOutput($pdo, $date) {
    if (!$pdo) return ['total' => 0, 'by_filter' => []];
    
    try {
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE 'manufactured_production'");
        if ($checkTable->rowCount() == 0) {
            return ['total' => 0, 'by_filter' => []];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                name_of_filter,
                SUM(count_of_filters) as total_count
            FROM manufactured_production
            WHERE date_of_production = ?
            GROUP BY name_of_filter
            ORDER BY total_count DESC
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        
        $total = 0;
        $by_filter = [];
        foreach ($rows as $row) {
            $total += (int)$row['total_count'];
            $by_filter[] = [
                'filter' => $row['name_of_filter'],
                'count' => (int)$row['total_count']
            ];
        }
        
        return ['total' => $total, 'by_filter' => $by_filter];
    } catch (PDOException $e) {
        error_log("Ошибка получения выпуска продукции: " . $e->getMessage());
        return ['total' => 0, 'by_filter' => []];
    }
}

// Получаем данные по гофропакетам
function getCorrugationOutput($pdo, $date) {
    if (!$pdo) return ['total' => 0, 'by_filter' => []];
    
    try {
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE 'corrugation_plan'");
        if ($checkTable->rowCount() == 0) {
            return ['total' => 0, 'by_filter' => []];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                filter_label,
                SUM(fact_count) as total_count
            FROM corrugation_plan
            WHERE plan_date = ? AND fact_count > 0
            GROUP BY filter_label
            ORDER BY total_count DESC
        ");
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        
        $total = 0;
        $by_filter = [];
        foreach ($rows as $row) {
            $total += (int)$row['total_count'];
            $by_filter[] = [
                'filter' => $row['filter_label'],
                'count' => (int)$row['total_count']
            ];
        }
        
        return ['total' => $total, 'by_filter' => $by_filter];
    } catch (PDOException $e) {
        error_log("Ошибка получения гофропакетов: " . $e->getMessage());
        return ['total' => 0, 'by_filter' => []];
    }
}

// Получаем данные по движению крышек для У3
function getCapsMovement($pdo, $date) {
    if (!$pdo) return ['income' => 0, 'outcome' => 0, 'details' => []];
    
    try {
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE 'cap_log'");
        if ($checkTable->rowCount() == 0) {
            return ['income' => 0, 'outcome' => 0, 'details' => []];
        }
        
        // Приход
        $stmt = $pdo->prepare("
            SELECT 
                name_of_cap_field,
                SUM(count_of_caps) as total_count
            FROM cap_log
            WHERE date_of_operation = ? AND cap_action = 'IN'
            GROUP BY name_of_cap_field
        ");
        $stmt->execute([$date]);
        $incomeRows = $stmt->fetchAll();
        
        // Расход
        $stmt = $pdo->prepare("
            SELECT 
                name_of_cap_field,
                SUM(count_of_caps) as total_count
            FROM cap_log
            WHERE date_of_operation = ? AND cap_action = 'OUT'
            GROUP BY name_of_cap_field
        ");
        $stmt->execute([$date]);
        $outcomeRows = $stmt->fetchAll();
        
        $income = 0;
        $outcome = 0;
        $details = [];
        
        foreach ($incomeRows as $row) {
            $income += (int)$row['total_count'];
            $details[$row['name_of_cap_field']]['income'] = (int)$row['total_count'];
        }
        
        foreach ($outcomeRows as $row) {
            $outcome += (int)$row['total_count'];
            if (!isset($details[$row['name_of_cap_field']])) {
                $details[$row['name_of_cap_field']] = ['income' => 0];
            }
            $details[$row['name_of_cap_field']]['outcome'] = (int)$row['total_count'];
        }
        
        return ['income' => $income, 'outcome' => $outcome, 'details' => $details];
    } catch (PDOException $e) {
        error_log("Ошибка получения движения крышек: " . $e->getMessage());
        return ['income' => 0, 'outcome' => 0, 'details' => []];
    }
}

// Получаем данные по порезке бухт
function getCuttingData($pdo, $date, $department) {
    if (!$pdo) return ['total' => 0, 'done' => 0, 'details' => []];
    
    try {
        // Определяем таблицу и поля в зависимости от участка
        if ($department === 'U2') {
            $tableName = 'roll_plan';
            $dateField = 'plan_date';
            $doneField = 'done';
        } elseif ($department === 'U5') {
            $tableName = 'roll_plans';
            $dateField = 'work_date';
            $doneField = 'done';
        } else {
            // Для U3 и U4 может не быть таблицы порезки или другая структура
            return ['total' => 0, 'done' => 0, 'details' => []];
        }
        
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
        if ($checkTable->rowCount() == 0) {
            return ['total' => 0, 'done' => 0, 'details' => []];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN `{$doneField}` = 1 THEN 1 ELSE 0 END) as done_count
            FROM `{$tableName}`
            WHERE `{$dateField}` = ?
        ");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done_count'] ?? 0);
        
        // Детали по заявкам
        $stmt = $pdo->prepare("
            SELECT 
                order_number,
                COUNT(*) as total_bales,
                SUM(CASE WHEN `{$doneField}` = 1 THEN 1 ELSE 0 END) as done_bales
            FROM `{$tableName}`
            WHERE `{$dateField}` = ?
            GROUP BY order_number
            ORDER BY order_number
        ");
        $stmt->execute([$date]);
        $details = $stmt->fetchAll();
        
        return [
            'total' => $total,
            'done' => $done,
            'details' => $details
        ];
    } catch (PDOException $e) {
        error_log("Ошибка получения данных порезки для {$department}: " . $e->getMessage());
        return ['total' => 0, 'done' => 0, 'details' => []];
    }
}

// Получаем данные по работе лазера
function getLaserData($pdo, $date) {
    if (!$pdo) return ['total' => 0, 'done' => 0, 'details' => []];
    
    try {
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE 'laser_requests'");
        if ($checkTable->rowCount() == 0) {
            return ['total' => 0, 'done' => 0, 'details' => []];
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as done_count
            FROM laser_requests
            WHERE DATE(created_at) = ?
        ");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        
        $total = (int)($row['total'] ?? 0);
        $done = (int)($row['done_count'] ?? 0);
        
        // Детали
        $stmt = $pdo->prepare("
            SELECT 
                id,
                order_number,
                filter_name,
                quantity,
                status,
                created_at
            FROM laser_requests
            WHERE DATE(created_at) = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$date]);
        $details = $stmt->fetchAll();
        
        return [
            'total' => $total,
            'done' => $done,
            'details' => $details
        ];
    } catch (PDOException $e) {
        error_log("Ошибка получения данных лазера: " . $e->getMessage());
        return ['total' => 0, 'done' => 0, 'details' => []];
    }
}

// Получаем данные по работе тигельного пресса
function getPressData($date) {
    try {
        $pressDbConfig = ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'name' => 'press_module'];
        $pdo = getConnection($pressDbConfig);
        
        if (!$pdo) return ['die_cut' => 0, 'glued' => 0, 'details' => []];
        
        // Высеченные заготовки
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM press_die_cut_blanks WHERE shift_date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        $dieCut = (int)($row['total'] ?? 0);
        
        // Склеенные коробки
        $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM press_glued_boxes WHERE shift_date = ?");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        $glued = (int)($row['total'] ?? 0);
        
        // Детали
        $stmt = $pdo->prepare("
            SELECT box_name, brand_name, SUM(quantity) as total
            FROM press_die_cut_blanks
            WHERE shift_date = ?
            GROUP BY box_name, brand_name
            ORDER BY total DESC
        ");
        $stmt->execute([$date]);
        $dieCutDetails = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("
            SELECT box_name, brand_name, SUM(quantity) as total
            FROM press_glued_boxes
            WHERE shift_date = ?
            GROUP BY box_name, brand_name
            ORDER BY total DESC
        ");
        $stmt->execute([$date]);
        $gluedDetails = $stmt->fetchAll();
        
        return [
            'die_cut' => $dieCut,
            'glued' => $glued,
            'die_cut_details' => $dieCutDetails,
            'glued_details' => $gluedDetails
        ];
    } catch (PDOException $e) {
        error_log("Ошибка получения данных пресса: " . $e->getMessage());
        return ['die_cut' => 0, 'glued' => 0, 'details' => []];
    }
}

// Собираем все данные
$monitoringData = [];

foreach (['U2', 'U3', 'U4', 'U5'] as $dept) {
    $pdo = getConnection($databases[$dept]);
    
    $monitoringData[$dept] = [
        'product_output' => getProductOutput($pdo, $yesterday),
        'corrugation' => getCorrugationOutput($pdo, $yesterday),
        'cutting' => getCuttingData($pdo, $yesterday, $dept),
        'laser' => getLaserData($pdo, $yesterday)
    ];
    
    // Движение крышек только для У3
    if ($dept === 'U3') {
        $monitoringData[$dept]['caps'] = getCapsMovement($pdo, $yesterday);
    }
}

// Данные по прессу (общие для всех участков)
$pressData = getPressData($yesterday);

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Мониторинг за <?= date('d.m.Y', strtotime($yesterday)) ?></title>
    <style>
        /* ===== Pro UI (neutral + single accent) ===== */
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1f2937;
            --muted:#6b7280;
            --border:#e5e7eb;
            --accent:#2457e6;
            --accent-ink:#ffffff;
            --radius:12px;
            --shadow:0 2px 12px rgba(2,8,20,.06);
            --shadow-soft:0 1px 8px rgba(2,8,20,.05);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg); color:var(--ink);
            font:14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
        }
        a{color:var(--accent); text-decoration:none}
        a:hover{text-decoration:underline}

        /* контейнер и сетка */
        .container{ max-width:1280px; margin:0 auto; padding:16px; }

        /* панели */
        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:16px;
            margin-bottom:16px;
        }
        .section-title{
            font-size:15px; font-weight:600; color:#111827;
            margin:0 0 12px; padding-bottom:6px; border-bottom:1px solid var(--border);
        }

        /* кнопки (единый стиль) */
        button, input[type="submit"], .btn{
            appearance:none;
            border:1px solid transparent;
            cursor:pointer;
            background:var(--accent);
            color:var(--accent-ink);
            padding:7px 14px;
            border-radius:9px;
            font-weight:600;
            transition:background .2s, box-shadow .2s, transform .04s, border-color .2s;
            box-shadow:0 3px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08);
            text-decoration:none;
            display:inline-block;
        }
        button:hover, input[type="submit"]:hover, .btn:hover{ 
            background:#1e47c5; 
            box-shadow:0 2px 8px rgba(2,8,20,.10); 
            transform:translateY(-1px); 
        }
        button:active, input[type="submit"]:active, .btn:active{ transform:translateY(0); }
        button:disabled, input[type="submit"]:disabled{
            background:#e5e7eb; color:#9ca3af; border-color:#e5e7eb; box-shadow:none; cursor:not-allowed;
        }

        .muted{color:var(--muted); font-size:12px}

        /* сетка участков */
        .departments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .dept-header {
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .dept-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
        }

        .section {
            margin-bottom: 16px;
        }

        .section-title-small {
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid var(--border);
        }

        .metric {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid var(--border);
        }

        .metric-label {
            font-size: 13px;
            color: var(--muted);
            font-weight: 500;
        }

        .metric-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--ink);
        }

        .metric-value.highlight {
            color: var(--accent);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent);
            transition: width 0.3s;
        }

        .header-panel {
            text-align: center;
        }

        .header-panel h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 4px;
        }

        /* Всплывающие подсказки */
        .tooltip-wrapper {
            position: relative;
            display: inline-block;
            cursor: help;
        }

        .tooltip-wrapper .tooltip {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 8px;
            background: var(--ink);
            color: var(--panel);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 11px;
            z-index: 1000;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2);
            max-width: 320px;
            min-width: 200px;
            transition: opacity 0.2s, visibility 0.2s;
            pointer-events: none;
        }

        .tooltip-wrapper .tooltip::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: var(--ink);
        }

        .tooltip-wrapper:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }

        .tooltip-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 250px;
            overflow-y: auto;
        }

        .tooltip-list::-webkit-scrollbar {
            width: 6px;
        }

        .tooltip-list::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }

        .tooltip-list::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }

        .tooltip-list li {
            padding: 6px 0;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }

        .tooltip-list li:last-child {
            border-bottom: none;
        }

        .tooltip-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .tooltip-item-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .tooltip-item-value {
            font-weight: 600;
            white-space: nowrap;
        }

        /* адаптив */
        @media (max-width:768px){
            .departments-grid{grid-template-columns:1fr; gap:12px}
            .container{padding:12px}
            .tooltip-wrapper .tooltip {
                max-width: 250px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel header-panel">
            <h1>📊 Мониторинг производства</h1>
            <p class="muted">Дата: <?= date('d.m.Y', strtotime($yesterday)) ?> (вчера)</p>
            <a href="index.php" class="btn" style="margin-top: 12px;">← Назад</a>
        </div>
        
        <div class="departments-grid">
            <?php foreach (['U2', 'U3', 'U4', 'U5'] as $dept): ?>
                <?php $data = $monitoringData[$dept]; ?>
                <div class="panel">
                    <div class="dept-header">
                        <div class="dept-title">Участок <?= $dept ?></div>
                    </div>
                    
                    <!-- Выпуск продукции -->
                    <div class="section">
                        <div class="section-title-small">Выпуск продукции</div>
                        <div class="metric">
                            <span class="metric-label">Всего:</span>
                            <?php if (!empty($data['product_output']['by_filter'])): ?>
                                <div class="tooltip-wrapper">
                                    <span class="metric-value highlight"><?= number_format($data['product_output']['total'], 0, ',', ' ') ?> шт</span>
                                    <div class="tooltip">
                                        <ul class="tooltip-list">
                                            <?php foreach ($data['product_output']['by_filter'] as $item): ?>
                                                <li>
                                                    <div class="tooltip-item">
                                                        <span class="tooltip-item-name"><?= htmlspecialchars($item['filter']) ?></span>
                                                        <span class="tooltip-item-value"><?= number_format($item['count'], 0, ',', ' ') ?></span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="metric-value highlight"><?= number_format($data['product_output']['total'], 0, ',', ' ') ?> шт</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Гофропакеты -->
                    <div class="section">
                        <div class="section-title-small">Гофропакеты</div>
                        <div class="metric">
                            <span class="metric-label">Всего:</span>
                            <?php if (!empty($data['corrugation']['by_filter'])): ?>
                                <div class="tooltip-wrapper">
                                    <span class="metric-value highlight"><?= number_format($data['corrugation']['total'], 0, ',', ' ') ?> шт</span>
                                    <div class="tooltip">
                                        <ul class="tooltip-list">
                                            <?php foreach ($data['corrugation']['by_filter'] as $item): ?>
                                                <li>
                                                    <div class="tooltip-item">
                                                        <span class="tooltip-item-name"><?= htmlspecialchars($item['filter']) ?></span>
                                                        <span class="tooltip-item-value"><?= number_format($item['count'], 0, ',', ' ') ?></span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="metric-value highlight"><?= number_format($data['corrugation']['total'], 0, ',', ' ') ?> шт</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Порезка бухт -->
                    <div class="section">
                        <div class="section-title-small">Порезка бухт</div>
                        <div class="metric">
                            <span class="metric-label">Выполнено:</span>
                            <?php if (!empty($data['cutting']['details'])): ?>
                                <div class="tooltip-wrapper">
                                    <span class="metric-value highlight"><?= $data['cutting']['done'] ?> / <?= $data['cutting']['total'] ?></span>
                                    <div class="tooltip">
                                        <ul class="tooltip-list">
                                            <?php foreach ($data['cutting']['details'] as $item): ?>
                                                <li>
                                                    <div class="tooltip-item">
                                                        <span class="tooltip-item-name"><?= htmlspecialchars($item['order_number']) ?></span>
                                                        <span class="tooltip-item-value"><?= $item['done_bales'] ?> / <?= $item['total_bales'] ?></span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="metric-value highlight"><?= $data['cutting']['done'] ?> / <?= $data['cutting']['total'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($data['cutting']['total'] > 0): ?>
                            <?php $percent = $data['cutting']['total'] > 0 ? ($data['cutting']['done'] / $data['cutting']['total'] * 100) : 0; ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $percent ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Отчет по лазеру -->
                    <div class="section">
                        <div class="section-title-small">Работа лазера</div>
                        <div class="metric">
                            <span class="metric-label">Выполнено:</span>
                            <span class="metric-value highlight"><?= $data['laser']['done'] ?> / <?= $data['laser']['total'] ?></span>
                        </div>
                        <?php if ($data['laser']['total'] > 0): ?>
                            <?php $percent = $data['laser']['total'] > 0 ? ($data['laser']['done'] / $data['laser']['total'] * 100) : 0; ?>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $percent ?>%"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Движение крышек (только для У3) -->
                    <?php if ($dept === 'U3' && isset($data['caps'])): ?>
                        <div class="section">
                            <div class="section-title-small">Движение крышек</div>
                            <div class="metric">
                                <span class="metric-label">Приход:</span>
                                <?php if (!empty($data['caps']['details'])): ?>
                                    <div class="tooltip-wrapper">
                                        <span class="metric-value" style="color: #10b981;"><?= number_format($data['caps']['income'], 0, ',', ' ') ?> шт</span>
                                        <div class="tooltip">
                                            <ul class="tooltip-list">
                                                <?php foreach ($data['caps']['details'] as $capName => $capData): ?>
                                                    <?php if (($capData['income'] ?? 0) > 0): ?>
                                                        <li>
                                                            <div class="tooltip-item">
                                                                <span class="tooltip-item-name"><?= htmlspecialchars($capName) ?></span>
                                                                <span class="tooltip-item-value">+<?= $capData['income'] ?? 0 ?></span>
                                                            </div>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="metric-value" style="color: #10b981;"><?= number_format($data['caps']['income'], 0, ',', ' ') ?> шт</span>
                                <?php endif; ?>
                            </div>
                            <div class="metric">
                                <span class="metric-label">Расход:</span>
                                <?php if (!empty($data['caps']['details'])): ?>
                                    <div class="tooltip-wrapper">
                                        <span class="metric-value" style="color: #ef4444;"><?= number_format($data['caps']['outcome'], 0, ',', ' ') ?> шт</span>
                                        <div class="tooltip">
                                            <ul class="tooltip-list">
                                                <?php foreach ($data['caps']['details'] as $capName => $capData): ?>
                                                    <?php if (($capData['outcome'] ?? 0) > 0): ?>
                                                        <li>
                                                            <div class="tooltip-item">
                                                                <span class="tooltip-item-name"><?= htmlspecialchars($capName) ?></span>
                                                                <span class="tooltip-item-value">-<?= $capData['outcome'] ?? 0 ?></span>
                                                            </div>
                                                        </li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="metric-value" style="color: #ef4444;"><?= number_format($data['caps']['outcome'], 0, ',', ' ') ?> шт</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Отчет по тигельному прессу -->
        <div class="panel">
            <div class="section-title">🔨 Тигельный пресс</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                <div>
                    <div class="section-title-small">Высеченные заготовки</div>
                    <div class="metric">
                        <span class="metric-label">Всего:</span>
                        <?php if (!empty($pressData['die_cut_details'])): ?>
                            <div class="tooltip-wrapper">
                                <span class="metric-value highlight"><?= number_format($pressData['die_cut'], 0, ',', ' ') ?> шт</span>
                                <div class="tooltip">
                                    <ul class="tooltip-list">
                                        <?php foreach ($pressData['die_cut_details'] as $item): ?>
                                            <li>
                                                <div class="tooltip-item">
                                                    <span class="tooltip-item-name"><?= htmlspecialchars($item['box_name']) ?> <?= htmlspecialchars($item['brand_name'] ?? '') ?></span>
                                                    <span class="tooltip-item-value"><?= number_format($item['total'], 0, ',', ' ') ?></span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="metric-value highlight"><?= number_format($pressData['die_cut'], 0, ',', ' ') ?> шт</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <div class="section-title-small">Склеенные коробки</div>
                    <div class="metric">
                        <span class="metric-label">Всего:</span>
                        <?php if (!empty($pressData['glued_details'])): ?>
                            <div class="tooltip-wrapper">
                                <span class="metric-value highlight"><?= number_format($pressData['glued'], 0, ',', ' ') ?> шт</span>
                                <div class="tooltip">
                                    <ul class="tooltip-list">
                                        <?php foreach ($pressData['glued_details'] as $item): ?>
                                            <li>
                                                <div class="tooltip-item">
                                                    <span class="tooltip-item-name"><?= htmlspecialchars($item['box_name']) ?> <?= htmlspecialchars($item['brand_name'] ?? '') ?></span>
                                                    <span class="tooltip-item-value"><?= number_format($item['total'], 0, ',', ' ') ?></span>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="metric-value highlight"><?= number_format($pressData['glued'], 0, ',', ' ') ?> шт</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

