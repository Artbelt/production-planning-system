<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

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

// Проверяем, есть ли у пользователя доступ к цеху U3
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U3');
}

require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');
require_once('cap_db_init.php');

// Получаем параметры фильтрации
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Валидация дат
if (!strtotime($date_from) || !strtotime($date_to)) {
    $date_from = date('Y-m-d', strtotime('-30 days'));
    $date_to = date('Y-m-d');
}

// Получаем данные о приходе крышек по дням (в штуках)
$income_data = [];
$sql_income = "
    SELECT 
        date,
        SUM(quantity) as total_pieces
    FROM cap_movements
    WHERE operation_type = 'INCOME'
      AND date BETWEEN ? AND ?
    GROUP BY date
    ORDER BY date DESC
";

$stmt_income = $pdo->prepare($sql_income);
if ($stmt_income && $stmt_income->execute([$date_from, $date_to])) {
    while ($row = $stmt_income->fetch(PDO::FETCH_ASSOC)) {
        $income_data[$row['date']] = (int)$row['total_pieces'];
    }
}

// Получаем данные о расходе крышек по дням (в штуках)
$production_data = [];
$fact_details = []; // Детальная информация для tooltip
$sql_production = "
    SELECT 
        date,
        filter_name,
        SUM(quantity) as total_pieces
    FROM cap_movements
    WHERE operation_type = 'PRODUCTION_OUT'
      AND date BETWEEN ? AND ?
    GROUP BY date, filter_name
    ORDER BY date DESC, filter_name
";

$stmt_production = $pdo->prepare($sql_production);
if ($stmt_production && $stmt_production->execute([$date_from, $date_to])) {
    while ($row = $stmt_production->fetch(PDO::FETCH_ASSOC)) {
        $date = $row['date'];
        $filter_name = trim($row['filter_name'] ?? '');
        $quantity = (int)$row['total_pieces'];
        
        if (!isset($production_data[$date])) {
            $production_data[$date] = 0;
            $fact_details[$date] = [];
        }
        
        $production_data[$date] += $quantity;
        
        // Сохраняем детали для tooltip
        if (!empty($filter_name)) {
            if (!isset($fact_details[$date][$filter_name])) {
                $fact_details[$date][$filter_name] = 0;
            }
            $fact_details[$date][$filter_name] += $quantity;
        }
    }
}

// Создаем массив всех дат в выбранном периоде
$all_dates = [];
$current_date = new DateTime($date_from);
$end_date = new DateTime($date_to);
$end_date->modify('+1 day'); // Добавляем день, чтобы включить конечную дату

while ($current_date < $end_date) {
    $all_dates[] = $current_date->format('Y-m-d');
    $current_date->modify('+1 day');
}

// Сортируем по возрастанию для графика
sort($all_dates);

// Получаем план потребления крышек по дням
$plan_data = [];
$plan_details = []; // Детальная информация для tooltip
$check_table = $pdo->query("SHOW TABLES LIKE 'build_plans'");
if ($check_table && $check_table->rowCount() > 0) {
    // Получаем план сборки по датам (только фильтры с металлическими крышками)
    $sql_plan = "
        SELECT 
            bp.day_date as date,
            bp.filter,
            bp.qty,
            rfs.up_cap,
            rfs.down_cap
        FROM build_plans bp
        LEFT JOIN round_filter_structure rfs ON TRIM(bp.filter) = TRIM(rfs.filter)
        WHERE bp.day_date BETWEEN ? AND ?
          AND (bp.order_number IN (SELECT order_number FROM orders WHERE (hide IS NULL OR hide != 1)))
          AND (
              (rfs.up_cap IS NOT NULL AND rfs.up_cap != '' AND (rfs.PU_up_cap IS NULL OR rfs.PU_up_cap = ''))
              OR
              (rfs.down_cap IS NOT NULL AND rfs.down_cap != '' AND (rfs.PU_down_cap IS NULL OR rfs.PU_down_cap = ''))
          )
        ORDER BY bp.day_date, bp.filter
    ";
    
    $stmt_plan = $pdo->prepare($sql_plan);
    if ($stmt_plan && $stmt_plan->execute([$date_from, $date_to])) {
        while ($row = $stmt_plan->fetch(PDO::FETCH_ASSOC)) {
            $date = $row['date'];
            $filter = trim($row['filter'] ?? '');
            $qty = (int)$row['qty'];
            $up_cap = trim($row['up_cap'] ?? '');
            $down_cap = trim($row['down_cap'] ?? '');
            
            if (!isset($plan_data[$date])) {
                $plan_data[$date] = 0;
                $plan_details[$date] = [];
            }
            
            // Считаем количество крышек для этого фильтра
            $caps_count = 0;
            if (!empty($up_cap)) {
                $caps_count += $qty;
            }
            if (!empty($down_cap)) {
                $caps_count += $qty;
            }
            
            $plan_data[$date] += $caps_count;
            
            // Сохраняем детали для tooltip
            if (!empty($filter) && $qty > 0) {
                if (!isset($plan_details[$date][$filter])) {
                    $plan_details[$date][$filter] = 0;
                }
                $plan_details[$date][$filter] += $qty;
            }
        }
    }
}

