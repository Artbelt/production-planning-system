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

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Определяем текущий цех
// ВАЖНО: Это файл plan/main.php - принудительно устанавливаем цех U2!
$currentDepartment = 'U2';
$_SESSION['auth_department'] = 'U2'; // Обновляем сессию для согласованности

// Проверяем, есть ли у пользователя доступ к цеху U2
$hasAccessToU2 = false;
$userRole = null;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U2') {
        $hasAccessToU2 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

// Если нет доступа к U2, показываем предупреждение, но не блокируем
if (!$hasAccessToU2) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U2</h3>";
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
            // Доступ имеют: сборщики, мастера (supervisor), директора (но не менеджеры)
            return in_array($role, ['assembler', 'supervisor', 'director']);
        }
    }
    return false;
}

// Для main.php всегда проверяем доступ к цеху U2
$canAccessLaser = canAccessLaserRequests($userDepartments, 'U2');

$advertisement = 'Информация';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>U2</title>

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
        .muted{color:var(--muted)}

        /* чипы заявок справа */
        .saved-orders input[type="submit"]{
            display:inline-block; margin:4px 6px 0 0;
            border-radius:999px!important; padding:6px 10px!important;
            background:var(--accent)!important; color:#fff!important;
            border:none!important; box-shadow:0 1px 4px rgba(2,8,20,.06);
        }

        /* карточка поиска */
        .search-card{
            border:1px solid var(--border);
            border-radius:10px; background:#fff;
            box-shadow:var(--shadow-soft); padding:12px; margin-top:8px;
        }

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
        
        /* футер */
        .footer{
            margin-top:20px; 
            padding:20px 24px; 
            background: var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius); 
            box-shadow:var(--shadow-soft); 
            color:var(--muted);
            text-align: center;
            font-weight: 500;
        }
    </style>
</head>
<body>

