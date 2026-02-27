<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Подключаем настройки базы данных
require_once('settings.php');
require_once('tools/tools.php');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем роль пользователя в цехе U5
$userRole = null;
$canArchiveOrder = false;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U5') {
        $userRole = $dept['role_name'];
        // Только мастер (supervisor) и директор (director) могут отправлять в архив
        if (in_array($userRole, ['supervisor', 'director'])) {
            $canArchiveOrder = true;
        }
        break;
    }
}

// Получаем номер заявки для заголовка
$order_number = $_POST['order_number'] ?? '';
$page_title = $order_number ? $order_number : "Заявка";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($page_title) ?></title>
    <style>
        /* ===== Modern UI palette (to match main.php) ===== */
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1e293b;
            --muted:#64748b;
            --border:#e2e8f0;
            --accent:#667eea;
            --secondary:#f1f5f9;
            --radius:14px;
            --shadow:0 10px 25px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.06);
            --shadow-soft:0 2px 8px rgba(0,0,0,0.08);
            /* Дополнительные переменные для совместимости с NP_cut_index.php */
            --foreground: var(--ink);
            --muted-foreground: var(--muted);
            --card: var(--panel);
            --success: hsl(142, 71%, 45%);
            --success-foreground: hsl(0, 0%, 100%);
            --warning: hsl(38, 92%, 50%);
            --warning-foreground: hsl(0, 0%, 100%);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg); color:var(--ink);
            font: 16px/1.6 "Inter","Segoe UI", Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
        }

        .container{ max-width:1200px; margin:0 auto; padding:16px; }

        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: help;
        }
        .tooltip .tooltiptext {
            visibility: hidden;
            width: max-content;
            max-width: 400px;
            background-color: #333;
            color: #fff;
            text-align: left;
            padding: 5px 10px;
            border-radius: 6px;
            position: absolute;
            z-index: 10;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            white-space: pre-line;
        }
        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        /* Индикатор загрузки */
        #loading {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15,23,42,0.25);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            color: #fff;
            font-weight: bold;
        }
        .spinner {
            border: 8px solid rgba(255,255,255,0.3);
            border-top: 8px solid #fff;
            border-radius: 50%;
            width: 80px;
            height: 80px;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-text {
            font-size: 20px;
            color: #fff;
        }

        h3{ margin:0; font-size:18px; font-weight:700; }

        /* Таблица */
        #order_table {
            font-size: 12px;
        }
        
        #order_table th,
        #order_table td {
            font-size: 12px;
        }

        /* Buttons */
        input[type='submit'], .btn{
            appearance:none; cursor:pointer; border:none; color:#fff;
            background: linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            padding: 10px 16px; border-radius: 10px; font-weight:600; box-shadow: var(--shadow-soft);
            transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
        }
        input[type='submit']:hover, .btn:hover{ transform: translateY(-1px); box-shadow: var(--shadow); filter: brightness(1.05); }
        input[type='submit']:active, .btn:active{ transform: translateY(0); }

        /* Button styles matching NP_cut_index.php */
        button, .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
            font-weight: 500;
            border-radius: calc(var(--radius) - 2px);
            border: none;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: inherit;
            text-decoration: none;
        }

        .btn-secondary {
            background: var(--secondary);
            color: var(--ink);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: hsl(220, 14%, 92%);
        }

        input[type='submit'].btn-secondary {
            background: var(--secondary);
            color: var(--ink);
            border: 1px solid var(--border);
            box-shadow: none;
        }

        input[type='submit'].btn-secondary:hover {
            background: hsl(220, 14%, 92%);
            transform: none;
            filter: none;
        }

        .btn-sm {
            padding: 0.3rem 0.625rem;
            font-size: 0.75rem;
        }

        /* Кнопка и столбец диаграммы Ганта */
        .gantt-col {
            white-space: nowrap;
            text-align: center;
        }
        .gantt-btn {
            padding: 0.25rem 0.5rem;
            min-width: 32px;
        }
        .gantt-icon {
            display: inline-block;
            vertical-align: middle;
        }
        .btn-toggle-gantt-active {
            background: var(--accent) !important;
            color: #fff !important;
            border-color: var(--accent) !important;
        }

        /* Индикаторы план/факт в столбце Ганта */
        .gantt-indicators {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-right: 6px;
            vertical-align: middle;
        }
        .gantt-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            cursor: help;
        }
        .gantt-indicator.dev-green { background: var(--success); }
        .gantt-indicator.dev-yellow { background: var(--warning); }
        .gantt-indicator.dev-red { background: #dc2626; }
        .gantt-indicator.dev-none { background: var(--muted); opacity: 0.7; }

        /* Строгий дизайн модального окна диаграммы Ганта */
        #ganttModal.modal {
            background: rgba(0, 0, 0, 0.6);
            padding: 1.5rem;
        }
        #ganttModal .gantt-modal-content {
            background: #fff;
            border: 1px solid #374151;
            border-radius: 2px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
            min-width: 600px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #ganttModal .gantt-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #374151;
            background: #f9fafb;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #111827;
            letter-spacing: 0.02em;
        }
        #ganttModal .gantt-modal-header h3 {
            margin: 0;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        #ganttModal .gantt-modal-close {
            background: transparent;
            border: 1px solid #374151;
            border-radius: 0;
            width: 28px;
            height: 28px;
            padding: 0;
            font-size: 1.25rem;
            line-height: 1;
            color: #374151;
            cursor: pointer;
        }
        #ganttModal .gantt-modal-close:hover {
            background: #e5e7eb;
            color: #111827;
        }
        #ganttModal .gantt-modal-body {
            flex: 1;
            min-height: 0;
            padding: 0;
            background: #fff;
        }
        #ganttModal .gantt-modal-body iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: none;
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Панель для кнопок */
        .action-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-soft);
        }

        .action-panel-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 900px){
            .container{ padding:16px; }
        }

        /* Modal styles - в стиле NP_cut_index.php */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .modal-content {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--panel);
        }
        
        .modal-header h2,
        .modal-header h3 {
            margin: 0;
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--ink);
        }
        
        .modal-close {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            font-size: 1.25rem;
            cursor: pointer;
            color: var(--muted);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        
        .modal-close:hover {
            background: var(--secondary);
            color: var(--ink);
        }

        .close {
            color: #9ca3af;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover,
        .close:focus {
            color: #374151;
        }

        .modal-body {
            padding: 1.5rem;
            background: var(--panel);
        }
        
        /* Стили для модального окна параметров фильтра */
        .modal-body .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .modal-body .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 900px) {
            .modal-body .row { 
                grid-template-columns: 1fr; 
            }
        }


        .modal-body .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid var(--border);
            background: #fafafa;
        }

        .modal-body .yn-yes { 
            color: #2e7d32; 
            font-weight: 600; 
        }

        .modal-body .yn-no { 
            color: #c62828; 
            font-weight: 600; 
        }

        .modal-body .section-title { 
            font-size: 13px; 
            font-weight: 700; 
            margin: 0 0 6px; 
        }

        .modal-body .small { 
            font-size: 11px; 
            color: var(--muted); 
        }

        .modal-body .value-mono { 
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; 
        }

        .modal-body .pair { 
            display: flex; 
            gap: 8px; 
            align-items: center; 
            flex-wrap: wrap; 
        }
        
        /* Анимация спиннера */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Стили для позиций с нулевым выпуском - в стиле NP_cut_index.php */
        .zero-position-item {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) - 2px);
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .zero-position-item:hover {
            box-shadow: 0 2px 4px 0 hsla(220, 15%, 15%, 0.08);
            border-color: var(--accent);
        }

        .zero-position-info {
            flex: 1;
        }

        .zero-position-filter {
            font-weight: 600;
            color: var(--ink);
            font-size: 0.8125rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .zero-position-planned {
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: normal;
        }

        .zero-position-details {
            color: var(--muted);
            font-size: 0.6875rem;
            margin-top: 0.25rem;
        }

        .zero-position-count {
            background: var(--warning);
            color: var(--warning-foreground);
            padding: 0.25rem 0.5rem;
            border-radius: calc(var(--radius) - 4px);
            font-weight: 600;
            font-size: 0.75rem;
        }

        .no-zero-positions {
            text-align: center;
            padding: 2.5rem;
            color: var(--muted);
            font-size: 1rem;
        }

        .no-zero-positions .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .zero-positions-header {
            margin: 0 0 1rem 0;
            font-size: 0.75rem;
            color: var(--ink);
            font-weight: 600;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        /* Компактное модальное окно */
        .modal-content.modal-compact { max-width: 520px; }
        .modal-content.modal-compact .modal-header { padding: 0.5rem 0.75rem; }
        .modal-content.modal-compact .modal-header h3 { font-size: 0.8125rem; }
        .modal-content.modal-compact .modal-body { padding: 0.5rem 0.75rem; }
        .modal-content.modal-compact .zero-positions-header { margin: 0 0 0.5rem 0; font-size: 0.6875rem; padding-bottom: 0.5rem; }
        .modal-content.modal-compact table { font-size: 0.6875rem; }
        .modal-content.modal-compact th, .modal-content.modal-compact td { padding: 0.2rem 0.4rem; }
        .modal-content.modal-compact .no-zero-positions { padding: 1rem; font-size: 0.75rem; }


        /* Мобильная адаптация для модального окна */
        @media (max-width: 768px) {
            .modal-content {
                max-width: 95%;
                max-height: 85vh;
            }

            .modal-header {
                padding: 10px 12px;
            }

            .modal-title {
                font-size: 1.1rem;
            }

            .close {
                font-size: 20px;
            }

            .modal-body {
                padding: 10px 12px;
            }

            .zero-position-item {
                padding: 5px 8px;
                margin-bottom: 3px;
            }

            .zero-position-filter {
                font-size: 0.9rem;
                gap: 6px;
            }

            .zero-position-planned {
                font-size: 0.75rem;
            }

            .zero-position-details {
                font-size: 0.7rem;
            }

            .zero-position-count {
                padding: 3px 6px;
                font-size: 0.8rem;
            }

            .zero-positions-header {
                font-size: 0.9rem;
                margin-bottom: 8px;
            }
        }
    </style>
</head>

<body>

<div id="loading">
    <div class="spinner"></div>
    <div class="loading-text">Загрузка...</div>
</div>

<div class="container">
    <?php
    // tools/tools.php и settings.php уже подключены в начале файла
    require('style/table.txt');

    /**
     * Рендер ячейки с тултипом по датам.
     * $dateList — массив вида [дата1, кол-во1, дата2, кол-во2, ...]
     * $totalQty — итоговое число, которое показываем в самой ячейке
     */
    function renderTooltipCell($dateList, $totalQty) {
        if (empty($dateList)) {
            return "<td>$totalQty</td>";
        }
        $tooltip = '';
        for ($i = 0; $i < count($dateList); $i += 2) {
            $tooltip .= $dateList[$i] . ' — ' . $dateList[$i + 1] . " шт\n";
        }
        return "<td><div class='tooltip'>$totalQty<span class='tooltiptext'>".htmlspecialchars(trim($tooltip))."</span></div></td>";
    }

    /**
     * Грузим FACT гофропакетов из manufactured_corrugated_packages:
     * - по заявке и фильтру
     * - суммируем count
     * - для тултипа возвращаем разбивку по date_of_production
     *
     * Возвращает [ $dateList, $totalFact ] как в renderTooltipCell
     */
    function normalize_filter_label($label) {
        $pos = mb_strpos($label, ' [');
        if ($pos !== false) {
            return trim(mb_substr($label, 0, $pos));
        }
        return trim($label);
    }

    function get_corr_fact_for_filter(PDO $pdo, string $orderNumber, string $filterLabel): array {
        $filterLabel = normalize_filter_label($filterLabel);

        $stmt = $pdo->prepare("
        SELECT date_of_production, SUM(COALESCE(count,0)) AS fact_count
        FROM manufactured_corrugated_packages
        WHERE order_number = ?
          AND TRIM(SUBSTRING_INDEX(filter_label, ' [', 1)) = ?
          AND COALESCE(count,0) > 0
        GROUP BY date_of_production
        ORDER BY date_of_production
    ");
        $stmt->execute([$orderNumber, $filterLabel]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dateList = [];
        $total = 0;
        foreach ($rows as $r) {
            $dateList[] = $r['date_of_production'];
            $dateList[] = (int)$r['fact_count'];
            $total += (int)$r['fact_count'];
        }
        return [$dateList, $total];
    }

    /**
     * Получает конструктивные параметры фильтра из salon_filter_structure и paper_package_salon
     */
    function get_filter_structure($filter_name) {
        global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
        
        try {
            $pdo = new PDO("mysql:host=$mysql_host;dbname=$mysql_database", $mysql_user, $mysql_user_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("
                SELECT 
                    sfs.*,
                    pps.p_p_height as height,
                    pps.p_p_width as width,
                    pps.p_p_pleats_count as ribs_count,
                    pps.p_p_material as material
                FROM salon_filter_structure sfs
                LEFT JOIN paper_package_salon pps ON CONCAT('гофропакет ', sfs.filter) = pps.p_p_name
                WHERE sfs.filter = ?
            ");
            $stmt->execute([$filter_name]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Расхождение в днях между первой плановой и первой фактической датой по операции.
     * Возвращает ['cut' => days|null, 'corr' => days|null, 'build' => days|null].
     * null = нет факта или нет плана.
     */
    function get_gantt_deviations(PDO $pdo, string $order_number, string $filter_label): array {
        $filter_base = normalize_filter_label($filter_label);
        $out = ['cut' => null, 'corr' => null, 'build' => null];

        // Порезка: план — min(work_date), факт — min(work_date) где done=1
        try {
            $st = $pdo->prepare("
                SELECT MIN(rp.work_date) AS d
                FROM roll_plans rp
                JOIN cut_plans cp ON cp.order_number = rp.order_number AND cp.bale_id = rp.bale_id
                WHERE rp.order_number = ? AND TRIM(SUBSTRING_INDEX(cp.filter, ' [', 1)) = ?
            ");
            $st->execute([$order_number, $filter_base]);
            $plan_cut = $st->fetchColumn();
            $st = $pdo->prepare("
                SELECT MIN(rp.work_date) AS d
                FROM roll_plans rp
                JOIN cut_plans cp ON cp.order_number = rp.order_number AND cp.bale_id = rp.bale_id
                WHERE rp.order_number = ? AND TRIM(SUBSTRING_INDEX(cp.filter, ' [', 1)) = ?
                  AND rp.done = 1
            ");
            $st->execute([$order_number, $filter_base]);
            $fact_cut = $st->fetchColumn();
            if ($plan_cut && $fact_cut) {
                $out['cut'] = abs((new DateTime($plan_cut))->diff(new DateTime($fact_cut))->days);
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'done') === false) throw $e;
        }

        // Гофрирование: план — min(plan_date), факт — min(date_of_production)
        $st = $pdo->prepare("
            SELECT MIN(plan_date) FROM corrugation_plan
            WHERE order_number = ? AND TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ?
        ");
        $st->execute([$order_number, $filter_base]);
        $plan_corr = $st->fetchColumn();
        $st = $pdo->prepare("
            SELECT MIN(date_of_production) FROM manufactured_corrugated_packages
            WHERE order_number = ? AND TRIM(SUBSTRING_INDEX(COALESCE(filter_label,''), ' [', 1)) = ? AND COALESCE(count,0) > 0
        ");
        $st->execute([$order_number, $filter_base]);
        $fact_corr = $st->fetchColumn();
        if ($plan_corr && $fact_corr) {
            $out['corr'] = abs((new DateTime($plan_corr))->diff(new DateTime($fact_corr))->days);
        }

        // Сборка: план — min(plan_date), факт — min(date_of_production)
        $st = $pdo->prepare("
            SELECT MIN(plan_date) FROM build_plan
            WHERE order_number = ? AND TRIM(SUBSTRING_INDEX(COALESCE(filter,''), ' [', 1)) = ?
        ");
        $st->execute([$order_number, $filter_base]);
        $plan_build = $st->fetchColumn();
        $st = $pdo->prepare("
            SELECT MIN(date_of_production) FROM manufactured_production
            WHERE name_of_order = ? AND TRIM(SUBSTRING_INDEX(COALESCE(name_of_filter,''), ' [', 1)) = ? AND COALESCE(count_of_filters,0) > 0
        ");
        $st->execute([$order_number, $filter_base]);
        $fact_build = $st->fetchColumn();
        if ($plan_build && $fact_build) {
            $out['build'] = abs((new DateTime($plan_build))->diff(new DateTime($fact_build))->days);
        }

        return $out;
    }

    // Номер заявки уже получен в начале файла

    // Подключим отдельный PDO для выборок из manufactured_corrugated_packages (факт гофропакетов)
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo_corr = getPdo('plan_u5');

    // Загружаем заявку (как и раньше)
    $result = show_order($order_number);

    // Инициализация счётчиков
    $filter_count_in_order = 0;   // всего фильтров по заявке (план)
    $filter_count_produced = 0;   // Всего изготовлено готовых фильтров (факт) — из select_produced_filters_by_order
    $count = 0;                   // номер п/п
    $corr_fact_summ = 0;          // суммарно изготовлено гофропакетов по всей заявке (из manufactured_corrugated_packages)

    // Отрисовка таблицы
    echo "<h3>Заявка: ".htmlspecialchars($order_number)."</h3>";
    
    // Панель с кнопками действий (перенесены наверх)
    echo "<div class='action-panel'>";
    echo "<div class='action-panel-title'>Действия</div>";
    echo "<div class='button-group'>";
    echo "<button onclick='showZeroProductionPositions()' class='btn-secondary btn-sm'>Позиции выпуск которых = 0</button>";
    echo "<button onclick='showLaggingPositions()' class='btn-secondary btn-sm'>Позиции отстающие &gt; 20%</button>";
    echo "<button onclick='checkGofraPackages()' class='btn-secondary btn-sm'>Проверка гофропакетов</button>";
    echo "<button id='btnToggleGantt' onclick='toggleGanttColumn()' class='btn-secondary btn-sm'>Диаграмма Ганта</button>";
    echo "<button onclick='openWorkersSpecification()' class='btn-secondary btn-sm'>Спецификация для рабочих</button>";
    
    // Кнопка отправки в архив - только для мастеров и директоров
    if ($canArchiveOrder) {
        echo "<button onclick='confirmArchiveOrder()' class='btn-secondary btn-sm'>Отправить заявку в архив</button>";
    }
    echo "</div>";
    echo "</div>";
    
    // Скрытая форма для отправки в архив
    if ($canArchiveOrder) {
        echo "<form id='archiveForm' action='hiding_order.php' method='post' style='display: none;'>";
        echo "<input type='hidden' name='order_number' value='".htmlspecialchars($order_number)."'>";
        echo "</form>";
    }
    
    echo "<div class='table-wrap'>";
    echo "<table id='order_table'>";
    echo "<tr>
        <th>I</th>
        <th>Фильтр</th>
        <th>Количество, шт</th>
        <th>Маркировка</th>
        <th>Упаковка инд.</th>
        <th>Этикетка инд.</th>
        <th>Упаковка групп.</th>
        <th>Норма упаковки</th>
        <th>Этикетка групп.</th>
        <th>Примечание</th>
        <th>Изготовлено, шт</th>
        <th>Остаток, шт</th>
        <th>Изготовленные гофропакеты, шт</th>
        <th class=\"gantt-col\" style=\"display:none;\" title=\"Три индикатора: порезка, гофрирование, сборка. Зелёный — расхождение ≤3 дн., жёлтый — ≤5 дн., красный — &gt;5 дн.\">Диаграмма Ганта</th>
      </tr>";

    while ($row = $result->fetch_assoc()) {
        $count++;

        // Готовые фильтры по заявке/фильтру (как было)
        $prod_info = select_produced_filters_by_order($row['filter'], $order_number);
        $date_list_filters = $prod_info[0]; // массив дат/кол-в
        $total_qty_filters = $prod_info[1]; // итог изготовлено фильтров

        $filter_count_in_order += (int)$row['count'];
        $filter_count_produced += $total_qty_filters;

        $difference = (int)$row['count'] - $total_qty_filters;

        // Гофропакеты: теперь из manufactured_corrugated_packages
        list($corr_date_list, $corr_total) = get_corr_fact_for_filter($pdo_corr, $order_number, $row['filter']);
        $corr_fact_summ += (int)$corr_total;

        // Получаем информацию о структуре фильтра
        $filter_structure = get_filter_structure($row['filter']);
        $has_structure = $filter_structure !== false;
        
        echo "<tr>
        <td style='text-align: center;'>
            <button onclick='showFilterInfo(\"".htmlspecialchars($row['filter'] ?? '')."\")' 
                    style='background: white; color: #3b82f6; border: 1px solid #3b82f6; border-radius: 50%; padding: 4px; cursor: pointer; font-weight: bold; font-size: 11px; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto;'
                    title='Информация о фильтре'>
                i
            </button>
        </td>
        <td>".htmlspecialchars($row['filter'] ?? '')."</td>
        <td>".(int)$row['count']."</td>
        <td>".htmlspecialchars($row['marking'] ?? '')."</td>
        <td>".htmlspecialchars($row['personal_packaging'] ?? '')."</td>
        <td>".htmlspecialchars($row['personal_label'] ?? '')."</td>
        <td>".htmlspecialchars($row['group_packaging'] ?? '')."</td>
        <td>".htmlspecialchars($row['packaging_rate'] ?? '')."</td>
        <td>".htmlspecialchars($row['group_label'] ?? '')."</td>
        <td>".htmlspecialchars($row['remark'] ?? '')."</td>";

        // Колонка «Изготовлено, шт» — готовые фильтры с тултипом по датам (как было)
        echo renderTooltipCell($date_list_filters, $total_qty_filters);

        // Остаток по фильтрам
        echo "<td>".(int)$difference."</td>";

        // Логика «Изготовленные гофропакеты, шт» — из manufactured_corrugated_packages (+ тултип по date_of_production)
        echo renderTooltipCell($corr_date_list, (int)$corr_total);

        // Индикаторы план/факт и кнопка диаграммы Ганта
        $deviations = get_gantt_deviations($pdo_corr, $order_number, $row['filter'] ?? '');
        $labels = ['cut' => 'Порезка', 'corr' => 'Гофрирование', 'build' => 'Сборка'];
        $indicator_html = '<span class="gantt-indicators">';
        foreach (['cut', 'corr', 'build'] as $op) {
            $d = $deviations[$op];
            if ($d === null) {
                $cls = 'dev-none';
                $title = $labels[$op] . ': нет данных';
            } elseif ($d <= 3) {
                $cls = 'dev-green';
                $title = $labels[$op] . ': расхождение ' . $d . ' дн. (норма)';
            } elseif ($d <= 5) {
                $cls = 'dev-yellow';
                $title = $labels[$op] . ': расхождение ' . $d . ' дн.';
            } else {
                $cls = 'dev-red';
                $title = $labels[$op] . ': расхождение ' . $d . ' дн.';
            }
            $indicator_html .= '<span class="gantt-indicator ' . $cls . '" title="' . htmlspecialchars($title) . '"></span>';
        }
        $indicator_html .= '</span>';
        $filter_esc = htmlspecialchars($row['filter'] ?? '', ENT_QUOTES, 'UTF-8');
        $order_esc = htmlspecialchars($order_number, ENT_QUOTES, 'UTF-8');
        echo "<td class='gantt-col' style='display:none;'>" . $indicator_html . "<button type='button' onclick=\"openGanttForPosition('{$order_esc}', '{$filter_esc}')\" class='btn-secondary btn-sm gantt-btn' title='Диаграмма Ганта план–факт'>" .
            "<svg class='gantt-icon' xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'><rect x='3' y='6' width='6' height='4' rx='1'/><rect x='3' y='14' width='10' height='4' rx='1'/><rect x='3' y='22' width='14' height='4' rx='1'/></svg>" .
            "</button></td>";

        echo "</tr>";
    }

    // Итоговая строка
    $summ_difference = $filter_count_in_order - $filter_count_produced;

    echo "<tr>
        <td></td>
        <td>Итого:</td>
        <td>".(int)$filter_count_in_order."</td>
        <td colspan='7'></td>
        <td>".(int)$filter_count_produced."</td>
        <td>".(int)$summ_difference."*</td>
        <td>".(int)$corr_fact_summ."*</td>
        <td class='gantt-col' style='display:none;'></td>
      </tr>";

    echo "</table>";
    echo "</div>";
    echo "<p>* - без учета перевыполнения</p>";
    ?>

</div>

<!-- Модальное окно для позиций с отставанием > 20% -->
<div id="laggingPositionsModal" class="modal">
    <div class="modal-content modal-compact">
        <div class="modal-header">
            <h3>Отставание &gt; 20%</h3>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button onclick="printLaggingPositions()" class="btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">Печать</button>
                <button class="modal-close" onclick="closeLaggingPositionsModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="laggingPositionsContent">
                <p style="text-align:center;padding:20px;color:var(--muted);font-size:0.75rem;">Загрузка...</p>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для позиций с нулевым выпуском -->
<div id="zeroProductionModal" class="modal">
    <div class="modal-content" style="max-width: 585px;">
        <div class="modal-header">
            <h3>Позиции с нулевым выпуском</h3>
            <button class="modal-close" onclick="closeZeroProductionModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="zeroProductionContent">
                <p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для проверки гофропакетов -->
<div id="gofraCheckModal" class="modal">
    <div class="modal-content" style="max-width: 585px;">
        <div class="modal-header">
            <h3>Проверка гофропакетов</h3>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button onclick="printGofraCheck()" class="btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                    Печать
                </button>
                <button class="modal-close" onclick="closeGofraCheckModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body">
            <!-- Фильтры для типов проблем -->
            <div id="gofraFilters" style="margin-bottom: 1rem; padding: 0.75rem; background: var(--secondary); border-radius: calc(var(--radius) - 2px); border: 1px solid var(--border);">
                <div style="font-weight: 600; margin-bottom: 0.5rem; color: var(--ink); font-size: 0.75rem;">Фильтр по типу проблемы:</div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.75rem;">
                        <input type="checkbox" id="filterNoGofra" checked style="margin: 0;">
                        <span style="color: #dc2626; font-weight: 600;">Нет гофропакетов</span>
                        <span style="color: var(--muted); font-size: 0.6875rem;">(0 гофропакетов, но есть выпуск)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.75rem;">
                        <input type="checkbox" id="filterShortage" checked style="margin: 0;">
                        <span style="color: #f59e0b; font-weight: 600;">Недостаток</span>
                        <span style="color: var(--muted); font-size: 0.6875rem;">(недостаток ≥ 20 штук)</span>
                    </label>
                </div>
            </div>
            <div id="gofraCheckContent">
                <p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для информации о фильтре -->
<div id="filterInfoModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px; max-height: 70vh; overflow-y: auto; padding: 20px;">
        <span class="close" onclick="closeFilterInfoModal()" style="position: absolute; top: 10px; right: 20px; color: #9ca3af; font-size: 28px; cursor: pointer;">&times;</span>
        <div id="filterInfoContent">
            <p style="color: #9ca3af; text-align: center; padding: 20px;">
                Загрузка данных...
            </p>
        </div>
    </div>
</div>

<!-- Модальное окно: диаграмма Ганта план/факт (строгий дизайн) -->
<div id="ganttModal" class="modal" style="display: none;">
    <div class="gantt-modal-content">
        <div class="gantt-modal-header">
            <h3 id="ganttModalTitle">Диаграмма Ганта план/факт</h3>
            <button type="button" class="gantt-modal-close" onclick="closeGanttModal()" title="Закрыть">&times;</button>
        </div>
        <div class="gantt-modal-body">
            <iframe id="ganttModalIframe"></iframe>
        </div>
    </div>
</div>

<script>
    window.addEventListener('load', function () {
        document.getElementById('loading').style.display = 'none';
    });

    // Функция для показа модального окна с позициями нулевого выпуска
    function showZeroProductionPositions() {
        const modal = document.getElementById('zeroProductionModal');
        const content = document.getElementById('zeroProductionContent');
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        
        // Загружаем данные
        loadZeroProductionData();
    }

    // Функция для закрытия модального окна
    function closeZeroProductionModal() {
        document.getElementById('zeroProductionModal').style.display = 'none';
    }

    // Функция для показа позиций с отставанием > 20%
    function showLaggingPositions() {
        const modal = document.getElementById('laggingPositionsModal');
        modal.style.display = 'flex';
        loadLaggingPositionsData();
    }

    function closeLaggingPositionsModal() {
        document.getElementById('laggingPositionsModal').style.display = 'none';
    }

    function printLaggingPositions() {
        const orderNumber = '<?= htmlspecialchars($order_number) ?>';
        const content = document.getElementById('laggingPositionsContent');
        const printWindow = window.open('', '_blank', 'width=700,height=500');
        const printHTML = `<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Отставание &gt; 20% - ${orderNumber}</title><style>body{font-family:Arial,sans-serif;margin:20px;font-size:11px}h1{color:#374151;font-size:14px;margin:0 0 10px}h2{color:#6b7280;font-size:12px;margin:0 0 15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #374151;padding:4px;text-align:left}th{background:#f3f4f6}</style></head><body><h1>Позиции с отставанием более 20%</h1><h2>Заявка: ${orderNumber} | ${new Date().toLocaleDateString('ru-RU')}</h2>${content.innerHTML}</body></html>`;
        printWindow.document.write(printHTML);
        printWindow.document.close();
        printWindow.onload = function() { printWindow.focus(); printWindow.print(); };
    }

    // Позиции, где изготовлено меньше заданного более чем на 20%: produced < planned * 0.8
    function loadLaggingPositionsData() {
        const content = document.getElementById('laggingPositionsContent');
        content.innerHTML = '<p style="text-align:center;padding:20px;color:var(--muted);font-size:0.75rem;">Загрузка...</p>';
        
        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const laggingPositions = [];
        
        for (let i = 1; i < rows.length - 1; i++) {
            const row = rows[i];
            const cells = row.querySelectorAll('td');
            
            if (cells.length >= 13) {
                const filter = cells[1].textContent.trim();
                const plannedCount = parseInt(cells[2].textContent) || 0;
                const producedElement = cells[10].querySelector('.tooltip') || cells[10];
                const producedCount = parseInt(producedElement.firstChild ? producedElement.firstChild.textContent.trim() : cells[10].textContent.trim()) || 0;
                const gofraElement = cells[12].querySelector('.tooltip') || cells[12];
                const gofraText = gofraElement.firstChild ? gofraElement.firstChild.textContent.trim() : cells[12].textContent.trim();
                const gofraCount = parseInt(gofraText) || 0;
                const remark = cells[9].textContent.trim();
                
                // manufactured < planned * 0.8 (отставание более 20%)
                if (plannedCount > 0 && producedCount < plannedCount * 0.8) {
                    const lagPercent = Math.round((1 - producedCount / plannedCount) * 100);
                    laggingPositions.push({
                        filter: filter,
                        plannedCount: plannedCount,
                        producedCount: producedCount,
                        gofraCount: gofraCount,
                        remark: remark,
                        lagPercent: lagPercent
                    });
                }
            }
        }
        
        displayLaggingPositions(laggingPositions);
    }

    function displayLaggingPositions(positions) {
        const content = document.getElementById('laggingPositionsContent');
        
        if (positions.length === 0) {
            content.innerHTML = `<div class="no-zero-positions"><p style="color:var(--success);font-weight:600;font-size:0.75rem;">Нет позиций с отставанием &gt; 20%</p></div>`;
            return;
        }
        
        let html = `<div class="zero-positions-header">Найдено: ${positions.length}</div>`;
        html += `<table class="compact-table"><thead><tr><th>Фильтр</th><th style="text-align:center;">План</th><th style="text-align:center;">Изгот.</th><th style="text-align:center;">Гофра</th><th style="text-align:center;">−%</th>${positions.some(p => p.remark) ? '<th>Прим.</th>' : ''}</tr></thead><tbody>`;
        
        positions.forEach((position) => {
            html += `<tr><td>${position.filter}</td><td style="text-align:center;">${position.plannedCount}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${position.producedCount}</td><td style="text-align:center;color:var(--accent);">${position.gofraCount}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${position.lagPercent}%</td>${positions.some(p => p.remark) ? `<td style="font-size:0.65rem;color:var(--muted);">${position.remark || ''}</td>` : ''}</tr>`;
        });
        
        html += `
                </tbody>
            </table>
        `;
        
        content.innerHTML = html;
    }

    // Функция для загрузки данных о позициях с нулевым выпуском
    function loadZeroProductionData() {
        const content = document.getElementById('zeroProductionContent');
        content.innerHTML = '<p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>';
        
        // Получаем данные из таблицы на странице
        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const zeroPositions = [];
        
        // Пропускаем заголовок и итоговую строку
        for (let i = 1; i < rows.length - 1; i++) {
            const row = rows[i];
            const cells = row.querySelectorAll('td');
            
            if (cells.length >= 13) {
                const filter = cells[1].textContent.trim();
                const plannedCount = parseInt(cells[2].textContent) || 0;
                const producedCount = parseInt(cells[10].textContent) || 0;
                const remark = cells[9].textContent.trim();
                
                // Извлекаем количество гофропакетов (колонка 12)
                // Учитываем, что может быть тултип, поэтому берем первый текстовый узел
                const gofraElement = cells[12].querySelector('.tooltip') || cells[12];
                const gofraText = gofraElement.firstChild ? gofraElement.firstChild.textContent.trim() : cells[12].textContent.trim();
                const gofraCount = parseInt(gofraText) || 0;
                
                if (producedCount === 0 && plannedCount > 0) {
                    zeroPositions.push({
                        filter: filter,
                        plannedCount: plannedCount,
                        producedCount: producedCount,
                        remark: remark,
                        gofraCount: gofraCount
                    });
                }
            }
        }
        
        // Отображаем результаты
        displayZeroPositions(zeroPositions);
    }

    // Функция для отображения позиций с нулевым выпуском
    function displayZeroPositions(positions) {
        const content = document.getElementById('zeroProductionContent');
        
        if (positions.length === 0) {
            content.innerHTML = `
                <div class="no-zero-positions">
                    <p style="color: var(--success); font-weight: 600;">Отлично! Все позиции имеют выпуск больше 0</p>
                </div>
            `;
            return;
        }
        
        let html = `<div class="zero-positions-header">Найдено позиций с нулевым выпуском: ${positions.length}</div>`;
        html += `
            <table class="compact-table">
                <thead>
                    <tr>
                        <th>Фильтр</th>
                        <th style="text-align: center;">План, шт</th>
                        <th style="text-align: center;">Гофропакетов, шт</th>
                        <th style="text-align: center;">Выпуск, шт</th>
                        ${positions.some(p => p.remark) ? '<th>Примечание</th>' : ''}
                    </tr>
                </thead>
                <tbody>
        `;
        
        positions.forEach((position, index) => {
            html += `
                <tr>
                    <td>${position.filter}</td>
                    <td style="text-align: center;">${position.plannedCount}</td>
                    <td style="text-align: center; color: var(--accent); font-weight: 500;">${position.gofraCount}</td>
                    <td style="text-align: center; color: #dc2626; font-weight: 600;">0</td>
                    ${positions.some(p => p.remark) ? `<td style="font-size: 0.6875rem; color: var(--muted);">${position.remark || ''}</td>` : ''}
                </tr>
            `;
        });
        
        html += `
                </tbody>
            </table>
        `;
        
        content.innerHTML = html;
    }

    // Функция для открытия спецификации для рабочих
    function openWorkersSpecification() {
        const orderNumber = '<?= htmlspecialchars($order_number) ?>';
        
        // Создаем форму для POST запроса
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'show_order_for_workers.php';
        form.target = '_blank';
        
        // Добавляем скрытое поле с номером заявки
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'order_number';
        input.value = orderNumber;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // Функция подтверждения отправки заявки в архив
    function confirmArchiveOrder() {
        const orderNumber = '<?= htmlspecialchars($order_number) ?>';
        const confirmed = confirm('Вы уверены, что хотите отправить заявку "' + orderNumber + '" в архив?\n\nЭто действие можно отменить только администратором базы данных.');
        
        if (confirmed) {
            document.getElementById('archiveForm').submit();
        }
    }

    // Функция для проверки гофропакетов
    function checkGofraPackages() {
        const modal = document.getElementById('gofraCheckModal');
        const content = document.getElementById('gofraCheckContent');
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        
        // Загружаем данные
        loadGofraCheckData();
        
        // Добавляем обработчики событий для фильтров
        const filterNoGofra = document.getElementById('filterNoGofra');
        const filterShortage = document.getElementById('filterShortage');
        
        // Удаляем старые обработчики, если они есть
        filterNoGofra.removeEventListener('change', loadGofraCheckData);
        filterShortage.removeEventListener('change', loadGofraCheckData);
        
        // Добавляем новые обработчики
        filterNoGofra.addEventListener('change', loadGofraCheckData);
        filterShortage.addEventListener('change', loadGofraCheckData);
    }

    // Функция для закрытия модального окна проверки гофропакетов
    function closeGofraCheckModal() {
        document.getElementById('gofraCheckModal').style.display = 'none';
    }

    // Функция для печати таблицы проверки гофропакетов
    function printGofraCheck() {
        const orderNumber = '<?= htmlspecialchars($order_number) ?>';
        const content = document.getElementById('gofraCheckContent');
        
        // Создаем новое окно для печати
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        
        // Формируем HTML для печати
        const printHTML = `
            <!DOCTYPE html>
            <html lang="ru">
            <head>
                <meta charset="UTF-8">
                <title>Проверка гофропакетов - ${orderNumber}</title>
                <style>
                    body { 
                        font-family: Arial, sans-serif; 
                        margin: 20px; 
                        font-size: 12px;
                        line-height: 1.4;
                    }
                    h1 { 
                        color: #dc2626; 
                        text-align: center; 
                        margin-bottom: 20px;
                        font-size: 18px;
                    }
                    h2 { 
                        color: #374151; 
                        margin: 15px 0 10px 0;
                        font-size: 14px;
                    }
                    table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        margin-bottom: 20px;
                        font-size: 11px;
                    }
                    th, td { 
                        border: 1px solid #374151; 
                        padding: 6px; 
                        text-align: center;
                    }
                    th { 
                        background-color: #f3f4f6; 
                        font-weight: bold;
                    }
                    .no-problems { 
                        text-align: center; 
                        color: #10b981; 
                        font-weight: bold;
                        padding: 20px;
                    }
                    .problem-count { 
                        color: #dc2626; 
                        font-weight: bold; 
                        margin-bottom: 10px;
                    }
                    .description { 
                        color: #6b7280; 
                        margin-bottom: 15px;
                        font-size: 10px;
                    }
                    @media print {
                        body { margin: 0; }
                        h1 { font-size: 16px; }
                    }
                </style>
            </head>
            <body>
                <h1>Проверка гофропакетов</h1>
                <h2>Заявка: ${orderNumber}</h2>
                <p style="color: #6b7280; font-size: 11px;">Дата проверки: ${new Date().toLocaleDateString('ru-RU')}</p>
                <p style="color: #374151; font-size: 11px; margin: 10px 0;">Проверяются позиции с проблемами гофропакетов:</p>
                <ul style="color: #6b7280; font-size: 10px; margin: 5px 0 15px 0;">
                    <li>• Гофропакетов = 0, но выпущено фильтров > 0</li>
                    <li>• Недостаток гофропакетов ≥ 20 штук</li>
                </ul>
                
                ${content.innerHTML}
                
                <div style="margin-top: 30px; font-size: 10px; color: #6b7280; text-align: center;">
                    Документ сформирован автоматически системой планирования производства
                </div>
            </body>
            </html>
        `;
        
        // Записываем HTML в новое окно
        printWindow.document.write(printHTML);
        printWindow.document.close();
        
        // Ждем загрузки и открываем диалог печати
        printWindow.onload = function() {
            printWindow.focus();
            printWindow.print();
        };
    }

    // Функция для загрузки данных о гофропакетах
    function loadGofraCheckData() {
        const content = document.getElementById('gofraCheckContent');
        content.innerHTML = '<p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>';
        
        // Получаем настройки фильтров
        const showNoGofra = document.getElementById('filterNoGofra').checked;
        const showShortage = document.getElementById('filterShortage').checked;
        
        // Получаем данные из таблицы на странице
        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const problemPositions = [];
        
        // Пропускаем заголовок и итоговую строку
        for (let i = 1; i < rows.length - 1; i++) {
            const row = rows[i];
            const cells = row.querySelectorAll('td');
            
            if (cells.length >= 13) {
                const num = cells[0].textContent.trim();
                const filter = cells[1].textContent.trim();
                const plan = cells[2].textContent.trim();
                // Извлекаем только число из ячейки, игнорируя тултип
                // Ищем первый элемент с текстом (число) в ячейке
                const producedElement = cells[10].querySelector('.tooltip') || cells[10];
                const gofraElement = cells[12].querySelector('.tooltip') || cells[12];
                
                const produced = producedElement.firstChild ? producedElement.firstChild.textContent.trim() : cells[10].textContent.trim();
                const gofra = gofraElement.firstChild ? gofraElement.firstChild.textContent.trim() : cells[12].textContent.trim();
                
                const gofraCount = parseInt(gofra) || 0;
                const producedCount = parseInt(produced) || 0;
                const shortage = Math.max(0, producedCount - gofraCount);
                
                // Определяем тип проблемы и проверяем фильтры
                let problemType = '';
                let shouldShow = false;
                
                if (gofraCount === 0 && producedCount > 0) {
                    problemType = 'Нет гофропакетов';
                    shouldShow = showNoGofra;
                } else if (gofraCount < producedCount && producedCount > 0 && shortage >= 20) {
                    problemType = 'Недостаток';
                    shouldShow = showShortage;
                }
                
                if (shouldShow) {
                    problemPositions.push({
                        num: num,
                        filter: filter,
                        plan: plan,
                        produced: producedCount,
                        gofra: gofraCount,
                        problemType: problemType,
                        shortage: shortage
                    });
                }
            }
        }
        
        // Формируем HTML с результатами
        if (problemPositions.length === 0) {
            let message = '';
            if (!showNoGofra && !showShortage) {
                message = 'Выберите хотя бы один тип проблемы для отображения.';
            } else {
                message = 'Для выбранных типов проблем ничего не найдено.';
            }
            
            content.innerHTML = `
                <div style="text-align: center; padding: 2.5rem;">
                    <p style="color: var(--success); font-size: 0.875rem; font-weight: 600;">${message}</p>
                    <p style="color: var(--muted); font-size: 0.75rem; margin-top: 0.5rem;">Проверьте настройки фильтров или убедитесь, что данные корректны.</p>
                </div>
            `;
        } else {
            // Формируем список активных фильтров
            let activeFilters = [];
            if (showNoGofra) activeFilters.push('Гофропакетов = 0, но выпущено фильтров > 0');
            if (showShortage) activeFilters.push('Недостаток гофропакетов ≥ 20 штук');
            
            let html = `
                <div class="zero-positions-header" style="margin-bottom: 1rem;">
                    Обнаружено проблемных позиций: ${problemPositions.length}
                </div>
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>Фильтр</th>
                            <th style="text-align: center;">План, шт</th>
                            <th style="text-align: center;">Выпущено, шт</th>
                            <th style="text-align: center;">Гофропакетов, шт</th>
                            <th style="text-align: center;">Недостаток, шт</th>
                            <th style="text-align: center;">Тип проблемы</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            problemPositions.forEach(pos => {
                // Цвет для типа проблемы
                let typeColor = pos.problemType === 'Нет гофропакетов' ? '#dc2626' : '#f59e0b';
                
                html += `
                    <tr>
                        <td>${pos.filter}</td>
                        <td style="text-align: center;">${pos.plan}</td>
                        <td style="text-align: center; color: var(--success); font-weight: 600;">${pos.produced}</td>
                        <td style="text-align: center; color: #dc2626; font-weight: 600;">${pos.gofra}</td>
                        <td style="text-align: center; color: #dc2626; font-weight: 600;">${pos.shortage}</td>
                        <td style="text-align: center; color: ${typeColor}; font-weight: 600; font-size: 0.6875rem;">${pos.problemType}</td>
                    </tr>
                `;
            });
            
            html += `
                    </tbody>
                </table>
            `;
            
            content.innerHTML = html;
        }
    }

    // Переключение видимости столбца «Диаграмма Ганта»
    function toggleGanttColumn() {
        const col = document.querySelectorAll('.gantt-col');
        if (!col.length) return;
        const isHidden = col[0].style.display === 'none';
        col.forEach(function(el) {
            el.style.display = isHidden ? '' : 'none';
        });
        const btn = document.getElementById('btnToggleGantt');
        if (btn) {
            if (isHidden) {
                btn.classList.add('btn-toggle-gantt-active');
            } else {
                btn.classList.remove('btn-toggle-gantt-active');
            }
        }
    }

    // Открытие диаграммы Ганта для позиции (план–факт) в модальном окне
    function openGanttForPosition(orderNumber, filter) {
        const modal = document.getElementById('ganttModal');
        const titleEl = document.getElementById('ganttModalTitle');
        const iframe = document.getElementById('ganttModalIframe');
        const contentEl = modal.querySelector('.gantt-modal-content');
        titleEl.textContent = 'Диаграмма Ганта план/факт — ' + orderNumber + ' · ' + filter;
        contentEl.style.width = '';
        contentEl.style.height = '';
        iframe.style.width = '1200px';
        iframe.style.height = '700px';
        iframe.src = 'gantt_plan_fact.php?order_number=' + encodeURIComponent(orderNumber) + '&filter=' + encodeURIComponent(filter);
        modal.style.display = 'flex';
    }

    function closeGanttModal() {
        const modal = document.getElementById('ganttModal');
        const iframe = document.getElementById('ganttModalIframe');
        const contentEl = modal.querySelector('.gantt-modal-content');
        modal.style.display = 'none';
        iframe.src = 'about:blank';
        contentEl.style.width = '';
        contentEl.style.height = '';
        iframe.style.width = '';
        iframe.style.height = '';
    }

    // Подгонка размера модального окна под контент iframe (таблицы Ганта)
    window.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'gantt-resize') {
            var w = event.data.width;
            var h = event.data.height;
            if (typeof w !== 'number' || typeof h !== 'number' || w <= 0 || h <= 0) return;
            var contentEl = document.querySelector('#ganttModal .gantt-modal-content');
            var iframe = document.getElementById('ganttModalIframe');
            if (!contentEl || !iframe) return;
            var headerH = 52;
            var pad = 19;
            var maxW = window.innerWidth * 0.95;
            var maxH = window.innerHeight * 0.9;
            contentEl.style.width = Math.max(600, Math.min(w + pad * 2, maxW)) + 'px';
            contentEl.style.height = Math.min(headerH + h + pad, maxH) + 'px';
            contentEl.style.overflow = 'hidden';
            iframe.style.width = '';
            iframe.style.height = '';
        }
    });

    // Функция для показа информации о фильтре
    function showFilterInfo(filterName) {
        const modal = document.getElementById('filterInfoModal');
        const content = document.getElementById('filterInfoContent');
        
        // Показываем модальное окно
        modal.style.display = 'flex';
        
        // Загружаем данные о фильтре
        loadFilterInfo(filterName);
    }

    // Функция для закрытия модального окна информации о фильтре
    function closeFilterInfoModal() {
        document.getElementById('filterInfoModal').style.display = 'none';
    }

    // Функция для загрузки информации о фильтре
    function loadFilterInfo(filterName) {
        const content = document.getElementById('filterInfoContent');
        content.innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 20px; height: 20px; border: 2px solid var(--border); border-top: 2px solid var(--accent); border-radius: 50%; animation: spin 1s linear infinite;"></div><br>Загрузка параметров...</div>';
        
        // Создаем AJAX запрос для получения данных о фильтре
        fetch('get_filter_structure.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'filter_name=' + encodeURIComponent(filterName)
        })
        .then(response => response.text())
        .then(html => {
            // Извлекаем только содержимое body из ответа
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const bodyContent = doc.body.innerHTML;
            content.innerHTML = bodyContent;
        })
        .catch(error => {
            content.innerHTML = '<p style="color: red; text-align: center; padding: 20px;">Ошибка загрузки данных: ' + error.message + '</p>';
        });
    }

    // Закрытие модального окна при клике вне его
    window.onclick = function(event) {
        const zeroModal = document.getElementById('zeroProductionModal');
        const laggingModal = document.getElementById('laggingPositionsModal');
        const gofraModal = document.getElementById('gofraCheckModal');
        const filterModal = document.getElementById('filterInfoModal');
        const ganttModal = document.getElementById('ganttModal');
        
        if (event.target === zeroModal) {
            closeZeroProductionModal();
        }
        if (event.target === laggingModal) {
            closeLaggingPositionsModal();
        }
        if (event.target === gofraModal) {
            closeGofraCheckModal();
        }
        if (event.target === filterModal) {
            closeFilterInfoModal();
        }
        if (event.target === ganttModal) {
            closeGanttModal();
        }
    }
</script>

</body>
</html>
