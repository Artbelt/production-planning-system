<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Подключаем настройки базы данных (путь относительно текущего файла)
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/../auth/includes/db.php');
require_once(__DIR__ . '/tools/tools.php');

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

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

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

// Определяем текущий цех
$currentDepartment = $_SESSION['auth_department'] ?? 'U3';

// Проверяем, есть ли у пользователя доступ к цеху U3
$hasAccessToU3 = false;
$userRole = null;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U3') {
        $hasAccessToU3 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

// Если нет доступа к U3, показываем предупреждение, но не блокируем
if (!$hasAccessToU3) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U3</h3>";
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

// Для main.php всегда проверяем доступ к цеху U3
$canAccessLaser = canAccessLaserRequests($userDepartments, 'U3');

// Проверяем, является ли пользователь оператором гофромашины для цеха U3
$isCorrugatorOperator = false;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U3' && $dept['role_name'] === 'corr_operator') {
        $isCorrugatorOperator = true;
        break;
    }
}

// Устанавливаем переменные для совместимости со старым кодом
$workshop = $currentDepartment;
$advertisement = 'Информация';

$application_name = 'Система управления производством на участке U3';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>U3</title>
    <link rel="stylesheet" href="sheets.css">

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

        /* Стиль для кнопки */
        .alert-button {
            background-color: yellow !important;
        }
        .alert-button:hover {
            background-color: skyblue !important;
        }

        /* модальные окна */
        .modal, .cap-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content, .cap-modal-content {
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
        .close, .cap-modal-close {
            color: var(--muted);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            float: right;
        }
        .close:hover, .cap-modal-close:hover {
            color: var(--ink);
        }
        .cap-modal-content h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 6px;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .cap-menu-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 15px;
        }
        .cap-menu-card {
            background: #f9f9f9;
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 12px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: #333;
            display: block;
        }
        .cap-menu-card:hover {
            border-color: #6495ed;
            background: #e8f0fe;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .cap-menu-card h2 {
            margin: 0 0 6px 0;
            color: #6495ed;
            font-size: 14px;
            font-weight: bold;
        }
        .cap-menu-card p {
            margin: 0;
            color: #666;
            font-size: 12px;
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
        .saved-orders{
            display:block; margin-top:8px;
            width:100%; box-sizing:border-box;
        }
        .saved-orders form{
            display:flex; flex-wrap:wrap; gap:6px; width:100%;
            margin:0; padding:0;
        }
        .saved-orders input[type="submit"],
        .saved-orders button[type="submit"]{
            display:inline-flex; 
            align-items:center;
            margin:0!important; padding:6px 12px!important;
            border-radius:8px;
            background:var(--accent); color:#fff;
            border:none!important; box-shadow:0 1px 3px rgba(0,0,0,0.1);
            font-size:13px; font-weight:500;
            transition:all 0.2s;
            white-space:nowrap;
            flex-shrink:0;
            cursor:pointer;
            box-sizing:border-box;
            line-height:1.4;
        }
        .saved-orders input[type="submit"]:hover,
        .saved-orders button[type="submit"]:hover{
            background:#1e47c5; transform:translateY(-1px);
            box-shadow:0 2px 6px rgba(0,0,0,0.15);
        }
        .saved-orders input[type="submit"].alert-button,
        .saved-orders button[type="submit"].alert-button{
            background:#f59e0b!important;
        }
        .saved-orders input[type="submit"].alert-button:hover,
        .saved-orders button[type="submit"].alert-button:hover{
            background:#d97706!important;
        }

        /* Метка на плашке заявки: зелёная = полностью распланирована, оранжевая = нет */
        .order-card-wrap{ position:relative; display:inline-flex; }
        .order-card-dot{
            position:absolute;
            top:4px;
            right:4px;
            width:6px;
            height:6px;
            border-radius:50%;
            pointer-events:none;
            z-index:1;
        }
        .order-card-dot--full{ background:#22c55e; box-shadow:0 0 0 1px rgba(255,255,255,.9); }
        .order-card-dot--partial{ background:#f59e0b; box-shadow:0 0 0 1px rgba(255,255,255,.9); }

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
</head>
<body>



<?php
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
                <div class="topbar">
                    <div class="topbar-left">
                        <span class="logo">U3</span>
                        <span class="system-name">Система управления</span>
                    </div>
                    <div class="topbar-center">
                        <?php echo htmlspecialchars($application_name); ?>
                    </div>
                    <div class="topbar-right">
                        <!-- Панель авторизации перенесена вверх -->
                    </div>
                </div>
            </td>
        </tr>

        <!-- Контент: 3 колонки -->
        <tr class="content-row">
            <!-- Левая панель -->
            <td class="panel panel--left" style="width:30%;">


                <div class="section-title">Операции</div>
                <div class="stack">
                    <a href="product_output.php" target="_blank" rel="noopener" class="stack"><button>Выпуск продукции</button></a>
                    <a href="gofro_packages_input.php" target="_blank" rel="noopener" class="stack"><button>Ввод изготовленных гофропакетов</button></a>
                    <button type="button" onclick="openDataEditor()">Редактор данных</button>
                    <button type="button" onclick="openCapManagementModal()">Операции с крышками</button>
                    <?php if ($isCorrugatorOperator): ?>
                    <form action="parts_output_for_workers.php" method="post" target="_blank" class="stack"><input type="submit" value="Выпуск гофропакетов"></form>
                    <?php endif; ?>
                    <?php if ($canAccessLaser): ?>
                    <a href="laser_request.php" target="_blank" rel="noopener" class="stack"><button type="button">Заявка на лазер</button></a>
                    <?php endif; ?>
                </div>

                <div class="section-title" style="margin-top:14px">Информация</div>
                <div class="stack">
                    <a href="cap_balance_chart.php" target="_blank" rel="noopener" class="stack"><button>Анализ</button></a>
                    <form action="summary_plan_U3.php" method="post" target="_blank" class="stack"><input type="submit" value="Сводный план У3"></form>
                    <form action="dimensions_report.php" method="post" target="_blank" class="stack"><input type="submit" value="Таблица размеров для участка"></form>
                    <form action="product_output_view.php" method="post" target="_blank" class="stack"><input type="submit" value="Обзор выпуска продукции"></form>
                    <form action="parts_output_view.php" method="post" target="_blank" class="stack"><input type="submit" value="Обзор выпуска гофропакетов"></form>
                    <form action="gofra_packages_table.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
                        <input type="submit" value="Кол-во гофропакетов из рулона">
                    </form>
                    <a href="NP_supply_requirements.php" target="_blank" rel="noopener" class="stack"><button>Потребность комплектующих по заявке</button></a>
                    <a href="active_positions.php" target="_blank" rel="noopener" class="stack"><button type="button">Активные позиции</button></a>
                </div>

                <div class="section-title" style="margin-top:14px">Управление данными</div>
                <div class="stack">
                    <form action="edit_filter_properties.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
                        <input type="submit" value="Добавить/изменить фильтр в БД">
                    </form>
                </div>

                <div class="section-title" style="margin-top:14px">Объявление</div>
                <div class="stack">
                    <button onclick="openCreateAdModal()">Создать объявление</button>
                </div>
            </td>

            <!-- Центральная панель -->
            <td class="panel panel--main" style="width:40%;">

                <?php
                // Виджет задач для мастеров
                if ($userRole === 'supervisor') {
    $tasksError = null;
    $myTasks = [];
    
    try {
        // Все задачи централизованно лежат в БД plan_u5, фильтруем по цеху
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo_tasks = getPdo('plan_u5');
        
        // Для страницы main.php всегда используем цех U3
        $taskDepartment = 'U3';
        $taskUserId = $session['user_id'];
        
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
                        $stmt_tasks->execute([$taskUserId, $taskDepartment]);
                        $myTasks = $stmt_tasks->fetchAll();
                        
                        $taskCount = count($myTasks);
                        
                        // Показываем виджет даже если задач нет, чтобы пользователь видел, что система работает
                        if (true): // Всегда показываем виджет
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
                        <?php if ($taskCount > 0): ?>
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
                        <?php else: ?>
                            <div style="background: white; padding: 16px; border-radius: 6px; border: 1px solid #e5e7eb; text-align: center; color: #6b7280;">
                                <p style="margin: 0;">📋 Нет активных задач</p>
                                <p style="margin: 8px 0 0 0; font-size: 12px;">Все задачи выполнены или еще не назначены</p>
                            </div>
                        <?php endif; ?>
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
                        const response = await fetch('tasks_api_u3.php?action=update_status', {
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
                        endif; // Всегда показываем виджет
                    } catch (Exception $e) {
                        // Показываем ошибку для отладки (можно убрать в продакшене)
                        if (isset($_GET['debug'])) {
                            echo "<div style='background: #fee2e2; border: 1px solid #dc2626; padding: 10px; border-radius: 6px; margin-bottom: 16px; color: #991b1b;'>";
                            echo "<strong>Ошибка загрузки задач:</strong> " . htmlspecialchars($e->getMessage());
                            echo "</div>";
                        }
                    }
                }
                ?>
                
                <div class="section-title">Объявления</div>
                <div class="stack-lg">

                    <?php 
                    show_ads();
                    
                    show_weekly_production();
                    
                    show_weekly_parts();
                    ?>

                    <div class="search-card">
                        <h4 style="margin:0 0 8px;">Поиск заявок по фильтру</h4>
                        <div class="stack">
                            <label for="filterSelect">Фильтр:</label>
                            <?php 
                            load_filters_into_select();
                            ?>
                        </div>
                        <div id="filterSearchResult" style="margin-top:10px;"></div>
                    </div>
                </div>

                <script>
                    (function(){
                        const resultBox = document.getElementById('filterSearchResult');
                        function getSelectEl(){ return document.querySelector('select[name="analog_filter"]'); }
                        async function runSearch(){
                            const sel = getSelectEl();
                            if(!sel){ resultBox.innerHTML = '<div class="muted">Не найден выпадающий список.</div>'; return; }
                            const val = sel.value.trim();
                            if(!val){ resultBox.innerHTML = '<div class="muted">Выберите фильтр…</div>'; return; }
                            resultBox.textContent = 'Загрузка…';
                            try{
                                const formData = new FormData(); formData.append('filter', val);
                                const resp = await fetch('search_filter_in_the_orders.php', { method:'POST', body:formData });
                                if(!resp.ok){ resultBox.innerHTML = `<div class="alert">Ошибка запроса: ${resp.status} ${resp.statusText}</div>`; return; }
                                resultBox.innerHTML = await resp.text();
                            }catch(e){ resultBox.innerHTML = `<div class="alert">Ошибка: ${e}</div>`; }
                        }
                        const sel = getSelectEl(); if(sel){ sel.id='filterSelect'; sel.addEventListener('change', runSearch); }
                    })();
                </script>
            </td>

            <!-- Правая панель -->
            <td class="panel panel--right" style="width:30%;">
                <?php
                /* ОПТИМИЗИРОВАННАЯ загрузка заявок */
                $pdo = getPdo('plan_u3');
                $result = $pdo->query("
                    SELECT order_number, workshop, hide,
                           COALESCE(plan_ready, 0) AS plan_ready,
                           COALESCE(corr_ready, 0) AS corr_ready,
                           COALESCE(build_ready, 0) AS build_ready
                    FROM orders
                    WHERE (hide IS NULL OR hide != 1)
                    ORDER BY order_number
                ");
                if (!$result) { echo 'Ошибка загрузки заявок'; exit; }

                echo '<div class="section-title">Сохраненные заявки</div>';
                echo '<div class="saved-orders">';

                $orders_list = [];
                while ($orders_data = $result->fetch(PDO::FETCH_ASSOC)){
                    $order_num = $orders_data['order_number'];
                    if (!isset($orders_list[$order_num])) {
                        $orders_list[$order_num] = $orders_data;
                    }
                }
                if (empty($orders_list)) { echo "<div class='muted'>В базе нет ни одной заявки</div>"; }

                echo '<form action="show_order.php" method="post" target="_blank" style="display:flex; flex-wrap:wrap; gap:6px; width:100%;">';

                $st_total = $pdo->prepare("SELECT SUM(count) as total FROM orders WHERE order_number = ?");
                $st_produced = $pdo->prepare("SELECT SUM(count_of_filters) as produced FROM manufactured_production WHERE name_of_order = ?");

                foreach ($orders_list as $order_num => $orders_data){
                    $total_planned = 0;
                    $total_produced = 0;
                    $st_total->execute([$order_num]);
                    $row_total = $st_total->fetch(PDO::FETCH_ASSOC);
                    if ($row_total && $row_total['total'] !== null) $total_planned = (int)$row_total['total'];
                    $st_produced->execute([$order_num]);
                    $row_produced = $st_produced->fetch(PDO::FETCH_ASSOC);
                    if ($row_produced && $row_produced['produced'] !== null) $total_produced = (int)$row_produced['produced'];
    
    // Вычисляем процент
    $progress = 0;
    if ($total_planned > 0) {
        $progress = round(($total_produced / $total_planned) * 100);
    }
    
                    // Формируем аккуратные кнопки заявок
                    $btnClass = str_contains($order_num, '[!]') ? "alert-button" : "";
                    $order_display = htmlspecialchars($order_num);
                    $plan_ready = (int)($orders_data['plan_ready'] ?? 0);
                    $corr_ready = (int)($orders_data['corr_ready'] ?? 0);
                    $build_ready = (int)($orders_data['build_ready'] ?? 0);
                    $fully_planned = ($plan_ready && $corr_ready && $build_ready);
                    $dot_class = $fully_planned ? 'order-card-dot order-card-dot--full' : 'order-card-dot order-card-dot--partial';
                    $dot_title = $fully_planned ? 'Заявка полностью распланирована' : 'Заявка распланирована не полностью';
                    
                    echo "<span class='order-card-wrap' style='position:relative; display:inline-flex;'>";
                    echo "<span class='{$dot_class}' title='" . htmlspecialchars($dot_title) . "'></span>";
                    echo "<button type='submit' name='order_number' value='{$order_display}' class='{$btnClass}' title='Прогресс выполнения: {$progress}%'>";
                    echo htmlspecialchars($order_num);
                    if ($progress > 0) {
                        echo " <span style='font-size:11px; opacity:0.9;'>[{$progress}%]</span>";
                    }
                    echo "</button>";
                    echo "</span>";
                }
                echo '</form>';
                echo '</div>';

                echo '<div class="section-title" style="margin-top:14px">Операции над заявками</div>';
                echo '<section class="stack">';
                echo "<form action='new_order.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Создать заявку вручную'>"
                    ."</form>";
                echo "<form action='archived_orders.php' target='_blank' class='stack'>"
                    ."<input type='submit' value='Архив заявок'>"
                    ."</form>";
                echo '<button type="button" onclick="openLoadFileModal()">Загрузить файл</button>';
                echo '<button type="button" onclick="window.location.href=\'edit_order.php\'">Редактировать заявку</button>';
                
                echo '<div style="border-top: 1px dashed var(--border); margin: 8px 0;"></div>';
                
                echo "<form action='NP_cut_index.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Менеджер планирования'>"
                    ."</form>";
                echo '</section>';
                
                echo '<div class="section-title" style="margin-top:14px">Сервис</div>';
                echo '<section class="stack">';
                echo '<a href="knives_bobinorezka.php" target="_blank" rel="noopener" class="stack">';
                echo '<button>Ножи бобинорезки</button>';
                echo '</a>';
                echo '<a href="knives_prosechnik.php" target="_blank" rel="noopener" class="stack">';
                echo '<button>Ножи просечников</button>';
                echo '</a>';
                echo '</section>';
                ?>
            </td>
        </tr>
    </table>
</div>

<?php
$cap_orders_list = [];
$res_modal = $pdo->query("SELECT DISTINCT order_number, workshop, hide FROM orders");
if ($res_modal) {
    $orders_temp_modal = [];
    while ($r = $res_modal->fetch(PDO::FETCH_ASSOC)) {
        if (($r['hide'] ?? 0) != 1 && !isset($orders_temp_modal[$r['order_number']])) {
            $orders_temp_modal[$r['order_number']] = true;
        }
    }
    $cap_orders_list = array_keys($orders_temp_modal);
    rsort($cap_orders_list);
}
?>


<!-- Модальное окно управления крышками -->
<div id="capManagementModal" class="cap-modal">
    <div class="cap-modal-content">
        <span class="cap-modal-close" onclick="closeCapManagementModal()">&times;</span>
        <h1>Управление крышками</h1>
        
        <div class="cap-menu-grid">
            <a href="cap_income.php" class="cap-menu-card" target="_blank">
                <h2>Прием крышек</h2>
                <p>Внести информацию о поступлении крышек на склад</p>
            </a>
            
            <a href="cap_stock_view.php" class="cap-menu-card" target="_blank">
                <h2>Остатки на складе</h2>
                <p>Просмотр текущих остатков крышек</p>
            </a>
            
            <a href="cap_movements_view.php" class="cap-menu-card" target="_blank">
                <h2>Движение по заявке</h2>
                <p>Просмотр движения крышек по конкретной заявке</p>
            </a>
            
            <a href="cap_history.php" class="cap-menu-card" target="_blank">
                <h2>История операций</h2>
                <p>Просмотр всех операций с крышками</p>
            </a>
            
            <a href="cap_balance_analysis.php" class="cap-menu-card" target="_blank">
                <h2>Анализ баланса потребления крышки</h2>
                <p>Анализ прихода крышек и производства фильтров по дням</p>
            </a>
        </div>
    </div>
</div>

<script>
function openCapManagementModal() {
    document.getElementById('capManagementModal').style.display = 'block';
}

function closeCapManagementModal() {
    document.getElementById('capManagementModal').style.display = 'none';
}

// Закрытие модального окна при клике вне его
window.onclick = function(event) {
    const modal = document.getElementById('capManagementModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

// Закрытие модального окна по клавише ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.getElementById('capManagementModal').style.display = 'none';
        document.getElementById('createAdModal').style.display = 'none';
        document.getElementById('dataEditorModal').style.display = 'none';
        document.getElementById('productEditorModal').style.display = 'none';
        document.getElementById('partsEditorModal').style.display = 'none';
        document.getElementById('addPositionModal').style.display = 'none';
        document.getElementById('addPartPositionModal').style.display = 'none';
        closeLoadFileModal();
    }
});

function openCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'block';
}

function closeCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'none';
}

// Функции для модальных окон редактора данных
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

function openPartsEditor() {
    document.getElementById('partsEditorModal').style.display = 'block';
    loadPartsEditor();
}

function closePartsEditor() {
    document.getElementById('partsEditorModal').style.display = 'none';
}

function closeAddPartPositionModal() {
    document.getElementById('addPartPositionModal').style.display = 'none';
}

// Закрытие модальных окон при клике вне их
window.onclick = function(event) {
    const capModal = document.getElementById('capManagementModal');
    const createAdModal = document.getElementById('createAdModal');
    const dataModal = document.getElementById('dataEditorModal');
    const productModal = document.getElementById('productEditorModal');
    const partsModal = document.getElementById('partsEditorModal');
    const addPositionModal = document.getElementById('addPositionModal');
    const addPartPositionModal = document.getElementById('addPartPositionModal');
    const loadFileModal = document.getElementById('loadFileModal');
    
    if (event.target == capModal) {
        closeCapManagementModal();
    }
    if (event.target == createAdModal) {
        closeCreateAdModal();
    }
    if (event.target === dataModal) {
        closeDataEditor();
    }
    if (event.target === productModal) {
        closeProductEditor();
    }
    if (event.target === partsModal) {
        closePartsEditor();
    }
    if (event.target === addPositionModal) {
        closeAddPositionModal();
    }
    if (event.target === addPartPositionModal) {
        closeAddPartPositionModal();
    }
    if (event.target === loadFileModal) {
        closeLoadFileModal();
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
    const orderFormData = new FormData();
    orderFormData.append('action', 'load_orders_for_dropdown');
    
    fetch('product_editor_api.php', {
        method: 'POST',
        body: orderFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const orderSelects = document.querySelectorAll('.order-select');
                
                orderSelects.forEach((select) => {
                    const currentValue = select.querySelector('option').value;
                    
                    select.innerHTML = '';
                    
                    const currentOption = document.createElement('option');
                    currentOption.value = currentValue;
                    currentOption.textContent = currentValue;
                    currentOption.selected = true;
                    select.appendChild(currentOption);
                    
                    data.orders.forEach(order => {
                        if (order !== currentValue) {
                            const option = document.createElement('option');
                            option.value = order;
                            option.textContent = order;
                            select.appendChild(option);
                        }
                    });
                });
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
    
    document.getElementById('addPositionDate').value = selectedDate;
    document.getElementById('addPositionFilter').value = '';
    document.getElementById('addPositionQuantity').value = '';
    document.getElementById('addPositionOrder').value = '';
    document.getElementById('addPositionTeam').value = '';
    
    loadFiltersAndOrders();
    document.getElementById('addPositionModal').style.display = 'block';
}

// Функция загрузки фильтров и заявок
function loadFiltersAndOrders() {
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
            }
        } catch (e) {
            console.error('Ошибка парсинга фильтров:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки фильтров:', error);
    });
    
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
            loadDataForDate();
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка добавления: ' + error.message);
    });
}

// ========== ФУНКЦИИ ДЛЯ РЕДАКТОРА ГОФРОПАКЕТОВ ==========

// Функция загрузки редактора гофропакетов
function loadPartsEditor() {
    // Устанавливаем сегодняшнюю дату по умолчанию
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('editPartsDate').value = today;
    
    // Скрываем таблицу данных
    document.getElementById('partsTableContainer').style.display = 'none';
}

// Функция загрузки данных по выбранной дате для гофропакетов
function loadPartsDataForDate() {
    const selectedDate = document.getElementById('editPartsDate').value;
    
    if (!selectedDate) {
        alert('Пожалуйста, выберите дату');
        return;
    }
    
    const container = document.getElementById('partsTableContainer');
    container.innerHTML = '<p>Загрузка данных...</p>';
    container.style.display = 'block';
    
    // AJAX запрос для загрузки данных по дате
    const formData = new FormData();
    formData.append('action', 'load_data_by_date');
    formData.append('date', selectedDate);
    
    fetch('parts_editor_api.php', {
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
                renderPartsEditor(data.data, selectedDate);
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

// Функция отображения редактора гофропакетов
function renderPartsEditor(data, selectedDate) {
    const container = document.getElementById('partsTableContainer');
    
    if (data.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <h3>📅 ${selectedDate}</h3>
                <p>Нет данных за выбранную дату</p>
                <button onclick="addNewPartPosition('${selectedDate}')" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; margin-top: 16px;">
                    ➕ Добавить позицию
                </button>
            </div>
        `;
        return;
    }
    
    let html = `
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #374151;">📅 ${selectedDate}</h3>
            <button onclick="addNewPartPosition('${selectedDate}')" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
                ➕ Добавить позицию
            </button>
        </div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: left;">Гофропакет</th>
                        <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Кол-во</th>
                        <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Заявка</th>
                        <th style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">Действия</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    data.forEach(item => {
        const partName = item.part_name || 'Не указан';
        const quantity = item.quantity || 0;
        const orderNumber = item.order_number || 'Не указан';
        const itemId = item.virtual_id || '';
        
        html += `
            <tr>
                <td style="padding: 8px; border: 1px solid #e5e7eb;">${partName}</td>
                <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                    <input type="number" value="${quantity}" min="0" 
                           onchange="updatePartQuantity('${itemId}', this.value)" 
                           style="width: 60px; padding: 4px; border: 1px solid #d1d5db; border-radius: 4px;">
                </td>
                <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                    <select onchange="movePartToOrder('${itemId}', this.value)" 
                            class="part-order-select" data-item-id="${itemId}"
                            style="padding: 4px; border: 1px solid #d1d5db; border-radius: 4px; min-width: 100px;">
                        <option value="${orderNumber}">${orderNumber}</option>
                    </select>
                </td>
                <td style="padding: 8px; border: 1px solid #e5e7eb; text-align: center;">
                    <button onclick="removePartPosition('${itemId}')" 
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
    `;
    
    container.innerHTML = html;
    
    // Загружаем заявки для всех выпадающих списков в таблице
    loadOrdersForPartsTableDropdowns();
}

// Функция загрузки заявок для выпадающих списков в таблице гофропакетов
function loadOrdersForPartsTableDropdowns() {
    const orderFormData = new FormData();
    orderFormData.append('action', 'load_orders_for_dropdown');
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: orderFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const orderSelects = document.querySelectorAll('.part-order-select');
                
                orderSelects.forEach((select) => {
                    const currentValue = select.querySelector('option').value;
                    
                    select.innerHTML = '';
                    
                    const currentOption = document.createElement('option');
                    currentOption.value = currentValue;
                    currentOption.textContent = currentValue;
                    currentOption.selected = true;
                    select.appendChild(currentOption);
                    
                    data.orders.forEach(order => {
                        if (order !== currentValue) {
                            const option = document.createElement('option');
                            option.value = order;
                            option.textContent = order;
                            select.appendChild(option);
                        }
                    });
                });
            }
        } catch (e) {
            console.error('Ошибка парсинга заявок для таблицы:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки заявок для таблицы:', error);
    });
}

// Функции для работы с данными гофропакетов
function updatePartQuantity(id, quantity) {
    const formData = new FormData();
    formData.append('action', 'update_quantity');
    formData.append('id', id);
    formData.append('quantity', quantity);
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Количество обновлено для ID:', id);
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка обновления: ' + error.message);
    });
}

