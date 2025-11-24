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
                        <?php edit_access_button_draw(); ?>
                        <?php if (is_edit_access_granted()): ?>
                            <div id="alert_div_1" style="width: 10px; height: 10px; background-color: lightgreen; border-radius: 50%; display: inline-block;"></div>
                        <?php else: ?>
                            <div id="alert_div_2" style="width: 10px; height: 10px; background-color: gray; border-radius: 50%; display: inline-block;"></div>
                        <?php endif; ?>
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
                    <button type="button" onclick="openCapManagementModal()">Операции с крышками</button>
                    <form action="parts_output_for_workers.php" method="post" target="_blank" class="stack"><input type="submit" value="Выпуск гофропакетов"></form>
                    <?php if ($canAccessLaser): ?>
                    <a href="laser_request.php" target="_blank" rel="noopener" class="stack"><button type="button">Заявка на лазер</button></a>
                    <?php endif; ?>
                </div>

                <div class="section-title" style="margin-top:14px">Информация</div>
                <div class="stack">
                    <form action="summary_plan_U3.php" method="post" target="_blank" class="stack"><input type="submit" value="Сводный план У3"></form>
                    <form action="dimensions_report.php" method="post" target="_blank" class="stack"><input type="submit" value="Таблица размеров для участка"></form>
                    <form action="product_output_view.php" method="post" target="_blank" class="stack"><input type="submit" value="Обзор выпуска продукции"></form>
                    <form action="gofra_packages_table.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
                        <input type="submit" value="Кол-во гофропакетов из рулона">
                    </form>
                </div>

                <div class="section-title" style="margin-top:14px">Управление данными</div>
                <div class="stack">
                    <form action="add_round_filter_into_db.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
                        <input type="submit" value="Добавить фильтр в БД(full)">
                    </form>
                    <form action="edit_filter_properties.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
                        <input type="submit" value="Изменить параметры фильтра">
                    </form>
                    <form action="manufactured_production_editor.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="U3">
                        <input type="submit" value="Редактор выпуска продукции">
                    </form>
                    <form action="manufactured_parts_editor.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="U3">
                        <input type="submit" value="Редактор выпуска комплектующих">
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
        $pdo_tasks = new PDO(
            "mysql:host=127.0.0.1;dbname=plan_u3;charset=utf8mb4",
            "root",
            "",
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        
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
                            <?php if ($task['description']): ?>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px; line-height: 1.4;">
                                <?php echo nl2br(htmlspecialchars(mb_substr($task['description'], 0, 80) . (mb_strlen($task['description']) > 80 ? '...' : ''))); ?>
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
                    } catch (Exception $e) {
                        // Тихо игнорируем ошибки
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
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                if ($mysqli->connect_errno) { 
                    echo 'Возникла проблема на сайте'; 
                    exit; 
                }
                
                echo '<div class="section-title">Сохраненные заявки</div>';
                echo '<div class="saved-orders">';

/** Выполняем запрос SQL для загрузки заявок*/
$sql = "SELECT DISTINCT order_number, workshop, hide FROM orders;";
if (!$result = $mysqli->query($sql)){
    echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"
        ."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}
/** Разбираем результат запроса */
if ($result->num_rows === 0) { echo "<div class='muted'>В базе нет ни одной заявки</div>";}

/** Разбор массива значений  */
echo '<form action="show_order.php" method="post" target="_blank" style="display:flex; flex-wrap:wrap; gap:6px; width:100%;">';

// Группируем заявки для отображения
$orders_list = [];
while ($orders_data = $result->fetch_assoc()){
    if ($orders_data['hide'] != 1){
        $order_num = $orders_data['order_number'];
        if (!isset($orders_list[$order_num])) {
            $orders_list[$order_num] = $orders_data;
        }
    }
}

// Выводим уникальные заявки с прогрессом
foreach ($orders_list as $order_num => $orders_data){
    // Расчет прогресса для заявки
    $total_planned = 0;
    $total_produced = 0;
    
    // Получаем общее количество по заявке
    $sql_total = "SELECT SUM(count) as total FROM orders WHERE order_number = '$order_num'";
    if ($res_total = $mysqli->query($sql_total)) {
        if ($row_total = $res_total->fetch_assoc()) {
            $total_planned = (int)$row_total['total'];
        }
    }
    
    // Получаем произведенное количество
    $sql_produced = "SELECT SUM(count_of_filters) as produced FROM manufactured_production WHERE name_of_order = '$order_num'";
    if ($res_produced = $mysqli->query($sql_produced)) {
        if ($row_produced = $res_produced->fetch_assoc()) {
            $total_produced = (int)$row_produced['produced'];
        }
    }
    
    // Вычисляем процент
    $progress = 0;
    if ($total_planned > 0) {
        $progress = round(($total_produced / $total_planned) * 100);
    }
    
                    // Формируем аккуратные кнопки заявок
                    $btnClass = str_contains($order_num, '[!]') ? "alert-button" : "";
                    $order_display = htmlspecialchars($order_num);
                    
                    echo "<button type='submit' name='order_number' value='{$order_display}' class='{$btnClass}' title='Прогресс выполнения: {$progress}%'>";
                    echo htmlspecialchars($order_num);
                    if ($progress > 0) {
                        echo " <span style='font-size:11px; opacity:0.9;'>[{$progress}%]</span>";
                    }
                    echo "</button>";
                }
                echo '</form>';
                echo '</div>';

                echo '<div class="section-title" style="margin-top:14px">Операции над заявками</div>';
                echo '<div class="stack">';
                echo "<form action='new_order.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Создать заявку вручную'>"
                    ."</form>";
                echo "<form action='planning_manager.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Менеджер планирования'>"
                    ."</form>";
                echo "<form action='combine_orders.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Объединение заявок'>"
                    ."</form>";
                echo "<form action='NP_cut_index.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Планирование работы (new)'>"
                    ."</form>";
                echo "<form action='archived_orders.php' target='_blank' class='stack'>"
                    ."<input type='submit' value='Архив заявок'>"
                    ."</form>";
                echo '</div>';

                echo '<div class="section-title" style="margin-top:14px">Мониторинг выполнения плана</div>';
                echo '<div class="stack">';
                echo "<form action='plan_monitoring.php' method='post' target='_blank' class='stack'>"
                    ."<input type='submit' value='Просмотр плана'>";
                load_plans();
                echo "</form>";
                echo "<button onclick=\"window.open('http://localhost/plan_U3/json_editor.html', '_blank');\">Редактор плана</button>";
                echo '</div>';

                echo '<div class="section-title" style="margin-top:14px">Загрузка заявок</div>';
                echo '<div class="stack">';
                echo '<form enctype="multipart/form-data" action="load_file.php" method="POST" target="_blank" class="stack">'
                    .'<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />'
                    .'<label>Добавить заявку в систему:</label>'
                    .'<input name="userfile" type="file" />'
                    .'<input type="submit" value="Загрузить файл" />'
                    .'</form>';
                echo '</div>';
                ?>
            </td>
        </tr>
    </table>
</div>

<?php
// Получаем список заявок для модального окна управления крышками (используем тот же алгоритм, что и для основного списка)
$cap_orders_list = [];
$sql_orders_modal = "SELECT DISTINCT order_number, workshop, hide FROM orders";
if ($result_orders_modal = $mysqli->query($sql_orders_modal)) {
    // Группируем заявки для отображения (как на main.php)
    $orders_temp_modal = [];
    while ($orders_data_modal = $result_orders_modal->fetch_assoc()) {
        if ($orders_data_modal['hide'] != 1) {
            $order_num = $orders_data_modal['order_number'];
            if (!isset($orders_temp_modal[$order_num])) {
                $orders_temp_modal[$order_num] = $orders_data_modal;
            }
        }
    }
    // Преобразуем в простой массив и сортируем по убыванию
    foreach ($orders_temp_modal as $order_num => $order_data) {
        $cap_orders_list[] = $order_num;
    }
    // Сортируем по убыванию (новые заявки сверху)
    rsort($cap_orders_list);
    $result_orders_modal->close();
}
// Закрываем соединение с БД
$mysqli->close();
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
    }
});

function openCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'block';
}

function closeCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'none';
}

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

</body>
</html>

