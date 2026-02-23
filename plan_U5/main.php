<?php
// ПРОФИЛИРОВАНИЕ: засекаем время начала
$_profile_start = microtime(true);
$_profile_times = [];

function profile_mark($label) {
    global $_profile_start, $_profile_times;
    $_profile_times[$label] = round((microtime(true) - $_profile_start) * 1000, 2);
}

// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
profile_mark('Auth includes loaded');

// Подключаем файлы настроек/инструментов
require_once('settings.php');
require_once('tools/tools.php');
require_once('tools/ensure_salary_warehouse_tables.php');
profile_mark('Settings & tools loaded');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();
profile_mark('Auth check');

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;
profile_mark('User loaded');

// Если пользователь не найден, используем данные из сессии
if (!$user) {
    $user = [
        'full_name' => $session['full_name'] ?? 'Пользователь',
        'phone' => $session['phone'] ?? ''
    ];
}

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);
profile_mark('User departments loaded');

// Проверяем, есть ли у пользователя доступ к цеху U5
$hasAccessToU5 = false;
$userRole = null;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U5') {
        $hasAccessToU5 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

// Определяем текущий цех
// ВАЖНО: Это файл plan_U5/main.php - принудительно устанавливаем цех U5!
$currentDepartment = 'U5';
$_SESSION['auth_department'] = 'U5'; // Обновляем сессию для согласованности

// Если нет доступа к U5, показываем предупреждение, но не блокируем
if (!$hasAccessToU5) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U5</h3>";
    echo "<p>Ваши доступные цеха: ";
    $deptNames = [];
    foreach ($userDepartments as $dept) {
        $deptNames[] = $dept['department_code'] . " (" . $dept['role_name'] . ")";
    }
    echo implode(", ", $deptNames);
    echo "</p>";
    echo "<p><a href='../index.php'>← Вернуться на главную страницу</a></p>";
    echo "</div>";
    
    // Устанавливаем роль по умолчанию для отображения
    $userRole = 'guest';
}

// Функция проверки доступа к заявкам на лазер
function canAccessLaserRequests($userDepartments, $currentDepartment) {
    // Проверяем доступ для текущего цеха
    foreach ($userDepartments as $dept) {
        if ($dept['department_code'] === $currentDepartment) {
            $role = $dept['role_name'];
            // Доступ имеют: сборщики, мастера, директора (но не менеджеры)
            return in_array($role, ['assembler', 'supervisor', 'director']);
        }
    }
    return false;
}

