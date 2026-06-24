<?php
/**
 * Мониторинг бобинорезки: У2 / У3 / У5, окно 5 календарных недель.
 * Смещение: GET w (целое) — сдвиг окна на w недель от «базы» (текущая неделя −2 … +2).
 * Отдельно: GET action=cut_by_day&day=Y-m-d — JSON «что порезано в выбранный день» (fact_cut_date).
 * Отдельно: GET action=cut_log&w=… — JSON лог отметок (fact_cut_at / fact_cut_date) за видимый период.
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/auth/includes/config.php';
require_once __DIR__ . '/auth/includes/auth-functions.php';
require_once __DIR__ . '/auth/includes/roll_plan_table.php';

initAuthSystem();

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    header('Location: auth/login.php');
    exit;
}

$weekOffset = (int) ($_GET['w'] ?? 0);

$dbHost = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

$databases = [
    'У2' => ['name' => 'plan', 'table' => 'roll_plan', 'dateField' => 'plan_date'],
    'У3' => ['name' => 'plan_u3', 'table' => 'roll_plans', 'dateField' => 'work_date'],
    'У5' => ['name' => 'plan_u5', 'table' => 'roll_plans', 'dateField' => 'work_date'],
];

function connectShop(string $host, string $user, string $pass, string $dbname): ?PDO
{
    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        try {
            $tz = new DateTimeZone(date_default_timezone_get());
            $offset = (new DateTimeImmutable('now', $tz))->format('P');
            $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        } catch (Throwable $e) {
            error_log('bobbin_cut_monitor time_zone: ' . $e->getMessage());
        }
        return $pdo;
    } catch (PDOException $e) {
        error_log('bobbin_cut_monitor: ' . $e->getMessage());
        return null;
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

/**
 * @return list<array{date:string,order:string,bale:string,done:bool}>
 */
function fetchAssignmentsWithDate(
    ?PDO $pdo,
    string $table,
    string $dateField,
    string $dateFrom,
    string $dateTo
): array {
    if (!$pdo || !tableExists($pdo, $table) || !columnExists($pdo, $table, $dateField)) {
        return [];
    }
    $hasDone = columnExists($pdo, $table, 'done');
    $doneExpr = $hasDone ? 'COALESCE(done, 0)' : '0';
    $sql = "SELECT order_number, bale_id, `{$dateField}` AS d, {$doneExpr} AS done
            FROM `{$table}`
            WHERE `{$dateField}` BETWEEN :d0 AND :d1
            ORDER BY `{$dateField}`, order_number, bale_id";
    $st = $pdo->prepare($sql);
    $st->execute([':d0' => $dateFrom, ':d1' => $dateTo]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'date' => (string) ($r['d'] ?? ''),
            'order' => (string) ($r['order_number'] ?? ''),
            'bale' => (string) ($r['bale_id'] ?? ''),
            'done' => !empty($r['done']),
        ];
    }
    return $out;
}

/** Границы 5 недель монитора с учётом смещения w. */
function computeMonitorWeekRange(int $weekOffset): array
{
    $today = new DateTimeImmutable('today');
    $monday = $today->modify('-' . (((int) $today->format('N')) - 1) . ' days');
    $rangeStart = $monday->modify('-2 weeks')->modify(($weekOffset >= 0 ? '+' : '') . ($weekOffset * 7) . ' days');
    $rangeEnd = $rangeStart->modify('+34 days');
    return [$rangeStart, $rangeEnd];
}

/**
 * Записи лога порезки из одной таблицы плана.
 *
 * @return list<array{shop:string,order:string,bale:string,marked_at:string,has_time:bool}>
 */
function fetchCutLogForTable(
    ?PDO $pdo,
    string $table,
    string $shopCode,
    string $from,
    string $to,
    string $departmentCode = ''
): array {
    if (!$pdo || !tableExists($pdo, $table) || !columnExists($pdo, $table, 'done')) {
        return [];
    }
    $planDateExpr = rollPlanDateExpressionPdo($pdo, $table, $departmentCode);
    if ($planDateExpr === null) {
        return [];
    }

    $hasAt = columnExists($pdo, $table, 'fact_cut_at');
    $hasDate = columnExists($pdo, $table, 'fact_cut_date');
    $cutDateExpr = rollPlanSqlEffectiveCutDate($planDateExpr, $hasAt, $hasDate);
    $sortExpr = rollPlanSqlEffectiveCutSort($planDateExpr, $hasAt, $hasDate);

    $select = "order_number, bale_id, ({$planDateExpr}) AS plan_d";
    if ($hasAt) {
        $select .= ', fact_cut_at';
    }
    if ($hasDate) {
        $select .= ', fact_cut_date';
    }

    $sql = "SELECT {$select} FROM `{$table}`
            WHERE done = 1 AND ({$cutDateExpr}) BETWEEN :f AND :t
            ORDER BY {$sortExpr} DESC";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([':f' => $from, ':t' => $to]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $hasTime = false;
            $markedAt = '';
            $legacyPlan = false;
            if ($hasAt && !empty($r['fact_cut_at'])) {
                $markedAt = (string) $r['fact_cut_at'];
                $hasTime = true;
            } elseif ($hasDate && !empty($r['fact_cut_date'])) {
                $markedAt = (string) $r['fact_cut_date'];
            } elseif (!empty($r['plan_d'])) {
                $markedAt = (string) $r['plan_d'];
                $legacyPlan = true;
            } else {
                continue;
            }
            $out[] = [
                'shop' => $shopCode,
                'order' => (string) ($r['order_number'] ?? ''),
                'bale' => (string) ($r['bale_id'] ?? ''),
                'marked_at' => $markedAt,
                'has_time' => $hasTime,
                'legacy_plan_date' => $legacyPlan,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('bobbin_cut_monitor fetchCutLogForTable: ' . $e->getMessage());
        return [];
    }
}

/**
 * Бухты, отмеченные порезанными в указанный календарный день (fact_cut_date).
 *
 * @return array{items: list<array{order:string,bale:string}>, note: ?string}
 */
