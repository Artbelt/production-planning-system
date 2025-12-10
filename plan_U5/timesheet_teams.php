<?php
// Управление бригадами и назначение сотрудников
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');

// Инициализация системы авторизации
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

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

// Подключение к БД plan_U5
try {
    $pdo = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Создаем таблицы, если их нет
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timesheet_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL COMMENT 'Название бригады/машины',
            description TEXT DEFAULT NULL COMMENT 'Описание',
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0 COMMENT 'Порядок сортировки',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (name),
            INDEX idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timesheet_user_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL COMMENT 'ID пользователя из auth_users',
            team_id INT NOT NULL COMMENT 'ID бригады',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (team_id) REFERENCES timesheet_teams(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_team (user_id, team_id),
            INDEX idx_user_id (user_id),
            INDEX idx_team_id (team_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    // Добавляем начальные бригады, если их нет
    $pdo->exec("
        INSERT IGNORE INTO timesheet_teams (name, description, sort_order) VALUES
        ('Бригада 1', 'Первая бригада', 1),
        ('Бригада 2', 'Вторая бригада', 2),
        ('Бригада 3', 'Третья бригада', 3),
        ('Бригада 4', 'Четвертая бригада', 4)
    ");
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$error = '';
$success = '';

// Обработка действий
if ($_POST && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action == 'add_team') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        if ($name) {
            try {
                $stmt = $pdo->prepare("INSERT INTO timesheet_teams (name, description, sort_order) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $sort_order]);
                $success = 'Бригада добавлена';
            } catch (PDOException $e) {
                $error = 'Ошибка при добавлении: ' . $e->getMessage();
            }
        } else {
            $error = 'Введите название бригады';
        }
    } elseif ($action == 'edit_team') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        if ($id && $name) {
            try {
                $stmt = $pdo->prepare("UPDATE timesheet_teams SET name = ?, description = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$name, $description, $sort_order, $id]);
                $success = 'Бригада обновлена';
            } catch (PDOException $e) {
                $error = 'Ошибка при обновлении: ' . $e->getMessage();
            }
        }
    } elseif ($action == 'delete_team') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id) {
            try {
                $stmt = $pdo->prepare("UPDATE timesheet_teams SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Бригада удалена';
            } catch (PDOException $e) {
                $error = 'Ошибка при удалении: ' . $e->getMessage();
            }
        }
    } elseif ($action == 'assign_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        
        if ($user_id && $team_id) {
            try {
                $stmt = $pdo->prepare("INSERT INTO timesheet_user_teams (user_id, team_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
                $stmt->execute([$user_id, $team_id]);
                $success = 'Сотрудник назначен на бригаду';
            } catch (PDOException $e) {
                $error = 'Ошибка при назначении: ' . $e->getMessage();
            }
        }
    } elseif ($action == 'remove_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        
        if ($user_id && $team_id) {
            try {
                $stmt = $pdo->prepare("UPDATE timesheet_user_teams SET is_active = 0 WHERE user_id = ? AND team_id = ?");
                $stmt->execute([$user_id, $team_id]);
                $success = 'Сотрудник удален из бригады';
            } catch (PDOException $e) {
                $error = 'Ошибка при удалении: ' . $e->getMessage();
            }
        }
    }
}

// Получаем список всех бригад
$teams = $pdo->query("SELECT * FROM timesheet_teams ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Получаем список сотрудников из системы авторизации
$employees = $db->select("
    SELECT DISTINCT u.id, u.full_name
    FROM auth_users u
    INNER JOIN auth_user_departments ud ON u.id = ud.user_id
    INNER JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.department_code = 'U5' 
    AND ud.is_active = 1 
    AND u.is_active = 1
    AND r.name NOT IN ('director', 'manager', 'laser_operator', 'cut_operator')
    ORDER BY u.full_name
");

// Получаем назначения сотрудников на бригады
$userTeams = [];
$stmt = $pdo->query("SELECT user_id, team_id FROM timesheet_user_teams WHERE is_active = 1");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $userTeams[$row['user_id']][] = $row['team_id'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление бригадами - Табель У5</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .team-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }
        .team-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }
        .team-members {
            min-height: 50px;
        }
        .member-badge {
            display: inline-block;
            background: #0d6efd;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            margin: 5px;
            font-size: 14px;
        }
        .member-badge .remove-btn {
            margin-left: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        .member-badge .remove-btn:hover {
            color: #ffc107;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Управление бригадами</h1>
            <div>
                <a href="timesheet.php" class="btn btn-primary btn-sm me-2">← Вернуться к табелю</a>
                <span class="me-3">Пользователь: <?php echo htmlspecialchars($user['full_name'] ?? 'Пользователь'); ?></span>
                <a href="../auth/logout.php" class="btn btn-secondary btn-sm">Выйти</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Форма добавления бригады -->
        <div class="card mb-4">
            <div class="card-header">Добавить бригаду</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_team">
                    <div class="row">
                        <div class="col-md-4">
                            <label for="name" class="form-label">Название бригады *</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="col-md-5">
                            <label for="description" class="form-label">Описание</label>
                            <input type="text" class="form-control" id="description" name="description">
                        </div>
                        <div class="col-md-2">
                            <label for="sort_order" class="form-label">Порядок</label>
                            <input type="number" class="form-control" id="sort_order" name="sort_order" value="0">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">Добавить</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Список бригад с сотрудниками -->
        <div class="row">
            <?php foreach ($teams as $team): ?>
                <?php if (!$team['is_active']) continue; ?>
                <div class="col-md-6">
                    <div class="team-card">
                        <div class="team-header">
                            <h5 style="margin: 0;"><?php echo htmlspecialchars($team['name']); ?></h5>
                            <div>
                                <button type="button" class="btn btn-sm btn-warning" onclick="editTeam(<?php echo $team['id']; ?>, '<?php echo htmlspecialchars($team['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($team['description'] ?? '', ENT_QUOTES); ?>', <?php echo $team['sort_order']; ?>)">Редактировать</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Вы уверены, что хотите удалить эту бригаду?');">
                                    <input type="hidden" name="action" value="delete_team">
                                    <input type="hidden" name="id" value="<?php echo $team['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Удалить</button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="team-members mb-3">
                            <?php 
                            $teamMembers = [];
                            foreach ($employees as $emp) {
                                if (isset($userTeams[$emp['id']]) && in_array($team['id'], $userTeams[$emp['id']])) {
                                    $teamMembers[] = $emp;
                                }
                            }
                            ?>
                            <?php if (empty($teamMembers)): ?>
                                <p class="text-muted">Нет назначенных сотрудников</p>
                            <?php else: ?>
                                <?php foreach ($teamMembers as $member): ?>
                                    <span class="member-badge">
                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить сотрудника из бригады?');">
                                            <input type="hidden" name="action" value="remove_user">
                                            <input type="hidden" name="user_id" value="<?php echo $member['id']; ?>">
                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                            <button type="submit" class="remove-btn" style="background: none; border: none; color: white; padding: 0; margin-left: 5px;">×</button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Форма назначения сотрудника -->
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="assign_user">
                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                            <div class="input-group">
                                <select name="user_id" class="form-select" required>
                                    <option value="">Выберите сотрудника</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <?php if (!isset($userTeams[$emp['id']]) || !in_array($team['id'], $userTeams[$emp['id']])): ?>
                                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['full_name']); ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-success">Добавить</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Модальное окно редактирования бригады -->
    <div class="modal fade" id="editTeamModal" tabindex="-1" aria-labelledby="editTeamModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTeamModalLabel">Редактировать бригаду</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="edit_team">
                    <input type="hidden" name="id" id="edit_team_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_team_name" class="form-label">Название бригады *</label>
                            <input type="text" class="form-control" id="edit_team_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_team_description" class="form-label">Описание</label>
                            <input type="text" class="form-control" id="edit_team_description" name="description">
                        </div>
                        <div class="mb-3">
                            <label for="edit_team_sort_order" class="form-label">Порядок сортировки</label>
                            <input type="number" class="form-control" id="edit_team_sort_order" name="sort_order" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editTeam(id, name, description, sortOrder) {
            document.getElementById('edit_team_id').value = id;
            document.getElementById('edit_team_name').value = name;
            document.getElementById('edit_team_description').value = description || '';
            document.getElementById('edit_team_sort_order').value = sortOrder || 0;
            var modal = new bootstrap.Modal(document.getElementById('editTeamModal'));
            modal.show();
        }
    </script>
</body>
</html>