function movePartToOrder(id, newOrderId) {
    const formData = new FormData();
    formData.append('action', 'move_to_order');
    formData.append('id', id);
    formData.append('new_order_id', newOrderId);
    
    fetch('parts_editor_api.php', {
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

function removePartPosition(id) {
    if (!confirm('Вы уверены, что хотите удалить эту позицию?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'remove_position');
    formData.append('id', id);
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
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

function addNewPartPosition(selectedDate) {
    if (!selectedDate) {
        selectedDate = document.getElementById('editPartsDate').value;
    }
    
    if (!selectedDate) {
        alert('Пожалуйста, выберите дату');
        return;
    }
    
    document.getElementById('addPartPositionDate').value = selectedDate;
    document.getElementById('addPartPositionPart').value = '';
    document.getElementById('addPartPositionQuantity').value = '';
    document.getElementById('addPartPositionOrder').value = '';
    
    loadPartsAndOrders();
    document.getElementById('addPartPositionModal').style.display = 'block';
}

// Функция загрузки гофропакетов и заявок
function loadPartsAndOrders() {
    const partFormData = new FormData();
    partFormData.append('action', 'load_parts');
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: partFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const partSelect = document.getElementById('addPartPositionPart');
                partSelect.innerHTML = '<option value="">Выберите гофропакет</option>';
                data.parts.forEach(part => {
                    const option = document.createElement('option');
                    option.value = part;
                    option.textContent = part;
                    partSelect.appendChild(option);
                });
            }
        } catch (e) {
            console.error('Ошибка парсинга гофропакетов:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки гофропакетов:', error);
    });
    
    const orderFormData = new FormData();
    orderFormData.append('action', 'load_orders');
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: orderFormData
    })
    .then(response => response.text())
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                const orderSelect = document.getElementById('addPartPositionOrder');
                orderSelect.innerHTML = '<option value="">Выберите заявку</option>';
                data.orders.forEach(order => {
                    const option = document.createElement('option');
                    option.value = order;
                    option.textContent = order;
                    orderSelect.appendChild(option);
                });
            }
        } catch (e) {
            console.error('Ошибка парсинга заявок:', e, text);
        }
    })
    .catch(error => {
        console.error('Ошибка загрузки заявок:', error);
    });
}