// Для main.php всегда проверяем доступ к цеху U5
$canAccessLaser = canAccessLaserRequests($userDepartments, 'U5');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>U5</title>

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
            --danger:#dc2626;
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
        .layout{ width:100%; border-spacing:16px; border:0; background:transparent; }
        .header-row .header-cell{ padding:0; border:0; background:transparent; }
        .headerbar{ display:flex; align-items:center; gap:12px; padding:10px 4px; color:#374151; }
        .headerbar .spacer{ flex:1; }

        /* панели-колонки */
        .content-row > td{ vertical-align:top; }
        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:14px;
        }
        .panel--main{ box-shadow:var(--shadow-soft); }
        .section-title{
            font-size:15px; font-weight:600; color:#111827;
            margin:0 0 10px; padding-bottom:6px; border-bottom:1px solid var(--border);
        }

        /* таблицы внутри панелей как карточки */
        .panel table{
            width:100%;
            border-collapse:collapse;
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            box-shadow:var(--shadow-soft);
            overflow:hidden;
        }
        .panel td,.panel th{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
        .panel tr:last-child td{border-bottom:0}

        /* вертикальные стеки вместо <p> */
        .stack{ display:flex; flex-direction:column; gap:8px; }
        .stack-lg{ gap:12px; }

        /* кнопки (единый стиль) */
        button, input[type="submit"]{
            appearance:none;
            border:1px solid transparent;
            cursor:pointer;
            background:var(--accent);
            color:var(--accent-ink);
            padding:7px 14px;
            border-radius:9px;
            font-weight:600;
            transition:background .2s, box-shadow .2s, transform .04s, border-color .2s;

            /* новая тень */
            box-shadow: 0 3px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08);
        }
        button:hover, input[type="submit"]:hover{ background:#1e47c5; box-shadow:0 2px 8px rgba(2,8,20,.10); transform:translateY(-1px); }
        button:active, input[type="submit"]:active{ transform:translateY(0); }
        button:disabled, input[type="submit"]:disabled{
            background:#e5e7eb; color:#9ca3af; border-color:#e5e7eb; box-shadow:none; cursor:not-allowed;
        }
        /* если где-то остались инлайновые background — приглушим */
        input[type="submit"][style*="background"], button[style*="background"]{
            background:var(--accent)!important; color:#fff!important;
        }

        /* модальные окна */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: var(--panel);
            margin: 5% auto;
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--ink);
        }
        .close {
            color: var(--muted);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        .close:hover {
            color: var(--ink);
        }

        /* Компактное модальное окно «Редактор выпущенной продукции» */
        #productEditorModal .modal-content {
            max-width: 720px;
            margin: 2% auto;
            padding: 12px 16px;
            max-height: 88vh;
        }
        #productEditorModal .modal-header {
            margin-bottom: 10px;
            padding-bottom: 6px;
        }
        #productEditorModal .modal-title {
            font-size: 15px;
        }
        #productEditorModal #productEditorContent > div:first-child {
            margin-bottom: 12px;
            padding: 10px 12px;
        }
        #productEditorModal #productEditorContent h4 {
            margin: 0 0 8px 0;
            font-size: 13px;
        }
        #productEditorModal #productEditorContent input[type="date"],
        #productEditorModal #productEditorContent button {
            padding: 6px 12px;
            font-size: 13px;
        }
        #productEditorModal #dataTableContainer > div[style*="margin-bottom: 20px"] {
            margin-bottom: 12px !important;
        }
        #productEditorModal #dataTableContainer h3 {
            font-size: 14px !important;
        }
        #productEditorModal #dataTableContainer table {
            font-size: 12px !important;
        }
        #productEditorModal #dataTableContainer th,
        #productEditorModal #dataTableContainer td {
            padding: 5px 6px !important;
        }
        #productEditorModal #dataTableContainer div[style*="margin-bottom: 30px"] {
            margin-bottom: 16px !important;
            padding: 10px 12px !important;
        }
        #productEditorModal #dataTableContainer div[style*="margin-bottom: 30px"] h4 {
            margin-bottom: 10px !important;
            font-size: 13px !important;
        }

        /* Модальное окно «Рейтинг фильтров» — как Редактор выпущенной продукции */
        #filterRatingModal .modal-content {
            max-width: 720px;
            margin: 2% auto;
            padding: 12px 16px;
            max-height: 88vh;
        }
        #filterRatingModal .modal-header {
            margin-bottom: 10px;
            padding-bottom: 6px;
        }
        #filterRatingModal .modal-title {
            font-size: 15px;
        }
        #filterRatingModal #filterRatingTableWrap table {
            font-size: 12px;
            width: 100%;
            border-collapse: collapse;
        }
        #filterRatingModal #filterRatingTableWrap th,
        #filterRatingModal #filterRatingTableWrap td {
            padding: 5px 6px;
            border: 1px solid var(--border);
        }
        #filterRatingModal #filterRatingTableWrap th {
            background: #f8f9fa;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }
        #filterRatingModal #filterRatingTableWrap th:hover {
            background: #e9ecef;
        }

        /* Модальное окно «Поиск по размерам» — как Рейтинг фильтров */
        #searchByDimensionsModal .modal-content {
            max-width: 720px;
            margin: 2% auto;
            padding: 12px 16px;
            max-height: 88vh;
        }
        #searchByDimensionsModal .modal-header {
            margin-bottom: 10px;
            padding-bottom: 6px;
        }
        #searchByDimensionsModal .modal-title {
            font-size: 15px;
        }
        #searchByDimensionsModal .search-dimensions-toolbar {
            margin-bottom: 12px;
            padding: 10px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        #searchByDimensionsModal .search-dimensions-toolbar label {
            font-weight: 600;
            color: var(--ink);
        }
        #searchByDimensionsModal .search-dimensions-toolbar input {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
        }
        #searchByDimensionsModal #searchByDimensionsTableWrap table {
            font-size: 12px;
            width: 100%;
            border-collapse: collapse;
        }
        #searchByDimensionsModal #searchByDimensionsTableWrap th,
        #searchByDimensionsModal #searchByDimensionsTableWrap td {
            padding: 5px 6px;
            border: 1px solid var(--border);
        }
        #searchByDimensionsModal #searchByDimensionsTableWrap th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .modal-buttons button {
            width: 100%;
            text-align: left;
            padding: 12px 16px;
            font-size: 14px;
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

        .modal-body .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }

        .modal-body .table th, 
        .modal-body .table td {
            border-bottom: 1px solid var(--border);
            padding: 6px 4px;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        .modal-body .table th { 
            width: 35%; 
            color: var(--muted); 
            font-weight: 600; 
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

        /* Стили для выпадающего списка фильтров */
        .filter-suggestion-item {
            padding: 8px 10px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            transition: background-color 0.2s;
        }

        .filter-suggestion-item:hover {
            background-color: #f8f9fa;
        }

        .filter-suggestion-item:last-child {
            border-bottom: none;
        }

        .filter-suggestion-item.highlighted {
            background-color: var(--accent-soft);
            color: var(--accent);
        }

        /* поля ввода/селекты */
        input[type="text"], input[type="date"], input[type="number"], input[type="password"],
        textarea, select{
            min-width:180px; padding:7px 10px;
            border:1px solid var(--border); border-radius:9px;
            background:#fff; color:var(--ink); outline:none;
            transition:border-color .2s, box-shadow .2s;
        }
        input:focus, textarea:focus, select:focus{
            border-color:#c7d2fe; box-shadow:0 0 0 3px #e0e7ff;
        }
        textarea{min-height:92px; resize:vertical}

        /* инфоблоки */
        .alert{
            background:#fffbe6; border:1px solid #f4e4a4; color:#634100;
            padding:10px; border-radius:9px; margin:12px 0; font-weight:600;
        }
        .important-message{
            background:#fff1f2; border:1px solid #ffd1d8; color:#6b1220;
            padding:12px; border-radius:9px; margin:12px 0; font-weight:700;
        }
        .highlight_green{
            background:#e7f5ee; color:#0f5132; border:1px solid #cfe9db;
            padding:2px 6px; border-radius:6px; font-weight:600;
        }
        .highlight_red{
            background:#fff7e6; color:#7a3e00; border:1px solid #ffe1ad;
            padding:2px 6px; border-radius:6px; font-weight:600;
        }

        /* чипы заявок справа */
        .saved-orders input[type="submit"]{
            display:inline-block; margin:4px 6px 0 0;
            border-radius:999px!important; padding:6px 10px!important;
            background:var(--accent)!important; color:#fff!important;
            border:none!important; box-shadow:0 1px 4px rgba(2,8,20,.06);
        }
        
        /* оранжевые кнопки для перепланирования */
        .saved-orders input[type="submit"].replanning-btn{
            background:#f59e0b!important; color:#fff!important;
            box-shadow:0 1px 4px rgba(245, 158, 11, 0.3);
        }
        
        .saved-orders input[type="submit"].replanning-btn:hover{
            background:#d97706!important;
            box-shadow:0 2px 8px rgba(245, 158, 11, 0.4);
        }

        /* карточка поиска */
        .search-card{
            border:1px solid var(--border);
            border-radius:10px; background:#fff;
            box-shadow:var(--shadow-soft); padding:12px; margin-top:8px;
        }
        .muted{color:var(--muted)}

        /* адаптив */
        @media (max-width:1100px){
            .layout{ border-spacing:10px; }
            .content-row > td{ display:block; width:auto!important; }
        }
        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            padding:10px 18px;
            background:var(--panel);
            border-bottom:1px solid var(--border);
            box-shadow:var(--shadow-soft);
            border-radius:var(--radius);
            margin-bottom:16px;
        }
        .topbar-left, .topbar-right, .topbar-center{
            display:flex;
            align-items:center;
            gap:10px;
        }
        .topbar-center{
            font-weight:600;
            font-size:15px;
            color:var(--ink);
        }
        .logo{
            font-size:18px;
            font-weight:700;
            color:var(--accent);
        }
        .system-name{
            font-size:14px;
            font-weight:500;
            color:var(--muted);
        }
        .logout-btn{
            background:var(--accent);
            color:var(--accent-ink);
            padding:6px 12px;
            border-radius:8px;
            font-weight:600;
            box-shadow:0 2px 6px rgba(0,0,0,0.08);
        }
        .logout-btn:hover{
            background:#1e47c5;
            text-decoration:none;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php
/** подключение файлов настроек/инструментов уже выполнено в начале файла */

global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;

// Устанавливаем переменные для совместимости со старым кодом
$workshop = $currentDepartment;
$advertisement = 'Информация';

// Добавляем аккуратную панель авторизации
echo "<!-- Аккуратная панель авторизации -->
<div style='position: fixed; top: 10px; right: 10px; background: white; padding: 12px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e5e7eb;'>
    <div style='display: flex; align-items: center; gap: 12px;'>
        <div style='width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px;'>
            " . mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8') . "
        </div>
        <div>
            <div style='font-weight: 600; font-size: 14px; color: #1f2937;'>" . htmlspecialchars($user['full_name'] ?? 'Пользователь') . "</div>
            <div style='font-size: 12px; color: #6b7280;'>" . htmlspecialchars($user['phone'] ?? '') . "</div>
            <div style='font-size: 11px; color: #9ca3af;'>" . $currentDepartment . " • " . ucfirst($userRole ?? 'guest') . "</div>
        </div>
        <a href='../auth/change-password.php' style='padding: 4px 8px; background: transparent; color: #9ca3af; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: 400; transition: all 0.2s; border: 1px solid #e5e7eb;' onmouseover='this.style.background=\"#f9fafb\"; this.style.color=\"#6b7280\"; this.style.borderColor=\"#d1d5db\"' onmouseout='this.style.background=\"transparent\"; this.style.color=\"#9ca3af\"; this.style.borderColor=\"#e5e7eb\"'>Пароль</a>
        <a href='../auth/logout.php' style='padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500; transition: background-color 0.2s;' onmouseover='this.style.background=\"#e5e7eb\"' onmouseout='this.style.background=\"#f3f4f6\"'>Выход</a>
    </div>
</div>";
?>

<div class="container">
    <table class="layout">
        <!-- Шапка -->
        <tr class="header-row">
            <td class="header-cell" colspan="3">
                <!-- Шапка -->
        <tr class="header-row">
            <td class="header-cell" colspan="3">
                <div class="topbar">
                    <div class="topbar-left">
                        <span class="logo">U5</span>
                        <span class="system-name">Система управления</span>
                    </div>
                    <div class="topbar-center">
                       
                    </div>
                    <div class="topbar-right">
                        <!-- Панель авторизации перенесена вверх -->
                    </div>
                </div>
            </td>
        </tr>

            </td>
        </tr>

        <!-- Контент: 3 колонки -->
        <tr class="content-row">
            <!-- Левая панель -->
            <td class="panel panel--left" style="width:30%;">
                <?php
                // Напоминание: продукция, закрытая в ЗП авансом, но ещё не сданная на склад
                $salary_reminder_list = [];
                try {
                    $pdo_mp = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
                    $pdo_mp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $cols = $pdo_mp->query("SHOW COLUMNS FROM manufactured_production")->fetchAll(PDO::FETCH_COLUMN);
                    if (in_array('salary_closed_advance', $cols) && in_array('handed_to_warehouse_at', $cols)) {
                        $stmt = $pdo_mp->query("SELECT date_of_production, name_of_filter, name_of_order, team, count_of_filters FROM manufactured_production WHERE salary_closed_advance = 1 AND handed_to_warehouse_at IS NULL ORDER BY date_of_production DESC LIMIT 20");
                        $salary_reminder_list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    }
                } catch (PDOException $e) { /* ignore */ }
                if (!empty($salary_reminder_list)): ?>
                <div class="panel" style="background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:10px; margin-bottom:12px;">
                    <div class="section-title" style="color:#92400e;">Напоминание: продукция в ЗП авансом, не сдана на склад</div>
                    <p style="font-size:12px; color:#92400e; margin:0 0 8px 0;">Следующая продукция закрыта в зарплату авансом и ещё не сдана на склад.</p>
                    <ul style="font-size:11px; margin:0 0 8px 0; padding-left:16px;">
                        <?php foreach (array_slice($salary_reminder_list, 0, 5) as $row): ?>
                        <li><?= htmlspecialchars($row['date_of_production']) ?> — <?= htmlspecialchars($row['name_of_filter']) ?> (заявка <?= htmlspecialchars($row['name_of_order']) ?>, бр. <?= (int)$row['team'] ?>, <?= (int)$row['count_of_filters'] ?> шт)</li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($salary_reminder_list) > 5): ?><p style="font-size:11px; margin:0;">… и ещё <?= count($salary_reminder_list) - 5 ?> записей.</p><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="section-title">Операции</div>
                <div class="stack">
                    <a href="product_output.php" target="_blank" rel="noopener" class="stack"><button>Выпуск продукции</button></a>
                    <form action="product_output_view.php" method="post" class="stack" target="_blank"><input type="submit" value="Обзор выпуска продукции"></form>
                    <a href="NP_supply_requirements.php" target="_blank" rel="noopener" class="stack"><button>Потребность комплектующих</button></a>
                    <?php if ($canAccessLaser): ?>
                    <a href="laser_request.php" target="_blank" rel="noopener" class="stack"><button type="button">Заявка на лазер</button></a>
                    <?php endif; ?>
                </div>

                <div class="section-title" style="margin-top:14px">Мониторинг</div>
                <div class="stack">
                    <form action="NP_build_plan_week.php" method="get" target="_blank" class="stack">
                        <input type="submit" value="Общий план">
                    </form>


                    <form action='NP_monitor.php' method='post' target="_blank" class="stack"><input type='submit' value='Мониторинг'></form>
                    <form action="worker_modules/tasks_corrugation.php" method="post" target="_blank" class="stack"><input type="submit" value="Модуль оператора ГМ"></form>
                    <a href="corrugation_worker_analysis.php" target="_blank" rel="noopener" class="stack"><button type="button">Анализ ГМ</button></a>
                    <form action="worker_modules/tasks_cut.php" method="post" target="_blank" class="stack"><input type="submit" value="Модуль оператора бумагорезки"></form>
                    <form action="NP/corrugation_print.php" method="post" target="_blank" class="stack"><input type="submit" value="План гофропакетчика"></form>
                    <form action="buffer_stock.php" method="post" target="_blank" class="stack"><input type="submit" value="Буфер гофропакетов"></form>
                </div>

                <div class="section-title" style="margin-top:14px">Табель</div>
                <div class="stack">
                    <a href="timesheet.php" target="_blank" rel="noopener" class="stack"><button type="button">Табель У5</button></a>
                    <a href="salary_report_monthly.php" target="_blank" rel="noopener" class="stack"><button>Отчет по ЗП за месяц</button></a>
                </div>

                <div class="section-title" style="margin-top:14px">Управление данными</div>
                <div class="stack">
                    <button onclick="openDataEditor()">Редактор данных</button>
                    <form action='add_salon_filter_into_db.php' method='post' target='_blank' class="stack">
                        <input type='hidden' name='workshop' value='<?php echo htmlspecialchars($workshop); ?>'>
                        <input type='submit' value='Добавить фильтр в БД(full)'>
                    </form>
                    <button onclick="openFilterParamsModal()">Просмотреть параметры фильтра</button>
                    <form action='add_filter_properties_into_db.php' method='post' target='_blank' class="stack">
                        <input type='hidden' name='workshop' value='<?php echo htmlspecialchars($workshop); ?>'>
                        <input type='submit' value='Изменить параметры фильтра'>
                    </form>
                    <form action='manage_tariffs.php' method='get' target='_blank' class="stack">
                        <input type='submit' value='Управление тарифами'>
                    </form>
                </div>

                <div class="section-title" style="margin-top:14px">Объявление</div>
                <div class="stack">
                    <button onclick="openCreateAdModal()">Создать объявление</button>
                </div>

                <div class="section-title" style="margin-top:14px">Дополнения</div>
                <div class="stack">
                    <form action="BOX_CREATOR.htm" method="post" class="stack" target="_blank"><input type="submit" value="Расчет коробок"></form>
                    <form action="BOX_CREATOR_2.htm" method="post" class="stack" target="_blank"><input type="submit" value="Максимальное количество"></form>
                    <button type="button" onclick="openSearchByDimensionsModal()" class="stack" style="width:100%;">Поиск по размерам</button>
                    <button type="button" onclick="openFilterRatingModal()" class="stack" style="width:100%;">Рейтинг фильтров</button>
                </div>
            </td>

            <!-- Центральная панель -->
            <td class="panel panel--main" style="width:40%;">
                <?php
                // Виджет задач для мастеров
                if ($userRole === 'supervisor') {
                    profile_mark('Tasks widget start');
                    require_once __DIR__ . '/../auth/includes/db.php';
                    $pdo_tasks = getPdo('plan_u5');
                    
                    try {
                        $stmt_tasks = $pdo_tasks->prepare("
                            SELECT id, title, description, priority, due_date, status
                            FROM tasks
                            WHERE assigned_to = ? 
                            AND status NOT IN ('completed', 'cancelled')
                            AND department = ?
                            ORDER BY 
                                CASE priority 
                                    WHEN 'urgent' THEN 1 
                                    WHEN 'high' THEN 2 
                                    WHEN 'normal' THEN 3 
                                    WHEN 'low' THEN 4 
                                END,
                                due_date ASC
                            LIMIT 5
                        ");
                        $stmt_tasks->execute([$session['user_id'], $currentDepartment]);
                        $myTasks = $stmt_tasks->fetchAll();
                        
                        $taskCount = count($myTasks);
                        
                        if ($taskCount > 0):
                            $today = new DateTime();
                            $today->setTime(0, 0, 0);
                ?>
                <!-- Виджет задач -->
                <div style="background: #f8f9fa; border: 2px solid #667eea; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #e5e7eb;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #374151;">
                            Мои задачи
                        </h3>
                        <span style="background: #667eea; color: white; padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 13px;">
                            <?php echo $taskCount; ?>
                        </span>
                    </div>
                    
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($myTasks as $task): 
                            $dueDate = new DateTime($task['due_date']);
                            $dueDate->setTime(0, 0, 0);
                            $isOverdue = $dueDate < $today;
                            
                            $priorityColors = [
                                'urgent' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                                'high' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                                'normal' => ['bg' => 'rgba(255, 255, 255, 0.3)', 'text' => 'white'],
                                'low' => ['bg' => 'rgba(255, 255, 255, 0.2)', 'text' => 'rgba(255, 255, 255, 0.8)']
                            ];
                            $priorityLabels = ['urgent' => 'Срочно', 'high' => 'Высокий', 'normal' => 'Обычный', 'low' => 'Низкий'];
                            $priority = $task['priority'];
                        ?>
                        <div style="background: white; padding: 12px; border-radius: 6px; border: 1px solid #e5e7eb;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px;">
                                <div style="font-weight: 600; font-size: 14px; color: #1f2937; flex: 1;"><?php echo htmlspecialchars($task['title']); ?></div>
                                <span style="background: <?php echo $priorityColors[$priority]['bg']; ?>; color: <?php echo $priorityColors[$priority]['text']; ?>; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600;">
                                    <?php echo $priorityLabels[$priority]; ?>
                                </span>
                            </div>
                            <?php if ($task['description']): 
                                $description = htmlspecialchars($task['description']);
                                $isLong = mb_strlen($task['description']) > 80;
                                $shortDescription = $isLong ? mb_substr($task['description'], 0, 80) : $task['description'];
                            ?>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px; line-height: 1.4;">
                                <div id="task-desc-short-<?php echo $task['id']; ?>" style="<?php echo $isLong ? '' : 'display: none;'; ?>">
                                    <?php echo nl2br(htmlspecialchars($shortDescription)); ?>
                                    <?php if ($isLong): ?>
                                    <button onclick="toggleTaskDescription(<?php echo $task['id']; ?>)" style="background: none; border: none; color: #667eea; cursor: pointer; padding: 0; margin-left: 4px; text-decoration: underline; font-size: 12px;">Развернуть</button>
                                    <?php endif; ?>
                                </div>
                                <div id="task-desc-full-<?php echo $task['id']; ?>" style="<?php echo $isLong ? 'display: none;' : ''; ?>">
                                    <?php echo nl2br($description); ?>
                                    <?php if ($isLong): ?>
                                    <button onclick="toggleTaskDescription(<?php echo $task['id']; ?>)" style="background: none; border: none; color: #667eea; cursor: pointer; padding: 0; margin-left: 4px; text-decoration: underline; font-size: 12px;">Свернуть</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11px;">
                                <span style="color: #9ca3af;">До: <strong style="<?php echo $isOverdue ? 'color: #ef4444;' : 'color: #374151;'; ?>"><?php echo $dueDate->format('d.m.Y'); ?></strong></span>
                                <div style="display: flex; gap: 5px;">
                                    <?php if ($task['status'] === 'pending'): ?>
                                    <button onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')" style="padding: 3px 10px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 4px; cursor: pointer; font-size: 11px;">
                                        Начать
                                    </button>
                                    <?php endif; ?>
                                    <button onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')" style="padding: 3px 10px; background: #10b981; border: none; color: white; border-radius: 4px; cursor: pointer; font-size: 11px;">
                                        Завершить
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <script>
                function toggleTaskDescription(taskId) {
                    const shortDiv = document.getElementById('task-desc-short-' + taskId);
                    const fullDiv = document.getElementById('task-desc-full-' + taskId);
                    if (shortDiv.style.display === 'none') {
                        shortDiv.style.display = 'block';
                        fullDiv.style.display = 'none';
                    } else {
                        shortDiv.style.display = 'none';
                        fullDiv.style.display = 'block';
                    }
                }
                
                async function updateTaskStatus(taskId, status) {
                    try {
                        const response = await fetch('/tasks_manager/tasks_api.php?action=update_status', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ task_id: taskId, status: status })
                        });
                        
                        const data = await response.json();
                        
                        if (data.ok) {
                            const messages = {
                                'in_progress': '▶️ Задача взята в работу',
                                'completed': '✅ Задача выполнена!'
                            };
                            alert(messages[status] || 'Статус обновлен');
                            location.reload();
                        } else {
                            alert('❌ Ошибка: ' + data.error);
                        }
                    } catch (error) {
                        alert('❌ Ошибка: ' + error.message);
                    }
                }
                </script>
                <?php 
                        endif; // if ($taskCount > 0)
                        profile_mark('Tasks widget completed');
                    } catch (Exception $e) {
                        // Тихо игнорируем ошибки
                        profile_mark('Tasks widget error (ignored)');
                    }
                } else {
                    profile_mark('Tasks widget skipped (not supervisor)');
                }
                ?>
                
                <div class="section-title">Объявления</div>
                <div class="stack-lg">

                    <?php 
                    show_ads();
                    profile_mark('show_ads() completed');
                    
                    show_weekly_production();
                    profile_mark('show_weekly_production() completed');
                    
                    show_monthly_production();
                    profile_mark('show_monthly_production() completed');
                    
                    show_weekly_corrugation();
                    profile_mark('show_weekly_corrugation() completed');
                    ?>

                    <div class="search-card">
                        <h4 style="margin:0 0 8px;">Поиск заявок по фильтру</h4>
                        <div class="stack">
                            <label for="filterSelect">Фильтр:</label>
                            <?php 
                            load_filters_into_select();
                            profile_mark('load_filters_into_select() completed');
                            ?>
                        </div>
                        <div id="filterSearchResult" style="margin-top:10px;"></div>
                        <div id="showAllButtonContainer" style="margin-top:10px; display:none;">
                            <button id="showAllButton" style="padding: 8px 16px; background: #4CAF50; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                Показать все
                            </button>
                        </div>
                    </div>
                </div>

                <script>
                    (function(){
                        const resultBox = document.getElementById('filterSearchResult');
                        const showAllButtonContainer = document.getElementById('showAllButtonContainer');
                        const showAllButton = document.getElementById('showAllButton');
                        let currentFilter = '';
                        let showingAll = false;
                        
                        function getSelectEl(){ return document.querySelector('select[name="analog_filter"]'); }
                        
                        async function runSearch(showAll = false){
                            const sel = getSelectEl();
                            if(!sel){ resultBox.innerHTML = '<div class="muted">Не найден выпадающий список.</div>'; return; }
                            const val = sel.value.trim();
                            if(!val){ 
                                resultBox.innerHTML = '<div class="muted">Выберите фильтр…</div>'; 
                                showAllButtonContainer.style.display = 'none';
                                return; 
                            }
                            
                            currentFilter = val;
                            showingAll = showAll;
                            resultBox.textContent = 'Загрузка…';
                            
                            try{
                                const formData = new FormData(); 
                                formData.append('filter', val);
                                if(showAll) {
                                    formData.append('show_all', '1');
                                }
                                
                                const resp = await fetch('search_filter_in_the_orders.php', { method:'POST', body:formData });
                                if(!resp.ok){ 
                                    resultBox.innerHTML = `<div class="alert">Ошибка запроса: ${resp.status} ${resp.statusText}</div>`; 
                                    showAllButtonContainer.style.display = 'none';
                                    return; 
                                }
                                
                                const html = await resp.text();
                                resultBox.innerHTML = html;
                                
                                // Показываем кнопку "Показать все" только если сейчас показываются не все заявки
                                if(!showAll) {
                                    showAllButtonContainer.style.display = 'block';
                                    showAllButton.textContent = 'Показать все';
                                } else {
                                    showAllButtonContainer.style.display = 'block';
                                    showAllButton.textContent = 'Показать за последний год';
                                }
                            }catch(e){ 
                                resultBox.innerHTML = `<div class="alert">Ошибка: ${e}</div>`; 
                                showAllButtonContainer.style.display = 'none';
                            }
                        }
                        
                        // Обработчик кнопки "Показать все"
                        showAllButton.addEventListener('click', function(){
                            runSearch(!showingAll);
                        });
                        
                        const sel = getSelectEl(); 
                        if(sel){ 
                            sel.id='filterSelect'; 
                            sel.addEventListener('change', function(){
                                showingAll = false;
                                runSearch(false);
                            }); 
                        }
                    })();
                </script>
            </td>

            <!-- Правая панель -->
            <td class="panel panel--right" style="width:30%;">
                <?php
                /* ОПТИМИЗИРОВАННАЯ загрузка заявок */
                profile_mark('Orders loading start');
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                if ($mysqli->connect_errno) { echo 'Возникла проблема на сайте'; exit; }
                
                // Оптимизированный запрос: фильтруем сразу в SQL, используем prepared statement
                $sql = "SELECT DISTINCT order_number, status 
                        FROM orders 
                        WHERE workshop = ? 
                        AND COALESCE(hide, 0) != 1 
                        ORDER BY order_number";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param('s', $currentDepartment);
                
                if (!$stmt->execute()){
                    echo "Ошибка: Наш запрос не удался\n"; exit;
                }
                $result = $stmt->get_result();
                profile_mark('Orders loaded');
                ?>

                <div class="section-title">Сохраненные заявки</div>
                <div class="saved-orders">
                    <?php
                    echo '<form action="show_order.php" method="post" target="_blank">';
                    if ($result->num_rows === 0) { 
                        echo "<div class='muted'>В базе нет ни одной заявки</div>"; 
                    }
                    while ($orders_data = $result->fetch_assoc()){
                        $val = htmlspecialchars($orders_data['order_number']);
                        $status = $orders_data['status'] ?? 'normal';
                        $class = ($status === 'replanning') ? ' class="replanning-btn"' : '';
                        echo "<input type='submit' name='order_number' value='{$val}'{$class}>";
                    }
                    echo '</form>';
                    $stmt->close();
                    ?>
                </div>

                <div class="section-title" style="margin-top:14px">Управление заявками</div>
                <section class="stack">
                    <button type="button" onclick="openLoadFileModal()">Прочитать XLS заявку</button>
                    <form action='new_order.php' method='post' target='_blank' class="stack"><input type='submit' value='Создать заявку вручную'></form>
                    <button type="button" onclick="openAddToOrderModal()">Добавить к заявке...</button>
                    <form action='combine_orders.php' method='post' target='_blank' class="stack"><input type='submit' value='Объединение заявок'></form>
                    <button type="button" onclick="openDeleteOrdersModal()">Удалить заявку</button>
                    <button type="button" onclick="window.location.href='edit_order.php'">Редактировать заявку</button>
                    
                    <div style="border-top: 1px dashed var(--border); margin: 8px 0;"></div>
                    
                    <form action='NP_cut_index.php' method='post' target='_blank' class="stack"><input type='submit' value='Планирование'></form>
                </section>

                <?php $result->close(); $mysqli->close(); ?>
            </td>
        </tr>
    </table>
