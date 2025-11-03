<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

// Параметры для расчёта зарплаты
$rate_per_unit = 100; // Пример: 100 рублей за единицу продукции (можно настроить)

// Обработка добавления/редактирования/удаления бригады и продукции
$error = '';
$success = '';
if ($_POST && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_team' || $_POST['action'] == 'edit_team') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            if ($_POST['action'] == 'add_team') {
                $stmt = $db->prepare('INSERT INTO teams (name) VALUES (?)');
                $stmt->execute([$name]);
                $success = 'Бригада добавлена';
            } else {
                $id = $_POST['id'] ?? 0;
                $stmt = $db->prepare('UPDATE teams SET name = ? WHERE id = ?');
                $stmt->execute([$name, $id]);
                $success = 'Бригада обновлена';
            }
        } else {
            $error = 'Название бригады обязательно';
        }
    } elseif ($_POST['action'] == 'delete_team') {
        $id = $_POST['id'] ?? 0;
        // Проверяем, нет ли сотрудников или продукции
        $stmt = $db->prepare('SELECT COUNT(*) FROM employees WHERE team_id = ?');
        $stmt->execute([$id]);
        $employee_count = $stmt->fetchColumn();
        $stmt = $db->prepare('SELECT COUNT(*) FROM production WHERE team_id = ?');
        $stmt->execute([$id]);
        $production_count = $stmt->fetchColumn();
        if ($employee_count == 0 && $production_count == 0) {
            $stmt = $db->prepare('DELETE FROM teams WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Бригада удалена';
        } else {
            $error = 'Нельзя удалить бригаду с сотрудниками или записями о продукции';
        }
    } elseif ($_POST['action'] == 'add_production') {
        $team_id = $_POST['team_id'] ?? 0;
        $month = $_POST['month'] ?? date('m');
        $year = $_POST['year'] ?? date('Y');
        $units_produced = $_POST['units_produced'] ?? 0;
        if ($team_id && $units_produced >= 0) {
            $stmt = $db->prepare('INSERT INTO production (team_id, month, year, units_produced) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE units_produced = ?');
            $stmt->execute([$team_id, $month, $year, $units_produced, $units_produced]);
            $success = 'Данные о продукции обновлены';
        } else {
            $error = 'Укажите бригаду и количество продукции';
        }
    }
}

// Получаем список бригад
$teams = $db->query('SELECT id, name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные о продукции за текущий месяц
$month = date('m');
$year = date('Y');
$production = [];
$stmt = $db->query("SELECT team_id, units_produced FROM production WHERE month = $month AND year = $year");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $production[$row['team_id']] = $row['units_produced'];
}

// Рассчитываем зарплату для каждой бригады
$salaries = [];
foreach ($teams as $team) {
    $team_id = $team['id'];
    $units = $production[$team_id] ?? 0;
    $stmt = $db->prepare('SELECT COUNT(*) FROM employees WHERE team_id = ?');
    $stmt->execute([$team_id]);
    $employee_count = $stmt->fetchColumn();
    $salary_per_employee = $employee_count > 0 ? ($units * $rate_per_unit) / $employee_count : 0;
    $salaries[$team_id] = [
        'units' => $units,
        'employee_count' => $employee_count,
        'salary_per_employee' => $salary_per_employee
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление бригадами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Управление бригадами</h1>
            <div>
                <a href="dashboard.php" class="btn btn-primary btn-sm me-2">Вернуться к табелю</a>
                <a href="employees.php" class="btn btn-primary btn-sm me-2">Управление сотрудниками</a>
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

        <!-- Форма добавления бригады -->
        <div class="card mb-4">
            <div class="card-header">Добавить бригаду</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_team">
                    <div class="mb-3">
                        <label for="name" class="form-label">Название бригады</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>
            </div>
        </div>

        <!-- Форма ввода продукции -->
        <div class="card mb-4">
            <div class="card-header">Ввод продукции за <?php echo date('F Y', strtotime("$year-$month-01")); ?></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_production">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="team_id" class="form-label">Бригада</label>
                            <select class="form-control" id="team_id" name="team_id" required>
                                <option value="">Выберите бригаду</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo $team['id']; ?>"><?php echo htmlspecialchars($team['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="units_produced" class="form-label">Количество продукции</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="units_produced" name="units_produced" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="month" class="form-label">Месяц и год</label>
                            <input type="month" class="form-control" id="month" name="month_year" value="<?php echo "$year-$month"; ?>" required>
                            <input type="hidden" name="month" value="<?php echo $month; ?>">
                            <input type="hidden" name="year" value="<?php echo $year; ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </form>
            </div>
        </div>

        <!-- Таблица бригад и зарплаты -->
        <div class="card">
            <div class="card-header">Список бригад и зарплата</div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Бригада</th>
                            <th>Продукция (ед.)</th>
                            <th>Сотрудников</th>
                            <th>Зарплата на сотрудника (руб.)</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $team): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($team['name']); ?></td>
                                <td><?php echo number_format($salaries[$team['id']]['units'] ?? 0, 2); ?></td>
                                <td><?php echo $salaries[$team['id']]['employee_count'] ?? 0; ?></td>
                                <td><?php echo number_format($salaries[$team['id']]['salary_per_employee'] ?? 0, 2); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning edit-team-btn" 
                                            data-id="<?php echo $team['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($team['name']); ?>">
                                        Редактировать
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-team-btn" 
                                            data-id="<?php echo $team['id']; ?>">
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

    <!-- Модальное окно для редактирования бригады -->
    <div class="modal fade" id="editTeamModal" tabindex="-1" aria-labelledby="editTeamModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editTeamModalLabel">Редактировать бригаду</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editTeamForm">
                        <input type="hidden" name="action" value="edit_team">
                        <input type="hidden" name="id" id="edit_team_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Название бригады</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для подтверждения удаления -->
    <div class="modal fade" id="deleteTeamModal" tabindex="-1" aria-labelledby="deleteTeamModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteTeamModalLabel">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить эту бригаду?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="POST" id="deleteTeamForm" style="display:inline;">
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="id" id="delete_team_id">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Обработка кнопки "Редактировать бригаду"
            $('.edit-team-btn').click(function() {
                var id = $(this).data('id');
                var name = $(this).data('name');

                $('#edit_team_id').val(id);
                $('#edit_name').val(name);
                $('#editTeamModal').modal('show');
            });

            // Обработка кнопки "Удалить бригаду"
            $('.delete-team-btn').click(function() {
                var id = $(this).data('id');
                $('#delete_team_id').val(id);
                $('#deleteTeamModal').modal('show');
            });

            // Обработка выбора месяца/года
            $('#month').change(function() {
                var monthYear = $(this).val().split('-');
                $('input[name="year"]').val(monthYear[0]);
                $('input[name="month"]').val(monthYear[1]);
            });
        });
    </script>
</body>
</html>