function fetchRowsCutOnDay(
    ?PDO $pdo,
    string $table,
    string $dayYmd,
    string $departmentCode = ''
): array {
    if (!$pdo || !tableExists($pdo, $table)) {
        return ['items' => [], 'note' => null];
    }
    if (!columnExists($pdo, $table, 'done')) {
        return ['items' => [], 'note' => 'В таблице нет поля done.'];
    }
    $planDateExpr = rollPlanDateExpressionPdo($pdo, $table, $departmentCode);
    if ($planDateExpr === null) {
        return ['items' => [], 'note' => 'Не найдено поле даты плана (plan_date/work_date).'];
    }

    $hasAt = columnExists($pdo, $table, 'fact_cut_at');
    $hasDate = columnExists($pdo, $table, 'fact_cut_date');
    $cutDateExpr = rollPlanSqlEffectiveCutDate($planDateExpr, $hasAt, $hasDate);

    try {
        $sql = "SELECT order_number, bale_id FROM `{$table}`
                WHERE done = 1 AND ({$cutDateExpr}) = :d
                ORDER BY order_number, bale_id";
        $st = $pdo->prepare($sql);
        $st->execute([':d' => $dayYmd]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $items[] = [
                'order' => (string) ($r['order_number'] ?? ''),
                'bale' => (string) ($r['bale_id'] ?? ''),
            ];
        }
        return ['items' => $items, 'note' => null];
    } catch (Throwable $e) {
        error_log('bobbin_cut_monitor fetchRowsCutOnDay: ' . $e->getMessage());
        return ['items' => [], 'note' => 'Ошибка чтения из ' . $table];
    }
}

