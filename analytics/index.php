<?php
/**
 * Страница аналитики по участкам
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/../auth/includes/integration.php';

// Авторизация
$user = getCurrentAuthUser();
if (!$user) {
    redirectToAuth();
}

// Доступ к аналитике только у директоров
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ? AND ud.is_active = 1
", [$user['user_id']]);
$isDirector = false;
foreach ($userDepartments as $d) {
    if ($d['role_name'] === 'director') {
        $isDirector = true;
        break;
    }
}
if (!$isDirector) {
    showAccessDenied('Доступ к странице аналитики имеют только директоры');
}

// Дата для отчёта: из GET или вчера по умолчанию
$reportDate = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d', strtotime('-1 day'));
} else {
    $dt = DateTime::createFromFormat('Y-m-d', $reportDate);
    if (!$dt || $dt->format('Y-m-d') !== $reportDate) {
        $reportDate = date('Y-m-d', strtotime('-1 day'));
    }
}

// Подключения к БД участков: код участка => путь к settings.php
$planSettingsPaths = [
    'U2' => __DIR__ . '/../plan/settings.php',
    'U3' => __DIR__ . '/../plan_U3/settings.php',
    'U4' => __DIR__ . '/../plan_U4/settings.php',
    'U5' => __DIR__ . '/../plan_U5/settings.php',
];

$statsYesterday = [];

foreach ($planSettingsPaths as $code => $settingsPath) {
    $statsYesterday[$code] = ['production' => 0, 'parts' => 0, 'cap_balance' => null];
    if (!is_file($settingsPath)) {
        continue;
    }
    $mysql_host = $mysql_user = $mysql_user_pass = $mysql_database = null;
    require $settingsPath;
    if (!isset($mysql_host, $mysql_database, $mysql_user, $mysql_user_pass)) {
        continue;
    }
    try {
        $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass ?? '', $mysql_database);
        if ($mysqli->connect_errno) {
            continue;
        }
        $mysqli->set_charset('utf8mb4');

        $stmt = $mysqli->prepare("SELECT COALESCE(SUM(count_of_filters), 0) AS total FROM manufactured_production WHERE date_of_production = ?");
        if ($stmt) {
            $stmt->bind_param('s', $reportDate);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $statsYesterday[$code]['production'] = (int) $row['total'];
            }
            $stmt->close();
        }

        // У5: выпуск по машинам 1 и 2 и проценты (как на plan_U5/main.php)
        if ($code === 'U5') {
            $check = $mysqli->query("SHOW COLUMNS FROM manufactured_production LIKE 'team'");
            if ($check && $check->num_rows > 0) {
                $stmt = $mysqli->prepare("SELECT COALESCE(SUM(count_of_filters), 0) AS total FROM manufactured_production WHERE date_of_production = ? AND team IN (1, 2)");
                if ($stmt) {
                    $stmt->bind_param('s', $reportDate);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $statsYesterday[$code]['machine1'] = (int) $row['total'];
                    }
                    $stmt->close();
                }
                $stmt = $mysqli->prepare("SELECT COALESCE(SUM(count_of_filters), 0) AS total FROM manufactured_production WHERE date_of_production = ? AND team IN (3, 4)");
                if ($stmt) {
                    $stmt->bind_param('s', $reportDate);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $statsYesterday[$code]['machine2'] = (int) $row['total'];
                    }
                    $stmt->close();
                }
                // Нормы для процентов (как в tools.php: SUM(count_of_filters / build_complexity) по машинам)
                $stmt = $mysqli->prepare("
                    SELECT mp.team,
                           SUM(mp.count_of_filters) AS count_of_filters,
                           COALESCE(MAX(sfs.build_complexity), 0) AS build_complexity
                    FROM manufactured_production mp
                    LEFT JOIN (SELECT filter, MAX(build_complexity) AS build_complexity FROM salon_filter_structure GROUP BY filter) sfs ON sfs.filter = mp.name_of_filter
                    WHERE mp.date_of_production = ? AND mp.team IN (1, 2, 3, 4)
                    GROUP BY mp.team, mp.name_of_filter, mp.name_of_order
                ");
                if ($stmt) {
                    $stmt->bind_param('s', $reportDate);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $norms_m1 = 0.0;
                    $norms_m2 = 0.0;
                    while ($row = $res->fetch_assoc()) {
                        $team = (int) $row['team'];
                        $cnt = (float) $row['count_of_filters'];
                        $bc = (float) $row['build_complexity'];
                        if ($bc > 0) {
                            if ($team <= 2) $norms_m1 += $cnt / $bc;
                            else $norms_m2 += $cnt / $bc;
                        }
                    }
                    $stmt->close();
                    $statsYesterday[$code]['machine1_pct'] = $norms_m1 > 0 ? (int) round($norms_m1 * 100) : 0;
                    $statsYesterday[$code]['machine2_pct'] = $norms_m2 > 0 ? (int) round($norms_m2 * 100) : 0;
                }
            }
        }

        // Гофропакеты: У2 и У5 — manufactured_corrugated_packages (count), У3/У4 — manufactured_parts
        if ($code === 'U2' || $code === 'U5') {
            $stmt = $mysqli->prepare("SELECT COALESCE(SUM(`count`), 0) AS total FROM manufactured_corrugated_packages WHERE date_of_production = ?");
            if ($stmt) {
                $stmt->bind_param('s', $reportDate);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $statsYesterday[$code]['parts'] = (int) $row['total'];
                }
                $stmt->close();
            }
        } else {
            $stmt = $mysqli->prepare("SELECT COALESCE(SUM(count_of_parts), 0) AS total FROM manufactured_parts WHERE date_of_production = ?");
            if ($stmt) {
                $stmt->bind_param('s', $reportDate);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $statsYesterday[$code]['parts'] = (int) $row['total'];
                }
                $stmt->close();
            }
        }

        // Баланс крышек, фильтры с крышками и данные для графика (только для У3)
        if ($code === 'U3') {
            $stmt = $mysqli->prepare("SELECT COALESCE(SUM(current_quantity), 0) AS total FROM cap_stock");
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $statsYesterday[$code]['cap_balance'] = (int) $row['total'];
                }
                $stmt->close();
            }
            // Фильтры с металлическими крышками за вчера (как в cap_balance_chart: только фильтры с up_cap/down_cap, не PU)
            $stmt = $mysqli->prepare("
                SELECT COALESCE(SUM(mp.count_of_filters), 0) AS total
                FROM manufactured_production mp
                JOIN round_filter_structure rfs ON TRIM(mp.name_of_filter) = TRIM(rfs.filter)
                WHERE mp.date_of_production = ?
                  AND (
                    (rfs.up_cap IS NOT NULL AND rfs.up_cap != '' AND (rfs.PU_up_cap IS NULL OR rfs.PU_up_cap = ''))
                    OR (rfs.down_cap IS NOT NULL AND rfs.down_cap != '' AND (rfs.PU_down_cap IS NULL OR rfs.PU_down_cap = ''))
                  )
            ");
            if ($stmt) {
                $stmt->bind_param('s', $reportDate);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $statsYesterday[$code]['filters_with_caps'] = (int) $row['total'];
                }
                $stmt->close();
            }
            // Данные для графика «Накопленный баланс крышек» (как в cap_balance_chart.php)
            $chart_date_from = date('Y-m-d', strtotime($reportDate . ' -30 days'));
            $chart_date_to = $reportDate;
            $income_by_date = [];
            $stmt = $mysqli->prepare("
                SELECT date, SUM(quantity) AS total_pieces
                FROM cap_movements
                WHERE operation_type = 'INCOME' AND date BETWEEN ? AND ?
                GROUP BY date
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $chart_date_from, $chart_date_to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $income_by_date[$row['date']] = (int) $row['total_pieces'];
                }
                $stmt->close();
            }
            $production_by_date = [];
            $stmt = $mysqli->prepare("
                SELECT date, SUM(quantity) AS total_pieces
                FROM cap_movements
                WHERE operation_type = 'PRODUCTION_OUT' AND date BETWEEN ? AND ?
                GROUP BY date
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $chart_date_from, $chart_date_to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $production_by_date[$row['date']] = (int) $row['total_pieces'];
                }
                $stmt->close();
            }
            $all_dates = [];
            $d = new DateTime($chart_date_from);
            $end = new DateTime($chart_date_to);
            $end->modify('+1 day');
            while ($d < $end) {
                $all_dates[] = $d->format('Y-m-d');
                $d->modify('+1 day');
            }
            sort($all_dates);
            $chart_labels = [];
            $chart_balance = [];
            $cumulative = 0;
            foreach ($all_dates as $date) {
                $inc = $income_by_date[$date] ?? 0;
                $prod = $production_by_date[$date] ?? 0;
                $cumulative += ($inc - $prod);
                $chart_labels[] = date('d.m', strtotime($date));
                $chart_balance[] = $cumulative;
            }
            $statsYesterday[$code]['cap_chart_labels'] = $chart_labels;
            $statsYesterday[$code]['cap_chart_balance'] = $chart_balance;

            // Выпуск фильтров с металлическими крышками по дням (для графика)
            $caps_output_by_date = [];
            $stmt = $mysqli->prepare("
                SELECT mp.date_of_production AS d, COALESCE(SUM(mp.count_of_filters), 0) AS total
                FROM manufactured_production mp
                JOIN round_filter_structure rfs ON TRIM(mp.name_of_filter) = TRIM(rfs.filter)
                WHERE mp.date_of_production BETWEEN ? AND ?
                  AND (
                    (rfs.up_cap IS NOT NULL AND rfs.up_cap != '' AND (rfs.PU_up_cap IS NULL OR rfs.PU_up_cap = ''))
                    OR (rfs.down_cap IS NOT NULL AND rfs.down_cap != '' AND (rfs.PU_down_cap IS NULL OR rfs.PU_down_cap = ''))
                  )
                GROUP BY mp.date_of_production
            ");
            if ($stmt) {
                $stmt->bind_param('ss', $chart_date_from, $chart_date_to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $caps_output_by_date[$row['d']] = (int) $row['total'];
                }
                $stmt->close();
            }
            $caps_output_data = [];
            foreach ($all_dates as $date) {
                $caps_output_data[] = $caps_output_by_date[$date] ?? 0;
            }
            $statsYesterday[$code]['caps_output_chart_labels'] = $chart_labels;
            $statsYesterday[$code]['caps_output_chart_data'] = $caps_output_data;
        }

        $mysqli->close();
    } catch (Throwable $e) {
        // оставляем нули для участка
    }
}

// Список участков, для которых есть системы планирования
$departments = [
    'U2' => [
        'name' => 'Участок 2',
        'plan_main' => '/plan/main.php',
        'orders' => '/plan/archived_orders.php',
        'plan' => '/plan/production_plans.php',
    ],
    'U3' => [
        'name' => 'Участок 3',
        'plan_main' => '/plan_U3/main.php',
        'orders' => '/plan_U3/archived_orders.php',
        'plan' => '/plan_U3/production_plans.php',
        'summary' => '/plan_U3/summary_plan_U3.php',
    ],
    'U4' => [
        'name' => 'Участок 4',
        'plan_main' => '/plan_U4/main.php',
        'orders' => '/plan_U4/archived_orders.php',
        'plan' => null, // нет отдельной страницы плана
    ],
    'U5' => [
        'name' => 'Участок 5',
        'plan_main' => '/plan_U5/main.php',
        'orders' => '/plan_U5/archived_orders.php',
        'plan' => '/plan_U5/production_plans.php',
        'mobile' => '/plan_U5/mobile_build_plan.php',
        'buffer' => '/plan_U5/buffer_stock.php',
    ],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аналитика по участкам</title>
    <link rel="stylesheet" href="/auth/assets/css/auth.css">
    <style>
        body {
            background: var(--gray-50);
            font-family: Arial, sans-serif;
        }
        .analytics-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .analytics-header {
            background: #fff;
            padding: 20px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .analytics-date-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .analytics-date-label {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 500;
        }
        .analytics-date-input {
            font-size: 14px;
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            color: var(--gray-800);
        }
        .analytics-date-input:focus {
            outline: none;
            border-color: var(--primary, #2563eb);
        }
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        .dept-card {
            background: #fff;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .dept-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dept-code {
            font-size: 13px;
            color: var(--gray-500);
        }
        .breadcrumbs {
            font-size: 13px;
            color: var(--gray-500);
            margin-bottom: 6px;
        }
        .breadcrumbs a {
            color: var(--primary);
            text-decoration: none;
        }
        .breadcrumbs a:hover {
            text-decoration: underline;
        }
        .dept-yesterday {
            font-size: 13px;
            color: var(--gray-700);
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--gray-100);
        }
        .dept-yesterday span {
            display: block;
            margin-bottom: 2px;
        }
        .production-machine-link {
            cursor: pointer;
            color: var(--primary, #2563eb);
            text-decoration: underline;
        }
        .production-machine-link:hover {
            color: var(--primary-hover, #1d4ed8);
        }
        .production-stat-u5 {
            margin-bottom: 8px;
        }
        .production-stat-u5-title {
            display: block;
            font-size: 13px;
            color: var(--gray-700);
            font-weight: 600;
            margin-bottom: 4px;
        }
        .production-stat-u5-machines {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .production-stat-u5-machine {
            padding: 0;
        }
        .production-stat-u5-machine-name {
            display: block;
            font-size: 13px;
            color: var(--gray-500);
            font-weight: normal;
            margin-bottom: 2px;
        }
        .production-stat-u5-machine-row {
            display: flex;
            flex-direction: row;
            align-items: baseline;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .production-stat-u5-machine-value {
            font-size: 13px;
            font-weight: normal;
            color: var(--gray-700);
            line-height: 1.3;
        }
        .production-stat-u5-machine-unit {
            font-size: 13px;
            color: var(--gray-700);
            margin-left: 0;
        }
        .production-stat-u5-machine-pct {
            display: inline-block;
            font-size: 13px;
            color: var(--gray-700);
            cursor: pointer;
            text-decoration: none;
        }
        .production-stat-u5-machine-pct:hover {
            text-decoration: underline;
        }
        .cap-chart-block {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--gray-100);
        }
        .cap-chart-title {
            font-size: 11px;
            color: var(--gray-600);
            margin-bottom: 6px;
            line-height: 1.2;
        }
        .cap-chart-wrap {
            position: relative;
            height: 70px;
            width: 100%;
        }
        @media (max-width: 768px) {
            .analytics-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .analytics-grid {
                grid-template-columns: 1fr;
            }
            .production-stat-u5-machines {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="analytics-container">
        <div class="analytics-header">
            <div>
                <div class="breadcrumbs">
                    <a href="/">Главная</a> / Аналитика по участкам
                </div>
                <h1 style="margin: 0; font-size: 22px;">Аналитика по участкам</h1>
            </div>
            <form method="get" action="" class="analytics-date-form" id="analyticsDateForm">
                <label for="analyticsDate" class="analytics-date-label">Дата:</label>
                <input type="date" id="analyticsDate" name="date" value="<?= htmlspecialchars($reportDate) ?>" class="analytics-date-input" max="<?= date('Y-m-d') ?>" onchange="document.getElementById('analyticsDateForm').submit()">
            </form>
        </div>

        <div class="analytics-grid">
            <?php foreach ($departments as $code => $dept): ?>
                <div class="dept-card">
                    <div class="dept-header">
                        <div>
                            <div style="font-weight: 600; font-size: 15px;"><?= htmlspecialchars($dept['name']) ?></div>
                            <div class="dept-code"><?= htmlspecialchars($code) ?></div>
                        </div>
                    </div>

                    <?php
                    $deptProd = $statsYesterday[$code]['production'] ?? 0;
                    $deptParts = $statsYesterday[$code]['parts'] ?? 0;
                    $capBalance = $statsYesterday[$code]['cap_balance'] ?? null;
                    $capChartLabels = $statsYesterday[$code]['cap_chart_labels'] ?? null;
                    $capChartBalance = $statsYesterday[$code]['cap_chart_balance'] ?? null;
                    $capsOutputChartLabels = $statsYesterday[$code]['caps_output_chart_labels'] ?? null;
                    $capsOutputChartData = $statsYesterday[$code]['caps_output_chart_data'] ?? null;
                    $machine1 = $statsYesterday[$code]['machine1'] ?? null;
                    $machine2 = $statsYesterday[$code]['machine2'] ?? null;
                    $machine1Pct = $statsYesterday[$code]['machine1_pct'] ?? null;
                    $machine2Pct = $statsYesterday[$code]['machine2_pct'] ?? null;
                    $hasMachines = ($code === 'U5' && isset($statsYesterday[$code]['machine1']) && isset($statsYesterday[$code]['machine2']));
                    ?>
                    <div class="dept-yesterday">
                        <?php if ($hasMachines): ?>
                        <div class="production-stat-u5">
                            <span class="production-stat-u5-title">За вчера:</span>
                            <div class="production-stat-u5-machines">
                                <div class="production-stat-u5-machine">
                                    <span class="production-stat-u5-machine-name">1</span>
                                    <div class="production-stat-u5-machine-row">
                                        <div class="production-stat-u5-machine-value"><span class="production-machine-link" data-teams="1,2" data-machine="1" title="Подробнее"><?= number_format($machine1, 0, ',', ' ') ?></span><span class="production-stat-u5-machine-unit"> шт.</span></div>
                                        <span class="production-stat-u5-machine-pct production-machine-link" data-teams="1,2" data-machine="1" title="Подробнее"><?= $machine1Pct !== null ? (int)$machine1Pct : '0' ?>%</span>
                                    </div>
                                </div>
                                <div class="production-stat-u5-machine">
                                    <span class="production-stat-u5-machine-name">2</span>
                                    <div class="production-stat-u5-machine-row">
                                        <div class="production-stat-u5-machine-value"><span class="production-machine-link" data-teams="3,4" data-machine="2" title="Подробнее"><?= number_format($machine2, 0, ',', ' ') ?></span><span class="production-stat-u5-machine-unit"> шт.</span></div>
                                        <span class="production-stat-u5-machine-pct production-machine-link" data-teams="3,4" data-machine="2" title="Подробнее"><?= $machine2Pct !== null ? (int)$machine2Pct : '0' ?>%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <span><strong>За вчера:</strong> продукция — <?= number_format($deptProd, 0, ',', ' ') ?> шт.</span>
                        <?php endif; ?>
                        <span>гофропакеты — <?= number_format($deptParts, 0, ',', ' ') ?> шт.</span>
                        <?php if ($code === 'U3'): ?>
                        <?php $filtersWithCaps = $statsYesterday[$code]['filters_with_caps'] ?? null; ?>
                        <span>Фильтры с крышками — <?= $filtersWithCaps !== null ? number_format($filtersWithCaps, 0, ',', ' ') . ' шт.' : '—' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($code === 'U3' && !empty($capChartLabels) && !empty($capChartBalance)): ?>
                    <div class="cap-chart-block">
                        <div class="cap-chart-title">Изменение склада крышек (накопленный баланс, шт.)</div>
                        <div class="cap-chart-wrap">
                            <canvas id="cap-balance-chart-u3"></canvas>
                        </div>
                        <script>
                        (function(){
                            var labels = <?= json_encode($capChartLabels, JSON_UNESCAPED_UNICODE) ?>;
                            var data = <?= json_encode($capChartBalance) ?>;
                            var el = document.getElementById('cap-balance-chart-u3');
                            if (!el || typeof Chart === 'undefined') return;
                            new Chart(el.getContext('2d'), {
                                type: 'line',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Накопленный баланс',
                                        data: data,
                                        borderColor: '#6495ed',
                                        backgroundColor: 'rgba(100, 149, 237, 0.15)',
                                        borderWidth: 1.5,
                                        fill: true,
                                        tension: 0.4,
                                        pointRadius: 0,
                                        pointHoverRadius: 3
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            callbacks: {
                                                label: function(ctx) {
                                                    var v = ctx.parsed.y;
                                                    return (v >= 0 ? '+' : '') + v.toLocaleString('ru-RU') + ' шт';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            display: true,
                                            ticks: { maxTicksLimit: 6, font: { size: 9 } }
                                        },
                                        y: {
                                            display: true,
                                            ticks: { font: { size: 9 } }
                                        }
                                    }
                                }
                            });
                        })();
                        </script>
                    </div>
                    <?php endif; ?>
                    <?php if ($code === 'U3' && !empty($capsOutputChartLabels) && isset($capsOutputChartData)): ?>
                    <div class="cap-chart-block">
                        <div class="cap-chart-title">Выпуск фильтров с металлическими крышками (шт.)</div>
                        <div class="cap-chart-wrap">
                            <canvas id="caps-output-chart-u3"></canvas>
                        </div>
                        <script>
                        (function(){
                            var labels = <?= json_encode($capsOutputChartLabels, JSON_UNESCAPED_UNICODE) ?>;
                            var data = <?= json_encode($capsOutputChartData) ?>;
                            var el = document.getElementById('caps-output-chart-u3');
                            if (!el || typeof Chart === 'undefined') return;
                            new Chart(el.getContext('2d'), {
                                type: 'line',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Выпуск, шт.',
                                        data: data,
                                        borderColor: '#22c55e',
                                        backgroundColor: 'rgba(34, 197, 94, 0.15)',
                                        borderWidth: 1.5,
                                        fill: true,
                                        tension: 0.4,
                                        pointRadius: 0,
                                        pointHoverRadius: 3
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            callbacks: {
                                                label: function(ctx) {
                                                    var v = ctx.parsed.y;
                                                    return v.toLocaleString('ru-RU') + ' шт';
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            display: true,
                                            ticks: { maxTicksLimit: 6, font: { size: 9 } }
                                        },
                                        y: {
                                            display: true,
                                            ticks: { font: { size: 9 } }
                                        }
                                    }
                                }
                            });
                        })();
                        </script>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Модалка подробностей выпуска У5 (машина 1/2) -->
    <div id="productionDetailModal" class="production-modal" style="display: none;">
        <div class="production-modal-backdrop"></div>
        <div class="production-modal-content">
            <button type="button" class="production-modal-close" id="productionModalClose">&times;</button>
            <h3 class="production-modal-title" id="productionModalTitle">Подробности выпуска</h3>
            <div class="production-modal-body" id="productionModalContent">Загрузка...</div>
        </div>
    </div>
    <style>
        .production-modal { position: fixed; left: 0; top: 0; width: 100%; height: 100%; z-index: 10000; justify-content: center; align-items: center; }
        .production-modal-backdrop { position: absolute; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .production-modal-content { position: relative; background: #fff; padding: 20px; border-radius: 12px; max-width: 90%; width: 800px; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .production-modal-close { position: absolute; right: 12px; top: 12px; background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer; line-height: 1; padding: 0; }
        .production-modal-close:hover { color: #111; }
        .production-modal-title { margin: 0 0 16px; font-size: 16px; color: #111; }
        .production-modal-body { overflow-y: auto; font-size: 13px; }
        #productionDetailModal table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        #productionDetailModal th { background: #f3f4f6; padding: 8px; text-align: left; border: 1px solid #e5e7eb; font-weight: 600; font-size: 12px; }
        #productionDetailModal td { padding: 8px; border: 1px solid #e5e7eb; }
        #productionDetailModal tr:nth-child(even) { background: #f9fafb; }
        .production-summary { background: #eff6ff; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
    </style>
    <script>
    (function() {
        var reportDate = <?= json_encode($reportDate) ?>;
        var dateDisplay = <?= json_encode(date('d.m.Y', strtotime($reportDate))) ?>;
        var modal = document.getElementById('productionDetailModal');
        var titleEl = document.getElementById('productionModalTitle');
        var contentEl = document.getElementById('productionModalContent');
        var closeBtn = document.getElementById('productionModalClose');

        function openModal() {
            modal.style.display = 'flex';
        }
        function closeModal() {
            modal.style.display = 'none';
        }

        document.querySelectorAll('.production-machine-link').forEach(function(link) {
            link.addEventListener('click', function() {
                var teams = this.getAttribute('data-teams');
                var machine = this.getAttribute('data-machine');
                titleEl.textContent = 'Выпуск продукции — Машина ' + machine + ' (' + dateDisplay + ')';
                contentEl.innerHTML = '<div style="text-align:center; padding:24px;">Загрузка...</div>';
                openModal();
                fetch('/plan_U5/get_team_percentage_details.php?date=' + encodeURIComponent(reportDate) + '&teams=' + encodeURIComponent(teams))
                    .then(function(r) { return r.ok ? r.json() : Promise.reject(new Error(r.status)); })
                    .then(function(data) {
                        if (data.error) {
                            contentEl.innerHTML = '<p style="color:#dc2626;">' + (data.error || 'Ошибка') + '</p>';
                            return;
                        }
                        if (!data.items || data.items.length === 0) {
                            contentEl.innerHTML = '<p style="color:#6b7280;">Нет данных за выбранную дату.</p>';
                            return;
                        }
                        var html = '<div class="production-summary"><strong>Итого:</strong> ' + (data.total_count || 0) + ' шт. | <strong>Сумма норм:</strong> ' + (data.norms_sum != null ? Number(data.norms_sum).toFixed(3) : '0') + ' | <strong>Процент выполнения:</strong> ' + (data.percentage != null ? data.percentage : 0) + '%</div>';
                        html += '<table><thead><tr><th>Фильтр</th><th>Заявка</th><th>Изготовлено</th><th>Норма (шт/смену)</th><th>Норм</th><th>% выполнения</th><th>Изготовлено по заявке</th></tr></thead><tbody>';
                        data.items.forEach(function(it) {
                            var norm = (it.build_complexity > 0) ? (1 / it.build_complexity).toFixed(2) : '—';
                            html += '<tr><td>' + (it.filter_name || '') + '</td><td>' + (it.order_number || '') + '</td><td>' + (it.count || 0) + '</td><td>' + norm + '</td><td>' + (it.norms != null ? Number(it.norms).toFixed(3) : '—') + '</td><td>' + (it.item_percentage != null ? it.item_percentage : 0) + '%</td><td>' + (it.produced_in_order != null ? it.produced_in_order : 0) + ' / ' + (it.ordered_in_order != null ? it.ordered_in_order : 0) + '</td></tr>';
                        });
                        html += '</tbody></table>';
                        contentEl.innerHTML = html;
                    })
                    .catch(function(err) {
                        contentEl.innerHTML = '<p style="color:#dc2626;">Ошибка загрузки: ' + (err.message || 'нет связи') + '</p>';
                    });
            });
        });

        closeBtn.addEventListener('click', closeModal);
        modal.querySelector('.production-modal-backdrop').addEventListener('click', closeModal);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
    })();
    </script>
</body>
</html>

