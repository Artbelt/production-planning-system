<?php
/**
 * Сравнение логики двух экранов за один период:
 * - product_output_view.php → show_manufactured_filters_more.php: всегда SUM(count_of_filters)
 * - salary_report_monthly.php → generate_monthly_salary_report.php: в «ИТОГО» для почасового тарифа суммируются ЧАСЫ, не штуки
 *
 * CLI:  php compare_salary_report_vs_product_output.php 2026-04-01 2026-04-15
 * WEB:  .../compare_salary_report_vs_product_output.php?start=2026-04-01&end=2026-04-15
 */
declare(strict_types=1);

require_once __DIR__ . '/tools/tools.php';
require_once __DIR__ . '/tools/ensure_salary_warehouse_tables.php';

$isCli = PHP_SAPI === 'cli';
if ($isCli) {
    $start = $argv[1] ?? '2026-04-01';
    $end = $argv[2] ?? '2026-04-15';
} else {
    header('Content-Type: text/html; charset=utf-8');
    $start = $_GET['start'] ?? '2026-04-01';
    $end = $_GET['end'] ?? '2026-04-15';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $msg = 'Укажите даты в формате YYYY-MM-DD.';
    echo $isCli ? ($msg . PHP_EOL) : '<p>' . htmlspecialchars($msg) . '</p>';
    exit(1);
}

$hours_raw = mysql_execute(
    "SELECT filter, order_number, date_of_work, hours FROM hourly_work_log WHERE date_of_work BETWEEN '$start' AND '$end'"
);
if ($hours_raw instanceof mysqli_result) {
    $hours_raw = iterator_to_array($hours_raw);
}
$hours_map = [];
foreach ($hours_raw as $h) {
    $date_work = date('Y-m-d', strtotime($h['date_of_work']));
    $key = $h['filter'] . '_' . $h['order_number'] . '_' . $date_work;
    $hours_map[$key] = (float) $h['hours'];
}

$sql = "
    SELECT 
        mp.date_of_production,
        mp.name_of_filter,
        mp.count_of_filters,
        mp.name_of_order,
        mp.team,
        st.tariff_name,
        st.type AS tariff_type
    FROM manufactured_production mp
    LEFT JOIN (
        SELECT 
            filter,
            MAX(paper_package) AS paper_package,
            MAX(tariff_id) AS tariff_id
        FROM salon_filter_structure
        GROUP BY filter
    ) sfs ON sfs.filter = mp.name_of_filter
    LEFT JOIN salary_tariffs st ON st.id = sfs.tariff_id
    WHERE mp.date_of_production BETWEEN '$start' AND '$end'
    ORDER BY mp.date_of_production, mp.team, mp.name_of_filter
";

$res = mysql_execute($sql);
if ($res instanceof mysqli_result) {
    $rows = iterator_to_array($res);
} else {
    $rows = [];
}

$sumPiecesProductView = 0;
$sumSalaryReportDisplay = 0;
$sumPiecesNonHourly = 0;
$sumPiecesHourlyTariff = 0;
$sumHoursUsed = 0.0;
$hourlyRows = 0;
$hourlyRowsMissingHours = [];

foreach ($rows as $row) {
    $count = (int) $row['count_of_filters'];
    $sumPiecesProductView += $count;

    $tariffName = mb_strtolower(trim($row['tariff_name'] ?? ''));
    $isHourly = $tariffName === 'почасовый';

    $date = date('Y-m-d', strtotime($row['date_of_production']));
    $key = $row['name_of_filter'] . '_' . $row['name_of_order'] . '_' . $date;
    $hours = $isHourly ? ($hours_map[$key] ?? 0.0) : 0.0;

    if ($isHourly) {
        $hourlyRows++;
        $sumPiecesHourlyTariff += $count;
        $sumHoursUsed += $hours;
        $sumSalaryReportDisplay += $hours;
        if ($hours <= 0 && $count > 0) {
            $hourlyRowsMissingHours[] = [
                'date' => $date,
                'filter' => $row['name_of_filter'],
                'order' => $row['name_of_order'],
                'pieces' => $count,
            ];
        }
    } else {
        $sumPiecesNonHourly += $count;
        $sumSalaryReportDisplay += $count;
    }
}