// AJAX: содержимое бухты (полосы из cut_plans)
if (($_GET['action'] ?? '') === 'bale_details') {
    header('Content-Type: application/json; charset=utf-8');
    $shopKey = (string) ($_GET['shop'] ?? '');
    $order = trim((string) ($_GET['order'] ?? ''));
    $bale = trim((string) ($_GET['bale'] ?? ''));
    $dbByShop = [
        'U2' => 'plan',
        'U3' => 'plan_u3',
        'U5' => 'plan_u5',
        'У2' => 'plan',
        'У3' => 'plan_u3',
        'У5' => 'plan_u5',
    ];
    if (!isset($dbByShop[$shopKey]) || $order === '' || $bale === '') {
        echo json_encode(['ok' => false, 'error' => 'Нужны параметры shop (U2/U3/U5), order и bale'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dbname = $dbByShop[$shopKey];
    $pdo = connectShop($dbHost, $dbUser, $dbPass, $dbname);
    if (!$pdo || !tableExists($pdo, 'cut_plans')) {
        echo json_encode(['ok' => false, 'error' => 'Нет данных раскроя'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $wantCols = [
        'strip_no' => '№ полосы',
        'filter' => 'Фильтр',
        'paper' => 'Бумага (гофропакет)',
        'material' => 'Материал',
        'width' => 'Ширина',
        'height' => 'Высота',
        'length' => 'Длина',
        'format' => 'Формат бухты',
        'fact_length' => 'Факт. длина',
    ];
    $select = [];
    $headers = [];
    foreach ($wantCols as $col => $label) {
        if (columnExists($pdo, 'cut_plans', $col)) {
            $select[] = '`' . str_replace('`', '', $col) . '`';
            $headers[$col] = $label;
        }
    }
    if ($select === []) {
        echo json_encode(['ok' => false, 'error' => 'В cut_plans нет ожидаемых колонок'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM cut_plans WHERE order_number = :o AND CAST(bale_id AS CHAR) = :b';
    $orderBy = [];
    if (columnExists($pdo, 'cut_plans', 'strip_no')) {
        $orderBy[] = 'strip_no';
    }
    if (columnExists($pdo, 'cut_plans', 'filter')) {
        $orderBy[] = '`filter`';
    }
    if ($orderBy !== []) {
        $sql .= ' ORDER BY ' . implode(', ', $orderBy);
    }
    $st = $pdo->prepare($sql);
    try {
        $st->execute([':o' => $order, ':b' => $bale]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('bobbin_cut_monitor bale_details: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Ошибка при загрузке раскроя'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $norm = [];
    foreach ($rows as $r) {
        $line = [];
        foreach (array_keys($headers) as $col) {
            $v = $r[$col] ?? null;
            if ($v === null || $v === '') {
                $line[$col] = '';
            } elseif (is_numeric($v) && !in_array($col, ['strip_no', 'format'], true)) {
                $line[$col] = round((float) $v, 4);
            } else {
                $line[$col] = $v;
            }
        }
        $norm[] = $line;
    }
    $shopNorm = in_array($shopKey, ['У2', 'У3', 'У5'], true)
        ? ['У2' => 'U2', 'У3' => 'U3', 'У5' => 'U5'][$shopKey]
        : $shopKey;
    echo json_encode(
        [
            'ok' => true,
            'shop' => $shopNorm,
            'order' => $order,
            'bale' => $bale,
            'headers' => $headers,
            'rows' => $norm,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// AJAX: слой «что порезано за выбранный день» (только fact_cut_date + done), не влияет на основную сетку
if (($_GET['action'] ?? '') === 'cut_by_day') {
    header('Content-Type: application/json; charset=utf-8');
    $dayRaw = trim((string) ($_GET['day'] ?? ''));
    $dd = DateTimeImmutable::createFromFormat('Y-m-d', $dayRaw);
    if (!$dd || $dd->format('Y-m-d') !== $dayRaw) {
        echo json_encode(['ok' => false, 'error' => 'Укажите день в формате YYYY-MM-DD'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dayYmd = $dd->format('Y-m-d');
    $shopsOut = [];
    $total = 0;

    $u2 = connectShop($dbHost, $dbUser, $dbPass, 'plan');
    $tblU2 = resolveRollPlanTablePdo($u2, 'U2') ?? 'roll_plan';
    $r2 = fetchRowsCutOnDay($u2, $tblU2, $dayYmd, 'U2');
    $shopsOut['U2'] = $r2;
    $total += count($r2['items']);

    $u3 = connectShop($dbHost, $dbUser, $dbPass, 'plan_u3');
    $tblU3 = resolveRollPlanTablePdo($u3, 'U3') ?? 'roll_plans';
    $r3 = fetchRowsCutOnDay($u3, $tblU3, $dayYmd, 'U3');
    $shopsOut['U3'] = $r3;
    $total += count($r3['items']);

    $u5 = connectShop($dbHost, $dbUser, $dbPass, 'plan_u5');
    $tblU5 = resolveRollPlanTablePdo($u5, 'U5') ?? 'roll_plans';
    $r5 = fetchRowsCutOnDay($u5, $tblU5, $dayYmd, 'U5');
    $shopsOut['U5'] = $r5;
    $total += count($r5['items']);

    echo json_encode(
        [
            'ok' => true,
            'day' => $dayYmd,
            'total' => $total,
            'shops' => $shopsOut,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// AJAX: слой «лог порезки» — отметки done с временем fact_cut_at (или датой fact_cut_date)
if (($_GET['action'] ?? '') === 'cut_log') {
    header('Content-Type: application/json; charset=utf-8');
    $logWeekOffset = (int) ($_GET['w'] ?? $weekOffset);
    [$logRangeStart, $logRangeEnd] = computeMonitorWeekRange($logWeekOffset);
    $from = trim((string) ($_GET['from'] ?? $logRangeStart->format('Y-m-d')));
    $to = trim((string) ($_GET['to'] ?? $logRangeEnd->format('Y-m-d')));
    $fromDt = DateTimeImmutable::createFromFormat('Y-m-d', $from);
    $toDt = DateTimeImmutable::createFromFormat('Y-m-d', $to);
    if (!$fromDt || $fromDt->format('Y-m-d') !== $from || !$toDt || $toDt->format('Y-m-d') !== $to) {
        echo json_encode(['ok' => false, 'error' => 'Некорректный период from/to'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($fromDt > $toDt) {
        echo json_encode(['ok' => false, 'error' => 'Дата начала позже даты конца'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entries = [];
    $notes = [];

    $u2 = connectShop($dbHost, $dbUser, $dbPass, 'plan');
    $tblU2 = resolveRollPlanTablePdo($u2, 'U2') ?? 'roll_plan';
    $entries = array_merge($entries, fetchCutLogForTable($u2, $tblU2, 'U2', $from, $to, 'U2'));

    $u3 = connectShop($dbHost, $dbUser, $dbPass, 'plan_u3');
    $tblU3 = resolveRollPlanTablePdo($u3, 'U3') ?? 'roll_plans';
    $entries = array_merge($entries, fetchCutLogForTable($u3, $tblU3, 'U3', $from, $to, 'U3'));

    $u5 = connectShop($dbHost, $dbUser, $dbPass, 'plan_u5');
    $tblU5 = resolveRollPlanTablePdo($u5, 'U5') ?? 'roll_plans';
    $entries = array_merge($entries, fetchCutLogForTable($u5, $tblU5, 'U5', $from, $to, 'U5'));

    usort($entries, static function ($a, $b) {
        return strcmp($b['marked_at'], $a['marked_at']);
    });

    $hasLegacyTime = false;
    $hasLegacyPlan = false;
    foreach ($entries as $row) {
        if (empty($row['has_time'])) {
            $hasLegacyTime = true;
        }
        if (!empty($row['legacy_plan_date'])) {
            $hasLegacyPlan = true;
        }
    }
    if ($hasLegacyTime) {
        $notes[] = 'Для части отметок время не записывалось — показана только дата (без времени).';
    }
    if ($hasLegacyPlan) {
        $notes[] = 'Для старых отметок без fact_cut_date дата в логе взята из даты плана порезки (как в аналитике).';
    }

    echo json_encode(
        [
            'ok' => true,
            'from' => $from,
            'to' => $to,
            'total' => count($entries),
            'entries' => $entries,
            'notes' => $notes,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// Понедельник текущей календарной недели; окно 35 дней (5 полных недель), сдвиг на w недель
$today = new DateTimeImmutable('today');
$monday = $today->modify('-' . (((int) $today->format('N')) - 1) . ' days');
$rangeStart = $monday->modify('-2 weeks')->modify(($weekOffset >= 0 ? '+' : '') . ($weekOffset * 7) . ' days');
$rangeEnd = $rangeStart->modify('+34 days');
$dateFrom = $rangeStart->format('Y-m-d');
$dateTo = $rangeEnd->format('Y-m-d');

$days = [];
for ($d = $rangeStart; $d <= $rangeEnd; $d = $d->modify('+1 day')) {
    $days[] = $d;
}

// Недели: массив сегментов [ ['from'=>,'to'=>,'days'=>[DateTimeImmutable,...]], ... ]
$weeks = [];
$buf = [];
$weekStart = null;
foreach ($days as $d) {
    if ($weekStart === null) {
        $weekStart = $d;
    }
    $buf[] = $d;
    if ((int) $d->format('N') === 7) {
        $weeks[] = ['from' => $weekStart, 'to' => $d, 'days' => $buf];
        $buf = [];
        $weekStart = null;
    }
}
if ($buf !== []) {
    $weeks[] = ['from' => $weekStart ?? $buf[0], 'to' => end($buf), 'days' => $buf];
}

$dowShort = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

/** @var array<string, array<string, list<array{order:string,bale:string,done:bool}>>> $grid[$shop][$ymd] */
$grid = ['У2' => [], 'У3' => [], 'У5' => []];

foreach (['У2', 'У3', 'У5'] as $shopLabel) {
    $cfg = $databases[$shopLabel];
    $pdo = connectShop($dbHost, $dbUser, $dbPass, $cfg['name']);
    if (!$pdo) {
        continue;
    }
    $rows = [];
    if ($shopLabel === 'У3') {
        $rows = array_merge(
            fetchAssignmentsWithDate($pdo, 'roll_plans', 'work_date', $dateFrom, $dateTo),
            fetchAssignmentsWithDate($pdo, 'roll_plan', 'plan_date', $dateFrom, $dateTo)
        );
    } else {
        $rows = fetchAssignmentsWithDate($pdo, $cfg['table'], $cfg['dateField'], $dateFrom, $dateTo);
    }
    $seen = [];
    foreach ($rows as $item) {
        $ymd = $item['date'];
        if ($ymd === '') {
            continue;
        }
        $key = $item['order'] . "\0" . $item['bale'] . "\0" . $ymd;
        if (isset($seen[$key])) {
            $seen[$key] = $seen[$key] || $item['done'];
            continue;
        }
        $seen[$key] = $item['done'];
        if (!isset($grid[$shopLabel][$ymd])) {
            $grid[$shopLabel][$ymd] = [];
        }
        $grid[$shopLabel][$ymd][] = [
            'order' => $item['order'],
            'bale' => $item['bale'],
            'done' => $item['done'],
        ];
    }
    // применить объединённый done для дубликатов
    foreach ($grid[$shopLabel] as $ymd => &$list) {
        foreach ($list as $i => &$one) {
            $k = $one['order'] . "\0" . $one['bale'] . "\0" . $ymd;
            if (isset($seen[$k])) {
                $one['done'] = $seen[$k];
            }
        }
        unset($one);
    }
    unset($list);
}

foreach (['У2', 'У3', 'У5'] as $shopLabel) {
    foreach ($grid[$shopLabel] as $ymd => &$list) {
        usort($list, static function ($a, $b) {
            $c = strcmp($a['order'], $b['order']);
            return $c !== 0 ? $c : strcmp($a['bale'], $b['bale']);
        });
    }
    unset($list);
}

$pageTitle = 'Мониторинг бобинорезки';
$navPrevW = $weekOffset - 1;
$navNextW = $weekOffset + 1;
$navQuery = static function (int $w): string {
    return 'bobbin_cut_monitor.php' . ($w !== 0 ? '?w=' . $w : '');
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f4f6;
            color: #111827;
            padding: 16px;
        }
        h1 {
            font-size: 1.25rem;
            margin: 0 0 8px;
        }
        .meta {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        .wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        table {
            border-collapse: collapse;
            font-size: 11px;
            min-width: 100%;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 4px 5px;
            vertical-align: top;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
        }
        th.shop, td.shop {
            position: sticky;
            left: 0;
            z-index: 2;
            background: #eef2ff;
            font-weight: 700;
            text-align: center;
            min-width: 44px;
        }
        th.week-sep {
            border-left: 2px solid #94a3b8;
        }
        td.week-sep {
            border-left: 2px solid #cbd5e1;
        }
        .cell-inner {
            display: flex;
            flex-wrap: wrap;
            gap: 3px;
            max-width: 220px;
        }
        .tag {
            display: inline-block;
            padding: 2px 4px;
            border-radius: 3px;
            background: #e0e7ff;
            border: 1px solid #c7d2fe;
            line-height: 1.2;
        }
        .tag.done {
            background: #dcfce7;
            border-color: #86efac;
            color: #14532d;
            text-decoration: line-through;
        }
        .tag.done::after {
            content: ' ✓';
            text-decoration: none;
            font-weight: bold;
        }
        .tag.bale-tag {
            cursor: pointer;
        }
        .tag.bale-tag:hover {
            background: #c7d2fe;
            border-color: #a5b4fc;
        }
        .tag.bale-tag:focus {
            outline: 2px solid #6366f1;
            outline-offset: 1px;
        }
        .nav-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .nav-bar a, .nav-bar button {
            font-size: 14px;
            padding: 8px 14px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #1f2937;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
        }
        .nav-bar a:hover, .nav-bar button:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        .nav-bar .nav-hint {
            font-size: 13px;
            color: #6b7280;
            margin-left: auto;
        }
        #bale-panel-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 9000;
        }
        #bale-panel-backdrop.is-open {
            display: block;
            z-index: 10100;
        }
        #bale-panel {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(96vw, 720px);
            max-height: min(85vh, 640px);
            z-index: 9001;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid #e5e7eb;
            flex-direction: column;
        }
        #bale-panel.is-open {
            display: flex;
            z-index: 10101;
        }
        #bale-panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 15px;
            font-weight: 600;
            color: #111827;
        }
        #bale-panel-close {
            flex-shrink: 0;
            padding: 6px 12px;
            font-size: 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #f9fafb;
            cursor: pointer;
            font-family: inherit;
        }
        #bale-panel-close:hover {
            background: #e5e7eb;
        }
        #bale-panel-body {
            padding: 12px 16px 16px;
            overflow: auto;
            font-size: 13px;
        }
        #bale-panel-body .bale-panel-loading {
            color: #6b7280;
        }
        #bale-panel-body table.detail {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        #bale-panel-body table.detail th,
        #bale-panel-body table.detail td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            text-align: left;
        }
        #bale-panel-body table.detail th {
            background: #f3f4f6;
            white-space: nowrap;
        }
        #bale-panel-body .bale-panel-err {
            color: #b91c1c;
        }

        /* ——— слой «Порезано за день» (отдельно от основной страницы) ——— */
        #cut-day-fab {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 8000;
            padding: 10px 14px;
            font-size: 13px;
            font-family: inherit;
            border-radius: 999px;
            border: 1px solid #0d9488;
            background: #14b8a6;
            color: #fff;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(20, 184, 166, 0.45);
        }
        #cut-day-fab:hover {
            background: #0d9488;
        }
        #cut-day-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.4);
            z-index: 9200;
        }
        #cut-day-backdrop.is-open {
            display: block;
        }
        #cut-day-panel {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: min(94vw, 640px);
            max-height: min(88vh, 720px);
            z-index: 9201;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.22);
            border: 1px solid #e5e7eb;
            flex-direction: column;
            overflow: hidden;
        }
        #cut-day-panel.is-open {
            display: flex;
        }
        #cut-day-panel-header {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 100%);
        }
        #cut-day-panel-header h2 {
            margin: 0 0 8px;
            font-size: 1.05rem;
            color: #134e4a;
        }
        #cut-day-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        #cut-day-toolbar label {
            font-size: 13px;
            color: #374151;
        }
        #cut-day-date {
            font-family: inherit;
            font-size: 14px;
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
        }
        #cut-day-load {
            padding: 7px 14px;
            font-size: 14px;
            border-radius: 6px;
            border: 1px solid #0d9488;
            background: #14b8a6;
            color: #fff;
            cursor: pointer;
            font-family: inherit;
        }
        #cut-day-load:hover {
            background: #0d9488;
        }
        #cut-day-panel-close {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            font-size: 13px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            font-family: inherit;
        }
        #cut-day-panel-body {
            padding: 12px 16px 18px;
            overflow: auto;
            font-size: 13px;
        }
        #cut-day-panel-body .cut-day-muted {
            color: #6b7280;
            font-size: 12px;
            margin: 0 0 10px;
        }
        #cut-day-panel-body .cut-day-shop {
            margin-top: 14px;
            font-weight: 700;
            color: #1e3a8a;
            font-size: 13px;
        }
        #cut-day-panel-body .cut-day-shop:first-of-type {
            margin-top: 0;
        }
        #cut-day-panel-body .cut-day-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        #cut-day-panel-body .cut-day-note {
            color: #92400e;
            font-size: 12px;
            margin-top: 4px;
        }
        #cut-day-panel-wrap {
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        /* ——— слой «Лог порезки» (панель справа) ——— */
        #cut-log-fab {
            position: fixed;
            right: 18px;
            bottom: 62px;
            z-index: 8000;
            padding: 10px 14px;
            font-size: 13px;
            font-family: inherit;
            border-radius: 999px;
            border: 1px solid #7c3aed;
            background: #8b5cf6;
            color: #fff;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.45);
        }
        #cut-log-fab:hover {
            background: #7c3aed;
        }
        #cut-log-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.25);
            z-index: 9280;
        }
        #cut-log-backdrop.is-open {
            display: block;
        }
        #cut-log-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: min(420px, 100vw);
            height: 100%;
            z-index: 9281;
            background: #fff;
            border-left: 1px solid #e5e7eb;
            box-shadow: -8px 0 30px rgba(0, 0, 0, 0.12);
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.22s ease;
        }
        #cut-log-panel.is-open {
            transform: translateX(0);
        }
        #cut-log-panel-header {
            padding: 14px 44px 12px 16px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            flex-shrink: 0;
        }
        #cut-log-panel-header h2 {
            margin: 0 0 6px;
            font-size: 1.05rem;
            color: #4c1d95;
        }
        #cut-log-panel-close {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 6px 12px;
            font-size: 13px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            font-family: inherit;
        }
        #cut-log-panel-close:hover {
            background: #f3f4f6;
        }
        #cut-log-panel-body {
            flex: 1;
            overflow: auto;
            padding: 10px 12px 16px;
            font-size: 13px;
        }
        #cut-log-panel-body .cut-log-muted {
            color: #6b7280;
            font-size: 12px;
            margin: 0 0 10px;
        }
        #cut-log-panel-body .cut-log-note {
            color: #92400e;
            font-size: 12px;
            margin: 0 0 10px;
            padding: 8px 10px;
            background: #fffbeb;
            border-radius: 6px;
            border: 1px solid #fde68a;
        }
        #cut-log-panel-body ul.cut-log-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        #cut-log-panel-body li.cut-log-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 8px;
            border-bottom: 1px solid #f3f4f6;
        }
        #cut-log-panel-body li.cut-log-item:last-child {
            border-bottom: none;
        }
        #cut-log-panel-body li.cut-log-item-today {
            background: #f0fdf4;
        }
        #cut-log-panel-body .cut-log-time {
            flex-shrink: 0;
            width: 118px;
            font-size: 11px;
            color: #6b7280;
            line-height: 1.35;
        }
        #cut-log-panel-body .cut-log-time .no-time {
            font-style: italic;
        }
        #cut-log-panel-body .cut-log-main {
            flex: 1;
            min-width: 0;
        }
        #cut-log-panel-body .cut-log-shop {
            font-size: 11px;
            font-weight: 700;
            color: #5b21b6;
            margin-bottom: 4px;
        }
        #cut-log-panel-body li.cut-log-item.is-active {
            background: #ede9fe;
            border-left: 3px solid #8b5cf6;
            padding-left: 5px;
        }
        #cut-log-panel-body .cut-log-bale {
            cursor: pointer;
        }
        #cut-log-panel-body .cut-log-bale:hover {
            background: #bbf7d0;
            border-color: #4ade80;
        }
        #cut-log-panel-body .cut-log-not-found {
            color: #b45309;
            font-size: 12px;
            margin: 6px 0 0;
            padding: 6px 8px;
            background: #fffbeb;
            border-radius: 6px;
            border: 1px solid #fde68a;
        }
        .tag.grid-highlight {
            outline: 3px solid #8b5cf6;
            outline-offset: 2px;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.35);
            position: relative;
            z-index: 1;
            animation: grid-highlight-pulse 1.2s ease-in-out 3;
        }
        td.grid-highlight-cell {
            background: #fef9c3 !important;
        }
        @keyframes grid-highlight-pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.35); }
            50% { box-shadow: 0 0 0 7px rgba(139, 92, 246, 0.55); }
        }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
    <div class="nav-bar">
        <a href="<?= htmlspecialchars($navQuery($navPrevW)) ?>">◀ На неделю назад</a>
        <a href="<?= htmlspecialchars($navQuery($navNextW)) ?>">На неделю вперёд ▶</a>
        <?php if ($weekOffset !== 0): ?>
            <a href="<?= htmlspecialchars($navQuery(0)) ?>">К периоду по умолчанию</a>
        <?php endif; ?>
        <span class="nav-hint">Один шаг — сдвиг на одну неделю.</span>
    </div>
    <div class="meta">
        Период: <?= htmlspecialchars($rangeStart->format('d.m.Y')) ?> — <?= htmlspecialchars($rangeEnd->format('d.m.Y')) ?>
        (5 полных недель<?= $weekOffset !== 0 ? ', сдвиг ' . ($weekOffset > 0 ? '+' : '') . $weekOffset . ' нед.' : ': −2 … +2 от текущей' ?>).
    </div>
    <div class="wrap">
        <table>
            <thead>
                <tr>
                    <th class="shop" rowspan="2">Уч.</th>
                    <?php
                    foreach ($weeks as $wi => $wk) {
                        $n = count($wk['days']);
                        $lbl = $wk['from']->format('d.m') . ' — ' . $wk['to']->format('d.m');
                        $cls = $wi > 0 ? ' week-sep' : '';
                        echo '<th class="' . trim($cls) . '" colspan="' . (int) $n . '">' . htmlspecialchars($lbl) . '</th>';
                    }
                    ?>
                </tr>
                <tr>
                    <?php
                    foreach ($days as $di => $d) {
                        $isMon = (int) $d->format('N') === 1;
                        $cls = $isMon && $di > 0 ? ' week-sep' : '';
                        $dn = (int) $d->format('N') - 1;
                        $head = $dowShort[$dn] . ' ' . $d->format('d.m');
                        echo '<th class="' . trim($cls) . '">' . htmlspecialchars($head) . '</th>';
                    }
                    ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (['У2', 'У3', 'У5'] as $shopLabel): ?>
                <?php
                $shopCode = ['У2' => 'U2', 'У3' => 'U3', 'У5' => 'U5'][$shopLabel] ?? $shopLabel;
                ?>
                <tr>
                    <td class="shop"><?= htmlspecialchars($shopLabel) ?></td>
                    <?php foreach ($days as $di => $d): ?>
                        <?php
                        $ymd = $d->format('Y-m-d');
                        $isMon = (int) $d->format('N') === 1;
                        $tdCls = $isMon && $di > 0 ? 'week-sep' : '';
                        $items = $grid[$shopLabel][$ymd] ?? [];
                        ?>
                        <td class="<?= htmlspecialchars($tdCls) ?>">
                            <div class="cell-inner">
                                <?php foreach ($items as $it): ?>
                                    <?php
                                    $cls = 'tag bale-tag' . ($it['done'] ? ' done' : '');
                                    $text = '[[' . $it['order'] . '][' . $it['bale'] . ']]';
                                    ?>
                                    <span
                                        class="<?= htmlspecialchars($cls) ?>"
                                        role="button"
                                        tabindex="0"
                                        title="Показать полосы бухты"
                                        data-shop="<?= htmlspecialchars($shopCode, ENT_QUOTES, 'UTF-8') ?>"
                                        data-order="<?= htmlspecialchars($it['order'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-bale="<?= htmlspecialchars($it['bale'], ENT_QUOTES, 'UTF-8') ?>"
                                    ><?= htmlspecialchars($text) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Слой «Лог порезки» — панель справа, отдельный API cut_log -->
    <button type="button" id="cut-log-fab" aria-expanded="false" aria-controls="cut-log-panel">
        Лог порезки
    </button>
    <div id="cut-log-backdrop" aria-hidden="true"></div>
    <aside id="cut-log-panel" role="dialog" aria-modal="true" aria-labelledby="cut-log-panel-h2" aria-hidden="true">
        <button type="button" id="cut-log-panel-close" aria-label="Закрыть лог">×</button>
        <div id="cut-log-panel-header">
            <h2 id="cut-log-panel-h2">Лог порезки</h2>
            <p class="cut-log-muted" id="cut-log-period-hint">
                Период таблицы: <?= htmlspecialchars($rangeStart->format('d.m.Y')) ?> — <?= htmlspecialchars($rangeEnd->format('d.m.Y')) ?>
            </p>
        </div>
        <div id="cut-log-panel-body"></div>
    </aside>

    <!-- Слой «Порезано за день» — отдельный UI и API, основная сетка не меняется -->
    <button type="button" id="cut-day-fab" aria-expanded="false" aria-controls="cut-day-panel">
        Порезано за день
    </button>
    <div id="cut-day-backdrop" aria-hidden="true"></div>
    <div id="cut-day-panel" role="dialog" aria-modal="true" aria-labelledby="cut-day-panel-h2" aria-hidden="true">
        <div id="cut-day-panel-wrap">
            <button type="button" id="cut-day-panel-close" aria-label="Закрыть слой">Закрыть</button>
            <div id="cut-day-panel-header">
                <h2 id="cut-day-panel-h2">Порезано за выбранный день</h2>
                <p class="cut-day-muted">Учитываются только бухты с отметкой «готово» и заполненной датой факта порезки (поле fact_cut_date в таблице плана порезки).</p>
                <div id="cut-day-toolbar">
                    <label for="cut-day-date">Дата</label>
                    <input type="date" id="cut-day-date" value="<?= htmlspecialchars((new DateTimeImmutable('today'))->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" id="cut-day-load">Показать</button>
                </div>
            </div>
            <div id="cut-day-panel-body"></div>
        </div>
    </div>

    <div id="bale-panel-backdrop" aria-hidden="true"></div>
    <div id="bale-panel" role="dialog" aria-modal="true" aria-labelledby="bale-panel-title">
        <div id="bale-panel-header">
            <div id="bale-panel-title">Содержимое бухты</div>
            <button type="button" id="bale-panel-close" aria-label="Закрыть">Закрыть</button>
        </div>
        <div id="bale-panel-body">
            <div class="bale-panel-loading">Загрузка…</div>
        </div>
    </div>

    <script>
    (function () {
        window.highlightBaleInMonitorGrid = function (shop, order, bale) {
            window.clearBaleMonitorGridHighlight();
            var found = null;
            document.querySelectorAll('.wrap .bale-tag').forEach(function (tag) {
                if (
                    tag.getAttribute('data-shop') === shop &&
                    tag.getAttribute('data-order') === order &&
                    tag.getAttribute('data-bale') === bale
                ) {
                    found = tag;
                }
            });

            if (!found) {
                return false;
            }

            found.classList.add('grid-highlight');
            var td = found.closest('td');
            if (td) {
                td.classList.add('grid-highlight-cell');
            }

            var wrap = document.querySelector('.wrap');
            if (wrap) {
                var tagRect = found.getBoundingClientRect();
                var wrapRect = wrap.getBoundingClientRect();
                var scrollLeft = wrap.scrollLeft + (tagRect.left - wrapRect.left) - (wrapRect.width / 2) + (tagRect.width / 2);
                var scrollTop = wrap.scrollTop + (tagRect.top - wrapRect.top) - (wrapRect.height / 2) + (tagRect.height / 2);
                wrap.scrollTo({ left: Math.max(0, scrollLeft), top: Math.max(0, scrollTop), behavior: 'smooth' });
            } else {
                found.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
            }

            return true;
        };

        window.clearBaleMonitorGridHighlight = function () {
            document.querySelectorAll('.tag.grid-highlight').forEach(function (el) {
                el.classList.remove('grid-highlight');
            });
            document.querySelectorAll('td.grid-highlight-cell').forEach(function (el) {
                el.classList.remove('grid-highlight-cell');
            });
        };
    })();
    </script>
    <script>
    (function () {
        var backdrop = document.getElementById('bale-panel-backdrop');
        var panel = document.getElementById('bale-panel');
        var bodyEl = document.getElementById('bale-panel-body');
        var titleEl = document.getElementById('bale-panel-title');
        var closeBtn = document.getElementById('bale-panel-close');
        var shopNames = { U2: 'У2', U3: 'У3', U5: 'У5' };

        function closePanel() {
            backdrop.classList.remove('is-open');
            panel.classList.remove('is-open');
            backdrop.setAttribute('aria-hidden', 'true');
        }

        function openPanel() {
            backdrop.classList.add('is-open');
            panel.classList.add('is-open');
            backdrop.setAttribute('aria-hidden', 'false');
        }

        function buildDetailUrl(shop, order, bale) {
            var u = new URL(window.location.href);
            u.searchParams.set('action', 'bale_details');
            u.searchParams.set('shop', shop);
            u.searchParams.set('order', order);
            u.searchParams.set('bale', bale);
            return u.toString();
        }

        function renderTable(headers, rows) {
            var keys = Object.keys(headers);
            if (keys.length === 0) return '<p class="bale-panel-err">Нет колонок для отображения.</p>';
            var h = '<table class="detail"><thead><tr>';
            keys.forEach(function (k) {
                h += '<th>' + escapeHtml(headers[k]) + '</th>';
            });
            h += '</tr></thead><tbody>';
            if (!rows || rows.length === 0) {
                h += '<tr><td colspan="' + keys.length + '">В раскрое нет полос для этой бухты.</td></tr>';
            } else {
                rows.forEach(function (row) {
                    h += '<tr>';
                    keys.forEach(function (k) {
                        var v = row[k];
                        if (v === null || v === undefined) v = '';
                        h += '<td>' + escapeHtml(String(v)) + '</td>';
                    });
                    h += '</tr>';
                });
            }
            h += '</tbody></table>';
            return h;
        }

        function escapeHtml(s) {
            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function loadBale(shop, order, bale) {
            var ru = shopNames[shop] || shop;
            titleEl.textContent = ru + ' · заявка ' + order + ' · бухта ' + bale;
            bodyEl.innerHTML = '<div class="bale-panel-loading">Загрузка…</div>';
            openPanel();
            fetch(buildDetailUrl(shop, order, bale), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        bodyEl.innerHTML = '<p class="bale-panel-err">' + escapeHtml(data.error || 'Ошибка') + '</p>';
                        return;
                    }
                    bodyEl.innerHTML = renderTable(data.headers || {}, data.rows || []);
                })
                .catch(function () {
                    bodyEl.innerHTML = '<p class="bale-panel-err">Не удалось загрузить данные.</p>';
                });
        }

        function onTagActivate(el) {
            var shop = el.getAttribute('data-shop');
            var order = el.getAttribute('data-order');
            var bale = el.getAttribute('data-bale');
            if (!shop || !order || !bale) return;
            loadBale(shop, order, bale);
        }

        document.body.addEventListener('click', function (e) {
            if (e.target.closest('#cut-log-panel .cut-log-bale')) {
                return;
            }
            var tag = e.target.closest('.bale-tag');
            if (tag) {
                e.preventDefault();
                onTagActivate(tag);
                return;
            }
            if (e.target === backdrop) closePanel();
        });

        document.body.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('is-open')) {
                closePanel();
                return;
            }
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var tag = e.target.closest && e.target.closest('.bale-tag');
            if (!tag || document.activeElement !== tag) return;
            e.preventDefault();
            onTagActivate(tag);
        });

        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closePanel();
        });
        panel.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    })();
    </script>
    <script>
    /* Слой «Порезано за день» — отдельная логика, не трогает скрипт основой таблицы выше */
    (function () {
        var fab = document.getElementById('cut-day-fab');
        var cutBackdrop = document.getElementById('cut-day-backdrop');
        var cutPanel = document.getElementById('cut-day-panel');
        var cutBody = document.getElementById('cut-day-panel-body');
        var cutDate = document.getElementById('cut-day-date');
        var cutLoad = document.getElementById('cut-day-load');
        var cutClose = document.getElementById('cut-day-panel-close');
        var balePanel = document.getElementById('bale-panel');

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function openCutDay() {
            cutBackdrop.classList.add('is-open');
            cutPanel.classList.add('is-open');
            cutBackdrop.setAttribute('aria-hidden', 'false');
            cutPanel.setAttribute('aria-hidden', 'false');
            fab.setAttribute('aria-expanded', 'true');
        }

        function closeCutDay() {
            cutBackdrop.classList.remove('is-open');
            cutPanel.classList.remove('is-open');
            cutBackdrop.setAttribute('aria-hidden', 'true');
            cutPanel.setAttribute('aria-hidden', 'true');
            fab.setAttribute('aria-expanded', 'false');
        }

        function buildCutByDayUrl(day) {
            var u = new URL(window.location.href);
            u.searchParams.set('action', 'cut_by_day');
            u.searchParams.set('day', day);
            return u.toString();
        }

        function renderCutDayResult(data) {
            var shops = data.shops || {};
            var order = ['U2', 'U3', 'U5'];
            var labels = { U2: 'У2', U3: 'У3', U5: 'У5' };
            var html = '<p class="cut-day-muted">Дата: <strong>' + escapeHtml(data.day) + '</strong> · всего бухт: <strong>' + (data.total | 0) + '</strong></p>';
            order.forEach(function (code) {
                var block = shops[code] || { items: [], note: null };
                var items = block.items || [];
                html += '<div class="cut-day-shop">' + escapeHtml(labels[code] || code) + '</div>';
                if (block.note) {
                    html += '<p class="cut-day-note">' + escapeHtml(block.note) + '</p>';
                }
                if (items.length === 0) {
                    if (!block.note) {
                        html += '<p class="cut-day-muted">Нет записей за этот день.</p>';
                    }
                } else {
                    html += '<div class="cut-day-tags">';
                    items.forEach(function (it) {
                        var o = it.order || '';
                        var b = it.bale || '';
                        var text = '[[' + o + '][' + b + ']]';
                        html += '<span class="tag bale-tag done" role="button" tabindex="0" title="Показать полосы бухты" data-shop="' + escapeHtml(code) + '" data-order="' + escapeHtml(o) + '" data-bale="' + escapeHtml(b) + '">' + escapeHtml(text) + '</span>';
                    });
                    html += '</div>';
                }
            });
            cutBody.innerHTML = html;
        }

        function loadCutDay() {
            var day = (cutDate && cutDate.value) ? cutDate.value : '';
            if (!day) {
                cutBody.innerHTML = '<p class="cut-day-note">Выберите дату.</p>';
                return;
            }
            cutBody.innerHTML = '<p class="cut-day-muted">Загрузка…</p>';
            fetch(buildCutByDayUrl(day), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        cutBody.innerHTML = '<p class="cut-day-note">' + escapeHtml(data.error || 'Ошибка') + '</p>';
                        return;
                    }
                    renderCutDayResult(data);
                })
                .catch(function () {
                    cutBody.innerHTML = '<p class="cut-day-note">Не удалось загрузить данные.</p>';
                });
        }

        fab.addEventListener('click', function () {
            if (cutPanel.classList.contains('is-open')) {
                closeCutDay();
            } else {
                openCutDay();
                loadCutDay();
            }
        });
        cutClose.addEventListener('click', function (e) {
            e.stopPropagation();
            closeCutDay();
        });
        cutLoad.addEventListener('click', function () {
            loadCutDay();
        });
        cutBackdrop.addEventListener('click', function (e) {
            if (e.target === cutBackdrop) closeCutDay();
        });
        cutPanel.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (balePanel && balePanel.classList.contains('is-open')) return;
            if (cutBackdrop && cutBackdrop.classList.contains('is-open')) {
                e.preventDefault();
                closeCutDay();
            }
        }, true);
    })();
    </script>
    <script>
    /* Слой «Лог порезки» — панель справа */
    (function () {
        var fab = document.getElementById('cut-log-fab');
        var backdrop = document.getElementById('cut-log-backdrop');
        var panel = document.getElementById('cut-log-panel');
        var bodyEl = document.getElementById('cut-log-panel-body');
        var closeBtn = document.getElementById('cut-log-panel-close');
        var balePanel = document.getElementById('bale-panel');
        var cutDayBackdrop = document.getElementById('cut-day-backdrop');
        var shopLabels = { U2: 'У2', U3: 'У3', U5: 'У5' };
        var monitorFrom = <?= json_encode($dateFrom, JSON_UNESCAPED_UNICODE) ?>;
        var monitorTo = <?= json_encode($dateTo, JSON_UNESCAPED_UNICODE) ?>;
        var todayYmd = <?= json_encode((new DateTimeImmutable('today'))->format('Y-m-d'), JSON_UNESCAPED_UNICODE) ?>;
        var activeLogItem = null;
        var notFoundEl = null;

        function clearLogHighlightState() {
            if (activeLogItem) {
                activeLogItem.classList.remove('is-active');
                activeLogItem = null;
            }
            if (notFoundEl && notFoundEl.parentNode) {
                notFoundEl.parentNode.removeChild(notFoundEl);
                notFoundEl = null;
            }
        }

        function showLogNotFound(li) {
            if (notFoundEl && notFoundEl.parentNode) {
                notFoundEl.parentNode.removeChild(notFoundEl);
            }
            notFoundEl = document.createElement('p');
            notFoundEl.className = 'cut-log-not-found';
            notFoundEl.textContent = 'Бухта не найдена в видимой сетке (плановая дата вне текущего периода таблицы).';
            li.appendChild(notFoundEl);
        }

        function onLogBaleClick(tag, li) {
            var shop = tag.getAttribute('data-shop');
            var order = tag.getAttribute('data-order');
            var bale = tag.getAttribute('data-bale');
            if (!shop || !order || !bale) return;

            clearLogHighlightState();
            if (li) {
                activeLogItem = li;
                li.classList.add('is-active');
            }

            var ok = typeof window.highlightBaleInMonitorGrid === 'function' &&
                window.highlightBaleInMonitorGrid(shop, order, bale);
            if (!ok && li) {
                showLogNotFound(li);
            }
        }

        function entryDateYmd(entry) {
            var s = String(entry.marked_at || '').trim();
            if (s.length >= 10) {
                return s.slice(0, 10);
            }
            return '';
        }

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function formatMarkedAt(iso, hasTime) {
            if (!iso) return '—';
            var parts = String(iso).trim().split(/[\sT]/);
            var d = parts[0].split('-');
            if (d.length !== 3) return escapeHtml(iso);
            var out = d[2] + '.' + d[1] + '.' + d[0];
            if (hasTime && parts[1]) {
                out += '<br>' + escapeHtml(parts[1].slice(0, 8));
            } else {
                out += '<br><span class="no-time">без времени</span>';
            }
            return out;
        }

        function openLog() {
            backdrop.classList.add('is-open');
            panel.classList.add('is-open');
            backdrop.setAttribute('aria-hidden', 'false');
            panel.setAttribute('aria-hidden', 'false');
            fab.setAttribute('aria-expanded', 'true');
        }

        function closeLog() {
            backdrop.classList.remove('is-open');
            panel.classList.remove('is-open');
            backdrop.setAttribute('aria-hidden', 'true');
            panel.setAttribute('aria-hidden', 'true');
            fab.setAttribute('aria-expanded', 'false');
            clearLogHighlightState();
            if (typeof window.clearBaleMonitorGridHighlight === 'function') {
                window.clearBaleMonitorGridHighlight();
            }
        }

        function buildCutLogUrl() {
            var u = new URL(window.location.href);
            u.searchParams.set('action', 'cut_log');
            u.searchParams.delete('day');
            u.searchParams.set('from', monitorFrom);
            u.searchParams.set('to', monitorTo);
            return u.toString();
        }

        function renderLog(data) {
            var html = '<p class="cut-log-muted">Записей: <strong>' + (data.total | 0) + '</strong></p>';
            if (data.notes && data.notes.length) {
                data.notes.forEach(function (n) {
                    html += '<p class="cut-log-note">' + escapeHtml(n) + '</p>';
                });
            }
            var entries = data.entries || [];
            if (!entries.length) {
                html += '<p class="cut-log-muted">За выбранный период отметок порезки нет.</p>';
                bodyEl.innerHTML = html;
                return;
            }
            html += '<ul class="cut-log-list">';
            entries.forEach(function (e) {
                var shop = e.shop || '';
                var o = e.order || '';
                var b = e.bale || '';
                var text = '[[' + o + '][' + b + ']]';
                var todayCls = entryDateYmd(e) === todayYmd ? ' cut-log-item-today' : '';
                html += '<li class="cut-log-item' + todayCls + '">';
                html += '<div class="cut-log-time">' + formatMarkedAt(e.marked_at, !!e.has_time) + '</div>';
                html += '<div class="cut-log-main">';
                html += '<div class="cut-log-shop">' + escapeHtml(shopLabels[shop] || shop) + '</div>';
                html += '<span class="tag bale-tag done cut-log-bale" role="button" tabindex="0" title="Подсветить в таблице мониторинга" data-shop="' + escapeHtml(shop) + '" data-order="' + escapeHtml(o) + '" data-bale="' + escapeHtml(b) + '">' + escapeHtml(text) + '</span>';
                html += '</div></li>';
            });
            html += '</ul>';
            bodyEl.innerHTML = html;
        }

        function loadLog() {
            bodyEl.innerHTML = '<p class="cut-log-muted">Загрузка…</p>';
            fetch(buildCutLogUrl(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        bodyEl.innerHTML = '<p class="cut-log-note">' + escapeHtml(data.error || 'Ошибка') + '</p>';
                        return;
                    }
                    renderLog(data);
                })
                .catch(function () {
                    bodyEl.innerHTML = '<p class="cut-log-note">Не удалось загрузить лог.</p>';
                });
        }

        fab.addEventListener('click', function () {
            if (panel.classList.contains('is-open')) {
                closeLog();
            } else {
                openLog();
                loadLog();
            }
        });
        closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeLog();
        });
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeLog();
        });
        panel.addEventListener('click', function (e) {
            var tag = e.target.closest('.cut-log-bale');
            if (tag) {
                e.preventDefault();
                e.stopPropagation();
                onLogBaleClick(tag, tag.closest('.cut-log-item'));
                return;
            }
            e.stopPropagation();
        });

        panel.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var tag = e.target.closest('.cut-log-bale');
            if (!tag || document.activeElement !== tag) return;
            e.preventDefault();
            onLogBaleClick(tag, tag.closest('.cut-log-item'));
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (balePanel && balePanel.classList.contains('is-open')) return;
            if (cutDayBackdrop && cutDayBackdrop.classList.contains('is-open')) return;
            if (backdrop && backdrop.classList.contains('is-open')) {
                e.preventDefault();
                closeLog();
            }
        }, true);
    })();
    </script>
</body>
</html>
