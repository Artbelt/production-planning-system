<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

// Обработка добавления/редактирования/удаления сотрудника
$error = '';
$success = '';
if ($_POST && isset($_POST['action'])) {
    if ($_POST['action'] == 'add' || $_POST['action'] == 'edit') {
        $full_name = trim($_POST['full_name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $team_id = !empty($_POST['team_id']) ? $_POST['team_id'] : null;
        if ($full_name) {
            if ($_POST['action'] == 'add') {
                $stmt = $db->prepare('INSERT INTO employees (full_name, position, team_id) VALUES (?, ?, ?)');
                $stmt->execute([$full_name, $position, $team_id]);
                $success = 'Сотрудник добавлен';
            } else {
                $id = $_POST['id'] ?? 0;
                $stmt = $db->prepare('UPDATE employees SET full_name = ?, position = ?, team_id = ? WHERE id = ?');
                $stmt->execute([$full_name, $position, $team_id, $id]);
                $success = 'Сотрудник обновлен';
            }
        } else {
            $error = 'ФИО обязательно';
        }
    } elseif ($_POST['action'] == 'delete') {
        $id = $_POST['id'] ?? 0;
        $stmt = $db->prepare('SELECT COUNT(*) FROM timesheets WHERE employee_id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $db->prepare('DELETE FROM employees WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Сотрудник удален';
        } else {
            $error = 'Нельзя удалить сотрудника с записями в табеле';
        }
    }
}

// Получаем список сотрудников
$employees = $db->query('SELECT e.id, e.full_name, e.position, e.team_id, t.name as team_name FROM employees e LEFT JOIN teams t ON e.team_id = t.id ORDER BY e.full_name')->fetchAll(PDO::FETCH_ASSOC);

// Получаем список бригад
$teams = $db->query('SELECT id, name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление сотрудниками</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Управление сотрудниками</h1>
            <div>
                <a href="dashboard.php" class="btn btn-primary btn-sm me-2">Вернуться к табелю</a>
                <a href="teams.php" class="btn btn-primary btn-sm me-2">Управление бригадами</a>
                <span class="me-3">Пользователь: <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" class="btn btn-secondary btn-sm">Выйти</a>
            </div>
        </div>

        <!-- Сообщения об успехе или ошибке -->
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Форма добавления сотрудника -->
        <div class="card mb-4">
            <div class="card-header">Добавить сотрудника</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="full_name" class="form-label">ФИО</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="position" class="form-label">Должность</label>
                            <input type="text" class="form-control" id="position" name="position">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="team_id" class="form-label">Бригада</label>
                            <select class="form-control" id="team_id" name="team_id">
                                <option value="">Без бригады</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['id']; ?>"><?php echo htmlspecialchars($team['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>
            </div>
        </div>

        <!-- Таблица сотрудников -->
        <div class="card">
            <div class="card-header">Список сотрудников</div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>ФИО</th>
                            <th>Должность</th>
                            <th>Бригада</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($employee['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($employee['position'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($employee['team_name'] ?? 'Без бригады'); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning edit-btn" 
                                            data-id="<?php echo $employee['id']; ?>" 
                                            data-full-name="<?php echo htmlspecialchars($employee['full_name']); ?>" 
                                            data-position="<?php echo htmlspecialchars($employee['position'] ?? ''); ?>"
                                            data-team-id="<?php echo $employee['team_id'] ?? ''; ?>">
                                        Редактировать
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-btn" 
                                            data-id="<?php echo $employee['id']; ?>">
                                        Удалить
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Модальное окно для редактирования сотрудника -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">Редактировать сотрудника</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editForm">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label for="edit_full_name" class="form-label">ФИО</label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_position" class="form-label">Должность</label>
                            <input type="text" class="form-control" id="edit_position" name="position">
                        </div>
                        <div class="mb-3">
                            <label for="edit_team_id" class="form-label">Бригада</label>
                            <select class="form-control" id="edit_team_id" name="team_id">
                                <option value="">Без бригады</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['id']; ?>"><?php echo htmlspecialchars($team['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для подтверждения удаления -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этого сотрудника?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="POST" id="deleteForm" style="display:inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete_id">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Обработка кнопки "Редактировать"
            $('.edit-btn').click(function() {
                var id = $(this).data('id');
                var fullName = $(this).data('full-name');
                var position = $(this).data('position');
                var teamId = $(this).data('team-id');

                $('#edit_id').val(id);
                $('#edit_full_name').val(fullName);
                $('#edit_position').val(position);
                $('#edit_team_id').val(teamId);
                $('#editModal').modal('show');
            });

            // Обработка кнопки "Удалить"
            $('.delete-btn').click(function() {
                var id = $(this).data('id');
                $('#delete_id').val(id);
                $('#deleteModal').modal('show');
            });
        });
    </script>
</body>
</html>