<div class="container">
    <table class="layout">
        <!-- Шапка -->
        <tr class="header-row">
            <td class="header-cell" colspan="3">
                <!-- Аккуратная панель авторизации -->
                <div style="position: fixed; top: 10px; right: 10px; background: white; padding: 12px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e5e7eb;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px;">
                            <?= mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8') ?>
                        </div>
                        <div>
                            <div style="font-weight: 600; font-size: 14px; color: #1f2937;"><?= htmlspecialchars($user['full_name'] ?? 'Пользователь') ?></div>
                            <div style="font-size: 12px; color: #6b7280;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
                            <div style="font-size: 11px; color: #9ca3af;"><?= $currentDepartment ?> • <?= ucfirst($userRole ?? 'guest') ?></div>
                        </div>
                        <a href="../auth/change-password.php" style="padding: 4px 8px; background: transparent; color: #9ca3af; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: 400; transition: all 0.2s; border: 1px solid #e5e7eb;" onmouseover="this.style.background='#f9fafb'; this.style.color='#6b7280'; this.style.borderColor='#d1d5db'" onmouseout="this.style.background='transparent'; this.style.color='#9ca3af'; this.style.borderColor='#e5e7eb'">Пароль</a>
                        <a href="../auth/logout.php" style="padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500; transition: background-color 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">Выход</a>
                    </div>
                </div>

                <div class="topbar">
                    <div class="topbar-left">
                        <span class="logo">U2</span>
                        <span class="system-name">Система управления</span>
                    </div>
                    <div class="topbar-center">
                       
                    </div>
                    <div class="topbar-right">
                        <!-- Панель авторизации перенесена вверх -->
                    </div>
                </div>
                <?php if (function_exists('edit_access_button_draw')) { edit_access_button_draw(); } ?>
            </td>
        </tr>

        <!-- Контент: 3 колонки (как в образце) -->
        <tr class="content-row">
            <!-- Левая панель: Операции + Приложения -->
            <td class="panel panel--left" style="width:22%;">
                <div class="section-title">Операции</div>
                <div class="stack">
                    <a href="product_output.php" target="_blank" rel="noopener" class="stack"><button>Выпуск продукции</button></a>
                    <form action="product_output_view.php" method="post" class="stack"><input type="submit" value="Обзор выпуска продукции"></form>
                    <form action="parts_output_view.php" method="post" class="stack"><input type="submit" value="Обзор изготовленных гофропакетов"></form>
                    <?php if ($canAccessLaser): ?>
                    <a href="laser_request.php" target="_blank" rel="noopener" class="stack"><button type="button">Заявка на лазер</button></a>
                    <?php endif; ?>
                </div>

                <div class="section-title" style="margin-top:14px;">Приложения</div>
                <div class="stack">
                    <form action="add_filter_properties_into_db.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>" >
                        <input type="submit" value="Добавить / изменить фильтр">
                    </form>
                    <form action="manufactured_production_editor.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Редактор внесенной продукции">
                    </form>
                    <form action="gofra_table.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Журнал для гофропакетчиков">
                    </form>
                    <form action="gofra_packages_table.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Кол-во гофропакетов из рулона">
                    </form>

                    <div style="border-top:1px solid var(--border); margin:6px 0;"></div>

                    <form action="NP_monitor.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Мониторинг">
                    </form>
                    <form action="worker_modules/tasks_corrugation.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Модуль оператора ГМ">
                    </form>
                    <form action="worker_modules/tasks_cut.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Модуль оператора бумагорезки">
                    </form>
                    <form action="worker_modules/tasks_for_builders.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="План для сборщиц">
                    </form>
                    <form action="buffer_stock.php" method="post" target="_blank" class="stack">
                        <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($currentDepartment); ?>">
                        <input type="submit" value="Буфер гофропакетов">
                    </form>
                </div>
            </td>

            <!-- Центральная панель: Поиск по фильтру -->
            <td class="panel panel--main">
                <?php
                // Виджет задач для мастеров
                if ($userRole === 'supervisor') {
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
                    } catch (Exception $e) {
                        // Тихо игнорируем ошибки
                    }
                }
                ?>
                




                    <div class="search-card">
                        <h4 style="margin:0 0 8px;">Поиск заявок по фильтру</h4>
                        <div class="stack">
                            <label for="filterSelect">Фильтр:</label>
                            <?php
                            if (function_exists('load_filters_into_select')) {
                                load_filters_into_select('Выберите фильтр');
                            }
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
                            if(!sel){ resultBox.innerHTML = '<div class="alert">Не найден выпадающий список.</div>'; return; }
                            const val = sel.value.trim();
                            if(!val){ resultBox.innerHTML = '<div class="muted">Выберите фильтр…</div>'; return; }
                            resultBox.textContent = 'Загрузка…';
                            try{
                                const formData = new FormData(); formData.append('filter', val);
                                const resp = await fetch('search_filter_in_the_orders.php', { method:'POST', body:formData });
                                if(!resp.ok){ resultBox.innerHTML = `<div class="alert">Ошибка запроса: ${resp.status} ${resp.statusText}</div>`; return; }
                        resultBox.innerHTML = await resp.text();
                        // после вставки HTML навешиваем обработчик на кнопку "Показать все", если она есть
                        const showAllBtn = resultBox.querySelector('#showAllOrders');
                        if (showAllBtn){
                            const revealAll = () => {
                                try{
                                    resultBox.querySelectorAll('.order-item--hidden').forEach(el => el.classList.remove('order-item--hidden'));
                                    const parent = showAllBtn.parentNode; if (parent) parent.remove();
                                }catch(e){ /* ignore */ }
                            };
                            showAllBtn.addEventListener('click', revealAll, { once: true });
                        }
                            }catch(e){ resultBox.innerHTML = `<div class="alert">Ошибка: ${e}</div>`; }
                        }
                        const sel = getSelectEl(); if(sel){ sel.id='filterSelect'; sel.addEventListener('change', runSearch); }
                    })();
                </script>
                
                <div class="stack-lg" style="margin-top: 20px;">
                    <?php 
                    if (function_exists('show_weekly_production')) { 
                        show_weekly_production(); 
                    } 
                    ?>
                </div>
            </td>

            <!-- Правая панель: Заявки/архив/планирование/загрузка -->
            <td class="panel panel--right" style="width:24%;">
                <div class="section-title">Сохраненные заявки</div>
                <div class="saved-orders">
                    <?php
                    // ОПТИМИЗИРОВАННАЯ загрузка заявок - все данные в одном запросе
                    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                    $mysqli = @new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                    if ($mysqli->connect_errno) {
                        echo '<div class="alert">Проблема подключения к БД</div>';
                    } else {
                        // Оптимизированный запрос - получаем все данные за один раз
                        $sql = "
                            SELECT 
                                o.order_number,
                                o.workshop,
                                SUM(o.count) as total_planned,
                                COALESCE(mp.total_produced, 0) as total_produced
                            FROM orders o
                            LEFT JOIN (
                                SELECT name_of_order, SUM(count_of_filters) as total_produced
                                FROM manufactured_production
                                GROUP BY name_of_order
                            ) mp ON o.order_number = mp.name_of_order
                            WHERE o.workshop = ? AND COALESCE(o.hide, 0) != 1
                            GROUP BY o.order_number, o.workshop
                            ORDER BY o.order_number
                        ";
                        
                        $stmt = $mysqli->prepare($sql);
                        $stmt->bind_param('s', $currentDepartment);
                        
                        if ($stmt->execute()) {
                            $result = $stmt->get_result();
                            echo '<form action="show_order.php" method="post" target="_blank">';
                            
                            if ($result->num_rows === 0) {
                                echo "<div class='muted'>В базе нет ни одной заявки</div>";
                            } else {
                                // Выводим заявки с уже рассчитанным прогрессом
                                while ($row = $result->fetch_assoc()) {
                                    $order_num = $row['order_number'];
                                    $total_planned = (int)$row['total_planned'];
                                    $total_produced = (int)$row['total_produced'];
                                    
                                    // Вычисляем процент
                                    $progress = 0;
                                    if ($total_planned > 0) {
                                        $progress = round(($total_produced / $total_planned) * 100);
                                    }
                                    
                                    // Формируем кнопку
                                    echo "<button type='submit' name='order_number' value='{$order_num}' style='height: 35px; width: 215px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; padding: 0 12px; margin-bottom: 8px;' title='Прогресс выполнения: {$progress}%'>";
                                    echo "<span style='font-size: 13px; flex: 1; text-align: center;'>{$order_num}</span>";
                                    echo "<span style='font-size: 10px; opacity: 0.8; margin-left: 8px;'>[{$progress}%]</span>";
                                    echo "</button>";
                                }
                            }
                            
                            echo '</form>';
                            $stmt->close();
                        } else {
                            echo '<div class="alert">Ошибка запроса заявок</div>';
                        }
                        $mysqli->close();
                    }
                    ?>
                </div>

                <div class="section-title" style="margin-top:14px;">Управление заявками</div>
                <section class="stack">
                    <form action="new_order.php" method="post" target="_blank"  class="stack"><input type="submit" value="Создать заявку вручную"></form>
                    <form action="archived_orders.php" target="_blank"  class="stack"><input type="submit" value="Архив заявок"></form>
                    <form action="NP_cut_index.php" method="post" target="_blank"  class="stack"><input type="submit" value="Менеджер планирования"></form>
                    <form action="NP_supply_requirements.php" method="post" target="_blank"  class="stack"><input type="submit" value="Потребность в комплектации"></form>

                    <div class="search-card">
                        <form enctype="multipart/form-data" action="load_file.php" method="POST" class="stack">
                            <label class="muted">Добавить заявку коммерческого отдела:</label>
                            <input type="file" name="userfile" />
                            <input type="submit" value="Загрузить заявку" />
                        </form>
                    </div>
                </section>
            </td>
        </tr>

        <!-- Футер (как панель) -->
        <tr>
            <td colspan="3">
                <div class="footer"><?php echo $advertisement; ?></div>
            </td>
        </tr>
    </table>
</div>


</body>
</html>
