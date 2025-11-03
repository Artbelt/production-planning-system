<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
require_once 'config.php';

// Получаем текущий месяц и год
$month = date('m');
$year = date('Y');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Получаем сотрудников с бригадами
$employees = $db->query('SELECT e.id, e.full_name, t.id as team_id, t.name as team_name FROM employees e LEFT JOIN teams t ON e.team_id = t.id ORDER BY e.full_name')->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные табеля за текущий месяц
$timesheets = [];
$stmt = $db->query("SELECT employee_id, DATE_FORMAT(date, '%Y-%m-%d') as date, hours_worked, comments FROM timesheets WHERE YEAR(date) = $year AND MONTH(date) = $month");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $timesheets[$row['employee_id']][$row['date']] = [
        'hours' => $row['hours_worked'],
        'comments' => $row['comments']
    ];
}

// Рассчитываем зарплату для каждого сотрудника
$salaries = [];
foreach ($employees as $employee) {
    $team_id = $employee['team_id'];
    if ($team_id) {
        $stmt = $db->prepare("SELECT SUM(fp.count_of_filters * fr.rate) as total_salary
            FROM plan_u5.manufactured_production fp
            LEFT JOIN filter_rates fr ON fp.name_of_filter = fr.filter_name
            WHERE fp.team = ? AND MONTH(fp.date_of_production) = ? AND YEAR(fp.date_of_production) = ?");
        $stmt->execute([$team_id, $month, $year]);
        $total_salary = $stmt->fetchColumn() ?: 0;
        $stmt = $db->prepare('SELECT COUNT(*) FROM employees WHERE team_id = ?');
        $stmt->execute([$team_id]);
        $employee_count = $stmt->fetchColumn();
        $salary_per_employee = $employee_count > 0 ? $total_salary / $employee_count : 0;
        $salaries[$employee['id']] = $salary_per_employee;
    } else {
        $salaries[$employee['id']] = 0;
    }
}

// Получаем список бригад
$teams = $db->query('SELECT id, name FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Получаем данные о выпущенной продукции по бригадам и дням
$production_data = [];
foreach ($teams as $team) {
    $production_data[$team['id']] = ['name' => $team['name'], 'days' => [], 'total' => 0];
}
$daily_totals = array_fill(1, $days_in_month, 0);
$total_production = 0;

$stmt = $db->prepare("SELECT fp.team, DAY(fp.date_of_production) as day, SUM(fp.count_of_filters) as total_filters
    FROM plan_u5.manufactured_production fp
    WHERE MONTH(fp.date_of_production) = ? AND YEAR(fp.date_of_production) = ?
    GROUP BY fp.team, DAY(fp.date_of_production)");
$stmt->execute([$month, $year]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $team_id = $row['team'];
    $day = $row['day'];
    $filters = $row['total_filters'];
    if (isset($production_data[$team_id])) {
        $production_data[$team_id]['days'][$day] = $filters;
        $production_data[$team_id]['total'] += $filters;
        $daily_totals[$day] = ($daily_totals[$day] ?? 0) + $filters;
        $total_production += $filters;
    }
}

// Подготовка пустой таблицы для заработанных средств
$earnings_data = [];
foreach ($teams as $team) {
    $earnings_data[$team['id']] = ['name' => $team['name'], 'days' => array_fill(1, $days_in_month, 0), 'total' => 0];
}
$earnings_daily_totals = array_fill(1, $days_in_month, 0);
$total_earnings = 0;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Система табелирования</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .table th, .table td { 
            text-align: center; 
            vertical-align: middle; 
            font-size: 0.9rem;
        }
        .table td.clickable { 
            cursor: pointer; 
        }
        .table td.clickable:hover { 
            background-color: #f0f0f0; 
        }
        .table-responsive {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }
        .table {
            width: 100%;
        }
        .employee-name, .team-name {
            white-space: nowrap;
            min-width: 150px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1>Табель за <?php echo date('F Y', strtotime("$year-$month-01")); ?></h1>
            <div>
                <a href="employees.php" class="btn btn-primary btn-sm me-2">Управление сотрудниками</a>
                <a href="teams.php" class="btn btn-primary btn-sm me-2">Управление бригадами</a>
                <a href="rates.php" class="btn btn-primary btn-sm me-2">Управление тарифами</a>
                <a href="export.php" class="btn btn-success btn-sm me-2">Экспорт в CSV</a>
                <span class="me-3">Пользователь: <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="logout.php" class="btn btn-secondary btn-sm">Выйти</a>
            </div>
        </div>

        <!-- Таблица табеля -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <th><?php echo $day; ?></th>
                                <?php endfor; ?>
                                <th>Зарплата (руб.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                                <tr>
                                    <td class="employee-name">
                                        <?php 
                                        echo htmlspecialchars($employee['full_name']); 
                                        if ($employee['team_name']) {
                                            echo ' (' . htmlspecialchars($employee['team_name']) . ')';
                                        }
                                        ?>
                                    </td>
                                    <?php for ($day = 1; $day <= $days_in_month; $day++): 
                                        $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                                        $hours = $timesheets[$employee['id']][$date]['hours'] ?? '';
                                        $comments = $timesheets[$employee['id']][$date]['comments'] ?? '';
                                        $display_hours = '';
                                        if ($hours !== '' && floatval($hours) != 0) {
                                            $display_hours = rtrim(number_format($hours, 2, '.', ''), '0.');
                                        }
                                        $tooltip_attrs = $comments ? 'data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($comments) . '"' : '';
                                    ?>
                                        <td class="clickable" 
                                            data-employee-id="<?php echo $employee['id']; ?>" 
                                            data-date="<?php echo $date; ?>" 
                                            data-hours="<?php echo $hours; ?>" 
                                            data-comments="<?php echo htmlspecialchars($comments); ?>"
                                            <?php echo $tooltip_attrs; ?>>
                                            <?php echo htmlspecialchars($display_hours); ?>
                                        </td>
                                    <?php endfor; ?>
                                    <td><?php echo number_format($salaries[$employee['id']] ?? 0, 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Таблица выпущенной продукции -->
        <div class="card mb-4">
            <div class="card-header">Выпущенная продукция за <?php echo date('F Y', strtotime("$year-$month-01")); ?></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Бригада</th>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <th><?php echo $day; ?></th>
                                <?php endfor; ?>
                                <th>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($production_data as $team_id => $team_data): ?>
                                <tr>
                                    <td class="team-name"><?php echo htmlspecialchars($team_data['name']); ?></td>
                                    <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                        <td><?php echo number_format($team_data['days'][$day] ?? 0, 0, ".", ""); ?></td>
                                    <?php endfor; ?>
                                    <td><?php echo number_format($team_data['total'], 0, ".", ""); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>Сумма за смену</strong></td>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <td><?php echo number_format($daily_totals[$day] ?? 0, 0, ".", ""); ?></td>
                                <?php endfor; ?>
                                <td><strong><?php echo number_format($total_production, 0, ".", ""); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Таблица заработанных средств бригадой -->
        <div class="card">
            <div class="card-header">Заработанные средства бригадой за <?php echo date('F Y', strtotime("$year-$month-01")); ?></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th>Бригада</th>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <th><?php echo $day; ?></th>
                                <?php endfor; ?>
                                <th>Итого</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($earnings_data as $team_id => $team_data): ?>
                                <tr>
                                    <td class="team-name"><?php echo htmlspecialchars($team_data['name']); ?></td>
                                    <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                        <td><?php echo number_format($team_data['days'][$day], 2, ".", ""); ?></td>
                                    <?php endfor; ?>
                                    <td><?php echo number_format($team_data['total'], 2, ".", ""); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td><strong>Итого</strong></td>
                                <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                                    <td><?php echo number_format($earnings_daily_totals[$day], 2, ".", ""); ?></td>
                                <?php endfor; ?>
                                <td><strong><?php echo number_format($total_earnings, 2, ".", ""); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для ввода часов -->
    <div class="modal fade" id="hoursModal" tabindex="-1" aria-labelledby="hoursModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="hoursModalLabel">Ввод часов</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="hoursForm">
                        <input type="hidden" id="employee_id" name="employee_id">
                        <input type="hidden" id="date" name="date">
                        <div class="mb-3">
                            <label for="hours_worked" class="form-label">Часы работы (например, 8.5)</label>
                            <input type="number" step="0.25" min="0" max="24" class="form-control" id="hours_worked" name="hours_worked" required>
                            <div class="mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm me-2" onclick="setHours(8)">8</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="setHours(11.5)">11.5</button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="comments" class="form-label">Комментарии</label>
                            <textarea class="form-control" id="comments" name="comments"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="saveHours">Сохранить</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Инициализация всплывающих подсказок
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Открытие модального окна при клике на ячейку
            $('.clickable').click(function() {
                var employeeId = $(this).data('employee-id');
                var date = $(this).data('date');
                var hours = $(this).data('hours');
                var comments = $(this).data('comments');
                $('#employee_id').val(employeeId);
                $('#date').val(date);
                $('#hours_worked').val(hours);
                $('#comments').val(comments);
                $('#hoursModal').modal('show');
            });

            // Сохранение данных при нажатии "Сохранить"
            $('#saveHours').click(function() {
                saveHours();
            });

            // Установка часов через кнопки
            window.setHours = function(hours) {
                $('#hours_worked').val(hours);
                saveHours();
            };

            // Функция сохранения часов
            function saveHours() {
                $.ajax({
                    url: 'save_timesheet.php',
                    type: 'POST',
                    data: $('#hoursForm').serialize(),
                    success: function(response) {
                        if (response.success) {
                            var employeeId = $('#employee_id').val();
                            var date = $('#date').val();
                            var hours = $('#hours_worked').val();
                            var comments = $('#comments').val();
                            var cell = $(`td[data-employee-id="${employeeId}"][data-date="${date}"]`);
                            var displayHours = '';
                            if (hours && parseFloat(hours) != 0) {
                                displayHours = parseFloat(hours).toFixed(2).replace(/\.?0+$/, '');
                            }
                            cell.text(displayHours);
                            cell.data('hours', hours);
                            cell.data('comments', comments);
                            if (comments) {
                                cell.attr('data-bs-toggle', 'tooltip');
                                cell.attr('data-bs-placement', 'top');
                                cell.attr('title', comments);
                            } else {
                                cell.removeAttr('data-bs-toggle');
                                cell.removeAttr('data-bs-placement');
                                cell.removeAttr('title');
                            }
                            var tooltip = bootstrap.Tooltip.getInstance(cell[0]);
                            if (tooltip) {
                                tooltip.dispose();
                            }
                            if (comments) {
                                new bootstrap.Tooltip(cell[0]);
                            }
                            $('#hoursModal').modal('hide');
                        } else {
                            alert('Ошибка: ' + response.error);
                        }
                    },
                    error: function() {
                        alert('Ошибка при сохранении данных');
                    }
                });
            }
        });
    </script>
</body>
</html>