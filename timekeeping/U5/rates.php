<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

// Обработка добавления/редактирования/удаления тарифа
$error = '';
$success = '';
if ($_POST && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_rate' || $_POST['action'] == 'edit_rate') {
        $filter_name = trim($_POST['filter_name'] ?? '');
        $rate = floatval($_POST['rate'] ?? 0);
        if ($filter_name && $rate > 0) {
            if ($_POST['action'] == 'add_rate') {
                $stmt = $db->prepare('INSERT INTO filter_rates (filter_name, rate) VALUES (?, ?)');
                try {
                    $stmt->execute([$filter_name, $rate]);
                    $success = 'Тариф добавлен';
                } catch (PDOException $e) {
                    $error = 'Ошибка: Фильтр с таким именем уже существует';
                }
            } else {
                $id = $_POST['id'] ?? 0;
                $stmt = $db->prepare('UPDATE filter_rates SET filter_name = ?, rate = ? WHERE id = ?');
                $stmt->execute([$filter_name, $rate, $id]);
                $success = 'Тариф обновлён';
            }
        } else {
            $error = 'Укажите название фильтра и тариф';
        }
    } elseif ($_POST['action'] == 'delete_rate') {
        $id = $_POST['id'] ?? 0;
        $stmt = $db->prepare('DELETE FROM filter_rates WHERE id = ?');
        $stmt->execute([$id]);
        $success = 'Тариф удалён';
    }
}

// Получаем список тарифов
$rates = $db->query('SELECT id, filter_name, rate FROM filter_rates ORDER BY filter_name')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление тарифами</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Управление тарифами</h1>
            <div>
                <a href="dashboard.php" class="btn btn-primary btn-sm me-2">Вернуться к табелю</a>
                <a href="teams.php" class="btn btn-primary btn-sm me-2">Управление бригадами</a>
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

        <!-- Форма добавления тарифа -->
        <div class="card mb-4">
            <div class="card-header">Добавить тариф</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_rate">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="filter_name" class="form-label">Название фильтра</label>
                            <input type="text" class="form-control" id="filter_name" name="filter_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="rate" class="form-label">Тариф (руб./ед.)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="rate" name="rate" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </form>
            </div>
        </div>

        <!-- Таблица тарифов -->
        <div class="card">
            <div class="card-header">Список тарифов</div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Фильтр</th>
                            <th>Тариф (руб./ед.)</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rates as $rate): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rate['filter_name']); ?></td>
                                <td><?php echo number_format($rate['rate'], 2); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning edit-rate-btn" 
                                            data-id="<?php echo $rate['id']; ?>" 
                                            data-filter="<?php echo htmlspecialchars($rate['filter_name']); ?>" 
                                            data-rate="<?php echo $rate['rate']; ?>">
                                        Редактировать
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-rate-btn" 
                                            data-id="<?php echo $rate['id']; ?>">
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

    <!-- Модальное окно для редактирования тарифа -->
    <div class="modal fade" id="editRateModal" tabindex="-1" aria-labelledby="editRateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRateModalLabel">Редактировать тариф</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editRateForm">
                        <input type="hidden" name="action" value="edit_rate">
                        <input type="hidden" name="id" id="edit_rate_id">
                        <div class="mb-3">
                            <label for="edit_filter_name" class="form-label">Название фильтра</label>
                            <input type="text" class="form-control" id="edit_filter_name" name="filter_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_rate" class="form-label">Тариф (руб./ед.)</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="edit_rate" name="rate" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для подтверждения удаления тарифа -->
    <div class="modal fade" id="deleteRateModal" tabindex="-1" aria-labelledby="deleteRateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteRateModalLabel">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Вы уверены, что хотите удалить этот тариф?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="POST" id="deleteRateForm" style="display:inline;">
                        <input type="hidden" name="action" value="delete_rate">
                        <input type="hidden" name="id" id="delete_rate_id">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Обработка кнопки "Редактировать тариф"
            $('.edit-rate-btn').click(function() {