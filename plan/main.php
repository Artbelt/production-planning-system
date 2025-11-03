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
$currentDepartment = $_SESSION['auth_department'] ?? 'U2';

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
        /* ===== Modern Pro UI Design ===== */
        :root{
            --bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
            --shadow-soft: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            --shadow-hover: 0 20px 40px rgba(0,0,0,0.15), 0 8px 16px rgba(0,0,0,0.1);
            --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-secondary: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-success: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-warning: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg-solid); color:var(--ink);
            font: 16px/1.6 "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
            font-weight: 400;
        }
        
        /* Import modern font */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        a{color:var(--accent); text-decoration:none}
        a:hover{text-decoration:underline}

        /* контейнер и сетка */
        .container{ 
            max-width:1400px; 
            margin:0 auto; 
            padding:24px; 
            min-height: 100vh;
        }
        .layout{ 
            width:100%; 
            border-spacing:20px; 
            border:0; 
            background:transparent; 
        }
        .header-row .header-cell{ 
            padding:0; 
            border:0; 
            background:transparent; 
        }
        .headerbar{ 
            display:flex; 
            align-items:center; 
            gap:16px; 
            padding:20px 24px; 
            background: var(--panel);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            color: var(--ink);
            font-weight: 500;
        }
        .headerbar .spacer{ flex:1; }
        /* Отступ для кнопки доступа под шапкой */
        .edit-access-wrap{ 
            margin-top: 12px; 
        }

        /* панели-колонки */
        .content-row > td{ vertical-align:top; }
        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:24px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .panel:hover{
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        .panel--main{ 
            box-shadow:var(--shadow-soft); 
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }
        .section-title{
            font-size:18px; 
            font-weight:600; 
            color:var(--ink);
            margin:0 0 20px; 
            padding-bottom:12px; 
            border-bottom:2px solid var(--border);
            position: relative;
        }
        .section-title::after{
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 40px;
            height: 2px;
            background: var(--gradient-primary);
            border-radius: 1px;
        }

        /* таблицы/карточки внутри панелей */
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

        /* вертикальные стеки */
        .stack{ display:flex; flex-direction:column; gap:8px; }
        .stack-lg{ gap:12px; }

        /* кнопки (современный стиль) */
        button, input[type="submit"]{
            appearance:none;
            border:1px solid transparent;
            cursor:pointer;
            background: var(--gradient-primary);
            color:var(--accent-ink);
            padding:12px 20px;
            border-radius:var(--radius-sm);
            font-weight:500;
            font-size:14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-soft);
            position: relative;
            overflow: hidden;
        }
        button::before, input[type="submit"]::before{
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        button:hover::before, input[type="submit"]:hover::before{
            left: 100%;
        }
        button:hover, input[type="submit"]:hover{ 
            transform: translateY(-2px); 
            box-shadow: var(--shadow-hover);
            filter: brightness(1.1);
        }
        button:active, input[type="submit"]:active{ 
            transform: translateY(0); 
            transition: transform 0.1s;
        }
        button:disabled, input[type="submit"]:disabled{
            background: #e2e8f0; 
            color: #94a3b8; 
            border-color: #e2e8f0; 
            box-shadow: none; 
            cursor: not-allowed;
            transform: none;
        }
        input[type="submit"][style*="background"], button[style*="background"]{
            background: var(--gradient-primary)!important; 
            color: #fff!important;
        }

        /* поля ввода/селекты */
        input[type="text"], input[type="date"], input[type="number"], input[type="password"],
        textarea, select{
            min-width:180px; 
            padding:12px 16px;
            border:2px solid var(--border); 
            border-radius:var(--radius-sm);
            background:#fff; 
            color:var(--ink); 
            outline:none;
            font-size:14px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-soft);
        }
        input:focus, textarea:focus, select:focus{
            border-color: var(--accent-solid); 
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }
        textarea{
            min-height:100px; 
            resize:vertical;
            font-family: inherit;
        }

        /* инфо-блоки */
        .alert{
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border:1px solid #f59e0b; 
            color:#92400e;
            padding:16px; 
            border-radius:var(--radius-sm); 
            margin:16px 0; 
            font-weight:500;
            box-shadow: var(--shadow-soft);
            position: relative;
            display: flex;
            align-items: center;
        }
        .alert::before{
            content: '⚠️';
            margin-right: 8px;
            font-size: 16px;
        }
        .muted{
            color:var(--muted);
            font-weight: 400;
        }

        /* чипы заявок справа */
        .saved-orders input[type="submit"]{
            display:inline-block; 
            margin:6px 8px 0 0;
            border-radius:999px!important; 
            padding:8px 16px!important;
            background: var(--gradient-primary)!important; 
            color:#fff!important;
            border:none!important; 
            box-shadow: var(--shadow-soft);
            font-size:12px;
            font-weight:500;
            transition: all 0.3s ease;
        }
        .saved-orders input[type="submit"]:hover{
            transform: translateY(-1px);
            box-shadow: var(--shadow);
            filter: brightness(1.1);
        }

        /* карточка поиска */
        .search-card{
            border:1px solid var(--border);
            border-radius:var(--radius-sm); 
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            box-shadow:var(--shadow-soft); 
            padding:20px; 
            margin-top:16px;
            transition: all 0.3s ease;
        }
        .search-card:hover{
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        .search-card h4{
            margin:0 0 16px;
            color: var(--ink);
            font-weight: 600;
            font-size: 16px;
        }
        /* ===== Modern Modal (ads) ===== */
        .modal-backdrop{
            position:fixed; 
            inset:0; 
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            opacity:0; 
            pointer-events:none; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index:60;
        }
        .modal{
            position:fixed; 
            inset:0; 
            display:flex; 
            align-items:center; 
            justify-content:center;
            pointer-events:none; 
            z-index:61;
            padding: 20px;
        }
        .modal__panel{
            width:min(600px, calc(100% - 40px)); 
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border:1px solid var(--border);
            border-radius:var(--radius); 
            box-shadow: 0 25px 50px rgba(0,0,0,0.25), 0 10px 20px rgba(0,0,0,0.1);
            transform: translateY(20px) scale(0.95);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            opacity:0;
            overflow: hidden;
        }
        .modal--open .modal__panel{ 
            transform: translateY(0) scale(1); 
            opacity:1; 
        }
        .modal--open, .modal--open + .modal-backdrop{ 
            pointer-events:auto; 
            opacity:1; 
        }

        .modal__head{ 
            display:flex; 
            align-items:center; 
            justify-content:space-between;
            padding:24px 24px 16px; 
            border-bottom:2px solid var(--border);
            background: var(--gradient-primary);
            color: white;
        }
        .modal__title{ 
            font-size:20px; 
            font-weight:600; 
            color: white; 
            margin:0; 
        }
        .modal__body{ 
            padding:24px; 
        }
        .modal__foot{ 
            display:flex; 
            gap:12px; 
            justify-content:flex-end; 
            padding:20px 24px;
            border-top:1px solid var(--border); 
            background: #f8fafc; 
        }

        .modal__close{
            appearance:none; 
            background: rgba(255,255,255,0.2); 
            border:1px solid rgba(255,255,255,0.3);
            color: white; 
            border-radius:var(--radius-sm); 
            padding:8px 12px; 
            cursor:pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .modal__close:hover{ 
            background: rgba(255,255,255,0.3); 
            transform: translateY(-1px);
        }

        .modal .field{ 
            display:flex; 
            flex-direction:column; 
            gap:8px; 
            margin-bottom:20px; 
        }
        .modal label{ 
            font-weight:500; 
            color:var(--ink);
            font-size: 14px;
        }


        /* адаптив */
        @media (max-width:1100px){
            /* убираем горизонтальные промежутки, оставляем только вертикальные */
            .layout{ border-spacing:0 16px; }
            .content-row > td{ display:block; width:auto!important; margin-bottom:16px; }
            /* равные внутренние отступы контейнера на мобильных */
            .container{ padding:16px 32px 16px 16px; }
            /* симметричные отступы панелей от краев экрана */
            .panel{ margin-left:12px; margin-right:24px; }
        }

        /* футер */
        .footer{
            margin-top:20px; 
            padding:20px 24px; 
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border:1px solid var(--border);
            border-radius:var(--radius); 
            box-shadow:var(--shadow-soft); 
            color:var(--muted);
            text-align: center;
            font-weight: 500;
        }
        
        /* Дополнительные улучшения */
        .stack{
            display:flex; 
            flex-direction:column; 
            gap:12px; 
        }
        .stack-lg{ 
            gap:16px; 
        }
        
        /* Анимация загрузки */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .panel {
            animation: fadeInUp 0.6s ease-out;
        }
        
        .panel:nth-child(1) { animation-delay: 0.1s; }
        .panel:nth-child(2) { animation-delay: 0.2s; }
        .panel:nth-child(3) { animation-delay: 0.3s; }
        
        /* Стили для состояния загрузки */
        .loading {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-style: italic;
        }
        .loading::before {
            content: '';
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-top: 2px solid var(--accent-solid);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

                <div class="headerbar">
                    <div>Подразделение: <?php echo htmlspecialchars($currentDepartment); ?></div>
                    <div class="spacer"></div>
                    <div><!-- Панель авторизации перенесена вверх --></div>
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
                    <a href="laser_request.php" target="_blank" rel="noopener"><button type="button">Заявка на лазер</button></a>
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
                    <div class="search-card">
                        <h4 style="margin:0 0 8px;">Объявления</h4>
                        <div class="stack" >
                            <button type="button" id="openAdModal">Создать объявление</button>

                            <noscript>
                                <!-- Носкрипт-фолбэк: обычная форма, если JS выключен -->
                                <form class="stack" action="create_ad.php" method="post">
                                    <input type="text" name="title" placeholder="Название объявления" required>
                                    <textarea name="content" placeholder="Введите текст" required></textarea>
                                    <input type="date" name="expires_at" required>
                                    <button type="submit">Создать объявление</button>
                                </form>
                            </noscript>
                        </div>
                    </div>
                </div>
            </td>

            <!-- Центральная панель: Объявления + Поиск по фильтру -->
            <td class="panel panel--main">
                <div class="section-title">Объявления</div>
                <div class="stack-lg">
                    <?php if (function_exists('show_ads')) { show_ads(); } ?>




                    <div class="search-card">
                        <h4 style="margin:0 0 8px;">Поиск заявок по фильтру</h4>
                        <div class="stack">
                            <label for="filterSelect">Фильтр:</label>
                            <?php
                            if (function_exists('load_filters_into_select')) {
                                // тот же селект, что и в образце
                                load_filters_into_select('Выберите фильтр'); // <select name="analog_filter">
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
                            resultBox.innerHTML = '<div class="loading">Загрузка…</div>';
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
            </td>

            <!-- Правая панель: Заявки/архив/планирование/загрузка -->
            <td class="panel panel--right" style="width:24%;">
                <div class="section-title">Сохраненные заявки</div>
                <div class="saved-orders">
                    <?php
                    // Подгрузка заявок по текущему цеху, hide != 1
                    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                    $mysqli = @new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                    if ($mysqli->connect_errno) {
                        echo '<div class="alert">Проблема подключения к БД</div>';
                    } else {
                        $sql = "SELECT DISTINCT order_number, workshop, hide FROM orders;";
                        if ($result = $mysqli->query($sql)) {
                            echo '<form action="show_order.php" method="post" target="_blank">';
                            if ($result->num_rows === 0) { echo "<div class='muted'>В базе нет ни одной заявки</div>"; }
                            
                            // Группируем заявки для отображения
                            $orders_list = [];
                            while ($orders_data = $result->fetch_assoc()){
                                if (($currentDepartment === $orders_data['workshop']) && ($orders_data['hide'] != 1)){
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
                                
                                // Формируем кнопку с меньшим шрифтом для процента
                                echo "<button type='submit' name='order_number' value='{$order_num}' style='height: 35px; width: 215px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; padding: 0 12px; margin-bottom: 8px;' title='Прогресс выполнения: {$progress}%'>";
                                echo "<span style='font-size: 13px; flex: 1; text-align: center;'>{$order_num}</span>";
                                echo "<span style='font-size: 10px; opacity: 0.8; margin-left: 8px;'>[{$progress}%]</span>";
                                echo "</button>";
                            }
                            
                            echo '</form>';
                            $result->close();
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
<!-- Ads Modal -->
<div id="adModal" class="modal" aria-hidden="true" aria-labelledby="adModalTitle" role="dialog">
    <div class="modal__panel" role="document">
        <div class="modal__head">
            <h3 class="modal__title" id="adModalTitle">Создать объявление</h3>
            <button type="button" class="modal__close" id="adModalCloseTop" aria-label="Закрыть">Закрыть</button>
        </div>
        <form id="adForm" action="create_ad.php" method="post">
            <div class="modal__body">
                <div class="field">
                    <label for="ad_title">Название</label>
                    <input id="ad_title" name="title" type="text" placeholder="Название объявления" required>
                </div>
                <div class="field">
                    <label for="ad_content">Текст</label>
                    <textarea id="ad_content" name="content" placeholder="Введите текст" required></textarea>
                </div>
                <div class="field">
                    <label for="ad_expires">Действительно до</label>
                    <input id="ad_expires" name="expires_at" type="date" required>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" class="modal__close" id="adModalCancel">Отмена</button>
                <button type="submit">Создать объявление</button>
            </div>
        </form>
    </div>
</div>
<div id="adModalBackdrop" class="modal-backdrop"></div>
<script>
    (function(){
        const modal = document.getElementById('adModal');
        const backdrop = document.getElementById('adModalBackdrop');
        const openBtn = document.getElementById('openAdModal');
        const closeTop = document.getElementById('adModalCloseTop');
        const cancelBtn = document.getElementById('adModalCancel');
        const form = document.getElementById('adForm');
        const firstInput = document.getElementById('ad_title');

        function open(){
            modal.classList.add('modal--open');
            backdrop.classList.add('modal--open');
            modal.setAttribute('aria-hidden','false');
            // небольшая задержка, чтобы браузер вставил в поток
            setTimeout(()=> firstInput && firstInput.focus(), 30);
            document.addEventListener('keydown', onKey);
        }
        function close(){
            modal.classList.remove('modal--open');
            backdrop.classList.remove('modal--open');
            modal.setAttribute('aria-hidden','true');
            document.removeEventListener('keydown', onKey);
            openBtn && openBtn.focus();
        }
        function onKey(e){
            if (e.key === 'Escape') close();
        }
        function onBackdropClick(e){
            if (e.target === backdrop) close();
        }

        openBtn && openBtn.addEventListener('click', open);
        closeTop && closeTop.addEventListener('click', close);
        cancelBtn && cancelBtn.addEventListener('click', close);
        backdrop && backdrop.addEventListener('click', onBackdropClick);

        // опционально: дизейбл кнопки на время отправки
        form && form.addEventListener('submit', function(){
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn){
                submitBtn.disabled = true; submitBtn.textContent = 'Отправка...';
            }
        });
    })();
</script>


</body>
</html>