</div>

<!-- Модальное окно редактора данных -->
<div id="dataEditorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Редактор данных</h2>
            <span class="close" onclick="closeDataEditor()">&times;</span>
        </div>
        <div class="modal-buttons">
            <button onclick="openProductEditor()">📊 Редактор выпущенной продукции</button>
            <button onclick="openAuditLogs()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">📋 Логи аудита</button>
            <button onclick="closeDataEditor()">❌ Закрыть</button>
        </div>
    </div>
</div>

<!-- Модальное окно редактора продукции -->
<div id="productEditorModal" class="modal">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header">
            <h2 class="modal-title">Редактор выпущенной продукции</h2>
            <div style="display: flex; gap: 8px; align-items: center;">
                <button onclick="openAuditLogs()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11px;">
                    📋 Логи аудита
                </button>
                <span class="close" onclick="closeProductEditor()">&times;</span>
            </div>
        </div>
        <div id="productEditorContent">
            <div style="margin-bottom: 12px; padding: 10px 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">
                <h4 style="margin: 0 0 8px 0; color: #495057; font-size: 13px;">📅 Выберите дату для редактирования</h4>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="date" id="editDate" style="padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px;">
                    <button onclick="loadDataForDate()" style="background: #3b82f6; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 13px;">
                        🔍 Загрузить данные
                    </button>
                </div>
            </div>
            <div id="dataTableContainer" style="display: none;">
                <!-- Здесь будет таблица с данными -->
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно добавления позиции -->
<div id="addPositionModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title">➕ Добавить позицию</h2>
            <span class="close" onclick="closeAddPositionModal()">&times;</span>
        </div>
        <div id="addPositionContent">
            <form id="addPositionForm">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Дата производства:</label>
                    <input type="date" id="addPositionDate" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Название фильтра:</label>
                    <select id="addPositionFilter" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">Выберите фильтр</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Количество:</label>
                    <input type="number" id="addPositionQuantity" required min="1" placeholder="Введите количество" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Название заявки:</label>
                    <select id="addPositionOrder" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">Выберите заявку</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Бригада:</label>
                    <select id="addPositionTeam" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">Выберите бригаду</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddPositionModal()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer;">
                        Отмена
                    </button>
                    <button type="submit" style="padding: 8px 16px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        ➕ Добавить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Функции для модальных окон