// Обработчик формы добавления позиции гофропакета
document.addEventListener('DOMContentLoaded', function() {
    const addPartPositionForm = document.getElementById('addPartPositionForm');
    if (addPartPositionForm) {
        addPartPositionForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitAddPartPosition();
        });
    }
});

function submitAddPartPosition() {
    const date = document.getElementById('addPartPositionDate').value;
    const part = document.getElementById('addPartPositionPart').value;
    const quantity = document.getElementById('addPartPositionQuantity').value;
    const order = document.getElementById('addPartPositionOrder').value;
    
    if (!date || !part || !quantity || !order) {
        alert('Пожалуйста, заполните все поля');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_position');
    formData.append('production_date', date);
    formData.append('part_name', part);
    formData.append('quantity', quantity);
    formData.append('order_name', order);
    
    fetch('parts_editor_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Позиция успешно добавлена!');
            closeAddPartPositionModal();
            loadPartsDataForDate();
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка добавления: ' + error.message);
    });
}

// Функции для модального окна загрузки файла
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
        fileSelectButton.style.background = 'var(--panel)';
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
                this.style.background = 'var(--panel)';
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
});

</script>

<!-- Модальное окно создания объявления -->
<div id="createAdModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">Создать объявление</div>
            <span class="close" onclick="closeCreateAdModal()">&times;</span>
        </div>
        <form action="create_ad.php" method="post" class="stack-lg">
            <label>
                <span style="font-weight: 600; display: block; margin-bottom: 4px;">Название объявления</span>
                <input type="text" name="title" placeholder="Введите название" required>
            </label>
            <label>
                <span style="font-weight: 600; display: block; margin-bottom: 4px;">Текст объявления</span>
                <textarea name="content" placeholder="Введите текст" required></textarea>
            </label>
            <label>
                <span style="font-weight: 600; display: block; margin-bottom: 4px;">Дата окончания</span>
                <input type="date" name="expires_at" required>
            </label>
            <div style="display: flex; gap: 10px;">
                <button type="submit">Создать объявление</button>
                <button type="button" onclick="closeCreateAdModal()" style="background: var(--muted);">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактора данных -->