// Подготовка данных для графика (накопленный баланс)
$chart_labels = [];
$chart_balance = [];
$chart_plan = [];
$chart_fact = [];
$chart_delta = [];
$cumulative_balance = 0;

foreach ($all_dates as $date) {
    $income = $income_data[$date] ?? 0;
    $production = $production_data[$date] ?? 0;
    $plan = $plan_data[$date] ?? 0;
    
    $daily_balance = $income - $production;
    $cumulative_balance += $daily_balance;
    
    // Дельту считаем как факт - план (положительное = превышение плана, отрицательное = недовыполнение)
    $delta = $production - $plan;
    
    $chart_labels[] = date('d.m', strtotime($date));
    $chart_balance[] = $cumulative_balance;
    // План показываем количественно (не накопительно)
    $chart_plan[] = $plan;
    // Факт потребления показываем количественно
    $chart_fact[] = $production;
    // Дельту показываем количественно
    $chart_delta[] = $delta;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Анализ баланса крышек - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 10px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1050px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 6px;
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 22px;
        }
        .filters {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-weight: bold;
            color: #555;
            font-size: 13px;
        }
        .filter-group input[type="date"] {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            height: 32px;
        }
        button:hover {
            background: #4169e1;
        }
        .chart-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            position: relative;
        }
        .chart-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }
        .chart-wrapper {
            position: relative;
            height: 224px;
            width: 100%;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Анализ баланса крышек</h1>
        
        <div class="filters">
            <div class="filter-group">
                <label for="date_from">Дата от:</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label for="date_to">Дата до:</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <button onclick="applyFilters()">Применить фильтр</button>
        </div>
        
        <?php if (!empty($chart_labels)): ?>
        <div class="chart-container">
            <div class="chart-title">Изменение склада крышек (накопленный баланс в штуках)</div>
            <div class="chart-wrapper">
                <canvas id="balanceChart"></canvas>
            </div>
        </div>
        
        <div class="chart-container">
            <div class="chart-title">План потребления крышек (количество в штуках по дням)</div>
            <div class="chart-wrapper">
                <canvas id="planChart"></canvas>
            </div>
        </div>
        
        <div class="chart-container">
            <div class="chart-title">Факт потребления крышек (количество в штуках по дням)</div>
            <div class="chart-wrapper">
                <canvas id="factChart"></canvas>
            </div>
        </div>
        
        <div class="chart-container">
            <div class="chart-title">Дельта: разница между фактом и планом (факт - план)</div>
            <div class="chart-wrapper">
                <canvas id="deltaChart"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function applyFilters() {
        const dateFrom = document.getElementById('date_from').value;
        const dateTo = document.getElementById('date_to').value;
        
        if (!dateFrom || !dateTo) {
            alert('Пожалуйста, выберите обе даты');
            return;
        }
        
        if (dateFrom > dateTo) {
            alert('Дата "от" не может быть больше даты "до"');
            return;
        }
        
        window.location.href = 'cap_balance_chart.php?date_from=' + dateFrom + '&date_to=' + dateTo;
    }
    
    // Применяем фильтр при нажатии Enter в полях дат
    document.getElementById('date_from').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
    
    document.getElementById('date_to').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
    
    // Инициализация графика баланса
    <?php if (!empty($chart_labels)): ?>
    const ctx = document.getElementById('balanceChart').getContext('2d');
    const balanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'Накопленный баланс (шт)',
                data: <?php echo json_encode($chart_balance); ?>,
                borderColor: '#6495ed',
                backgroundColor: 'rgba(100, 149, 237, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#6495ed',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y;
                            const sign = value >= 0 ? '+' : '';
                            return 'Баланс: ' + sign + value.toLocaleString('ru-RU') + ' шт';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: 'Накопленный баланс (шт)',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString('ru-RU');
                        }
                    },
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return '#dc3545';
                            }
                            return 'rgba(0, 0, 0, 0.1)';
                        },
                        lineWidth: function(context) {
                            if (context.tick.value === 0) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    
    // Данные для детальных tooltip
    const planDetails = <?php echo json_encode($plan_details, JSON_UNESCAPED_UNICODE); ?>;
    const factDetails = <?php echo json_encode($fact_details, JSON_UNESCAPED_UNICODE); ?>;
    const chartDates = <?php echo json_encode($all_dates, JSON_UNESCAPED_UNICODE); ?>;
    
    // Инициализация графика плана
    const ctxPlan = document.getElementById('planChart').getContext('2d');
    const planChart = new Chart(ctxPlan, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'План потребления (шт)',
                data: <?php echo json_encode($chart_plan); ?>,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#f59e0b',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        title: function(context) {
                            const index = context[0].dataIndex;
                            const date = chartDates[index];
                            return 'Дата: ' + context[0].label;
                        },
                        label: function(context) {
                            const value = context.parsed.y;
                            const index = context.dataIndex;
                            const date = chartDates[index];
                            let tooltipText = 'План: ' + value.toLocaleString('ru-RU') + ' шт';
                            
                            if (planDetails[date] && Object.keys(planDetails[date]).length > 0) {
                                tooltipText += '\n\nДетали:';
                                const sortedFilters = Object.entries(planDetails[date])
                                    .sort((a, b) => b[1] - a[1]);
                                sortedFilters.forEach(([filter, qty]) => {
                                    tooltipText += '\n• ' + filter + ': ' + qty.toLocaleString('ru-RU') + ' фильтров';
                                });
                            }
                            
                            return tooltipText.split('\n');
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: 'Количество крышек (шт)',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString('ru-RU');
                        }
                    },
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return '#dc3545';
                            }
                            return 'rgba(0, 0, 0, 0.1)';
                        },
                        lineWidth: function(context) {
                            if (context.tick.value === 0) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    
    // Инициализация графика факта потребления
    const ctxFact = document.getElementById('factChart').getContext('2d');
    const factChart = new Chart(ctxFact, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'Факт потребления (шт)',
                data: <?php echo json_encode($chart_fact); ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        title: function(context) {
                            const index = context[0].dataIndex;
                            const date = chartDates[index];
                            return 'Дата: ' + context[0].label;
                        },
                        label: function(context) {
                            const value = context.parsed.y;
                            const index = context.dataIndex;
                            const date = chartDates[index];
                            let tooltipText = 'Факт: ' + value.toLocaleString('ru-RU') + ' шт';
                            
                            if (factDetails[date] && Object.keys(factDetails[date]).length > 0) {
                                tooltipText += '\n\nДетали:';
                                const sortedFilters = Object.entries(factDetails[date])
                                    .sort((a, b) => b[1] - a[1]);
                                sortedFilters.forEach(([filter, qty]) => {
                                    tooltipText += '\n• ' + filter + ': ' + qty.toLocaleString('ru-RU') + ' шт крышек';
                                });
                            }
                            
                            return tooltipText.split('\n');
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    title: {
                        display: true,
                        text: 'Количество крышек (шт)',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString('ru-RU');
                        }
                    },
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return '#dc3545';
                            }
                            return 'rgba(0, 0, 0, 0.1)';
                        },
                        lineWidth: function(context) {
                            if (context.tick.value === 0) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    
    // Инициализация графика дельты (разница между фактом и планом)
    const deltaData = <?php echo json_encode($chart_delta); ?>;
    // Разделяем данные на положительные (превышение) и отрицательные (недовыполнение)
    const deltaPositive = deltaData.map(val => val >= 0 ? val : null);
    const deltaNegative = deltaData.map(val => val < 0 ? val : null);
    
    const ctxDelta = document.getElementById('deltaChart').getContext('2d');
    const deltaChart = new Chart(ctxDelta, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
            datasets: [{
                label: 'Превышение плана',
                data: deltaPositive,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                spanGaps: true
            }, {
                label: 'Недовыполнение',
                data: deltaNegative,
                borderColor: '#dc2626',
                backgroundColor: 'rgba(220, 38, 38, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#dc2626',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                spanGaps: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 13
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    filter: function(tooltipItem) {
                        return tooltipItem.parsed.y !== null;
                    },
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.y;
                            if (value === null) return null;
                            const sign = value >= 0 ? '+' : '';
                            const interpretation = value > 0 ? ' (превышение плана)' : (value < 0 ? ' (недовыполнение)' : ' (выполнено точно)');
                            return context.dataset.label + ': ' + sign + value.toLocaleString('ru-RU') + ' шт' + interpretation;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Разница (факт - план, шт)',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        callback: function(value) {
                            const sign = value >= 0 ? '+' : '';
                            return sign + value.toLocaleString('ru-RU');
                        }
                    },
                    grid: {
                        color: function(context) {
                            if (context.tick.value === 0) {
                                return '#dc3545';
                            }
                            return 'rgba(0, 0, 0, 0.1)';
                        },
                        lineWidth: function(context) {
                            if (context.tick.value === 0) {
                                return 2;
                            }
                            return 1;
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Дата',
                        font: {
                            size: 12
                        }
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            },
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
