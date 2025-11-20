<?php
// API для работы с задачами
require_once(__DIR__ . '/../auth/includes/config.php');
require_once(__DIR__ . '/../auth/includes/auth-functions.php');

// Инициализация системы авторизации
initAuthSystem();

// Инициализация сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Не авторизован']);
    exit;
}

$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Получить список пользователей определенного цеха для выпадающего списка
if ($action === 'get_users') {
    try {
        $department = $_GET['department'] ?? 'U5';
        
        // Получаем пользователей цеха с ролью supervisor (мастер)
        $users = $db->select("
            SELECT u.id, u.full_name, u.phone, r.display_name as role_name
            FROM auth.auth_users u
            JOIN auth.auth_user_departments ud ON u.id = ud.user_id
            JOIN auth.auth_roles r ON ud.role_id = r.id
            WHERE ud.department_code = ? 
            AND r.name = 'supervisor'
            GROUP BY u.id, u.full_name, u.phone, r.display_name
            ORDER BY u.full_name
        ", [$department]);
        
        echo json_encode(['ok' => true, 'users' => $users]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Создать новую задачу (только для директоров)
if ($action === 'create_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Проверяем права доступа
        $userDepartments = $db->select("
            SELECT r.name as role_name
            FROM auth.auth_user_departments ud
            JOIN auth.auth_roles r ON ud.role_id = r.id
            WHERE ud.user_id = ? AND ud.department_code = ?
        ", [$session['user_id'], $_SESSION['auth_department'] ?? 'U5']);
        
        $isDirector = false;
        foreach ($userDepartments as $dept) {
            if ($dept['role_name'] === 'director') {
                $isDirector = true;
                break;
            }
        }
        
        if (!$isDirector) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Недостаточно прав для создания задач']);
            exit;
        }
        
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое тело запроса']);
            exit;
        }
        
        $title = trim($payload['title'] ?? '');
        $description = trim($payload['description'] ?? '');
        $assigned_to = (int)($payload['assigned_to'] ?? 0);
        $department = trim($payload['department'] ?? 'U5');
        $priority = trim($payload['priority'] ?? 'normal');
        $due_date = trim($payload['due_date'] ?? '');
        
        // Валидация
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Укажите заголовок задачи']);
            exit;
        }
        
        if ($assigned_to <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Выберите исполнителя']);
            exit;
        }
        
        // Создаем задачу
        $stmt = $pdo->prepare("
            INSERT INTO tasks (title, description, assigned_to, assigned_by, department, priority, status, due_date)
            VALUES (:title, :description, :assigned_to, :assigned_by, :department, :priority, 'pending', :due_date)
        ");
        
        $stmt->execute([
            ':title' => $title,
            ':description' => $description,
            ':assigned_to' => $assigned_to,
            ':assigned_by' => $session['user_id'],
            ':department' => $department,
            ':priority' => $priority,
            ':due_date' => $due_date ?: null,
        ]);
        
        echo json_encode(['ok' => true, 'message' => 'Задача успешно создана', 'task_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Получить задачи для текущего пользователя
if ($action === 'get_my_tasks') {
    try {
        $status = $_GET['status'] ?? 'all';
        $department = $_GET['department'] ?? 'U5';
        
        $sql = "
            SELECT 
                t.*,
                assigned_user.full_name as assigned_to_name,
                creator_user.full_name as assigned_by_name
            FROM tasks t
            LEFT JOIN auth.auth_users assigned_user ON t.assigned_to = assigned_user.id
            LEFT JOIN auth.auth_users creator_user ON t.assigned_by = creator_user.id
            WHERE t.assigned_to = ? AND t.department = ?
        ";
        
        $params = [$session['user_id'], $department];
        
        if ($status !== 'all') {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY 
            CASE t.priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'normal' THEN 3 
                WHEN 'low' THEN 4 
            END,
            t.due_date ASC,
            t.created_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
        echo json_encode(['ok' => true, 'tasks' => $tasks]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Получить все задачи (для директора)
if ($action === 'get_all_tasks') {
    try {
        // Проверяем права доступа
        $userDepartments = $db->select("
            SELECT r.name as role_name
            FROM auth.auth_user_departments ud
            JOIN auth.auth_roles r ON ud.role_id = r.id
            WHERE ud.user_id = ? AND ud.department_code = ?
        ", [$session['user_id'], $_SESSION['auth_department'] ?? 'U5']);
        
        $isDirector = false;
        foreach ($userDepartments as $dept) {
            if ($dept['role_name'] === 'director') {
                $isDirector = true;
                break;
            }
        }
        
        if (!$isDirector) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Недостаточно прав для просмотра всех задач']);
            exit;
        }
        
        $department = $_GET['department'] ?? 'U5';
        $status = $_GET['status'] ?? 'all';
        
        $sql = "
            SELECT 
                t.*,
                assigned_user.full_name as assigned_to_name,
                creator_user.full_name as assigned_by_name
            FROM tasks t
            LEFT JOIN auth.auth_users assigned_user ON t.assigned_to = assigned_user.id
            LEFT JOIN auth.auth_users creator_user ON t.assigned_by = creator_user.id
            WHERE t.department = ?
        ";
        
        $params = [$department];
        
        if ($status !== 'all') {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY t.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll();
        
        echo json_encode(['ok' => true, 'tasks' => $tasks]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Обновить статус задачи
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое тело запроса']);
            exit;
        }
        
        $task_id = (int)($payload['task_id'] ?? 0);
        $status = trim($payload['status'] ?? '');
        
        if ($task_id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Неверный ID задачи']);
            exit;
        }
        
        if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Неверный статус']);
            exit;
        }
        
        // Проверяем, что задача назначена текущему пользователю
        $stmt = $pdo->prepare("SELECT assigned_to FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        if (!$task) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Задача не найдена']);
            exit;
        }
        
        if ($task['assigned_to'] != $session['user_id']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Вы не можете изменять чужие задачи']);
            exit;
        }
        
        // Обновляем статус
        if ($status === 'completed') {
            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NOW() WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = NULL WHERE id = ?");
        }
        
        $stmt->execute([$status, $task_id]);
        
        echo json_encode(['ok' => true, 'message' => 'Статус задачи обновлен']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Удалить задачу (только для директора или создателя)
if ($action === 'delete_task' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!$payload) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое тело запроса']);
            exit;
        }
        
        $task_id = (int)($payload['task_id'] ?? 0);
        
        if ($task_id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Неверный ID задачи']);
            exit;
        }
        
        // Проверяем права доступа
        $stmt = $pdo->prepare("SELECT assigned_by FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch();
        
        if (!$task) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Задача не найдена']);
            exit;
        }
        
        // Может удалять только создатель задачи
        if ($task['assigned_by'] != $session['user_id']) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Только создатель может удалить задачу']);
            exit;
        }
        
        // Удаляем задачу
        $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
        $stmt->execute([$task_id]);
        
        echo json_encode(['ok' => true, 'message' => 'Задача удалена']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Неверный запрос']);