function openDataEditor() {
    document.getElementById('dataEditorModal').style.display = 'block';
}

function closeDataEditor() {
    document.getElementById('dataEditorModal').style.display = 'none';
}

function openProductEditor() {
    document.getElementById('productEditorModal').style.display = 'block';
    loadProductEditor();
}

function closeProductEditor() {
    document.getElementById('productEditorModal').style.display = 'none';
}

function openAuditLogs() {
    // Закрываем модальное окно редактора данных
    closeDataEditor();
    // Открываем страницу логов аудита в новой вкладке
    window.open('audit_viewer.php', '_blank');
}

function closeAddPositionModal() {
    document.getElementById('addPositionModal').style.display = 'none';
}

// Закрытие модальных окон при клике вне их
window.onclick = function(event) {
    const dataModal = document.getElementById('dataEditorModal');
    const productModal = document.getElementById('productEditorModal');
    const addPositionModal = document.getElementById('addPositionModal');
    const addToOrderModal = document.getElementById('addToOrderModal');
    
    if (event.target === dataModal) {
        closeDataEditor();
    }
    if (event.target === productModal) {
        closeProductEditor();
    }
    if (event.target === addPositionModal) {
        closeAddPositionModal();
    }
    if (event.target === addToOrderModal) {
        closeAddToOrderModal();
    }
}

// Функции для модального окна "Добавить к заявке"
function openAddToOrderModal() {
    document.getElementById('addToOrderModal').style.display = 'block';
    loadOrdersAndFiltersForAddToOrder();
}

function closeAddToOrderModal() {
    document.getElementById('addToOrderModal').style.display = 'none';
    document.getElementById('addToOrderForm').reset();
    // Сбрасываем значения по умолчанию
    document.getElementById('inputMarking').value = 'стандарт';
    document.getElementById('inputPersonalPackaging').value = 'стандарт';
    document.getElementById('inputPersonalLabel').value = 'стандарт';
    document.getElementById('inputGroupPackaging').value = 'стандарт';
    document.getElementById('inputPackagingRate').value = '10';
    document.getElementById('inputGroupLabel').value = 'стандарт';
    document.getElementById('inputRemark').value = 'дополнение';
}

async function loadOrdersAndFiltersForAddToOrder() {
    try {
        // Загружаем список заявок
        const ordersResponse = await fetch('add_to_order_api.php?action=get_orders');
        const ordersData = await ordersResponse.json();
        
        if (ordersData.ok) {
            const orderSelect = document.getElementById('selectOrderNumber');
            orderSelect.innerHTML = '<option value="">-- Выберите заявку --</option>';
            ordersData.orders.forEach(order => {
                const option = document.createElement('option');
                option.value = order;
                option.textContent = order;
                orderSelect.appendChild(option);
            });
        }
        
        // Загружаем список фильтров
        const filtersResponse = await fetch('add_to_order_api.php?action=get_filters');
        const filtersData = await filtersResponse.json();
        
        if (filtersData.ok) {
            const filterSelect = document.getElementById('inputFilter');
            filterSelect.innerHTML = '<option value="">-- Выберите фильтр --</option>';
            filtersData.filters.forEach(filter => {
                const option = document.createElement('option');
                option.value = filter;
                option.textContent = filter;
                filterSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Ошибка загрузки данных:', error);
        alert('Ошибка загрузки данных: ' + error.message);
    }
}

async function submitAddToOrder(event) {
    event.preventDefault();
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Добавление...';
    submitBtn.disabled = true;
    
    const payload = {
        order_number: document.getElementById('selectOrderNumber').value,
        filter: document.getElementById('inputFilter').value,
        count: parseInt(document.getElementById('inputCount').value) || 0,
        marking: document.getElementById('inputMarking').value.trim(),
        personal_packaging: document.getElementById('inputPersonalPackaging').value.trim(),
        personal_label: document.getElementById('inputPersonalLabel').value.trim(),
        group_packaging: document.getElementById('inputGroupPackaging').value.trim(),
        packaging_rate: parseInt(document.getElementById('inputPackagingRate').value) || 10,
        group_label: document.getElementById('inputGroupLabel').value.trim(),
        remark: document.getElementById('inputRemark').value.trim()
    };
    
    try {
        const response = await fetch('add_to_order_api.php?action=add_position', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        const data = await response.json();
        
        if (data.ok) {
            alert('✅ Позиция успешно добавлена к заявке!');
            closeAddToOrderModal();
        } else {
            alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
        }
    } catch (error) {
        alert('❌ Ошибка при добавлении: ' + error.message);
    } finally {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }
}

// Функция загрузки редактора продукции
function loadProductEditor() {
    // Устанавливаем сегодняшнюю дату по умолчанию
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('editDate').value = today;
    
    // Скрываем таблицу данных
    document.getElementById('dataTableContainer').style.display = 'none';
}

// Функция загрузки данных по выбранной дате
function loadDataForDate() {
    const selectedDate = document.getElementById('editDate').value;
    
    if (!selectedDate) {
        alert('Пожалуйста, выберите дату');
        return;
    }
    
    const container = document.getElementById('dataTableContainer');
    container.innerHTML = '<p>Загрузка данных...</p>';
    container.style.display = 'block';
    
    // AJAX запрос для загрузки данных по дате
    const formData = new FormData();
    formData.append('action', 'load_data_by_date');
    formData.append('date', selectedDate);
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                renderProductEditor(data.data, selectedDate);
            } else {
                container.innerHTML = `<p style="color: red;">Ошибка: ${data.error}</p>`;
            }
        } catch (e) {
            container.innerHTML = `
                <div style="color: red;">
                    <p><strong>Ошибка парсинга JSON:</strong></p>
                    <p>${e.message}</p>
                    <p><strong>Ответ сервера:</strong></p>
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; overflow: auto;">${text}</pre>
                </div>
            `;
        }
    })
    .catch(error => {
        container.innerHTML = `<p style="color: red;">Ошибка загрузки: ${error.message}</p>`;
    });
}