<div id="dataEditorModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Редактор данных</h2>
            <span class="close" onclick="closeDataEditor()">&times;</span>
        </div>
        <div class="modal-buttons" style="display: flex; flex-direction: column; gap: 10px;">
            <button onclick="openProductEditor()">📊 Редактор выпущенной продукции</button>
            <button onclick="openPartsEditor()">📦 Редактор изготовленных гофропакетов</button>
            <button onclick="openAuditLogs()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">📋 Логи аудита</button>
            <button onclick="closeDataEditor()">❌ Закрыть</button>
        </div>
    </div>
</div>

<!-- Модальное окно редактора продукции -->
<div id="productEditorModal" class="modal">
    <div class="modal-content" style="max-width: 1200px;">
        <div class="modal-header">
            <h2 class="modal-title">Редактор выпущенной продукции</h2>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button onclick="openAuditLogs()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                    📋 Логи аудита
                </button>
                <span class="close" onclick="closeProductEditor()">&times;</span>
            </div>
        </div>
        <div id="productEditorContent">
            <div style="margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                <h4 style="margin: 0 0 12px 0; color: #495057;">📅 Выберите дату для редактирования</h4>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <input type="date" id="editDate" style="padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <button onclick="loadDataForDate()" style="background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
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

<!-- Модальное окно редактора гофропакетов -->
<div id="partsEditorModal" class="modal">
    <div class="modal-content" style="max-width: 1200px;">
        <div class="modal-header">
            <h2 class="modal-title">Редактор изготовленных гофропакетов</h2>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button onclick="openAuditLogs()" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                    📋 Логи аудита
                </button>
                <span class="close" onclick="closePartsEditor()">&times;</span>
            </div>
        </div>
        <div id="partsEditorContent">
            <div style="margin-bottom: 20px; padding: 16px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
                <h4 style="margin: 0 0 12px 0; color: #495057;">📅 Выберите дату для редактирования</h4>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <input type="date" id="editPartsDate" style="padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    <button onclick="loadPartsDataForDate()" style="background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        🔍 Загрузить данные
                    </button>
                </div>
            </div>
            <div id="partsTableContainer" style="display: none;">
                <!-- Здесь будет таблица с данными -->
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно добавления позиции гофропакета -->
<div id="addPartPositionModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title">➕ Добавить позицию гофропакета</h2>
            <span class="close" onclick="closeAddPartPositionModal()">&times;</span>
        </div>
        <div id="addPartPositionContent">
            <form id="addPartPositionForm">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Дата производства:</label>
                    <input type="date" id="addPartPositionDate" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Название гофропакета:</label>
                    <select id="addPartPositionPart" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">Выберите гофропакет</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Количество:</label>
                    <input type="number" id="addPartPositionQuantity" required min="1" placeholder="Введите количество" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 500; color: #374151;">Название заявки:</label>
                    <select id="addPartPositionOrder" required style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <option value="">Выберите заявку</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddPartPositionModal()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer;">
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

