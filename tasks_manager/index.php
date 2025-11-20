<?php
// Модуль управления задачами для директора
require_once(__DIR__ . '/../auth/includes/config.php');
require_once(__DIR__ . '/../auth/includes/auth-functions.php');

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем права директора и собираем список цехов
$isDirector = false;
$directorDepartments = [];
foreach ($userDepartments as $dept) {
    if ($dept['role_name'] === 'director') {
        $isDirector = true;
        $directorDepartments[] = $dept['department_code'];
    }
}

if (!$isDirector) {
    die('<div style="padding: 20px; text-align: center;">
        <h2>⚠️ Доступ запрещен</h2>
        <p>У вас недостаточно прав для управления задачами.</p>
        <p><a href="main.php">← Вернуться на главную</a></p>
    </div>');
}

// Выбранный цех (из GET параметра или текущая сессия)
$selectedDepartment = $_GET['department'] ?? ($_SESSION['auth_department'] ?? $directorDepartments[0] ?? 'U5');
$currentDepartment = $selectedDepartment;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление задачами</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1120px;
            margin: 0 auto;
            padding: 12px;
        }

        /* Заголовок */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 16px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-icon {
            font-size: 28px;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .header-subtitle {
            font-size: 13px;
            opacity: 0.9;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: white;
            padding: 14px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-card h3 {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 6px;
            font-weight: 500;
        }

        .stat-card .stat-number {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 0;
        }

        .stat-card.total .stat-number { color: #3b82f6; }
        .stat-card.in-progress .stat-number { color: #f59e0b; }
        .stat-card.completed .stat-number { color: #10b981; }
        .stat-card.overdue .stat-number { color: #ef4444; }

        /* Форма создания задачи */
        .section {
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 16px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f3f4f6;
        }

        .section-header-icon {
            font-size: 18px;
        }

        .section-header h2 {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: #ef4444;
        }

        .form-control {
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        textarea.form-control {
            min-height: 60px;
            resize: vertical;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* Список задач */
        .filters {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .filters input[type="text"] {
            flex: 1;
            min-width: 200px;
            padding: 7px 10px 7px 32px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="%239ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8.5" cy="8.5" r="5.5"/><path d="M12.5 12.5l4 4"/></svg>');
            background-repeat: no-repeat;
            background-position: 10px center;
        }

        .filters select {
            padding: 7px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }

        .tasks-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .task-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fafafa;
            transition: all 0.2s;
        }

        .task-item:hover {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.15);
        }

        .task-info {
            flex: 1;
        }

        .task-title {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .task-meta {
            display: flex;
            gap: 10px;
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .task-description {
            font-size: 12px;
            color: #4b5563;
            line-height: 1.5;
        }

        .task-badges {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 500;
        }

        .badge-pending { background: #dbeafe; color: #1e40af; }
        .badge-in-progress { background: #fef3c7; color: #92400e; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-low { background: #f3f4f6; color: #6b7280; }
        .badge-normal { background: #dbeafe; color: #1e40af; }
        .badge-high { background: #fef3c7; color: #92400e; }
        .badge-urgent { background: #fee2e2; color: #991b1b; }

        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
            padding: 5px 12px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-delete:hover {
            background: #fecaca;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .empty-icon {
            font-size: 50px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .empty-text {
            font-size: 14px;
            color: #6b7280;
        }

        /* Панель пользователя */
        .user-panel {
            position: fixed;
            top: 10px;
            right: 10px;
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid #e5e7eb;
        }

        .user-panel-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            font-size: 14px;
            color: #1f2937;
        }

        .user-phone {
            font-size: 12px;
            color: #6b7280;
        }

        .user-role {
            font-size: 11px;
            color: #9ca3af;
        }

        .user-actions {
            display: flex;
            flex-direction: row;
            gap: 8px;
        }

        .user-btn {
            padding: 6px 12px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            transition: background-color 0.2s;
            text-align: center;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .user-btn:hover {
            background: #e5e7eb;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .filters {
                flex-direction: column;
            }
            
            .filters input[type="text"] {
                min-width: 100%;
            }
            
            .user-panel {
                position: static;
                margin-bottom: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Панель пользователя -->
    <div class="user-panel">
        <div class="user-panel-content">
            <div class="user-avatar">
                <?php echo mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8'); ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Пользователь'); ?></div>
                <div class="user-phone"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></div>
                <div class="user-role">Директор всех цехов</div>
            </div>
            <div class="user-actions">
                <a href="../auth/change-password.php" class="user-btn">Пароль</a>
                <a href="../auth/logout.php" class="user-btn">Выход</a>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Заголовок -->
        <div class="header">
            <div class="header-content">
                <div class="header-icon">📋</div>
                <div>
                    <h1>Управление задачами</h1>
                    <div class="header-subtitle">Постановка и контроль задач для команды • Все цеха</div>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card total">
                <h3>Всего задач</h3>
                <div class="stat-number" id="statTotal">0</div>
            </div>
            <div class="stat-card in-progress">
                <h3>В работе</h3>
                <div class="stat-number" id="statInProgress">0</div>
            </div>
            <div class="stat-card completed">
                <h3>Завершено</h3>
                <div class="stat-number" id="statCompleted">0</div>
            </div>
            <div class="stat-card overdue">
                <h3>Просрочено</h3>
                <div class="stat-number" id="statOverdue">0</div>
            </div>
        </div>

        <!-- Форма создания задачи -->
        <div class="section">
            <div class="section-header">
                <div class="section-header-icon">➕</div>
                <h2>Новая задача</h2>
            </div>

            <form id="taskForm" onsubmit="createTask(event)">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Название задачи <span class="required">*</span></label>
                        <input type="text" class="form-control" id="taskTitle" placeholder="Введите название задачи" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Описание</label>
                        <textarea class="form-control" id="taskDescription" placeholder="Подробное описание задачи"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Исполнитель <span class="required">*</span></label>
                        <select class="form-control" id="taskAssignee" required>
                            <option value="">Выберите исполнителя</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Приоритет</label>
                        <select class="form-control" id="taskPriority">
                            <option value="low">Низкий</option>
                            <option value="normal" selected>Средний</option>
                            <option value="high">Высокий</option>
                            <option value="urgent">Срочный</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Срок выполнения <span class="required">*</span></label>
                        <input type="date" class="form-control" id="taskDueDate" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <span>➕</span>
                    <span>Создать задачу</span>
                </button>
            </form>
        </div>

        <!-- Список задач -->
        <div class="section">
            <div class="section-header">
                <div class="section-header-icon">📊</div>
                <h2>Список задач <span id="taskCount">(0)</span></h2>
            </div>

            <div class="filters">
                <input type="text" id="searchInput" placeholder="Поиск по названию или исполнителю..." onkeyup="filterTasks()">
                <select id="departmentFilter" onchange="filterTasks()">
                    <option value="">Все цеха</option>
                    <?php foreach ($directorDepartments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="statusFilter" onchange="filterTasks()">
                    <option value="">Все статусы</option>
                    <option value="pending">Ожидает</option>
                    <option value="in_progress">В работе</option>
                    <option value="completed">Завершено</option>
                </select>
                <select id="priorityFilter" onchange="filterTasks()">
                    <option value="">Все приоритеты</option>
                    <option value="low">Низкий</option>
                    <option value="normal">Средний</option>
                    <option value="high">Высокий</option>
                    <option value="urgent">Срочный</option>
                </select>
            </div>

            <div class="tasks-list" id="tasksList">
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <div class="empty-text">Нет задач. Создайте первую задачу!</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allTasks = [];
        const allDepartments = <?php echo json_encode($directorDepartments); ?>;

        // Загрузка при открытии страницы
        document.addEventListener('DOMContentLoaded', function() {
            loadAllUsers();
            loadAllTasks();
            
            // Установить завтрашнюю дату по умолчанию
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('taskDueDate').value = tomorrow.toISOString().split('T')[0];
        });

        // Загрузка всех мастеров из всех цехов
        async function loadAllUsers() {
            try {
                // Загружаем мастеров из всех цехов одновременно
                const promises = allDepartments.map(dept => 
                    fetch(`/tasks_manager/tasks_api.php?action=get_users&department=${dept}`)
                        .then(r => r.json())
                );

                const results = await Promise.all(promises);
                const allUsers = [];

                results.forEach((data, index) => {
                    if (data.ok && data.users) {
                        data.users.forEach(user => {
                            allUsers.push({
                                ...user,
                                department: allDepartments[index]
                            });
                        });
                    }
                });

                // Сортируем по имени
                allUsers.sort((a, b) => a.full_name.localeCompare(b.full_name));

                const assigneeSelect = document.getElementById('taskAssignee');
                assigneeSelect.innerHTML = '<option value="">Выберите исполнителя</option>';
                
                if (allUsers.length > 0) {
                    allUsers.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.id;
                        option.dataset.department = user.department;
                        option.textContent = `${user.full_name} (${user.department})`;
                        assigneeSelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'Нет доступных мастеров';
                    option.disabled = true;
                    assigneeSelect.appendChild(option);
                }
            } catch (error) {
                console.error('Ошибка загрузки пользователей:', error);
            }
        }

        // Загрузка задач из всех цехов
        async function loadAllTasks() {
            try {
                // Загружаем задачи из всех цехов одновременно
                const promises = allDepartments.map(dept => 
                    fetch(`/tasks_manager/tasks_api.php?action=get_all_tasks&department=${dept}&status=all`)
                        .then(r => r.json())
                );

                const results = await Promise.all(promises);
                allTasks = [];

                results.forEach(data => {
                    if (data.ok && data.tasks) {
                        allTasks = allTasks.concat(data.tasks);
                    }
                });

                // Сортируем по приоритету и дате
                allTasks.sort((a, b) => {
                    const priorityOrder = { 'urgent': 1, 'high': 2, 'normal': 3, 'low': 4 };
                    return priorityOrder[a.priority] - priorityOrder[b.priority];
                });

                updateStats();
                filterTasks();
            } catch (error) {
                console.error('Ошибка загрузки задач:', error);
            }
        }

        // Обновление статистики
        function updateStats() {
            const stats = {
                total: 0,
                inProgress: 0,
                completed: 0,
                overdue: 0
            };

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            allTasks.forEach(task => {
                stats.total++;
                if (task.status === 'in_progress') stats.inProgress++;
                if (task.status === 'completed') stats.completed++;
                
                if (task.due_date && task.status !== 'completed' && task.status !== 'cancelled') {
                    const dueDate = new Date(task.due_date);
                    dueDate.setHours(0, 0, 0, 0);
                    if (dueDate < today) stats.overdue++;
                }
            });

            document.getElementById('statTotal').textContent = stats.total;
            document.getElementById('statInProgress').textContent = stats.inProgress;
            document.getElementById('statCompleted').textContent = stats.completed;
            document.getElementById('statOverdue').textContent = stats.overdue;
        }

        // Фильтрация задач
        function filterTasks() {
            const searchText = document.getElementById('searchInput').value.toLowerCase();
            const departmentFilter = document.getElementById('departmentFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const priorityFilter = document.getElementById('priorityFilter').value;

            let filtered = allTasks.filter(task => {
                const matchSearch = !searchText || 
                    task.title.toLowerCase().includes(searchText) ||
                    (task.assigned_to_name && task.assigned_to_name.toLowerCase().includes(searchText));
                const matchDepartment = !departmentFilter || task.department === departmentFilter;
                const matchStatus = !statusFilter || task.status === statusFilter;
                const matchPriority = !priorityFilter || task.priority === priorityFilter;

                return matchSearch && matchDepartment && matchStatus && matchPriority;
            });

            renderTasks(filtered);
        }

        // Отрисовка задач
        function renderTasks(tasks) {
            const container = document.getElementById('tasksList');
            document.getElementById('taskCount').textContent = `(${tasks.length})`;

            if (tasks.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <div class="empty-text">Нет задач. Создайте первую задачу!</div>
                    </div>
                `;
                return;
            }

            container.innerHTML = '';
            tasks.forEach(task => {
                const statusLabels = {
                    'pending': 'Ожидает',
                    'in_progress': 'В работе',
                    'completed': 'Завершено'
                };
                const priorityLabels = {
                    'low': 'Низкий',
                    'normal': 'Средний',
                    'high': 'Высокий',
                    'urgent': 'Срочный'
                };

                const dueDate = new Date(task.due_date).toLocaleDateString('ru-RU');

                const taskEl = document.createElement('div');
                taskEl.className = 'task-item';
                taskEl.innerHTML = `
                    <div class="task-info">
                        <div class="task-title">${escapeHtml(task.title)}</div>
                        <div class="task-meta">
                            <span>🏭 ${escapeHtml(task.department)}</span>
                            <span>👤 ${escapeHtml(task.assigned_to_name)}</span>
                            <span>📅 ${dueDate}</span>
                        </div>
                        ${task.description ? `<div class="task-description">${escapeHtml(task.description)}</div>` : ''}
                        <div class="task-badges" style="margin-top: 10px;">
                            <span class="badge badge-${task.status}">${statusLabels[task.status]}</span>
                            <span class="badge badge-${task.priority}">${priorityLabels[task.priority]}</span>
                        </div>
                    </div>
                    <button class="btn-delete" onclick="deleteTask(${task.id})">🗑️ Удалить</button>
                `;
                container.appendChild(taskEl);
            });
        }

        // Создание задачи
        async function createTask(event) {
            event.preventDefault();

            const assigneeSelect = document.getElementById('taskAssignee');
            const selectedOption = assigneeSelect.options[assigneeSelect.selectedIndex];
            const department = selectedOption.dataset.department;
            
            if (!department) {
                alert('Выберите исполнителя');
                return;
            }

            const data = {
                title: document.getElementById('taskTitle').value,
                description: document.getElementById('taskDescription').value,
                assigned_to: parseInt(document.getElementById('taskAssignee').value),
                priority: document.getElementById('taskPriority').value,
                due_date: document.getElementById('taskDueDate').value,
                department: department
            };

            try {
                const response = await fetch('/tasks_manager/tasks_api.php?action=create_task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.ok) {
                    alert('✅ Задача успешно создана!');
                    document.getElementById('taskForm').reset();
                    loadAllTasks();
                    loadAllUsers();
                    
                    // Установить завтрашнюю дату
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    document.getElementById('taskDueDate').value = tomorrow.toISOString().split('T')[0];
                } else {
                    alert('❌ Ошибка: ' + result.error);
                }
            } catch (error) {
                alert('❌ Ошибка создания задачи: ' + error.message);
            }
        }

        // Удаление задачи
        async function deleteTask(taskId) {
            if (!confirm('Вы уверены, что хотите удалить эту задачу?')) return;

            try {
                const response = await fetch('/tasks_manager/tasks_api.php?action=delete_task', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ task_id: taskId })
                });

                const result = await response.json();

                if (result.ok) {
                    alert('✅ Задача удалена');
                    loadAllTasks();
                } else {
                    alert('❌ Ошибка: ' + result.error);
                }
            } catch (error) {
                alert('❌ Ошибка удаления: ' + error.message);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    </script>
</body>
</html>