// Функция отображения редактора продукции
function renderProductEditor(data, selectedDate) {
    const container = document.getElementById('dataTableContainer');
    
    if (data.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <h3>📅 ${selectedDate}</h3>
                <p>Нет данных за выбранную дату</p>
                <button onclick="addNewPosition('${selectedDate}')" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-top: 16px;">
                    ➕ Добавить позицию
                </button>
            </div>
        `;
        return;
    }
    
    // Группируем данные по бригаде (дата уже известна)
    const groupedData = {};
    data.forEach(item => {
        const brigade = item.brigade || 'Не указана';
        const key = brigade;
        
        if (!groupedData[key]) {
            groupedData[key] = {
                brigade: brigade,
                items: []
            };
        }
        groupedData[key].items.push(item);
    });
    
    let html = `
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #374151;">📅 ${selectedDate}</h3>
            <button onclick="addNewPosition('${selectedDate}')" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
                ➕ Добавить позицию
            </button>
        </div>
    `;
    
    // Отображаем данные по группам
    Object.values(groupedData).forEach(group => {
        html += `
            <div style="margin-bottom: 30px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                <h4 style="margin: 0 0 16px 0; color: #374151;">
                    👥 Бригада ${group.brigade}
                </h4>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: left;">Фильтр</th>
                                <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Кол-во</th>
                                <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Заявка</th>
                                <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        group.items.forEach(item => {
            const filterName = item.filter_name || 'Не указан';
            const quantity = item.quantity || 0;
            const orderNumber = item.order_number || 'Не указан';
            const itemId = item.virtual_id || '';
            
            html += `
                <tr>
                    <td style="padding: 8px; border: 1px solid #e5e7eb;">${filterName}</td>
                    <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                        <input type="number" value="${quantity}" min="0" 
                               onchange="updateQuantity('${itemId}', this.value)" 
                               style="width: 60px; padding: 4px; border: 1px solid #d1d5db; border-radius: 4px;">
                    </td>
                    <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                        <select onchange="moveToOrder('${itemId}', this.value)" 
                                class="order-select" data-item-id="${itemId}"
                                style="padding: 4px; border: 1px solid #d1d5db; border-radius: 4px; min-width: 100px;">
                            <option value="${orderNumber}">${orderNumber}</option>
                        </select>
                    </td>
                    <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                        <button onclick="removePosition('${itemId}')" 
                                data-item-id="${itemId}"
                                style="background: #ef4444; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                            🗑️ Удалить
                        </button>
                    </td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    
    // Загружаем заявки для всех выпадающих списков в таблице
    loadOrdersForTableDropdowns();
}

// Функция загрузки заявок для выпадающих списков в таблице
function loadOrdersForTableDropdowns() {
    console.log('Начинаем загрузку заявок для таблицы...');
    
    const orderFormData = new FormData();
    orderFormData.append('action', 'load_orders_for_dropdown');
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: orderFormData
    })
    .then(response => {
        console.log('Ответ получен, статус:', response.status);
        return response.text();
    })
    .then(text => {
        console.log('Ответ сервера:', text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                console.log('Заявки получены:', data.orders);
                // Находим все выпадающие списки заявок в таблице
                const orderSelects = document.querySelectorAll('.order-select');
                console.log('Найдено выпадающих списков:', orderSelects.length);
                
                orderSelects.forEach((select, index) => {
                    const currentValue = select.querySelector('option').value;
                    console.log(`Обрабатываем список ${index + 1}, текущее значение:`, currentValue);
                    
                    select.innerHTML = '';
                    
                    // Добавляем текущее значение как выбранное
                    const currentOption = document.createElement('option');
                    currentOption.value = currentValue;
                    currentOption.textContent = currentValue;
                    currentOption.selected = true;
                    select.appendChild(currentOption);
                    
                    // Добавляем все заявки
                    data.orders.forEach(order => {
                        if (order !== currentValue) {
                            const option = document.createElement('option');
                            option.value = order;
                            option.textContent = order;
                            select.appendChild(option);
                        }
                    });
                });
                console.log('Заявки для таблицы загружены:', data.orders);
            } else {
                console.error('Ошибка загрузки заявок для таблицы:', data.error);
            }
        } catch (e) {
            console.error('Ошибка парсинга заявок для таблицы:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки заявок для таблицы:', error);
    });
}

// Функции для работы с данными
function updateQuantity(id, quantity) {
    const formData = new FormData();
    formData.append('action', 'update_quantity');
    formData.append('id', id);
    formData.append('quantity', quantity);
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Количество успешно обновлено
            console.log('Количество обновлено для ID:', id);
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка обновления: ' + error.message);
    });
}

function moveToOrder(id, newOrderId) {
    const formData = new FormData();
    formData.append('action', 'move_to_order');
    formData.append('id', id);
    formData.append('new_order_id', newOrderId);
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Позиция успешно перенесена');
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка переноса: ' + error.message);
    });
}

function removePosition(id) {
    if (!confirm('Вы уверены, что хотите удалить эту позицию?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'remove_position');
    formData.append('id', id);
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Находим и удаляем строку из таблицы по data-атрибуту
            const rowToRemove = document.querySelector(`button[data-item-id="${id}"]`).closest('tr');
            if (rowToRemove) {
                rowToRemove.remove();
            }
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка удаления: ' + error.message);
    });
}

function addNewPosition(selectedDate) {
    if (!selectedDate) {
        selectedDate = document.getElementById('editDate').value;
    }
    
    if (!selectedDate) {
        alert('Пожалуйста, выберите дату');
        return;
    }
    
    // Устанавливаем выбранную дату в форму
    document.getElementById('addPositionDate').value = selectedDate;
    
    // Очищаем остальные поля
    document.getElementById('addPositionFilter').value = '';
    document.getElementById('addPositionQuantity').value = '';
    document.getElementById('addPositionOrder').value = '';
    document.getElementById('addPositionTeam').value = '';
    
    // Загружаем данные для выпадающих списков
    loadFiltersAndOrders();
    
    // Открываем модальное окно
    document.getElementById('addPositionModal').style.display = 'block';
}

// Функция загрузки фильтров и заявок
function loadFiltersAndOrders() {
    // Загружаем фильтры
    const filterFormData = new FormData();
    filterFormData.append('action', 'load_filters');
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: filterFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const filterSelect = document.getElementById('addPositionFilter');
                filterSelect.innerHTML = '<option value="">Выберите фильтр</option>';
                data.filters.forEach(filter => {
                    const option = document.createElement('option');
                    option.value = filter;
                    option.textContent = filter;
                    filterSelect.appendChild(option);
                });
                console.log('Фильтры загружены:', data.filters);
            } else {
                console.error('Ошибка загрузки фильтров:', data.error);
            }
        } catch (e) {
            console.error('Ошибка парсинга фильтров:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки фильтров:', error);
    });
    
    // Загружаем заявки
    const orderFormData = new FormData();
    orderFormData.append('action', 'load_orders');
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: orderFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const orderSelect = document.getElementById('addPositionOrder');
                orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
                data.orders.forEach(order => {
                    const option = document.createElement('option');
                    option.value = order;
                    option.textContent = order;
                    orderSelect.appendChild(option);
                });
                console.log('Заявки загружены:', data.orders);
            } else {
                console.error('Ошибка загрузки заявок:', data.error);
            }
        } catch (e) {
            console.error('Ошибка парсинга заявок:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки заявок:', error);
    });
}

// Обработчик формы добавления позиции
document.addEventListener('DOMContentLoaded', function() {
    const addPositionForm = document.getElementById('addPositionForm');
    if (addPositionForm) {
        addPositionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitAddPosition();
        });
    }
});

function submitAddPosition() {
    const date = document.getElementById('addPositionDate').value;
    const filter = document.getElementById('addPositionFilter').value;
    const quantity = document.getElementById('addPositionQuantity').value;
    const order = document.getElementById('addPositionOrder').value;
    const team = document.getElementById('addPositionTeam').value;
    
    if (!date || !filter || !quantity || !order || !team) {
        alert('Пожалуйста, заполните все поля');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_position');
    formData.append('production_date', date);
    formData.append('filter_name', filter);
    formData.append('quantity', quantity);
    formData.append('order_name', order);
    formData.append('team', team);
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Позиция успешно добавлена!');
            closeAddPositionModal();
            // Перезагружаем данные для выбранной даты
            loadDataForDate();
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка добавления: ' + error.message);
    });
}

// Функции для модального окна параметров фильтра
function openFilterParamsModal() {
    document.getElementById('filterParamsModal').style.display = 'block';
}

function closeFilterParamsModal() {
    document.getElementById('filterParamsModal').style.display = 'none';
}

function loadFilterParams() {
    const filterName = document.getElementById('filterNameInput').value.trim();
    if (!filterName) {
        alert('Введите имя фильтра');
        return;
    }

    const contentDiv = document.getElementById('filterParamsContent');
    contentDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 20px; height: 20px; border: 2px solid var(--border); border-top: 2px solid var(--accent); border-radius: 50%; animation: spin 1s linear infinite;"></div><br>Загрузка параметров...</div>';

    fetch('view_salon_filter_params.php', {
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
        
        // Убираем header и оставляем только данные
        const dataStart = bodyContent.indexOf('<div class="card">');
        if (dataStart !== -1) {
            contentDiv.innerHTML = bodyContent.substring(dataStart);
        } else {
            contentDiv.innerHTML = '<p style="color: var(--danger); text-align: center; padding: 20px;">Фильтр не найден или произошла ошибка</p>';
        }
    })
    .catch(error => {
        contentDiv.innerHTML = '<p style="color: var(--danger); text-align: center; padding: 20px;">Ошибка загрузки: ' + error.message + '</p>';
    });
}

// Переменные для автодополнения
let filterSuggestions = [];
let currentHighlightIndex = -1;

// Функция поиска фильтров
function searchFilters(query) {
    if (query.length < 2) {
        hideFilterSuggestions();
        return;
    }

    // Загружаем список фильтров из БД
    fetch('get_filter_list.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'query=' + encodeURIComponent(query)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.filters) {
            filterSuggestions = data.filters;
            showFilterSuggestions(data.filters);
        } else {
            hideFilterSuggestions();
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки фильтров:', error);
        hideFilterSuggestions();
    });
}

// Показать выпадающий список
function showFilterSuggestions(filters) {
    const suggestionsDiv = document.getElementById('filterSuggestions');
    suggestionsDiv.innerHTML = '';
    
    if (filters.length === 0) {
        suggestionsDiv.innerHTML = '<div class="filter-suggestion-item" style="color: var(--muted);">Фильтры не найдены</div>';
    } else {
        filters.forEach((filter, index) => {
            const item = document.createElement('div');
            item.className = 'filter-suggestion-item';
            item.textContent = filter;
            item.onclick = () => selectFilter(filter);
            item.onmouseover = () => highlightSuggestion(index);
            suggestionsDiv.appendChild(item);
        });
    }
    
    suggestionsDiv.style.display = 'block';
    currentHighlightIndex = -1;
}

// Скрыть выпадающий список
function hideFilterSuggestions() {
    setTimeout(() => {
        document.getElementById('filterSuggestions').style.display = 'none';
    }, 200);
}

// Выделить элемент в списке
function highlightSuggestion(index) {
    const items = document.querySelectorAll('.filter-suggestion-item');
    items.forEach((item, i) => {
        item.classList.toggle('highlighted', i === index);
    });
    currentHighlightIndex = index;
}

// Выбрать фильтр
function selectFilter(filterName) {
    document.getElementById('filterNameInput').value = filterName;
    hideFilterSuggestions();
    loadFilterParams();
}

// Обработка клавиш в поле ввода
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('filterNameInput');
    if (input) {
        input.addEventListener('keydown', function(e) {
            const suggestionsDiv = document.getElementById('filterSuggestions');
            const items = suggestionsDiv.querySelectorAll('.filter-suggestion-item');
            
            if (suggestionsDiv.style.display === 'block' && items.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1);
                    highlightSuggestion(currentHighlightIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    currentHighlightIndex = Math.max(currentHighlightIndex - 1, -1);
                    if (currentHighlightIndex === -1) {
                        items.forEach(item => item.classList.remove('highlighted'));
                    } else {
                        highlightSuggestion(currentHighlightIndex);
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                        selectFilter(items[currentHighlightIndex].textContent);
                    } else {
                        loadFilterParams();
                    }
                } else if (e.key === 'Escape') {
                    hideFilterSuggestions();
                }
            }
        });
    }
});

// Функции для модального окна создания объявления
function openCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'block';
    // Устанавливаем дату по умолчанию (через неделю)
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 7);
    document.querySelector('input[name="expires_at"]').value = tomorrow.toISOString().split('T')[0];
}

function closeCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'none';
    document.getElementById('createAdForm').reset();
}

function submitAd(event) {
    event.preventDefault();
    
    const form = document.getElementById('createAdForm');
    const formData = new FormData(form);
    
    // Показываем индикатор загрузки
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Создание...';
    submitBtn.disabled = true;
    
    fetch('create_ad.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        if (data.includes('success') || data.includes('успешно')) {
            alert('Объявление успешно создано!');
            closeCreateAdModal();
            // Перезагружаем страницу для обновления списка объявлений
            location.reload();
        } else {
            alert('Ошибка при создании объявления: ' + data);
        }
    })
    .catch(error => {
        alert('Ошибка при создании объявления: ' + error.message);
    })
    .finally(() => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
}

// Поиск гофропакетов по размерам: один раз грузим все, фильтруем по вводу каждого символа
var searchByDimensionsAllItems = null;
function openSearchByDimensionsModal() {
    document.getElementById('searchByDimensionsModal').style.display = 'block';
    document.getElementById('searchDimWidth').value = '';
    document.getElementById('searchDimHeight').value = '';
    document.getElementById('searchDimPleats').value = '';
    var container = document.getElementById('searchByDimensionsTableWrap');
    container.innerHTML = '<div style="text-align:center; padding:24px; color: var(--muted);">Загрузка...</div>';
    searchByDimensionsAllItems = null;
    fetch('get_paper_packages_by_dimensions.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                container.innerHTML = '<p style="color: var(--danger); padding: 12px;">' + (data.error || 'Ошибка загрузки') + '</p>';
                return;
            }
            searchByDimensionsAllItems = data.items || [];
            filterAndRenderSearchByDimensions();
        })
        .catch(function(err) {
            container.innerHTML = '<p style="color: var(--danger); padding: 12px;">Ошибка: ' + escapeHtml(err.message) + '</p>';
        });
}
function closeSearchByDimensionsModal() {
    document.getElementById('searchByDimensionsModal').style.display = 'none';
}
function filterAndRenderSearchByDimensions() {
    if (searchByDimensionsAllItems == null) return;
    var width = document.getElementById('searchDimWidth').value.trim();
    var height = document.getElementById('searchDimHeight').value.trim();
    var pleats = document.getElementById('searchDimPleats').value.trim();
    var filtered = searchByDimensionsAllItems.filter(function(row) {
        var w = (row.width !== undefined && row.width !== null && row.width !== '') ? String(row.width) : '';
        var h = (row.height !== undefined && row.height !== null && row.height !== '') ? String(row.height) : '';
        var p = (row.pleats !== undefined && row.pleats !== null && row.pleats !== '') ? String(row.pleats) : '';
        if (width && w.indexOf(width) < 0) return false;
        if (height && h.indexOf(height) < 0) return false;
        if (pleats && p.indexOf(pleats) < 0) return false;
        return true;
    });
    renderSearchByDimensionsTable(filtered);
}
function renderSearchByDimensionsTable(items) {
    var container = document.getElementById('searchByDimensionsTableWrap');
    var html = '<table><thead><tr><th>Гофропакет</th><th style="text-align: center;">Ширина, мм</th><th style="text-align: center;">Высота, мм</th><th style="text-align: center;">Кол-во ребер</th></tr></thead><tbody>';
    if (items.length === 0) {
        html += '<tr><td colspan="4" style="text-align: center; color: var(--muted);">Нет данных по заданным размерам</td></tr>';
    } else {
        items.forEach(function(row) {
            html += '<tr><td>' + (row.name ? escapeHtml(row.name) : '—') + '</td><td style="text-align: center;">' + (row.width !== undefined && row.width !== '' ? escapeHtml(String(row.width)) : '—') + '</td><td style="text-align: center;">' + (row.height !== undefined && row.height !== '' ? escapeHtml(String(row.height)) : '—') + '</td><td style="text-align: center;">' + (row.pleats !== undefined && row.pleats !== '' ? escapeHtml(String(row.pleats)) : '—') + '</td></tr>';
        });
    }
    html += '</tbody></table>';
    container.innerHTML = html;
}
function escapeHtml(s) {
    if (s == null) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

// Рейтинг фильтров
var filterRatingItems = [];
var filterRatingSortBy = 'total_pieces';
var filterRatingSortDir = 'desc';

function openFilterRatingModal() {
    document.getElementById('filterRatingModal').style.display = 'block';
    loadFilterRating();
}
function closeFilterRatingModal() {
    document.getElementById('filterRatingModal').style.display = 'none';
}
function loadFilterRating() {
    var container = document.getElementById('filterRatingTableWrap');
    container.innerHTML = '<div style="text-align:center; padding:24px; color: var(--muted);">Загрузка...</div>';
    fetch('get_filter_rating.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) {
                container.innerHTML = '<p style="color: #b91c1c; padding: 12px;">' + escapeHtml(data.error || 'Ошибка загрузки') + '</p>';
                return;
            }
            filterRatingItems = data.items || [];
            filterRatingSortBy = 'total_pieces';
            filterRatingSortDir = 'desc';
            renderFilterRatingTable();
        })
        .catch(function(err) {
            container.innerHTML = '<p style="color: #b91c1c; padding: 12px;">Ошибка: ' + escapeHtml(err.message) + '</p>';
        });
}
function sortFilterRating(col) {
    if (filterRatingSortBy === col) {
        filterRatingSortDir = filterRatingSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        filterRatingSortBy = col;
        filterRatingSortDir = (col === 'filter' ? 'asc' : 'desc');
    }
    renderFilterRatingTable();
}
function renderFilterRatingTable() {
    var items = filterRatingItems.slice();
    var key = filterRatingSortBy;
    var dir = filterRatingSortDir === 'asc' ? 1 : -1;
    items.sort(function(a, b) {
        var va, vb;
        if (key === 'filter') {
            va = a.filter || '';
            vb = b.filter || '';
        } else if (key === 'order_numbers') {
            va = a.order_numbers || '';
            vb = b.order_numbers || '';
        } else {
            va = Number(a[key]) || 0;
            vb = Number(b[key]) || 0;
        }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return 0;
    });
    var arrow = function(col) {
        if (filterRatingSortBy !== col) return '';
        return filterRatingSortDir === 'asc' ? ' \u25b2' : ' \u25bc';
    };
    var html = '<p style="margin: 0 0 10px 0; font-size: 12px; color: var(--muted);">Все данные из БД</p>';
    html += '<table><thead><tr>';
    html += '<th onclick="sortFilterRating(\'filter\')" title="Сортировать">Фильтр' + arrow('filter') + '</th>';
    html += '<th style="text-align: right;" onclick="sortFilterRating(\'total_pieces\')" title="Сортировать">Штук в заказах' + arrow('total_pieces') + '</th>';
    html += '<th style="text-align: right;" onclick="sortFilterRating(\'orders_count\')" title="Сортировать">Кол-во заказов' + arrow('orders_count') + '</th>';
    html += '<th onclick="sortFilterRating(\'order_numbers\')" title="Сортировать">Номера заявок' + arrow('order_numbers') + '</th>';
    html += '</tr></thead><tbody>';
    if (items.length === 0) {
        html += '<tr><td colspan="4" style="text-align: center; color: var(--muted);">Нет данных за выбранный период</td></tr>';
    } else {
        items.forEach(function(row) {
            html += '<tr><td>' + escapeHtml(row.filter || '—') + '</td><td style="text-align: right;">' + (row.total_pieces != null ? row.total_pieces : '—') + '</td><td style="text-align: right;">' + (row.orders_count != null ? row.orders_count : '—') + '</td><td style="font-size: 11px;">' + escapeHtml(row.order_numbers || '—') + '</td></tr>';
        });
    }
    html += '</tbody></table>';
    document.getElementById('filterRatingTableWrap').innerHTML = html;
}

// Закрытие модальных окон при клике вне их
window.onclick = function(event) {
    const filterModal = document.getElementById('filterParamsModal');
    const adModal = document.getElementById('createAdModal');
    const searchDimModal = document.getElementById('searchByDimensionsModal');
    const filterRatingModal = document.getElementById('filterRatingModal');
    
    if (event.target === filterModal) {
        closeFilterParamsModal();
    } else if (event.target === adModal) {
        closeCreateAdModal();
    } else if (event.target === searchDimModal) {
        closeSearchByDimensionsModal();
    } else if (event.target === filterRatingModal) {
        closeFilterRatingModal();
    }
}
</script>

<!-- Модальное окно для просмотра параметров фильтра -->
<div id="filterParamsModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 700px; max-height: 70vh; overflow-y: auto;">
        <div class="modal-header">
            <h3 class="modal-title">Просмотр параметров фильтра</h3>
            <span class="close" onclick="closeFilterParamsModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div style="margin-bottom: 16px; position: relative;">
                <input type="text" id="filterNameInput" placeholder="Введите имя фильтра (например: AF1593)" 
                       style="width: 300px; padding: 10px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 10px;"
                       oninput="searchFilters(this.value)" onfocus="searchFilters(this.value)" onblur="hideFilterSuggestions()">
                <div id="filterSuggestions" style="position: absolute; top: 50px; left: 0; width: 300px; background: white; border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; display: none; max-height: 200px; overflow-y: auto;">
                </div>
                <button onclick="loadFilterParams()" style="padding: 10px 20px; background: var(--accent); color: white; border: none; border-radius: 8px; cursor: pointer; margin-left: 10px;">
                    Показать параметры
                </button>
            </div>
            <div id="filterParamsContent">
                <p style="color: var(--muted); text-align: center; padding: 20px;">
                    Введите имя фильтра и нажмите "Показать параметры"
                </p>
            </div>
        </div>
    </div>
    </div>

    <!-- Модальное окно: поиск по размерам (гофропакеты) — оформление как Рейтинг фильтров -->
    <div id="searchByDimensionsModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Поиск по размерам (гофропакеты)</h2>
                <span class="close" onclick="closeSearchByDimensionsModal()">&times;</span>
            </div>
            <div>
                <div class="search-dimensions-toolbar" style="display: flex; flex-direction: column; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <span style="min-width: 52px;">Ширина, мм:</span>
                        <input type="text" id="searchDimWidth" placeholder="мм" style="width: 80px; box-sizing: border-box;" oninput="filterAndRenderSearchByDimensions()">
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <span style="min-width: 52px;">Высота, мм:</span>
                        <input type="text" id="searchDimHeight" placeholder="мм" style="width: 80px; box-sizing: border-box;" oninput="filterAndRenderSearchByDimensions()">
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <span style="min-width: 44px;">Ребер:</span>
                        <input type="text" id="searchDimPleats" placeholder="число" style="width: 80px; box-sizing: border-box;" oninput="filterAndRenderSearchByDimensions()">
                    </label>
                </div>
                <div id="searchByDimensionsTableWrap" style="min-height: 120px;">
                    <div style="text-align: center; padding: 24px; color: var(--muted);">Загрузка списка гофропакетов...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно: рейтинг фильтров за период (оформление как Редактор выпущенной продукции) -->
    <div id="filterRatingModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Рейтинг фильтров</h2>
                <span class="close" onclick="closeFilterRatingModal()">&times;</span>
            </div>
            <div>
                <div id="filterRatingTableWrap" style="min-height: 120px;">
                    <div style="text-align: center; padding: 24px; color: var(--muted);">Загрузка...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для создания объявления -->
    <div id="createAdModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div class="modal-header">
                <h3 class="modal-title">📢 Создать объявление</h3>
                <span class="close" onclick="closeCreateAdModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="createAdForm" onsubmit="submitAd(event)">
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 500;">Название объявления:</label>
                        <input type="text" name="title" placeholder="Введите название объявления" required
                               style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px;">
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 500;">Текст объявления:</label>
                        <textarea name="content" placeholder="Введите текст объявления" required
                                  style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; min-height: 120px; resize: vertical;"></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: space-between; align-items: end; flex-wrap: wrap;">
                        <div style="min-width: 160px; max-width: 180px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500; font-size: 14px;">Дата окончания:</label>
                            <input type="date" name="expires_at" required
                                   style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px;">
                        </div>
                        <div style="display: flex; gap: 10px; flex-shrink: 0;">
                            <button type="button" onclick="closeCreateAdModal()" 
                                    style="padding: 10px 20px; background: var(--muted); color: white; border: none; border-radius: 8px; cursor: pointer;">
                                Отмена
                            </button>
                            <button type="submit" 
                                    style="padding: 10px 20px; background: var(--accent); color: white; border: none; border-radius: 8px; cursor: pointer;">
                                Создать объявление
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно для добавления позиции к заявке -->
    <div id="addToOrderModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px; max-height: 90vh; overflow-y: auto; padding: 12px;">
            <div class="modal-header" style="margin-bottom: 10px; padding-bottom: 8px;">
                <h3 class="modal-title" style="font-size: 16px;">➕ Добавить позицию к заявке</h3>
                <span class="close" onclick="closeAddToOrderModal()">&times;</span>
            </div>
            <div class="modal-body" style="padding: 0;">
                <form id="addToOrderForm" onsubmit="submitAddToOrder(event)">
                    <div style="display: grid; gap: 8px; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Выберите заявку:</label>
                            <select id="selectOrderNumber" required style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                                <option value="">-- Выберите заявку --</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Фильтр:</label>
                            <select id="inputFilter" required style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                                <option value="">-- Выберите фильтр --</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Количество, шт:</label>
                            <input type="number" id="inputCount" required min="1" placeholder="0" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Маркировка:</label>
                            <input type="text" id="inputMarking" value="стандарт" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Упаковка инд.:</label>
                            <input type="text" id="inputPersonalPackaging" value="стандарт" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Этикетка инд.:</label>
                            <input type="text" id="inputPersonalLabel" value="стандарт" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Упаковка групп.:</label>
                            <input type="text" id="inputGroupPackaging" value="стандарт" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Норма упаковки:</label>
                            <input type="number" id="inputPackagingRate" value="10" min="1" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Этикетка групп.:</label>
                            <input type="text" id="inputGroupLabel" value="стандарт" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <label style="min-width: 120px; font-weight: 500; font-size: 12px;">Примечание:</label>
                            <input type="text" id="inputRemark" value="дополнение" style="flex: 1; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px; font-size: 12px;">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" onclick="closeAddToOrderModal()" style="padding: 6px 14px; background: var(--muted); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">
                            Отмена
                        </button>
                        <button type="submit" style="padding: 6px 14px; background: var(--accent); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">
                            ➕ Добавить позицию
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно для загрузки XLS файла -->
    <div id="loadFileModal" class="modal">
        <div class="modal-content" style="max-width: 420px; padding: 16px; overflow-x: hidden;">
            <div class="modal-header" style="margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
                <div class="modal-title" style="font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 18px;">📄</span>
                    Прочитать XLS заявку
                </div>
                <span class="close" onclick="closeLoadFileModal()" style="font-size: 20px;">&times;</span>
            </div>
            <div class="modal-body" style="padding: 0; overflow-x: hidden;">
                <form id="loadFileForm" enctype="multipart/form-data" action="load_file.php" method="POST">
                    <input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
                    <p style="margin: 0 0 12px 0; color: var(--muted); font-size: 12px; line-height: 1.4;">Выберите файл Excel с заявкой коммерческого отдела</p>
                    <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <input id="loadFileInput" name="userfile" type="file" accept=".xls,.xlsx" style="position: absolute; width: 0; height: 0; opacity: 0; overflow: hidden;" />
                        <button type="button" onclick="document.getElementById('loadFileInput').click();" id="fileSelectButton" style="padding: 7px 16px; border: 1px solid var(--border); border-radius: 6px; background: var(--paper); cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; color: var(--ink);">
                            <span style="font-size: 14px;">📎</span>
                            <span>Выбрать файл</span>
                        </button>
                        <span style="font-size: 11px; color: var(--muted);">(.xls, .xlsx)</span>
                    </div>
                    <div id="fileNameDisplay" style="margin-bottom: 12px; padding: 6px 10px; background: var(--paper); border-radius: 6px; font-size: 11px; color: var(--ink); display: none; border: 1px solid var(--border);">
                        <span style="font-weight: 500;">Выбранный файл: </span><span id="fileNameText"></span>
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end; padding-top: 8px; border-top: 1px solid var(--border);">
                        <button type="button" onclick="closeLoadFileModal()" style="padding: 7px 16px; background: transparent; color: var(--ink); border: 1px solid var(--border); border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.2s;">
                            Отмена
                        </button>
                        <button type="submit" id="submitFileButton" disabled style="padding: 7px 16px; background: var(--muted); color: white; border: none; border-radius: 6px; cursor: not-allowed; font-size: 12px; font-weight: 500; transition: all 0.2s; opacity: 0.5;">
                            Загрузить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно для удаления заявок -->
    <div id="deleteOrdersModal" class="modal">
        <div class="modal-content" style="max-width: 600px; padding: 12px;">
            <div class="modal-header" style="margin-bottom: 10px; padding-bottom: 8px;">
                <div class="modal-title" style="font-size: 16px;">Удаление заявок</div>
                <span class="close" onclick="closeDeleteOrdersModal()">&times;</span>
            </div>
            <div class="modal-body" style="padding: 0;">
                <p style="margin-bottom: 10px; color: var(--danger); font-weight: 600; font-size: 12px;">
                    ⚠️ Внимание: Заявка будет полностью удалена. Это действие необратимо!
                </p>
                
                <div style="margin-bottom: 8px; display: flex; gap: 10px; align-items: center;">
                    <span id="selectedCount" style="margin-left: auto; color: var(--muted); font-size: 11px;">Выбрано: 0</span>
                </div>
                
                <div id="ordersList" style="max-height: 300px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; padding: 6px;">
                    <div style="text-align: center; padding: 15px; color: var(--muted); font-size: 12px;">Загрузка заявок...</div>
                </div>
                
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px;">
                    <button type="button" onclick="closeDeleteOrdersModal()" style="padding: 6px 14px; background: var(--muted); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">
                        Отмена
                    </button>
                    <button type="button" onclick="deleteSelectedOrders()" id="deleteBtn" disabled style="padding: 6px 14px; background: var(--danger); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">
                        Удалить выбранные
                    </button>
                </div>
            </div>
        </div>
    </div>


<?php
// ВЫВОД ПРОФИЛИРОВАНИЯ
profile_mark('Page fully rendered');
if (isset($_GET['profile'])) {
    echo "<div style='position:fixed;bottom:0;left:0;right:0;background:#f8f9fa;border-top:2px solid #007bff;padding:15px;z-index:99999;max-height:40vh;overflow-y:auto;font-family:monospace;font-size:12px;'>";
    echo "<div style='display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;'>";
    echo "<h3 style='margin:0;color:#007bff;'>⏱️ Профиль производительности</h3>";
    echo "<button onclick='this.parentElement.parentElement.style.display=\"none\"' style='background:#dc3545;color:white;border:none;padding:5px 15px;border-radius:4px;cursor:pointer;'>✕ Закрыть</button>";
    echo "</div>";
    echo "<table style='width:100%;border-collapse:collapse;background:white;'>";
    echo "<tr style='background:#007bff;color:white;'><th style='padding:8px;text-align:left;'>Этап</th><th style='padding:8px;text-align:right;width:100px;'>Время (мс)</th><th style='padding:8px;text-align:right;width:100px;'>Прирост (мс)</th></tr>";
    
    $prev = 0;
    $total = round((microtime(true) - $_profile_start) * 1000, 2);
    
    foreach ($_profile_times as $label => $time) {
        $delta = round($time - $prev, 2);
        $color = $delta > 1000 ? '#dc3545' : ($delta > 500 ? '#ffc107' : '#28a745');
        echo "<tr style='border-bottom:1px solid #dee2e6;'>";
        echo "<td style='padding:8px;'>" . htmlspecialchars($label) . "</td>";
        echo "<td style='padding:8px;text-align:right;font-weight:bold;'>" . $time . " мс</td>";
        echo "<td style='padding:8px;text-align:right;font-weight:bold;color:" . $color . ";'>" . $delta . " мс</td>";
        echo "</tr>";
        $prev = $time;
    }
    
    echo "<tr style='background:#f8f9fa;font-weight:bold;'>";
    echo "<td style='padding:8px;'>ИТОГО</td>";
    echo "<td style='padding:8px;text-align:right;color:#007bff;'>" . $total . " мс</td>";
    echo "<td style='padding:8px;'></td>";
    echo "</tr>";
    echo "</table>";
    echo "<div style='margin-top:10px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;'>";
    echo "<strong>💡 Подсказка:</strong> Этапы с временем > 500мс выделены оранжевым, > 1000мс - красным. Это узкие места!";
    echo "</div>";
    echo "</div>";
}
?>
<script>
// Функция открытия модального окна загрузки файла
function openLoadFileModal() {
    document.getElementById('loadFileModal').style.display = 'block';
    // Сброс формы при открытии
    document.getElementById('loadFileForm').reset();
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('submitFileButton').disabled = true;
    document.getElementById('submitFileButton').style.background = 'var(--muted)';
    document.getElementById('submitFileButton').style.opacity = '0.5';
    document.getElementById('submitFileButton').style.cursor = 'not-allowed';
}

// Функция закрытия модального окна загрузки файла
function closeLoadFileModal() {
    document.getElementById('loadFileModal').style.display = 'none';
    document.getElementById('loadFileForm').reset();
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('submitFileButton').disabled = true;
    document.getElementById('submitFileButton').style.background = 'var(--muted)';
    document.getElementById('submitFileButton').style.opacity = '0.5';
    document.getElementById('submitFileButton').style.cursor = 'not-allowed';
    
    // Сброс кнопки выбора файла
    const fileSelectButton = document.getElementById('fileSelectButton');
    if (fileSelectButton) {
        const iconSpan = fileSelectButton.querySelector('span:first-child');
        const textSpan = fileSelectButton.querySelector('span:last-child');
        if (iconSpan) iconSpan.textContent = '📎';
        if (textSpan) textSpan.textContent = 'Выбрать файл';
        fileSelectButton.style.borderColor = 'var(--border)';
        fileSelectButton.style.background = 'var(--paper)';
        fileSelectButton.removeAttribute('data-selected');
    }
}

// Обработка выбора файла
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('loadFileInput');
    const fileSelectButton = document.getElementById('fileSelectButton');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const fileNameText = document.getElementById('fileNameText');
    const submitButton = document.getElementById('submitFileButton');
    
    if (fileInput && fileSelectButton) {
        // Стили для кнопки при наведении
        fileSelectButton.addEventListener('mouseenter', function() {
            if (!this.dataset.selected) {
                this.style.borderColor = 'var(--accent)';
                this.style.background = '#f0f4ff';
            }
        });
        fileSelectButton.addEventListener('mouseleave', function() {
            if (!this.dataset.selected) {
                this.style.borderColor = 'var(--border)';
                this.style.background = 'var(--paper)';
            }
        });
        
        // Обработка выбора файла
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                fileNameText.textContent = fileName;
                fileNameDisplay.style.display = 'block';
                
                // Активация кнопки загрузки
                submitButton.disabled = false;
                submitButton.style.background = 'var(--accent)';
                submitButton.style.opacity = '1';
                submitButton.style.cursor = 'pointer';
                
                // Обновление текста и стиля кнопки выбора
                const iconSpan = fileSelectButton.querySelector('span:first-child');
                const textSpan = fileSelectButton.querySelector('span:last-child');
                if (iconSpan) iconSpan.textContent = '✓';
                if (textSpan) textSpan.textContent = 'Файл выбран';
                fileSelectButton.style.borderColor = 'var(--accent)';
                fileSelectButton.style.background = '#f0f4ff';
                fileSelectButton.dataset.selected = 'true';
            }
        });
    }
    
    // Закрытие модального окна при клике вне его
    const modal = document.getElementById('loadFileModal');
    if (modal) {
        window.addEventListener('click', function(event) {
            if (event.target == modal) {
                closeLoadFileModal();
            }
        });
    }
    
    // Закрытие модального окна удаления заявок при клике вне его
    const deleteModal = document.getElementById('deleteOrdersModal');
    if (deleteModal) {
        window.addEventListener('click', function(event) {
            if (event.target == deleteModal) {
                closeDeleteOrdersModal();
            }
        });
    }
});

// Функции для модального окна удаления заявок
let ordersList = [];

function openDeleteOrdersModal() {
    document.getElementById('deleteOrdersModal').style.display = 'block';
    loadOrdersList();
}

function closeDeleteOrdersModal() {
    document.getElementById('deleteOrdersModal').style.display = 'none';
    ordersList = [];
}

async function loadOrdersList() {
    const listDiv = document.getElementById('ordersList');
    listDiv.innerHTML = '<div style="text-align: center; padding: 15px; color: var(--muted); font-size: 12px;">Загрузка заявок...</div>';
    
    try {
        const response = await fetch('delete_orders_api.php?action=get_orders');
        const data = await response.json();
        
        if (data.ok && data.orders) {
            ordersList = data.orders;
            renderOrdersList();
        } else {
            listDiv.innerHTML = '<div style="text-align: center; padding: 15px; color: var(--danger); font-size: 12px;">Ошибка загрузки заявок</div>';
        }
    } catch (error) {
        listDiv.innerHTML = '<div style="text-align: center; padding: 15px; color: var(--danger); font-size: 12px;">Ошибка: ' + error.message + '</div>';
    }
}

function renderOrdersList() {
    const listDiv = document.getElementById('ordersList');
    
    if (ordersList.length === 0) {
        listDiv.innerHTML = '<div style="text-align: center; padding: 15px; color: var(--muted); font-size: 12px;">Нет заявок для удаления</div>';
        return;
    }
    
    let html = '<div style="display: flex; flex-direction: column; gap: 4px;">';
    ordersList.forEach(order => {
        const statusClass = order.status === 'replanning' ? ' style="color: #dc2626; font-weight: 600;"' : '';
        const hiddenBadge = order.is_hidden ? '<span style="background: var(--muted); color: white; padding: 1px 4px; border-radius: 3px; font-size: 9px; margin-left: 6px;">Скрыта</span>' : '';
        html += `
            <label style="display: flex; align-items: center; gap: 6px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 4px; cursor: pointer; transition: background 0.2s;" 
                   onmouseover="this.style.background='#f9fafb'" 
                   onmouseout="this.style.background=''">
                <input type="checkbox" class="order-checkbox" value="${order.order_number}" onchange="updateSelectedCount()" style="margin: 0; cursor: pointer; width: 14px; height: 14px;">
                <div style="flex: 1;">
                    <div${statusClass} style="font-size: 13px;">${order.order_number}${hiddenBadge}</div>
                    <div style="font-size: 11px; color: var(--muted);">
                        Позиций: ${order.positions_count}, Всего: ${order.total_count} шт
                    </div>
                </div>
            </label>
        `;
    });
    html += '</div>';
    listDiv.innerHTML = html;
    updateSelectedCount();
}

function selectAllOrders() {
    document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAllOrders() {
    document.querySelectorAll('.order-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.order-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = 'Выбрано: ' + checked;
    document.getElementById('deleteBtn').disabled = checked === 0;
}

async function deleteSelectedOrders() {
    const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
    const selectedOrders = Array.from(checkedBoxes).map(cb => cb.value);
    
    if (selectedOrders.length === 0) {
        alert('Выберите заявки для удаления');
        return;
    }
    
    const deleteType = 'full'; // Всегда полное удаление
    const confirmText = `Вы уверены, что хотите полностью удалить ${selectedOrders.length} заявок? Это действие необратимо!`;
    
    if (!confirm(confirmText)) {
        return;
    }
    
    const deleteBtn = document.getElementById('deleteBtn');
    const originalText = deleteBtn.textContent;
    deleteBtn.textContent = 'Удаление...';
    deleteBtn.disabled = true;
    
    try {
        const response = await fetch('delete_orders_api.php?action=delete_orders', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                orders: selectedOrders,
                delete_type: deleteType
            })
        });
        
        const data = await response.json();
        
        if (data.ok) {
            alert(`✅ Успешно удалено ${data.deleted_count} заявок`);
            closeDeleteOrdersModal();
            // Перезагружаем страницу для обновления списка заявок
            location.reload();
        } else {
            alert('❌ Ошибка: ' + (data.error || 'Неизвестная ошибка'));
            deleteBtn.textContent = originalText;
            deleteBtn.disabled = false;
        }
    } catch (error) {
        alert('❌ Ошибка при удалении: ' + error.message);
        deleteBtn.textContent = originalText;
        deleteBtn.disabled = false;
    }
}

</script>
<?php
?>
</body>
</html>