<!-- Модальное окно загрузки файла -->
<div id="loadFileModal" class="modal">
    <div class="modal-content" style="max-width: 420px; padding: 16px; overflow-x: hidden;">
        <div class="modal-header" style="margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border);">
            <div class="modal-title" style="font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">📄</span>
                Загрузить файл
            </div>
            <span class="close" onclick="closeLoadFileModal()" style="font-size: 20px;">&times;</span>
        </div>
        <div class="modal-body" style="padding: 0; overflow-x: hidden;">
            <form id="loadFileForm" enctype="multipart/form-data" action="load_file.php" method="POST">
                <input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
                <p style="margin: 0 0 12px 0; color: var(--muted); font-size: 12px; line-height: 1.4;">Выберите файл Excel с заявкой коммерческого отдела</p>
                <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <input id="loadFileInput" name="userfile" type="file" accept=".xls,.xlsx" style="position: absolute; width: 0; height: 0; opacity: 0; overflow: hidden;" />
                    <button type="button" onclick="document.getElementById('loadFileInput').click();" id="fileSelectButton" style="padding: 7px 16px; border: 1px solid var(--border); border-radius: 6px; background: var(--panel); cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; color: var(--ink);">
                        <span style="font-size: 14px;">📎</span>
                        <span>Выбрать файл</span>
                    </button>
                    <span style="font-size: 11px; color: var(--muted);">(.xls, .xlsx)</span>
                </div>
                <div id="fileNameDisplay" style="margin-bottom: 12px; padding: 6px 10px; background: var(--panel); border-radius: 6px; font-size: 11px; color: var(--ink); display: none; border: 1px solid var(--border);">
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

</body>
</html>

