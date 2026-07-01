<?php
/** Определение типа машины гофрирования по высоте ребра гофропакета. */

function gofroNormalizePackageKey(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name));
    return mb_strtoupper($name, 'UTF-8');
}

function gofroMachineTypeFromFoldHeight(?float $foldHeight): string
{
    $ribHeightRounded = (int)round((float)($foldHeight ?? 0));
    return in_array($ribHeightRounded, [23, 29, 36, 40, 45, 55], true) ? 'rotary' : 'knife';
}

function loadGofroFoldHeightByPackage(PDO $pdo): array
{
    $map = [];
    try {
        $stmt = $pdo->query('SELECT p_p_name, p_p_fold_height FROM paper_package_round');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = gofroNormalizePackageKey((string)($row['p_p_name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $map[$key] = (float)($row['p_p_fold_height'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

function gofroMachineTypeForPackage(string $packageName, array $foldHeightMap): string
{
    $key = gofroNormalizePackageKey($packageName);
    return gofroMachineTypeFromFoldHeight($foldHeightMap[$key] ?? null);
}

function gofroMachineShortLabel(string $machineType): string
{
    return $machineType === 'rotary' ? 'Р' : 'Н';
}

function gofroMachineTitle(string $machineType): string
{
    return $machineType === 'rotary' ? 'Ротационная машина' : 'Ножевая машина';
}

function renderGofroMachineTotalsSummary(int $knifeTotal, int $rotaryTotal, int $grandTotal): string
{
    $html = <<<'CSS'
<style>
.gofro-machine-totals{
    margin:0 0 14px;
    padding:12px 14px;
    border:1px solid #e5e7eb;
    border-radius:8px;
    background:#f8fafc;
    font:14px/1.4 "Segoe UI",Roboto,Arial,sans-serif;
}
.gofro-machine-totals__title{
    margin:0 0 10px;
    padding-bottom:8px;
    border-bottom:1px solid #e5e7eb;
    font-size:13px;
    font-weight:600;
    color:#374151;
}
.gofro-machine-totals__grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:0;
}
.gofro-machine-totals__item{
    padding:4px 16px;
    text-align:center;
}
.gofro-machine-totals__item:not(:last-child){
    border-right:1px solid #e5e7eb;
}
.gofro-machine-totals__label{
    display:block;
    margin-bottom:6px;
    font-size:12px;
    color:#6b7280;
}
.gofro-machine-totals__value{
    display:block;
    font-size:20px;
    font-weight:700;
    font-variant-numeric:tabular-nums;
    color:#111827;
}
.gofro-machine-totals__item--knife .gofro-machine-totals__value{color:#15803d}
.gofro-machine-totals__item--rotary .gofro-machine-totals__value{color:#1d4ed8}
.gofro-machine-totals__unit{
    margin-left:4px;
    font-size:13px;
    font-weight:500;
    color:#9ca3af;
}
@media (max-width:768px){
    .gofro-machine-totals__grid{grid-template-columns:1fr}
    .gofro-machine-totals__item{
        padding:8px 0;
        text-align:left;
    }
    .gofro-machine-totals__item:not(:last-child){
        border-right:none;
        border-bottom:1px solid #e5e7eb;
    }
}
</style>
CSS;

    $html .= '<div class="gofro-machine-totals">';
    $html .= '<div class="gofro-machine-totals__title">Итого по машинам</div>';
    $html .= '<div class="gofro-machine-totals__grid">';

    $html .= '<div class="gofro-machine-totals__item gofro-machine-totals__item--knife">';
    $html .= '<span class="gofro-machine-totals__label">Ножевая</span>';
    $html .= '<span class="gofro-machine-totals__value">' . (int)$knifeTotal . '<span class="gofro-machine-totals__unit">шт.</span></span>';
    $html .= '</div>';

    $html .= '<div class="gofro-machine-totals__item gofro-machine-totals__item--rotary">';
    $html .= '<span class="gofro-machine-totals__label">Ротационная</span>';
    $html .= '<span class="gofro-machine-totals__value">' . (int)$rotaryTotal . '<span class="gofro-machine-totals__unit">шт.</span></span>';
    $html .= '</div>';

    $html .= '<div class="gofro-machine-totals__item gofro-machine-totals__item--total">';
    $html .= '<span class="gofro-machine-totals__label">Всего</span>';
    $html .= '<span class="gofro-machine-totals__value">' . (int)$grandTotal . '<span class="gofro-machine-totals__unit">шт.</span></span>';
    $html .= '</div>';

    $html .= '</div></div>';
    return $html;
}

/**
 * @return array<string, array{knife: int, rotary: int}>
 */
function loadGofroDailyMachineTotals(PDO $pdo, string $startDate, string $endDate): array
{
    $sql = "
        SELECT
            date_of_production,
            name_of_parts,
            SUM(COALESCE(count_of_parts, 0)) AS qty
        FROM manufactured_parts
        WHERE date_of_production BETWEEN :start AND :end
          AND COALESCE(count_of_parts, 0) > 0
        GROUP BY date_of_production, name_of_parts
        ORDER BY date_of_production
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);

    $foldHeightMap = loadGofroFoldHeightByPackage($pdo);
    $totals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string)($row['date_of_production'] ?? '');
        if ($date === '') {
            continue;
        }
        $qty = (int)($row['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if (!isset($totals[$date])) {
            $totals[$date] = ['knife' => 0, 'rotary' => 0];
        }
        $machineType = gofroMachineTypeForPackage((string)($row['name_of_parts'] ?? ''), $foldHeightMap);
        if ($machineType === 'rotary') {
            $totals[$date]['rotary'] += $qty;
        } else {
            $totals[$date]['knife'] += $qty;
        }
    }
    return $totals;
}

function gofroRussianMonthName(int $month): string
{
    static $names = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
        5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
        9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];
    return $names[$month] ?? (string)$month;
}

function renderGofroHalfYearCalendar(PDO $pdo): string
{
    $today = new DateTime('today');
    $endDate = $today->format('Y-m-d');
    $start = (clone $today)->modify('first day of -5 months');
    $startDate = $start->format('Y-m-d');

    $dailyTotals = loadGofroDailyMachineTotals($pdo, $startDate, $endDate);

    $periodKnife = 0;
    $periodRotary = 0;
    foreach ($dailyTotals as $dayTotals) {
        $periodKnife += (int)$dayTotals['knife'];
        $periodRotary += (int)$dayTotals['rotary'];
    }

    $html = <<<'CSS'
<style>
.gofro-cal-report{margin:0 0 8px;font:14px/1.4 "Segoe UI",Roboto,Arial,sans-serif}
.gofro-cal-report__head{
    display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px 16px;
    margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;
}
.gofro-cal-report__title{margin:0;font-size:15px;font-weight:600;color:#111827}
.gofro-cal-report__range{font-size:12px;color:#6b7280}
.gofro-cal-report__legend{font-size:12px;color:#6b7280}
.gofro-cal-report__legend span{margin-left:10px}
.gofro-cal-report__legend .n{color:#15803d;font-weight:600}
.gofro-cal-report__legend .r{color:#1d4ed8;font-weight:600}
.gofro-cal-months{
    display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;
}
.gofro-cal-month{
    border:1px solid #e5e7eb;border-radius:8px;background:#fff;overflow:hidden;
}
.gofro-cal-month__title{
    padding:8px 10px;background:#f8fafc;border-bottom:1px solid #e5e7eb;
    font-size:13px;font-weight:600;color:#374151;text-align:center;
}
.gofro-cal-grid{
    display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#e5e7eb;
}
.gofro-cal-grid__wd{
    padding:4px 2px;background:#f8fafc;font-size:10px;font-weight:600;
    color:#9ca3af;text-align:center;
}
.gofro-cal-day{
    min-height:52px;padding:3px 2px;background:#fff;text-align:center;
    font-variant-numeric:tabular-nums;
}
.gofro-cal-day--empty{background:#fafafa}
.gofro-cal-day--future{background:#fafafa;opacity:.55}
.gofro-cal-day__num{
    display:block;margin-bottom:2px;font-size:11px;font-weight:600;color:#6b7280;line-height:1;
}
.gofro-cal-day__n,.gofro-cal-day__r{
    display:block;font-size:11px;font-weight:700;line-height:1.25;
}
.gofro-cal-day__n{color:#15803d}
.gofro-cal-day__r{color:#1d4ed8}
.gofro-cal-day__dash{color:#d1d5db;font-weight:500}
.gofro-cal-day--has-data{background:#fcfdff}
.gofro-cal-report__actions{
    display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 10px;
}
.gofro-cal-report__print{
    appearance:none;border:1px solid #d1d5db;background:#fff;color:#374151;
    padding:5px 12px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;
}
.gofro-cal-report__print:hover{background:#f9fafb;border-color:#9ca3af}
@media (max-width:768px){
    .gofro-cal-months{grid-template-columns:1fr}
}
</style>
CSS;

    $startLabel = $start->format('d.m.Y');
    $endLabel = $today->format('d.m.Y');

    $html .= '<div class="gofro-cal-report">';
    $html .= '<div class="gofro-cal-report__head">';
    $html .= '<h3 class="gofro-cal-report__title">Календарь выпуска за полгода</h3>';
    $html .= '<div class="gofro-cal-report__range">' . htmlspecialchars($startLabel, ENT_QUOTES, 'UTF-8')
        . ' — ' . htmlspecialchars($endLabel, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '</div>';
    $html .= '<div class="gofro-cal-report__actions no-print">';
    $html .= '<button type="button" class="gofro-cal-report__print" onclick="printGofroCalendar()">🖨 Печать календаря</button>';
    $html .= '</div>';
    $html .= renderGofroMachineTotalsSummary($periodKnife, $periodRotary, $periodKnife + $periodRotary);
    $html .= '<div class="gofro-cal-report__legend">В ячейке: <span class="n">верх — ножевая</span><span class="r">низ — ротационная</span></div>';
    $html .= '<div class="gofro-cal-months">';

    $monthCursor = (clone $start)->modify('first day of this month');
    $lastMonth = (clone $today)->modify('first day of this month');

    while ($monthCursor <= $lastMonth) {
        $year = (int)$monthCursor->format('Y');
        $month = (int)$monthCursor->format('n');
        $daysInMonth = (int)$monthCursor->format('t');

        $html .= '<div class="gofro-cal-month">';
        $html .= '<div class="gofro-cal-month__title">' . htmlspecialchars(gofroRussianMonthName($month), ENT_QUOTES, 'UTF-8')
            . ' ' . $year . '</div>';
        $html .= '<div class="gofro-cal-grid">';

        foreach (['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $wd) {
            $html .= '<div class="gofro-cal-grid__wd">' . $wd . '</div>';
        }

        $firstWeekday = ((int)$monthCursor->format('N')) - 1;
        for ($i = 0; $i < $firstWeekday; $i++) {
            $html .= '<div class="gofro-cal-day gofro-cal-day--empty"></div>';
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateObj = DateTime::createFromFormat('Y-n-j', $year . '-' . $month . '-' . $day);
            $iso = $dateObj ? $dateObj->format('Y-m-d') : '';
            $isFuture = $iso > $endDate;
            $knife = (int)($dailyTotals[$iso]['knife'] ?? 0);
            $rotary = (int)($dailyTotals[$iso]['rotary'] ?? 0);
            $hasData = $knife > 0 || $rotary > 0;

            $classes = 'gofro-cal-day';
            if ($isFuture) {
                $classes .= ' gofro-cal-day--future';
            } elseif ($hasData) {
                $classes .= ' gofro-cal-day--has-data';
            }

            $title = $iso !== '' ? gofroMachineTitle('knife') . ': ' . $knife . ' шт.; '
                . gofroMachineTitle('rotary') . ': ' . $rotary . ' шт.' : '';

            $html .= '<div class="' . $classes . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<span class="gofro-cal-day__num">' . $day . '</span>';

            if ($isFuture) {
                $html .= '<span class="gofro-cal-day__n gofro-cal-day__dash">—</span>';
                $html .= '<span class="gofro-cal-day__r gofro-cal-day__dash">—</span>';
            } else {
                $html .= '<span class="gofro-cal-day__n">' . ($knife > 0 ? (string)$knife : '—') . '</span>';
                $html .= '<span class="gofro-cal-day__r">' . ($rotary > 0 ? (string)$rotary : '—') . '</span>';
            }

            $html .= '</div>';
        }

        $filled = $firstWeekday + $daysInMonth;
        $tail = (7 - ($filled % 7)) % 7;
        for ($i = 0; $i < $tail; $i++) {
            $html .= '<div class="gofro-cal-day gofro-cal-day--empty"></div>';
        }

        $html .= '</div></div>';
        $monthCursor->modify('first day of next month');
    }

    $html .= '</div></div>';
    return $html;
}