$sumSql = mysql_execute(
    "SELECT COALESCE(SUM(count_of_filters), 0) AS t FROM manufactured_production     WHERE date_of_production BETWEEN '$start' AND '$end'"
);
$sumDb = 0;
if ($sumSql instanceof mysqli_result) {
    $r = $sumSql->fetch_assoc();
    $sumDb = (int) ($r['t'] ?? 0);
}

$diff = $sumSalaryReportDisplay - $sumPiecesProductView;

$lines = [];
$lines[] = '=== Сравнение: отчёт ЗП (как в ИТОГО строке) vs обзор выпуска ===';
$lines[] = "Период: $start — $end";
$lines[] = '';
$lines[] = 'Обзор выпуска (product_output): SUM(count_of_filters) = ' . $sumPiecesProductView;
$lines[] = 'Проверка тем же SQL SUM в БД:                        ' . $sumDb;
$lines[] = '';
$lines[] = 'Отчёт ЗП: сумма «отображаемых единиц» по строкам как в generate_monthly_salary_report.php:';
$lines[] = '  • не почасовый тариф → + count_of_filters (шт)';
$lines[] = '  • почасовый тариф     → + hours из hourly_work_log (часы, не штуки)';
$lines[] = 'Итого (как нижняя строка «ИТОГО» в зарплатном отчёте):     ' . $sumSalaryReportDisplay;
$lines[] = '';
$lines[] = '--- Детализация по почасовому тарифу ---';
$lines[] = 'Строк manufactured_production с тарифом «почасовый»: ' . $hourlyRows;
$lines[] = 'Штук (count_of_filters) на этих строках:             ' . $sumPiecesHourlyTariff;
$lines[] = 'Сумма часов, попавших в отчёт ЗП:                    ' . $sumHoursUsed;
$lines[] = 'Штук на не-почасовых строках:                        ' . $sumPiecesNonHourly;
$lines[] = '';
$lines[] = 'Разница (отчёт ЗП − обзор выпуска): ' . $diff;
$lines[] = '';
if ($diff !== 0.0) {
    $lines[] = 'Вывод: цифры расходятся, если есть почасовой тариф: в зарплатном отчёте в итог попадают ЧАСЫ,';
    $lines[] = 'а в обзоре выпуска — только ШТУКИ. Подпись «ИТОГО (шт)» в отчёте ЗП для таких строк некорректна.';
} else {
    $lines[] = 'За период суммы совпали (нет почасовых или часы в сумме равны штукам — редкий случай).';
}
$lines[] = '';
if ($hourlyRowsMissingHours !== []) {
    $lines[] = '--- Внимание: почасовые строки без часов в hourly_work_log (в отчёте будет 0): ---';
    foreach (array_slice($hourlyRowsMissingHours, 0, 30) as $item) {
        $lines[] = sprintf(
            '  %s | %s | заявка %s | шт %d',
            $item['date'],
            $item['filter'],
            $item['order'],
            $item['pieces']
        );
    }
    if (count($hourlyRowsMissingHours) > 30) {
        $lines[] = '  ... ещё ' . (count($hourlyRowsMissingHours) - 30) . ' строк(и)';
    }
}

$text = implode(PHP_EOL, $lines) . PHP_EOL;

if ($isCli) {
    echo $text;
} else {
    echo '<pre style="font:13px/1.4 Consolas,monospace;white-space:pre-wrap;">';
    echo htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '</pre>';
    echo '<p class="muted">Параметры: <code>?start=YYYY-MM-DD&amp;end=YYYY-MM-DD</code></p>';
}
