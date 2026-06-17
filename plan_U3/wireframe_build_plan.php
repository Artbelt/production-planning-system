<?php
/**
 * Планирование изготовления каркасов: позиции с каркасами в гофропакете;
 * в ячейках дат — основной план каркасов; полоска — справочное покрытие слота г/п к сборке
 * (план corrugation_plan_v2): каркасы по датам слева направо последовательно закрывают слоты г/п.
 */

require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';
require_once __DIR__ . '/../auth/includes/db.php';

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

$pdo = getPdo('plan_u3');
$todayIso = (new DateTime())->format('Y-m-d');
$loadError = '';
$wfMaxCompletionPctDefault = 80;
/** Ширина бумаги (= каркаса), мм: группа станков «шире» vs «до» порога. */
const WF_PAPER_WIDTH_SPLIT_MM = 400;

/** Право менять план каркасов: мастер / директор / supervisor в цехе U3 (как active_positions). */
$dbAuth = Database::getInstance();
$userDepartmentsForU3 = $dbAuth->select(
    'SELECT ud.department_code, r.name AS role_name
     FROM auth_user_departments ud
     JOIN auth_roles r ON ud.role_id = r.id
     WHERE ud.user_id = ?',
    [$session['user_id']]
);
$wireframeBuildEditRoleNames = ['supervisor', 'director', 'master'];
$wireframeBuildCanEdit = false;
foreach ($userDepartmentsForU3 as $dept) {
    if (($dept['department_code'] ?? '') === 'U3'
        && in_array((string) ($dept['role_name'] ?? ''), $wireframeBuildEditRoleNames, true)
    ) {
        $wireframeBuildCanEdit = true;
        break;
    }
}

function wfNormalizeFilterKey(string $name): string
{
    $name = preg_replace('/\[.*$/u', '', $name);
    $name = trim($name);

    return mb_strtoupper($name, 'UTF-8');
}

function wfNormalizeTextKey(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name));

    return mb_strtoupper($name, 'UTF-8');
}

/**
 * @param list<string> $dates
 * @return list<string>
 */
function wfFilterPlanDatesFromToday(array $dates, string $todayIso): array
{
    $filtered = [];
    foreach ($dates as $date) {
        $d = (string) $date;
        if ($d !== '' && $d >= $todayIso) {
            $filtered[] = $d;
        }
    }
    if ($filtered === []) {
        return [];
    }
    sort($filtered);
    $fullDateRange = [];
    $cursor = new DateTimeImmutable((string) $filtered[0]);
    $lastDate = new DateTimeImmutable((string) $filtered[count($filtered) - 1]);
    while ($cursor <= $lastDate) {
        $fullDateRange[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $fullDateRange;
}

/**
 * Долг: план каркасов на прошедшие даты (ещё не перенесён / не снят с плана).
 *
 * @param array<string, int> $wfByDate
 * @return list<array{date: string, qty: int}>
 */
function wfBuildWireframeDebtShifts(array $wfByDate, string $todayIso): array
{
    $shifts = [];
    foreach ($wfByDate as $d => $q) {
        $qty = max(0, (int) $q);
        if ($qty <= 0 || (string) $d >= $todayIso) {
            continue;
        }
        $shifts[] = ['date' => (string) $d, 'qty' => $qty];
    }
    usort($shifts, static function ($a, $b) {
        return strcmp((string) $a['date'], (string) $b['date']);
    });

    return $shifts;
}

function wfEnsureCorrugationPlanV2Table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS corrugation_plan_v2 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_row_key VARCHAR(255) NOT NULL,
            order_number VARCHAR(64) NOT NULL,
            filter_name VARCHAR(255) NOT NULL,
            package_key VARCHAR(255) NOT NULL,
            package_name VARCHAR(255) NOT NULL,
            plan_date DATE NOT NULL,
            group_id VARCHAR(128) NOT NULL,
            strip_id VARCHAR(128) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cp2_row_date (source_row_key, plan_date),
            KEY idx_cp2_order (order_number),
            KEY idx_cp2_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * @param array<string, mixed> $meta
 */
function wfWireframeSummary(array $meta): string
{
    $ext = trim((string) ($meta['ext_wireframe'] ?? ''));
    $int = trim((string) ($meta['int_wireframe'] ?? ''));
    $parts = [];
    if ($ext !== '') {
        $parts[] = 'нар. ' . $ext;
    }
    if ($int !== '') {
        $parts[] = 'внутр. ' . $int;
    }

    return implode('; ', $parts);
}

/**
 * @param array<string, mixed> $meta
 */
function wfRowHasWireframe(array $meta): bool
{
    return wfWireframeSummary($meta) !== '';
}

/**
 * Группа станков по ширине бумаги (как ширина каркаса), мм.
 *
 * @param array<string, mixed> $meta
 * @return array{attr: string, label: string, width_display: string}
 */
function wfMachineGroupFromMeta(array $meta): array
{
    $w = $meta['paper_width_mm'] ?? null;
    if ($w === null || (float) $w <= 0) {
        return ['attr' => 'notwide', 'label' => '400', 'width_display' => '—'];
    }
    $mm = (float) $w;
    if ($mm > WF_PAPER_WIDTH_SPLIT_MM) {
        return ['attr' => 'wide', 'label' => '600', 'width_display' => wfFormatMm($mm)];
    }

    return ['attr' => 'notwide', 'label' => '400', 'width_display' => wfFormatMm($mm)];
}

function wfFormatMm(float $mm): string
{
    if (abs($mm - round($mm)) < 0.001) {
        return (string) (int) round($mm);
    }

    return rtrim(rtrim(number_format($mm, 1, '.', ''), '0'), '.');
}

function wfEnsureWireframeBuildPlanTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wireframe_build_plan (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_row_key VARCHAR(255) NOT NULL,
            order_number VARCHAR(64) NOT NULL,
            filter_name VARCHAR(255) NOT NULL,
            plan_date DATE NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wf_plan_row_date (source_row_key, plan_date),
            KEY idx_wf_plan_order (order_number),
            KEY idx_wf_plan_date (plan_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Сохранить количество каркасов на дату (0 — удалить запись).
 */
function wfApplyWireframePlanSetCell(
    PDO $pdo,
    string $rowKey,
    string $order,
    string $filterName,
    string $planDate,
    int $qty,
    ?int $userId
): void {
    $expectedKey = $order . '|' . wfNormalizeTextKey($filterName);
    if ($rowKey !== $expectedKey) {
        throw new RuntimeException('Несовпадение ключа строки с заявкой и позицией.');
    }
    if ($qty < 0 || $qty > 999999) {
        throw new RuntimeException('Недопустимое количество.');
    }
    wfEnsureWireframeBuildPlanTable($pdo);
    if ($qty <= 0) {
        $del = $pdo->prepare('DELETE FROM wireframe_build_plan WHERE source_row_key = ? AND plan_date = ?');
        $del->execute([mb_substr($rowKey, 0, 255, 'UTF-8'), $planDate]);

        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO wireframe_build_plan (source_row_key, order_number, filter_name, plan_date, qty, created_by)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE qty = VALUES(qty), updated_at = CURRENT_TIMESTAMP, created_by = VALUES(created_by)
    ');
    $ins->execute([
        mb_substr($rowKey, 0, 255, 'UTF-8'),
        mb_substr($order, 0, 64, 'UTF-8'),
        mb_substr($filterName, 0, 255, 'UTF-8'),
        $planDate,
        $qty,
        $userId,
    ]);
}

$maxPct = max(0, min(100, (int) $wfMaxCompletionPctDefault));
if (isset($_GET['max_pct']) && $_GET['max_pct'] !== '') {
    $fromUrl = (int) $_GET['max_pct'];
    if ($fromUrl >= 0 && $fromUrl <= 100) {
        $maxPct = $fromUrl;
    }
}

$rows = [];
try {
    $sql = "
        SELECT
            agg.order_number,
            agg.filter_name,
            agg.ordered,
            COALESCE(prod.produced, 0) AS produced
        FROM (
            SELECT order_number, `filter` AS filter_name, SUM(`count`) AS ordered
            FROM orders
            WHERE (hide IS NULL OR hide != 1)
            GROUP BY order_number, `filter`
        ) agg
        LEFT JOIN (
            SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
            FROM manufactured_production
            GROUP BY name_of_order, name_of_filter
        ) prod
            ON prod.name_of_order = agg.order_number
           AND prod.name_of_filter = agg.filter_name
        WHERE agg.ordered > COALESCE(prod.produced, 0)
          AND agg.ordered > 0
          AND COALESCE(prod.produced, 0) * 100 <= {$maxPct} * agg.ordered
        ORDER BY agg.order_number, agg.filter_name
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$buildPlanMap = [];
$buildPlanDates = [];
try {
    $sqlBuildPlan = "
        SELECT
            bp.order_number,
            bp.filter AS filter_name,
            bp.day_date,
            SUM(bp.qty) AS qty
        FROM build_plans bp
        INNER JOIN (
            SELECT DISTINCT order_number
            FROM orders
            WHERE (hide IS NULL OR hide != 1)
        ) ao ON ao.order_number = bp.order_number
        WHERE bp.shift = 'D'
        GROUP BY bp.order_number, bp.filter, bp.day_date
        ORDER BY bp.day_date, bp.order_number, bp.filter
    ";
    $planRows = $pdo->query($sqlBuildPlan)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($planRows as $pr) {
        $order = (string) ($pr['order_number'] ?? '');
        $filter = (string) ($pr['filter_name'] ?? '');
        $date = (string) ($pr['day_date'] ?? '');
        $qty = (int) ($pr['qty'] ?? 0);
        if ($order === '' || $filter === '' || $date === '' || $date < $todayIso) {
            continue;
        }
        $key = $order . '|' . wfNormalizeFilterKey($filter);
        if (!isset($buildPlanMap[$key])) {
            $buildPlanMap[$key] = [];
        }
        if (!isset($buildPlanMap[$key][$date])) {
            $buildPlanMap[$key][$date] = 0;
        }
        $buildPlanMap[$key][$date] += $qty;
        $buildPlanDates[$date] = true;
    }
    try {
        wfEnsureCorrugationPlanV2Table($pdo);
        $stmtV2Dates = $pdo->query('SELECT DISTINCT plan_date FROM corrugation_plan_v2 WHERE qty > 0 AND plan_date >= ' . $pdo->quote($todayIso));
        if ($stmtV2Dates) {
            while ($rowV2 = $stmtV2Dates->fetch(PDO::FETCH_ASSOC)) {
                $pd = trim((string) ($rowV2['plan_date'] ?? ''));
                if ($pd !== '' && $pd >= $todayIso) {
                    $buildPlanDates[$pd] = true;
                }
            }
        }
    } catch (Throwable $eV2) {
    }
    $buildPlanDates = wfFilterPlanDatesFromToday(array_keys($buildPlanDates), $todayIso);
} catch (Throwable $e) {
    $buildPlanMap = [];
    $buildPlanDates = [];
}

/** [rowKey][date] => qty г/п */
$gofroPlanMap = [];
try {
    wfEnsureCorrugationPlanV2Table($pdo);
    $stmtGofroPlan = $pdo->query('
        SELECT source_row_key, plan_date, SUM(qty) AS qty
        FROM corrugation_plan_v2
        WHERE qty > 0
        GROUP BY source_row_key, plan_date
    ');
    $dateSet = [];
    foreach ($buildPlanDates as $d) {
        $dateSet[(string) $d] = true;
    }
    foreach ($stmtGofroPlan->fetchAll(PDO::FETCH_ASSOC) as $gp) {
        $rk = trim((string) ($gp['source_row_key'] ?? ''));
        $pd = trim((string) ($gp['plan_date'] ?? ''));
        $q = (int) ($gp['qty'] ?? 0);
        if ($rk === '' || $pd === '' || $q <= 0) {
            continue;
        }
        if (!isset($gofroPlanMap[$rk])) {
            $gofroPlanMap[$rk] = [];
        }
        $gofroPlanMap[$rk][$pd] = ($gofroPlanMap[$rk][$pd] ?? 0) + $q;
        if ($pd >= $todayIso) {
            $dateSet[$pd] = true;
        }
    }
    $buildPlanDates = wfFilterPlanDatesFromToday(array_merge($buildPlanDates, array_keys($dateSet)), $todayIso);
} catch (Throwable $e) {
    $gofroPlanMap = [];
}

/** [rowKey][date] => qty каркасов (план), все даты */
$wireframePlanMapAll = [];
try {
    wfEnsureWireframeBuildPlanTable($pdo);
    $stmtWf = $pdo->query('
        SELECT source_row_key, plan_date, SUM(qty) AS qty
        FROM wireframe_build_plan
        WHERE qty > 0
        GROUP BY source_row_key, plan_date
    ');
    $dateSetWf = [];
    foreach ($buildPlanDates as $d) {
        $dateSetWf[(string) $d] = true;
    }
    foreach ($stmtWf->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $rk = trim((string) ($w['source_row_key'] ?? ''));
        $pd = trim((string) ($w['plan_date'] ?? ''));
        $q = (int) ($w['qty'] ?? 0);
        if ($rk === '' || $pd === '' || $q <= 0) {
            continue;
        }
        if (!isset($wireframePlanMapAll[$rk])) {
            $wireframePlanMapAll[$rk] = [];
        }
        $wireframePlanMapAll[$rk][$pd] = ($wireframePlanMapAll[$rk][$pd] ?? 0) + $q;
        if ($pd >= $todayIso) {
            $dateSetWf[$pd] = true;
        }
    }
    $buildPlanDates = wfFilterPlanDatesFromToday(array_merge($buildPlanDates, array_keys($dateSetWf)), $todayIso);
} catch (Throwable $e) {
    $wireframePlanMapAll = [];
}

/** [rowKey][date] => qty в видимом горизонте (даты таблицы) */
$wireframePlanMap = [];
foreach ($wireframePlanMapAll as $rk => $byDate) {
    foreach ($byDate as $pd => $q) {
        if ((string) $pd >= $todayIso) {
            if (!isset($wireframePlanMap[$rk])) {
                $wireframePlanMap[$rk] = [];
            }
            $wireframePlanMap[$rk][(string) $pd] = (int) $q;
        }
    }
}

$filterMetaByKey = [];
if (!empty($rows)) {
    $rawFilters = [];
    foreach ($rows as $row) {
        $raw = trim((string) ($row['filter_name'] ?? ''));
        if ($raw !== '') {
            $rawFilters[$raw] = true;
        }
    }
    if (!empty($rawFilters)) {
        try {
            $rawFilterList = array_keys($rawFilters);
            $placeholders = implode(',', array_fill(0, count($rawFilterList), '?'));
            $sqlMeta = "
                SELECT
                    rfs.filter AS filter_name,
                    rfs.analog AS analog,
                    rfs.filter_package AS filter_package,
                    ppr.p_p_paper_width AS paper_width_mm,
                    ppr.p_p_fold_height AS fold_height,
                    ppr.p_p_fold_count AS fold_count,
                    ppr.p_p_ext_wireframe AS ext_wireframe,
                    ppr.p_p_int_wireframe AS int_wireframe
                FROM round_filter_structure rfs
                LEFT JOIN paper_package_round ppr
                    ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                WHERE rfs.filter IN ($placeholders)
            ";
            $stmtMeta = $pdo->prepare($sqlMeta);
            $stmtMeta->execute($rawFilterList);
            foreach ($stmtMeta->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                $metaKey = wfNormalizeFilterKey((string) ($metaRow['filter_name'] ?? ''));
                if ($metaKey === '') {
                    continue;
                }
                if (!isset($filterMetaByKey[$metaKey])) {
                    $filterMetaByKey[$metaKey] = [
                        'analog' => null,
                        'filter_package' => null,
                        'paper_width_mm' => null,
                        'fold_height' => null,
                        'fold_count' => null,
                        'ext_wireframe' => null,
                        'int_wireframe' => null,
                    ];
                }
                $metaAnalog = trim((string) ($metaRow['analog'] ?? ''));
                if ($metaAnalog !== '' && $filterMetaByKey[$metaKey]['analog'] === null) {
                    $filterMetaByKey[$metaKey]['analog'] = $metaAnalog;
                }
                $pkg = trim((string) ($metaRow['filter_package'] ?? ''));
                if ($pkg !== '' && $filterMetaByKey[$metaKey]['filter_package'] === null) {
                    $filterMetaByKey[$metaKey]['filter_package'] = $pkg;
                }
                if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$metaKey]['paper_width_mm'] === null) {
                    $filterMetaByKey[$metaKey]['paper_width_mm'] = (float) $metaRow['paper_width_mm'];
                }
                if ($metaRow['fold_height'] !== null && $filterMetaByKey[$metaKey]['fold_height'] === null) {
                    $filterMetaByKey[$metaKey]['fold_height'] = (float) $metaRow['fold_height'];
                }
                if ($metaRow['fold_count'] !== null && $filterMetaByKey[$metaKey]['fold_count'] === null) {
                    $filterMetaByKey[$metaKey]['fold_count'] = (float) $metaRow['fold_count'];
                }
                $ew = trim((string) ($metaRow['ext_wireframe'] ?? ''));
                if ($ew !== '' && $filterMetaByKey[$metaKey]['ext_wireframe'] === null) {
                    $filterMetaByKey[$metaKey]['ext_wireframe'] = $ew;
                }
                $iw = trim((string) ($metaRow['int_wireframe'] ?? ''));
                if ($iw !== '' && $filterMetaByKey[$metaKey]['int_wireframe'] === null) {
                    $filterMetaByKey[$metaKey]['int_wireframe'] = $iw;
                }
            }
            foreach ($rawFilterList as $rawFilterTry) {
                $targetKey = wfNormalizeFilterKey((string) $rawFilterTry);
                if ($targetKey === '') {
                    continue;
                }
                $existingPkg = trim((string) ($filterMetaByKey[$targetKey]['filter_package'] ?? ''));
                if ($existingPkg !== '') {
                    continue;
                }
                $stmtAlt = $pdo->prepare('
                    SELECT
                        rfs.filter AS filter_name,
                        rfs.analog AS analog,
                        rfs.filter_package AS filter_package,
                        ppr.p_p_paper_width AS paper_width_mm,
                        ppr.p_p_fold_height AS fold_height,
                        ppr.p_p_fold_count AS fold_count,
                        ppr.p_p_ext_wireframe AS ext_wireframe,
                        ppr.p_p_int_wireframe AS int_wireframe
                    FROM round_filter_structure rfs
                    LEFT JOIN paper_package_round ppr
                        ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                    WHERE UPPER(TRIM(rfs.filter)) = UPPER(TRIM(?))
                       OR UPPER(TRIM(rfs.analog)) = UPPER(TRIM(?))
                    LIMIT 8
                ');
                $stmtAlt->execute([$rawFilterTry, $rawFilterTry]);
                foreach ($stmtAlt->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                    $pkg = trim((string) ($metaRow['filter_package'] ?? ''));
                    if ($pkg === '') {
                        continue;
                    }
                    if (!isset($filterMetaByKey[$targetKey])) {
                        $filterMetaByKey[$targetKey] = [
                            'analog' => null,
                            'filter_package' => null,
                            'paper_width_mm' => null,
                            'fold_height' => null,
                            'fold_count' => null,
                            'ext_wireframe' => null,
                            'int_wireframe' => null,
                        ];
                    }
                    $metaAnalog = trim((string) ($metaRow['analog'] ?? ''));
                    if ($metaAnalog !== '' && $filterMetaByKey[$targetKey]['analog'] === null) {
                        $filterMetaByKey[$targetKey]['analog'] = $metaAnalog;
                    }
                    $filterMetaByKey[$targetKey]['filter_package'] = $pkg;
                    if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$targetKey]['paper_width_mm'] === null) {
                        $filterMetaByKey[$targetKey]['paper_width_mm'] = (float) $metaRow['paper_width_mm'];
                    }
                    if ($metaRow['fold_height'] !== null && $filterMetaByKey[$targetKey]['fold_height'] === null) {
                        $filterMetaByKey[$targetKey]['fold_height'] = (float) $metaRow['fold_height'];
                    }
                    if ($metaRow['fold_count'] !== null && $filterMetaByKey[$targetKey]['fold_count'] === null) {
                        $filterMetaByKey[$targetKey]['fold_count'] = (float) $metaRow['fold_count'];
                    }
                    $ew = trim((string) ($metaRow['ext_wireframe'] ?? ''));
                    if ($ew !== '' && $filterMetaByKey[$targetKey]['ext_wireframe'] === null) {
                        $filterMetaByKey[$targetKey]['ext_wireframe'] = $ew;
                    }
                    $iw = trim((string) ($metaRow['int_wireframe'] ?? ''));
                    if ($iw !== '' && $filterMetaByKey[$targetKey]['int_wireframe'] === null) {
                        $filterMetaByKey[$targetKey]['int_wireframe'] = $iw;
                    }
                    break;
                }
            }
            foreach ($rawFilterList as $rawFilterTry) {
                $targetKey = wfNormalizeFilterKey((string) $rawFilterTry);
                if ($targetKey === '') {
                    continue;
                }
                $existingPkg = trim((string) ($filterMetaByKey[$targetKey]['filter_package'] ?? ''));
                if ($existingPkg !== '') {
                    continue;
                }
                $needle = trim((string) $rawFilterTry);
                if ($needle === '' || mb_strlen($needle, 'UTF-8') < 4) {
                    continue;
                }
                $stmtLike = $pdo->prepare('
                    SELECT
                        rfs.filter AS filter_name,
                        rfs.analog AS analog,
                        rfs.filter_package AS filter_package,
                        ppr.p_p_paper_width AS paper_width_mm,
                        ppr.p_p_fold_height AS fold_height,
                        ppr.p_p_fold_count AS fold_count,
                        ppr.p_p_ext_wireframe AS ext_wireframe,
                        ppr.p_p_int_wireframe AS int_wireframe
                    FROM round_filter_structure rfs
                    LEFT JOIN paper_package_round ppr
                        ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                    WHERE UPPER(TRIM(rfs.filter)) LIKE CONCAT(\'%\', UPPER(TRIM(?)), \'%\')
                       OR UPPER(TRIM(rfs.analog)) LIKE CONCAT(\'%\', UPPER(TRIM(?)), \'%\')
                    LIMIT 12
                ');
                $stmtLike->execute([$needle, $needle]);
                foreach ($stmtLike->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                    $pkg = trim((string) ($metaRow['filter_package'] ?? ''));
                    if ($pkg === '') {
                        continue;
                    }
                    if (!isset($filterMetaByKey[$targetKey])) {
                        $filterMetaByKey[$targetKey] = [
                            'analog' => null,
                            'filter_package' => null,
                            'paper_width_mm' => null,
                            'fold_height' => null,
                            'fold_count' => null,
                            'ext_wireframe' => null,
                            'int_wireframe' => null,
                        ];
                    }
                    $metaAnalog = trim((string) ($metaRow['analog'] ?? ''));
                    if ($metaAnalog !== '' && $filterMetaByKey[$targetKey]['analog'] === null) {
                        $filterMetaByKey[$targetKey]['analog'] = $metaAnalog;
                    }
                    $filterMetaByKey[$targetKey]['filter_package'] = $pkg;
                    if ($metaRow['paper_width_mm'] !== null && $filterMetaByKey[$targetKey]['paper_width_mm'] === null) {
                        $filterMetaByKey[$targetKey]['paper_width_mm'] = (float) $metaRow['paper_width_mm'];
                    }
                    if ($metaRow['fold_height'] !== null && $filterMetaByKey[$targetKey]['fold_height'] === null) {
                        $filterMetaByKey[$targetKey]['fold_height'] = (float) $metaRow['fold_height'];
                    }
                    if ($metaRow['fold_count'] !== null && $filterMetaByKey[$targetKey]['fold_count'] === null) {
                        $filterMetaByKey[$targetKey]['fold_count'] = (float) $metaRow['fold_count'];
                    }
                    $ew = trim((string) ($metaRow['ext_wireframe'] ?? ''));
                    if ($ew !== '' && $filterMetaByKey[$targetKey]['ext_wireframe'] === null) {
                        $filterMetaByKey[$targetKey]['ext_wireframe'] = $ew;
                    }
                    $iw = trim((string) ($metaRow['int_wireframe'] ?? ''));
                    if ($iw !== '' && $filterMetaByKey[$targetKey]['int_wireframe'] === null) {
                        $filterMetaByKey[$targetKey]['int_wireframe'] = $iw;
                    }
                    break;
                }
            }
        } catch (Throwable $e) {
            $filterMetaByKey = [];
        }
    }
}

$wireframeRows = [];
foreach ($rows as $r) {
    $rawFilter = (string) ($r['filter_name'] ?? '');
    $meta = $filterMetaByKey[wfNormalizeFilterKey($rawFilter)] ?? [];
    if (!wfRowHasWireframe($meta)) {
        continue;
    }
    $wireframeRows[] = $r;
}

/** Долг по каркасам: [rowKey] => [['date' => Y-m-d, 'qty' => int], ...] */
$wireframeDebtShiftMap = [];
foreach ($wireframeRows as $r) {
    $rawOrder = (string) ($r['order_number'] ?? '');
    $rawFilter = (string) ($r['filter_name'] ?? '');
    if ($rawOrder === '' || $rawFilter === '') {
        continue;
    }
    $rowKey = $rawOrder . '|' . wfNormalizeTextKey($rawFilter);
    $wfByDate = $wireframePlanMapAll[$rowKey] ?? [];
    $shifts = wfBuildWireframeDebtShifts($wfByDate, $todayIso);
    if ($shifts !== []) {
        $wireframeDebtShiftMap[$rowKey] = $shifts;
    }
}

$wfFooterSumWide = [];
$wfFooterSumNotWide = [];
foreach ($buildPlanDates as $__d) {
    $wfFooterSumWide[$__d] = 0;
    $wfFooterSumNotWide[$__d] = 0;
}
foreach ($wireframeRows as $r) {
    $rawFilter = (string) ($r['filter_name'] ?? '');
    $rawOrder = (string) ($r['order_number'] ?? '');
    $rowKey = $rawOrder . '|' . wfNormalizeTextKey($rawFilter);
    $meta = $filterMetaByKey[wfNormalizeFilterKey($rawFilter)] ?? [];
    $mg = wfMachineGroupFromMeta($meta);
    $attr = $mg['attr'];
    foreach ($buildPlanDates as $pd) {
        $q = (int) ($wireframePlanMap[$rowKey][$pd] ?? 0);
        if ($q <= 0) {
            continue;
        }
        if ($attr === 'wide') {
            $wfFooterSumWide[$pd] += $q;
        } else {
            $wfFooterSumNotWide[$pd] += $q;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = [];
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if (empty($payload)) {
        $payload = $_POST;
    }
    if (($payload['action'] ?? '') === 'set_wireframe_cell') {
        header('Content-Type: application/json; charset=utf-8');
        $isDate = static function (string $value): bool {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
        };
        try {
            if (!$wireframeBuildCanEdit) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Недостаточно прав для изменения плана каркасов.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rowKey = trim((string) ($payload['source_row_key'] ?? ''));
            $order = trim((string) ($payload['order_number'] ?? ''));
            $filterName = trim((string) ($payload['filter_name'] ?? ''));
            $planDate = trim((string) ($payload['plan_date'] ?? ''));
            $qty = max(0, (int) ($payload['qty'] ?? 0));
            if ($rowKey === '' || $order === '' || $filterName === '' || !$isDate($planDate)) {
                throw new RuntimeException('Некорректные параметры запроса.');
            }
            if ($planDate < $todayIso) {
                throw new RuntimeException('Нельзя менять план в прошедших датах.');
            }
            $userId = isset($session['user_id']) ? (int) $session['user_id'] : null;
            wfApplyWireframePlanSetCell($pdo, $rowKey, $order, $filterName, $planDate, $qty, $userId);
            echo json_encode(['ok' => true, 'qty' => $qty, 'plan_date' => $planDate, 'source_row_key' => $rowKey], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if (($payload['action'] ?? '') === 'apply_wireframe_debt') {
        header('Content-Type: application/json; charset=utf-8');
        $isDate = static function (string $value): bool {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
        };
        try {
            if (!$wireframeBuildCanEdit) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Недостаточно прав для изменения плана каркасов.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rowKey = trim((string) ($payload['source_row_key'] ?? ''));
            $order = trim((string) ($payload['order_number'] ?? ''));
            $filterName = trim((string) ($payload['filter_name'] ?? ''));
            $fromDate = trim((string) ($payload['from_date'] ?? ''));
            $toDate = trim((string) ($payload['to_date'] ?? ''));
            $qty = max(0, (int) ($payload['qty'] ?? 0));
            if ($rowKey === '' || $order === '' || $filterName === '' || !$isDate($fromDate) || !$isDate($toDate) || $qty <= 0) {
                throw new RuntimeException('Некорректные параметры переноса долга.');
            }
            if ($toDate < $todayIso) {
                throw new RuntimeException('Перенос долга только на сегодня или будущие даты.');
            }
            $userId = isset($session['user_id']) ? (int) $session['user_id'] : null;
            wfEnsureWireframeBuildPlanTable($pdo);
            if ($fromDate < $todayIso) {
                $stmtFrom = $pdo->prepare('
                    SELECT COALESCE(SUM(qty), 0) AS qty
                    FROM wireframe_build_plan
                    WHERE source_row_key = ? AND plan_date = ?
                ');
                $stmtFrom->execute([$rowKey, $fromDate]);
                $fromQty = (int) ($stmtFrom->fetchColumn() ?: 0);
                if ($fromQty > 0) {
                    $newFrom = max(0, $fromQty - $qty);
                    wfApplyWireframePlanSetCell($pdo, $rowKey, $order, $filterName, $fromDate, $newFrom, $userId);
                }
            }
            $stmtTo = $pdo->prepare('
                SELECT COALESCE(SUM(qty), 0) AS qty
                FROM wireframe_build_plan
                WHERE source_row_key = ? AND plan_date = ?
            ');
            $stmtTo->execute([$rowKey, $toDate]);
            $toQty = (int) ($stmtTo->fetchColumn() ?: 0);
            wfApplyWireframePlanSetCell($pdo, $rowKey, $order, $filterName, $toDate, $toQty + $qty, $userId);
            echo json_encode([
                'ok' => true,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'qty' => $qty,
                'to_qty' => $toQty + $qty,
                'source_row_key' => $rowKey,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$pageTitle = 'План изготовления каркасов';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — U3</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2457e6;
        }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 13px/1.35 "Segoe UI", Roboto, Arial, sans-serif; }
        .wrap { max-width: 100%; margin: 0 auto; padding: 14px; }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; overflow-x: auto; }
        .muted { color: var(--muted); }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; align-items: center; }
        .toolbar a, .toolbar-btn {
            display: inline-block;
            padding: 6px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--ink);
            text-decoration: none;
            font-size: 12px;
        }
        .toolbar button.toolbar-btn {
            font: inherit;
            cursor: pointer;
        }
        .toolbar a:hover { border-color: #93c5fd; background: #eff6ff; }
        .toolbar-btn.secondary { font-weight: 500; }
        table.wf-plan-table {
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12px;
            width: max-content;
        }
        table.wf-plan-table th, table.wf-plan-table td {
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 4px 6px;
        }
        th { background: #f9fafb; font-size: 11px; position: sticky; top: 0; z-index: 2; box-shadow: 0 1px 0 0 var(--border); }
        .col-pos { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .col-analog, .col-order, .wf-col-width, .wf-col-group { white-space: nowrap; }
        .col-order form { display: inline; margin: 0; }
        .col-order button[type="submit"] {
            appearance: none;
            border: 0;
            background: none;
            color: var(--accent);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            text-decoration: underline;
            font-variant-numeric: tabular-nums;
        }
        .col-order button[type="submit"]:hover { color: #1e47c5; }
        .date-col { text-align: center; min-width: 52px; vertical-align: middle; }
        .date-col.weekend { background: #faf5ff; }
        thead th.date-col {
            vertical-align: bottom;
            padding: 4px 4px 5px;
            line-height: 1.15;
        }
        .wf-th-date {
            display: block;
            font-weight: 600;
            font-size: 11px;
        }
        .wf-th-load {
            display: block;
            margin-top: 3px;
            font-size: 9px;
            font-weight: 500;
            color: #64748b;
        }
        .wf-th-load > span { display: block; white-space: nowrap; }
        .wf-th-load-val { font-weight: 700; color: #334155; font-variant-numeric: tabular-nums; }
        .wf-date-cell {
            position: relative;
            min-height: 36px;
            min-width: 40px;
            overflow: hidden;
            background: #fff;
            --wf-cover-pct: 0%;
        }
        /* тело таблицы: выходные — базовый белый */
        .wf-date-cell.date-col.weekend {
            background: #fff;
        }
        /* Гистограмма покрытия плана гофро (как pos-fill на active_positions) */
        .wf-date-cell .wf-date-cell-fill {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: var(--wf-cover-pct, 0%);
            pointer-events: none;
            border-radius: 0;
            transition: width 0.2s ease, opacity 0.15s;
            z-index: 0;
        }
        /* Справочно: план г/п (второстепенно) */
        .wf-info-gofro {
            position: absolute;
            right: 2px;
            bottom: 2px;
            font-size: 10px;
            font-weight: 500;
            color: #94a3b8;
            line-height: 1.15;
            z-index: 1;
            pointer-events: none;
            user-select: none;
            white-space: nowrap;
            max-width: calc(100% - 4px);
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Основное: план каркасов */
        .wf-main-qty {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-left: 4px;
            font-size: 17px;
            font-weight: 400;
            color: #0f766e;
            line-height: 1;
            z-index: 2;
            pointer-events: none;
            user-select: none;
            font-variant-numeric: tabular-nums;
        }
        .wf-date-cell--editable {
            cursor: pointer;
        }
        .wf-col-width {
            text-align: right;
            font-variant-numeric: tabular-nums;
            max-width: 56px;
        }
        .wf-col-group {
            font-size: 11px;
            text-align: center;
            max-width: 72px;
        }
        tbody tr.wf-data-row { background: #fff; }
        tbody tr.wf-data-row.wf-row--gofro-uncovered,
        tbody tr.wf-data-row.wf-row--gofro-uncovered td {
            background-color: #fff7ed;
        }
        tbody tr.wf-data-row.wf-row--gofro-uncovered td.wf-date-cell {
            background-color: #fff7ed;
        }
        table.wf-plan-table.wf-filter-wide tbody tr.wf-data-row[data-machine-group="notwide"] {
            display: none;
        }
        table.wf-plan-table.wf-filter-notwide tbody tr.wf-data-row[data-machine-group="wide"] {
            display: none;
        }
        .toolbar .wf-filter-btn.is-active[data-wf-hl="wide"] {
            border-color: #059669;
            background: #ecfdf5;
        }
        .toolbar .wf-filter-btn.is-active[data-wf-hl="notwide"] {
            border-color: #2563eb;
            background: #eff6ff;
        }
        th.debt-col, td.debt-cell {
            width: 0.1%;
            min-width: 56px;
            max-width: 88px;
            text-align: center;
            box-sizing: border-box;
        }
        td.debt-cell {
            vertical-align: middle;
            padding: 1px 3px;
            height: 24px;
            max-height: 24px;
            min-height: 24px;
            overflow: hidden;
            position: relative;
        }
        td.debt-cell.debt-cell--warn {
            background: #fffbeb;
            padding-right: 14px;
        }
        td.debt-cell.debt-cell--warn::after {
            content: "⏳";
            position: absolute;
            right: 2px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            opacity: 0.75;
            pointer-events: none;
        }
        tr.wf-data-row.has-overdue-debt td.col-pos {
            background: #fff7ed;
            box-shadow: inset 3px 0 0 #ea580c;
        }
        .debt-list {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: center;
            gap: 1px;
            height: 20px;
            max-height: 20px;
            overflow: hidden;
            white-space: nowrap;
        }
        .debt-shift {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            min-height: 16px;
            max-height: 18px;
            padding: 0 2px;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            background: #f9fafb;
            color: #374151;
            font-size: 9px;
            font-weight: 600;
            line-height: 1.15;
            cursor: grab;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .debt-shift.drag-source-single {
            outline: 2px solid #93c5fd;
            outline-offset: -2px;
        }
        .debt-more {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            min-height: 16px;
            padding: 0 3px;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            background: #f1f5f9;
            color: #475569;
            font-size: 9px;
            font-weight: 700;
            cursor: pointer;
            flex-shrink: 0;
        }
        .debt-more:hover { background: #e2e8f0; }
        .debt-popover[hidden] { display: none; }
        .debt-popover {
            position: fixed;
            z-index: 10001;
            max-width: min(320px, calc(100vw - 16px));
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.15);
        }
        .debt-popover__inner {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            max-height: 200px;
            overflow: auto;
        }
        .debt-shift.debt-shift--popover {
            min-width: 52px;
            padding: 4px 6px;
            font-size: 10px;
        }
        .wf-date-cell.drag-drop-target {
            outline: 2px solid #3b82f6;
            outline-offset: -2px;
            background: #eff6ff !important;
        }
        tfoot td {
            background: #f1f5f9;
            border-top: 2px solid #cbd5e1;
            font-size: 11px;
            vertical-align: middle;
        }
        .wf-tfoot-label {
            text-align: left;
            color: #475569;
            font-weight: 600;
            white-space: normal;
            line-height: 1.2;
        }
        .wf-foot-sum {
            text-align: center;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            color: #0f172a;
        }
        .wf-cell-panel {
            position: fixed;
            z-index: 1000;
            min-width: 200px;
            max-width: 280px;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            font-size: 12px;
        }
        .wf-cell-panel[hidden] { display: none !important; }
        .wf-cell-panel__title { font-weight: 600; margin: 0 0 8px; line-height: 1.25; color: var(--ink); }
        .wf-cell-panel__meta { font-size: 11px; color: var(--muted); margin: 0 0 8px; }
        .wf-cell-panel__label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); margin: 8px 0 4px; }
        .wf-cell-panel__grid { display: flex; flex-wrap: wrap; gap: 6px; }
        .wf-cell-panel__grid button {
            min-width: 44px;
            padding: 5px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #f8fafc;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            font-variant-numeric: tabular-nums;
        }
        .wf-cell-panel__grid button:hover { border-color: #93c5fd; background: #eff6ff; }
        .wf-cell-panel__manual { display: flex; gap: 6px; align-items: center; margin-top: 8px; }
        .wf-cell-panel__manual input {
            width: 72px;
            padding: 5px 6px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font: inherit;
        }
        .wf-cell-panel__manual .wf-cell-input-clear {
            padding: 5px 10px;
            border-radius: 6px;
            font: inherit;
            font-size: 12px;
            cursor: pointer;
            border: 1px solid var(--border);
            background: #fff;
        }
        .wf-cell-panel__manual .wf-cell-input-clear:hover {
            border-color: #93c5fd;
            background: #eff6ff;
        }
        .wf-cell-panel__actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; justify-content: flex-end; }
        .wf-cell-panel__actions button {
            padding: 5px 12px;
            border-radius: 6px;
            font: inherit;
            cursor: pointer;
            border: 1px solid var(--border);
            background: #fff;
        }
        .wf-cell-panel__actions .wf-btn-primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            font-weight: 600;
        }
        .wf-cell-panel__err { color: #b91c1c; font-size: 11px; margin-top: 6px; }
        .ind-modal[hidden] { display: none; }
        .ind-modal {
            position: fixed;
            inset: 0;
            z-index: 10002;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, .45);
            padding: 16px;
        }
        .ind-modal__dialog {
            width: min(460px, 100%);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 14px 40px rgba(2, 8, 20, .25);
            overflow: hidden;
        }
        .ind-modal__head {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            font-size: 15px;
        }
        .ind-modal__body {
            padding: 12px 14px;
            display: grid;
            gap: 10px;
        }
        .ind-field {
            display: grid;
            gap: 6px;
        }
        .ind-field label {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
        }
        .ind-field input {
            width: 100%;
            max-width: 120px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 6px 8px;
            font: inherit;
        }
        .ind-modal__foot {
            padding: 12px 14px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1 style="margin:0 0 8px; font-size:20px;"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted" style="margin:0 0 10px;">
        Позиции с ненулевым каркасом в справочнике гофропакета (наружный/внутренний), выполнение заявки не выше <?= (int) $maxPct ?>%.
        Крупное число слева — <strong>план каркасов</strong> (основной); внизу справа мелко — план гофропакетов (справочно).
        Группы станков в колонке «Станки»: <strong>600</strong> — шире <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм по ширине бумаги (= каркаса), <strong>400</strong> — до <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм включительно или ширина не задана. Внизу таблицы — суммы плана каркасов по дням для каждой группы.
        <?php if ($wireframeBuildCanEdit): ?>
            Клик по ячейке даты — внести количество каркасов. Долг — каркасы, запланированные на прошедшие даты и ещё не снятые с плана; перетащите на дату с сегодня.
        <?php else: ?>
            Изменение плана каркасов доступно мастеру / директору / supervisor цеха U3.
        <?php endif; ?>
    </p>
    <div class="toolbar">
        <button type="button" id="open-max-pct-btn" class="toolbar-btn secondary" title="Порог % выполнения позиции для отображения в таблице">Выполнение ≤ <?= (int) $maxPct ?>%</button>
        <?php if ($loadError === ''): ?>
            <span class="muted" style="font-size:12px;">Фильтр:</span>
            <button type="button" class="toolbar-btn secondary wf-filter-btn" id="wfFilter600" data-wf-hl="wide" title="Только позиции группы 600 (шире <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм по бумаге). Повторный клик — все строки.">600</button>
            <button type="button" class="toolbar-btn secondary wf-filter-btn" id="wfFilter400" data-wf-hl="notwide" title="Только позиции группы 400 (до <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм или без ширины). Повторный клик — все строки.">400</button>
        <?php endif; ?>
    </div>
    <?php if ($loadError !== ''): ?>
        <div class="panel" style="padding:12px;">Ошибка загрузки: <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="panel">
            <table class="wf-plan-table">
                <thead>
                <tr>
                    <th class="col-pos">Позиция</th>
                    <th class="col-analog">Аналог</th>
                    <th class="col-order">Заявка</th>
                    <th class="wf-col-width" title="Ширина бумаги гофропакета (= ширина каркаса), мм">Ширина<br>бумаги, мм</th>
                    <th class="wf-col-group" title="600 — шире <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм по ширине бумаги; 400 — остальные">Станки</th>
                    <th class="debt-col" title="Долг: план каркасов на прошедшие даты, не выполненный к сегодня. Перетащите на дату в плане (с сегодня).">Долг</th>
                    <?php foreach ($buildPlanDates as $planDate):
                        $d = DateTime::createFromFormat('Y-m-d', (string) $planDate);
                        $isWeekend = $d ? in_array((int) $d->format('N'), [6, 7], true) : false;
                        $cls = $isWeekend ? 'date-col weekend' : 'date-col';
                        $label = $d ? $d->format('d.m') : htmlspecialchars((string) $planDate, ENT_QUOTES, 'UTF-8');
                        $pd = (string) $planDate;
                        $sum600 = (int) ($wfFooterSumWide[$pd] ?? 0);
                        $sum400 = (int) ($wfFooterSumNotWide[$pd] ?? 0);
                        $t600 = $sum600 > 0 ? (string) $sum600 : '';
                        $t400 = $sum400 > 0 ? (string) $sum400 : '';
                        $headTitle = $pd . ' — загрузка станков (план каркасов, шт.): 600 → ' . ($sum600 > 0 ? $sum600 : 0) . ', 400 → ' . ($sum400 > 0 ? $sum400 : 0);
                        ?>
                        <th class="<?= htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') ?>" data-plan-date="<?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($headTitle, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="wf-th-date"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="wf-th-load" aria-label="Загрузка станков по группам">
                                <span>600 <span class="wf-th-load-val wf-th-load-val--wide"><?= htmlspecialchars($t600, ENT_QUOTES, 'UTF-8') ?></span></span>
                                <span>400 <span class="wf-th-load-val wf-th-load-val--notwide"><?= htmlspecialchars($t400, ENT_QUOTES, 'UTF-8') ?></span></span>
                            </span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($wireframeRows)): ?>
                    <tr>
                        <td colspan="<?= 6 + count($buildPlanDates) ?>" class="muted" style="text-align:center;padding:16px;">
                            Нет позиций с каркасами при текущем пороге выполнения или нет строк в справочнике.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($wireframeRows as $r):
                        $rawOrder = (string) ($r['order_number'] ?? '');
                        $rawFilter = (string) ($r['filter_name'] ?? '');
                        $rowKey = $rawOrder . '|' . wfNormalizeTextKey($rawFilter);
                        $meta = $filterMetaByKey[wfNormalizeFilterKey($rawFilter)] ?? [];
                        $rowAnalog = trim((string) ($meta['analog'] ?? ''));
                        $wfHint = wfWireframeSummary($meta);
                        $ordered = (int) ($r['ordered'] ?? 0);
                        $produced = (int) ($r['produced'] ?? 0);
                        $remaining = max(0, $ordered - $produced);
                        $mg = wfMachineGroupFromMeta($meta);
                        $rowMachineAttr = $mg['attr'];
                        $rowPoolDiag = 0;
                        $rowGofroUncovered = false;
                        foreach ($buildPlanDates as $__planDate) {
                            $__pd = (string) $__planDate;
                            $__g = (int) ($gofroPlanMap[$rowKey][$__pd] ?? 0);
                            $__w = (int) ($wireframePlanMap[$rowKey][$__pd] ?? 0);
                            $rowPoolDiag += $__w;
                            if ($__g > 0) {
                                $__alloc = min($rowPoolDiag, $__g);
                                if ($__alloc < $__g) {
                                    $rowGofroUncovered = true;
                                }
                                $rowPoolDiag -= $__alloc;
                            }
                        }
                        ?>
                        <tr class="wf-data-row<?= $rowGofroUncovered ? ' wf-row--gofro-uncovered' : '' ?>"
                            data-machine-group="<?= htmlspecialchars($rowMachineAttr, ENT_QUOTES, 'UTF-8') ?>"
                            data-row-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                            data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <td class="col-pos" title="<?= htmlspecialchars($rawFilter . ($wfHint !== '' ? ' — каркас: ' . $wfHint : ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="col-analog" title="<?= $rowAnalog !== '' ? htmlspecialchars('Аналог: ' . $rowAnalog, ENT_QUOTES, 'UTF-8') : '' ?>">
                                <?= $rowAnalog !== '' ? htmlspecialchars($rowAnalog, ENT_QUOTES, 'UTF-8') : '<span class="muted">—</span>' ?>
                            </td>
                            <td class="col-order">
                                <?php if ($rawOrder !== ''): ?>
                                    <form action="show_order.php" method="post" target="_blank" rel="noopener">
                                        <input type="hidden" name="order_number" value="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit"><?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="wf-col-width" title="Из справочника paper_package_round (ширина бумаги)"><?= htmlspecialchars($mg['width_display'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="wf-col-group" title="Группа станков: 600 — шире <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм по бумаге; 400 — до <?= (int) WF_PAPER_WIDTH_SPLIT_MM ?> мм или ширина не задана"><?= htmlspecialchars($mg['label'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="debt-cell"
                                data-debt-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                                data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                                data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="debt-list"></div>
                            </td>
                            <?php
                            $pool = 0;
                            foreach ($buildPlanDates as $planDate):
                                $pd = (string) $planDate;
                                $gofroQty = (int) ($gofroPlanMap[$rowKey][$pd] ?? 0);
                                $wfQty = (int) ($wireframePlanMap[$rowKey][$pd] ?? 0);
                                $pool += $wfQty;
                                if ($gofroQty <= 0) {
                                    $coverPct = 0.0;
                                } else {
                                    $allocated = min($pool, $gofroQty);
                                    $coverPct = min(100.0, ($allocated / $gofroQty) * 100.0);
                                    $pool -= $allocated;
                                }
                                $hueBar = (int) round(min(100.0, $coverPct) * 1.2);
                                $title = 'Каркасы: ' . $wfQty . ' шт. · г/п к сборке (справочно): ' . $gofroQty . ' шт.'
                                    . ($gofroQty > 0 ? ' · покрытие этого слота г/п (по очереди дат): ' . number_format($coverPct, 1, ',', ' ') . '%' : '');
                                $pdObj = DateTime::createFromFormat('Y-m-d', $pd);
                                $isWEnd = $pdObj && in_array((int) $pdObj->format('N'), [6, 7], true);
                                $editableClass = $wireframeBuildCanEdit && $pd >= $todayIso ? ' wf-date-cell--editable' : '';
                                $coverPctStr = number_format($coverPct, 4, '.', '');
                                ?>
                                <td class="wf-date-cell date-col<?= $isWEnd ? ' weekend' : '' ?><?= htmlspecialchars($editableClass, ENT_QUOTES, 'UTF-8') ?>"
                                    style="--wf-cover-pct: <?= htmlspecialchars($coverPctStr, ENT_QUOTES, 'UTF-8') ?>%;"
                                    title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                                    data-plan-date="<?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?>"
                                    data-wf="<?= (int) $wfQty ?>"
                                    data-gofro="<?= (int) $gofroQty ?>"
                                    <?php if ($wireframeBuildCanEdit && $pd >= $todayIso): ?>
                                        tabindex="0"
                                        role="button"
                                        data-row-key="<?= htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8') ?>"
                                        data-order="<?= htmlspecialchars($rawOrder, ENT_QUOTES, 'UTF-8') ?>"
                                        data-filter="<?= htmlspecialchars($rawFilter, ENT_QUOTES, 'UTF-8') ?>"
                                        data-remaining="<?= (int) $remaining ?>"
                                    <?php endif; ?>
                                >
                                    <span class="wf-date-cell-fill" aria-hidden="true" style="background: <?= $gofroQty > 0 ? 'hsla(' . $hueBar . ', 65%, 52%, 0.28)' : 'transparent' ?>;"></span>
                                    <?php if ($gofroQty > 0): ?>
                                        <span class="wf-info-gofro" title="План гофропакетов к сборке (справочно)"><?= (int) $gofroQty ?></span>
                                    <?php endif; ?>
                                    <?php if ($wfQty > 0): ?>
                                        <span class="wf-main-qty"><?= (int) $wfQty ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($wireframeRows)): ?>
                <tfoot>
                <tr>
                    <td colspan="6" class="wf-tfoot-label">600</td>
                    <?php foreach ($buildPlanDates as $planDate):
                        $pd = (string) $planDate;
                        $d = DateTime::createFromFormat('Y-m-d', $pd);
                        $isWEnd = $d ? in_array((int) $d->format('N'), [6, 7], true) : false;
                        $sum = (int) ($wfFooterSumWide[$pd] ?? 0);
                        ?>
                        <td class="wf-foot-sum date-col<?= $isWEnd ? ' weekend' : '' ?>" data-foot="wide" data-plan-date="<?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?>"><?= $sum > 0 ? (int) $sum : '—' ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td colspan="6" class="wf-tfoot-label">400</td>
                    <?php foreach ($buildPlanDates as $planDate):
                        $pd = (string) $planDate;
                        $d = DateTime::createFromFormat('Y-m-d', $pd);
                        $isWEnd = $d ? in_array((int) $d->format('N'), [6, 7], true) : false;
                        $sum = (int) ($wfFooterSumNotWide[$pd] ?? 0);
                        ?>
                        <td class="wf-foot-sum date-col<?= $isWEnd ? ' weekend' : '' ?>" data-foot="notwide" data-plan-date="<?= htmlspecialchars($pd, ENT_QUOTES, 'UTF-8') ?>"><?= $sum > 0 ? (int) $sum : '—' ?></td>
                    <?php endforeach; ?>
                </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
    <div id="maxPctModal" class="ind-modal" hidden>
        <div class="ind-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="maxPctModalTitle">
            <div id="maxPctModalTitle" class="ind-modal__head">Порог выполнения позиций</div>
            <div class="ind-modal__body">
                <div class="ind-field">
                    <label for="maxPctInput">Макс. % выполнения для списка (включительно)</label>
                    <input id="maxPctInput" type="number" min="0" max="100" step="1" value="<?= (int) $maxPct ?>">
                    <p class="muted" style="margin:0; font-size:12px;">
                        Позиции с выпуском фильтров выше этого процента не показываются. При сохранении страница перезагрузится.
                        Значение по умолчанию — <?= (int) $wfMaxCompletionPctDefault ?>%. То же хранилище, что на странице «План гофро».
                    </p>
                </div>
            </div>
            <div class="ind-modal__foot">
                <button type="button" id="maxPctResetBtn" class="toolbar-btn secondary">Сброс (<?= (int) $wfMaxCompletionPctDefault ?>%)</button>
                <button type="button" id="maxPctCancelBtn" class="toolbar-btn secondary">Отмена</button>
                <button type="button" id="maxPctSaveBtn" class="toolbar-btn">Сохранить</button>
            </div>
        </div>
    </div>
    <script>
(function () {
    const serverDefaultMaxPct = <?= (int) $wfMaxCompletionPctDefault ?>;
    const currentPageMaxPct = <?= (int) $maxPct ?>;
    const gofroListPagePath = new URL('gofro_build_plan.php', window.location.href).pathname;
    const MAX_PCT_STORAGE_KEY = 'gofroBuildPlanMaxListPct:' + gofroListPagePath;
    const urlParams = new URLSearchParams(window.location.search);
    const openMaxPctBtn = document.getElementById('open-max-pct-btn');
    const maxPctModal = document.getElementById('maxPctModal');
    const maxPctInput = document.getElementById('maxPctInput');
    const maxPctSaveBtn = document.getElementById('maxPctSaveBtn');
    const maxPctCancelBtn = document.getElementById('maxPctCancelBtn');
    const maxPctResetBtn = document.getElementById('maxPctResetBtn');
    const wfPlanTable = document.querySelector('table.wf-plan-table');
    const wfFilter600 = document.getElementById('wfFilter600');
    const wfFilter400 = document.getElementById('wfFilter400');
    let wfMachineFilterMode = null;
    function applyWfMachineFilter(mode) {
        if (!wfPlanTable) return;
        wfPlanTable.classList.remove('wf-filter-wide', 'wf-filter-notwide');
        document.querySelectorAll('.wf-filter-btn.is-active').forEach(function (b) {
            b.classList.remove('is-active');
        });
        wfMachineFilterMode = mode;
        if (mode === 'wide') {
            wfPlanTable.classList.add('wf-filter-wide');
            if (wfFilter600) wfFilter600.classList.add('is-active');
        } else if (mode === 'notwide') {
            wfPlanTable.classList.add('wf-filter-notwide');
            if (wfFilter400) wfFilter400.classList.add('is-active');
        }
    }
    function toggleWfMachineFilter(mode) {
        if (wfMachineFilterMode === mode) {
            applyWfMachineFilter(null);
        } else {
            applyWfMachineFilter(mode);
        }
    }
    if (wfFilter600) {
        wfFilter600.addEventListener('click', function () {
            toggleWfMachineFilter('wide');
        });
    }
    if (wfFilter400) {
        wfFilter400.addEventListener('click', function () {
            toggleWfMachineFilter('notwide');
        });
    }
    function updateMaxPctBtnLabel() {
        if (openMaxPctBtn) {
            openMaxPctBtn.textContent = 'Выполнение ≤ ' + currentPageMaxPct + '%';
        }
    }
    function reloadWithMaxPct(pct) {
        const url = new URL(window.location.href);
        if (pct === serverDefaultMaxPct) {
            url.searchParams.delete('max_pct');
        } else {
            url.searchParams.set('max_pct', String(pct));
        }
        window.location.href = url.toString();
    }
    function openMaxPctModal() {
        if (!maxPctModal || !maxPctInput) return;
        maxPctInput.value = String(currentPageMaxPct);
        maxPctModal.hidden = false;
    }
    function closeMaxPctModal() {
        if (maxPctModal) maxPctModal.hidden = true;
    }
    try {
        if (!urlParams.has('max_pct')) {
            const savedMaxPct = localStorage.getItem(MAX_PCT_STORAGE_KEY);
            if (savedMaxPct !== null) {
                const parsedSaved = Math.max(0, Math.min(100, parseInt(savedMaxPct, 10)));
                if (!Number.isNaN(parsedSaved) && parsedSaved !== currentPageMaxPct) {
                    reloadWithMaxPct(parsedSaved);
                    return;
                }
            }
        }
    } catch (_) { /* ignore */ }
    if (openMaxPctBtn) {
        updateMaxPctBtnLabel();
        openMaxPctBtn.addEventListener('click', openMaxPctModal);
    }
    if (maxPctCancelBtn) maxPctCancelBtn.addEventListener('click', closeMaxPctModal);
    if (maxPctModal) {
        maxPctModal.addEventListener('click', function (e) {
            if (e.target === maxPctModal) closeMaxPctModal();
        });
    }
    if (maxPctSaveBtn && maxPctInput) {
        maxPctSaveBtn.addEventListener('click', function () {
            const newPct = Math.max(0, Math.min(100, parseInt(maxPctInput.value, 10) || serverDefaultMaxPct));
            try {
                localStorage.setItem(MAX_PCT_STORAGE_KEY, String(newPct));
            } catch (_) { /* ignore */ }
            closeMaxPctModal();
            if (newPct !== currentPageMaxPct) {
                reloadWithMaxPct(newPct);
            }
        });
    }
    if (maxPctResetBtn) {
        maxPctResetBtn.addEventListener('click', function () {
            try {
                localStorage.removeItem(MAX_PCT_STORAGE_KEY);
            } catch (_) { /* ignore */ }
            closeMaxPctModal();
            if (currentPageMaxPct !== serverDefaultMaxPct) {
                reloadWithMaxPct(serverDefaultMaxPct);
            }
        });
    }
})();
    </script>
    <?php if ($loadError === '' && !empty($wireframeRows)): ?>
    <div id="debtExpandPopover" class="debt-popover" hidden>
        <div id="debtExpandPopoverInner" class="debt-popover__inner"></div>
    </div>
    <div id="wfCellPanel" class="wf-cell-panel" hidden>
        <p class="wf-cell-panel__title" id="wfCellPanelTitle"></p>
        <p class="wf-cell-panel__meta" id="wfCellPanelMeta"></p>
        <div class="wf-cell-panel__label">Быстрый выбор</div>
        <div class="wf-cell-panel__grid" id="wfCellPanelGrid"></div>
        <div class="wf-cell-panel__label">Своё количество</div>
        <div class="wf-cell-panel__manual">
            <input type="number" id="wfCellQtyInput" min="0" max="999999" step="1" inputmode="numeric" placeholder="0">
            <button type="button" class="wf-cell-input-clear" id="wfCellInputClearBtn" title="Очистить поле ввода">Очистить</button>
            <button type="button" class="wf-btn-primary" id="wfCellApplyCustom">OK</button>
        </div>
        <div class="wf-cell-panel__actions">
            <button type="button" id="wfCellClearBtn">Сброс</button>
            <button type="button" id="wfCellCloseBtn">Закрыть</button>
        </div>
        <p class="wf-cell-panel__err" id="wfCellPanelErr" hidden></p>
    </div>
    <script>
(function () {
    const WF_CAN_EDIT = <?= $wireframeBuildCanEdit ? 'true' : 'false' ?>;
    const panel = document.getElementById('wfCellPanel');
    const titleEl = document.getElementById('wfCellPanelTitle');
    const metaEl = document.getElementById('wfCellPanelMeta');
    const gridEl = document.getElementById('wfCellPanelGrid');
    const inputEl = document.getElementById('wfCellQtyInput');
    const errEl = document.getElementById('wfCellPanelErr');
    const btnClose = document.getElementById('wfCellCloseBtn');
    const btnClear = document.getElementById('wfCellClearBtn');
    const btnInputClear = document.getElementById('wfCellInputClearBtn');
    const btnApplyCustom = document.getElementById('wfCellApplyCustom');
    let activeTd = null;
    window.WF_BUILD_PLAN_DATES = <?= json_encode($buildPlanDates, JSON_UNESCAPED_UNICODE) ?>;
    const WF_TODAY_ISO = <?= json_encode($todayIso, JSON_UNESCAPED_UNICODE) ?>;
    const initialWireframePlanAll = <?= json_encode($wireframePlanMapAll, JSON_UNESCAPED_UNICODE) ?> || {};
    const DEBT_COMPACT_VISIBLE = 3;
    const debtExpandPopover = document.getElementById('debtExpandPopover');
    const debtExpandPopoverInner = document.getElementById('debtExpandPopoverInner');
    const debtStateMap = {};
    const wfPlanByRow = {};
    const rowStateMap = new Map();
    let debtPopoverAnchorCell = null;
    let dragContext = null;

    function getTodayIso() {
        return WF_TODAY_ISO;
    }
    function toShortDate(iso) {
        const p = String(iso || '').split('-');
        return p.length === 3 ? (p[2] + '.' + p[1]) : iso;
    }
    function cloneDebtShifts(shifts) {
        return (Array.isArray(shifts) ? shifts : []).map(function (s) {
            return {
                date: String(s.date || ''),
                qty: Math.max(0, parseInt(s.qty || '0', 10) || 0),
            };
        }).filter(function (s) { return s.date && s.qty > 0; });
    }
    function getDebtShiftsForKey(rowKey) {
        return cloneDebtShifts(debtStateMap[rowKey] || []);
    }
    function setDebtShiftsForKey(rowKey, shifts) {
        const next = cloneDebtShifts(shifts).sort(function (a, b) { return a.date.localeCompare(b.date); });
        if (next.length === 0) {
            delete debtStateMap[rowKey];
        } else {
            debtStateMap[rowKey] = next;
        }
    }
    function getOverdueDebtQtyForRow(rowKey) {
        return getDebtShiftsForKey(rowKey)
            .filter(function (s) { return s.date && s.date < getTodayIso(); })
            .reduce(function (sum, s) { return sum + s.qty; }, 0);
    }
    function syncDebtFromPastWfPlan(tr) {
        const rowKey = tr.getAttribute('data-row-key') || '';
        if (!rowKey) {
            return;
        }
        syncWfPlanFromRow(tr);
        const wf = wfPlanByRow[rowKey] || {};
        const todayIso = getTodayIso();
        const shifts = [];
        Object.keys(wf).forEach(function (d) {
            const q = Math.max(0, parseInt(wf[d] || '0', 10) || 0);
            if (q > 0 && d < todayIso) {
                shifts.push({ date: d, qty: q });
            }
        });
        shifts.sort(function (a, b) { return a.date.localeCompare(b.date); });
        setDebtShiftsForKey(rowKey, shifts);
        renderDebtCell(tr);
    }
    function syncWfPlanFromRow(tr) {
        const rowKey = tr.getAttribute('data-row-key') || '';
        if (!rowKey) {
            return;
        }
        if (!wfPlanByRow[rowKey]) {
            wfPlanByRow[rowKey] = Object.assign({}, initialWireframePlanAll[rowKey] || {});
        }
        tr.querySelectorAll('td.wf-date-cell[data-plan-date]').forEach(function (td) {
            const d = td.getAttribute('data-plan-date') || '';
            if (!d) {
                return;
            }
            const q = parseInt(td.getAttribute('data-wf') || '0', 10) || 0;
            if (q > 0) {
                wfPlanByRow[rowKey][d] = q;
            } else {
                delete wfPlanByRow[rowKey][d];
            }
        });
    }
    function getRowState(tr) {
        const rowKey = tr.getAttribute('data-row-key') || '';
        return rowStateMap.get(rowKey) || null;
    }
    function bindDebtShiftDrag(item, state) {
        item.addEventListener('dragstart', function (e) {
            const fromDate = item.dataset.date || '';
            const qty = Math.max(0, parseInt(item.dataset.qty || '0', 10) || 0);
            if (!fromDate || qty <= 0 || !state || !WF_CAN_EDIT) {
                e.preventDefault();
                return;
            }
            dragContext = {
                sourceType: 'debt',
                rowKey: state.rowKey,
                order: state.order,
                filterName: state.filter,
                fromDate: fromDate,
                movedQty: qty,
            };
            item.classList.add('drag-source-single');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', state.rowKey + '|' + fromDate + '|debt');
            }
        });
        item.addEventListener('dragend', function () {
            item.classList.remove('drag-source-single');
            dragContext = null;
            document.querySelectorAll('td.wf-date-cell.drag-drop-target').forEach(function (el) {
                el.classList.remove('drag-drop-target');
            });
        });
    }
    function closeDebtExpandPopover() {
        debtPopoverAnchorCell = null;
        if (debtExpandPopover) {
            debtExpandPopover.hidden = true;
        }
        if (debtExpandPopoverInner) {
            debtExpandPopoverInner.innerHTML = '';
        }
    }
    function positionDebtExpandPopover(anchorCell) {
        if (!anchorCell || !debtExpandPopover || debtExpandPopover.hidden) {
            return;
        }
        const rect = anchorCell.getBoundingClientRect();
        const gap = 6;
        const vw = window.innerWidth || 800;
        const vh = window.innerHeight || 600;
        debtExpandPopover.style.left = Math.round(rect.left) + 'px';
        debtExpandPopover.style.top = Math.round(rect.bottom + gap) + 'px';
        const popRect = debtExpandPopover.getBoundingClientRect();
        let left = rect.left + (rect.width - popRect.width) / 2;
        let top = rect.bottom + gap;
        if (left + popRect.width > vw - 8) {
            left = Math.max(8, vw - popRect.width - 8);
        }
        if (left < 8) {
            left = 8;
        }
        if (top + popRect.height > vh - 8) {
            top = Math.max(8, rect.top - popRect.height - gap);
        }
        debtExpandPopover.style.left = Math.round(left) + 'px';
        debtExpandPopover.style.top = Math.round(top) + 'px';
    }
    function fillDebtPopoverShifts(debtCell, state) {
        if (!debtExpandPopoverInner || !state) {
            return;
        }
        debtExpandPopoverInner.innerHTML = '';
        getDebtShiftsForKey(state.rowKey).forEach(function (shift) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'debt-shift debt-shift--popover';
            item.draggable = true;
            item.dataset.date = shift.date;
            item.dataset.qty = String(shift.qty);
            item.title = 'Долг ' + toShortDate(shift.date) + ': ' + shift.qty + ' шт — перетащите на дату';
            item.textContent = toShortDate(shift.date) + ' • ' + shift.qty;
            bindDebtShiftDrag(item, state);
            debtExpandPopoverInner.appendChild(item);
        });
    }
    function openDebtExpandPopover(debtCell, state) {
        if (!debtCell || !state || getDebtShiftsForKey(state.rowKey).length === 0) {
            closeDebtExpandPopover();
            return;
        }
        debtPopoverAnchorCell = debtCell;
        fillDebtPopoverShifts(debtCell, state);
        if (debtExpandPopover) {
            debtExpandPopover.hidden = false;
            positionDebtExpandPopover(debtCell);
            window.requestAnimationFrame(function () { positionDebtExpandPopover(debtCell); });
        }
    }
    function renderDebtCell(tr) {
        const rowKey = tr.getAttribute('data-row-key') || '';
        const state = getRowState(tr);
        const debtCell = tr.querySelector('td.debt-cell');
        if (!rowKey || !state || !debtCell) {
            return;
        }
        const list = debtCell.querySelector('.debt-list');
        if (!list) {
            return;
        }
        const shifts = getDebtShiftsForKey(rowKey);
        const total = shifts.length;
        const debtQty = shifts.reduce(function (s, it) { return s + it.qty; }, 0);
        const overdueQty = getOverdueDebtQtyForRow(rowKey);
        tr.dataset.debtQty = String(debtQty);
        debtCell.classList.toggle('debt-cell--warn', total > DEBT_COMPACT_VISIBLE);
        debtCell.title = total > 0
            ? ('Долг: ' + debtQty + ' шт. на прошедшие даты (план не снят). Перетащите на дату с сегодня.')
            : '';
        tr.classList.toggle('has-overdue-debt', overdueQty > 0);
        list.innerHTML = '';
        shifts.slice(0, DEBT_COMPACT_VISIBLE).forEach(function (shift) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'debt-shift';
            item.draggable = WF_CAN_EDIT;
            item.dataset.date = shift.date;
            item.dataset.qty = String(shift.qty);
            item.title = 'Долг ' + toShortDate(shift.date) + ': ' + shift.qty + ' шт';
            item.textContent = String(shift.qty);
            if (WF_CAN_EDIT) {
                bindDebtShiftDrag(item, state);
            }
            list.appendChild(item);
        });
        const hidden = total - DEBT_COMPACT_VISIBLE;
        if (hidden > 0) {
            const moreBtn = document.createElement('button');
            moreBtn.type = 'button';
            moreBtn.className = 'debt-more';
            moreBtn.textContent = '+' + hidden;
            moreBtn.title = 'Ещё ' + hidden + ' — открыть список';
            moreBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openDebtExpandPopover(debtCell, state);
            });
            list.appendChild(moreBtn);
        }
        if (debtPopoverAnchorCell === debtCell) {
            fillDebtPopoverShifts(debtCell, state);
            positionDebtExpandPopover(debtCell);
        }
    }
    function canDropDebtOnCell(td, state) {
        if (!dragContext || dragContext.sourceType !== 'debt' || !td || !state) {
            return false;
        }
        if (dragContext.rowKey !== state.rowKey) {
            return false;
        }
        const toDate = String(td.getAttribute('data-plan-date') || '');
        if (!toDate || toDate < getTodayIso()) {
            return false;
        }
        const targetQty = parseInt(td.getAttribute('data-wf') || '0', 10) || 0;
        return targetQty <= 0;
    }
    function submitDebtMove(state, fromDate, toDate, qty) {
        if (!state || !WF_CAN_EDIT) {
            return;
        }
        const movedQty = Math.max(0, parseInt(qty, 10) || 0);
        if (movedQty <= 0) {
            return;
        }
        const cell = state.row.querySelector('td.wf-date-cell[data-plan-date="' + toDate + '"]');
        if (!cell) {
            return;
        }
        const prevQty = parseInt(cell.getAttribute('data-wf') || '0', 10) || 0;
        if (!wfPlanByRow[state.rowKey]) {
            wfPlanByRow[state.rowKey] = {};
        }
        const wr = wfPlanByRow[state.rowKey];
        if (fromDate < getTodayIso()) {
            const curFrom = Math.max(0, parseInt(wr[fromDate] || '0', 10) || 0);
            const nextFrom = Math.max(0, curFrom - movedQty);
            if (nextFrom > 0) {
                wr[fromDate] = nextFrom;
            } else {
                delete wr[fromDate];
            }
        }
        updateTdVisual(cell, prevQty + movedQty, { prevQty: prevQty, skipDebtSync: true });
        syncDebtFromPastWfPlan(state.row);
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                action: 'apply_wireframe_debt',
                source_row_key: state.rowKey,
                order_number: state.order,
                filter_name: state.filter,
                from_date: fromDate,
                to_date: toDate,
                qty: movedQty,
            }),
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.j || !res.j.ok) {
                    window.alert((res.j && res.j.error) ? res.j.error : 'Ошибка переноса долга');
                    window.location.reload();
                }
            })
            .catch(function () {
                window.alert('Сеть или сервер недоступны.');
                window.location.reload();
            });
    }
    function bindDebtDropOnDateCell(td, state) {
        if (!WF_CAN_EDIT) {
            return;
        }
        td.addEventListener('dragover', function (e) {
            if (!dragContext || dragContext.sourceType !== 'debt') {
                return;
            }
            if (canDropDebtOnCell(td, state)) {
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
                td.classList.add('drag-drop-target');
            }
        });
        td.addEventListener('dragleave', function () {
            td.classList.remove('drag-drop-target');
        });
        td.addEventListener('drop', function (e) {
            if (!dragContext || dragContext.sourceType !== 'debt') {
                return;
            }
            td.classList.remove('drag-drop-target');
            if (!canDropDebtOnCell(td, state)) {
                return;
            }
            e.preventDefault();
            const toDate = String(td.getAttribute('data-plan-date') || '');
            submitDebtMove(state, dragContext.fromDate, toDate, dragContext.movedQty);
            dragContext = null;
        });
    }

    /**
     * По строке: идём по датам слева направо, пул каркасов += wf на дату,
     * на каждый ненулевой план г/п списываем min(пул, гофро) — одни и те же каркасы
     * не могут закрыть несколько слотов сразу (как суммарный план к нескольким сборкам).
     */
    function wfRefreshGofroPlanFillsForRow(tr) {
        if (!tr) return;
        const cells = Array.from(tr.querySelectorAll('td.wf-date-cell[data-plan-date]'));
        cells.sort(function (a, b) {
            const da = a.getAttribute('data-plan-date') || '';
            const db = b.getAttribute('data-plan-date') || '';
            return da < db ? -1 : (da > db ? 1 : 0);
        });
        let pool = 0;
        let rowUncovered = false;
        cells.forEach(function (td) {
            const wf = parseInt(td.getAttribute('data-wf') || '0', 10) || 0;
            const gofro = parseInt(td.getAttribute('data-gofro') || '0', 10) || 0;
            pool += wf;

            let fill = td.querySelector('.wf-date-cell-fill');
            if (!fill) {
                fill = document.createElement('span');
                fill.className = 'wf-date-cell-fill';
                fill.setAttribute('aria-hidden', 'true');
                td.insertBefore(fill, td.firstChild);
            }

            let coverPct = 0;
            if (gofro > 0) {
                const alloc = Math.min(pool, gofro);
                if (alloc < gofro) {
                    rowUncovered = true;
                }
                coverPct = (alloc / gofro) * 100;
                pool -= alloc;
            }
            const hue = Math.round(Math.min(100, coverPct) * 1.2);
            td.style.setProperty('--wf-cover-pct', coverPct.toFixed(4) + '%');
            fill.style.background = gofro > 0 ? 'hsla(' + hue + ', 65%, 52%, 0.28)' : 'transparent';

            const coverStr = gofro > 0 ? String(Math.round(coverPct * 10) / 10).replace('.', ',') : '';
            td.title = 'Каркасы: ' + wf + ' шт. · г/п к сборке (справочно): ' + gofro + ' шт.'
                + (gofro > 0 ? ' · покрытие этого слота г/п (по очереди дат): ' + coverStr + '%' : '');
        });
        tr.classList.toggle('wf-row--gofro-uncovered', rowUncovered);
    }

    function recalcGroupFooters() {
        const dates = window.WF_BUILD_PLAN_DATES || [];
        const sumWide = {};
        const sumNotWide = {};
        dates.forEach(function (d) {
            sumWide[d] = 0;
            sumNotWide[d] = 0;
        });
        document.querySelectorAll('tbody tr.wf-data-row').forEach(function (tr) {
            const g = tr.getAttribute('data-machine-group') || 'notwide';
            tr.querySelectorAll('td.wf-date-cell').forEach(function (td) {
                const d = td.getAttribute('data-plan-date');
                if (!d) return;
                const q = parseInt(td.getAttribute('data-wf') || '0', 10) || 0;
                if (g === 'wide') {
                    sumWide[d] = (sumWide[d] || 0) + q;
                } else {
                    sumNotWide[d] = (sumNotWide[d] || 0) + q;
                }
            });
        });
        dates.forEach(function (d) {
            const elW = document.querySelector('tfoot .wf-foot-sum[data-foot="wide"][data-plan-date="' + d + '"]');
            const elN = document.querySelector('tfoot .wf-foot-sum[data-foot="notwide"][data-plan-date="' + d + '"]');
            const sw = sumWide[d] || 0;
            const sn = sumNotWide[d] || 0;
            const wTxt = sw > 0 ? String(sw) : '';
            const nTxt = sn > 0 ? String(sn) : '';
            if (elW) elW.textContent = wTxt;
            if (elN) elN.textContent = nTxt;
            const th = document.querySelector('thead th[data-plan-date="' + d + '"]');
            if (th) {
                const hW = th.querySelector('.wf-th-load-val--wide');
                const hN = th.querySelector('.wf-th-load-val--notwide');
                if (hW) hW.textContent = wTxt;
                if (hN) hN.textContent = nTxt;
                th.title = d + ' — загрузка станков (план каркасов, шт.): 600 → ' + sw + ', 400 → ' + sn;
            }
        });
    }

    function hideErr() {
        errEl.hidden = true;
        errEl.textContent = '';
    }
    function showErr(msg) {
        errEl.hidden = false;
        errEl.textContent = msg;
    }
    function closePanel() {
        panel.hidden = true;
        activeTd = null;
        hideErr();
    }
    function formatDateRu(iso) {
        const p = String(iso).split('-');
        if (p.length !== 3) return iso;
        return p[2] + '.' + p[1] + '.' + p[0];
    }
    /** Варианты «вправо» по шкале и по г/п / остатку */
    function buildQuickQty(gofro, remaining, currentWf) {
        const set = new Set();
        const add = function (n) {
            n = Math.max(0, Math.floor(Number(n)));
            if (n > 999999) return;
            set.add(n);
        };
        add(currentWf);
        if (gofro > 0) {
            add(gofro);
            if (gofro > 1) add(Math.ceil(gofro / 2));
        }
        if (remaining > 0) add(remaining);
        const base = Math.max(1, gofro, currentWf);
        const ladder = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 60, 75, 100, 120, 150, 200, 250, 300, 400, 500];
        for (let i = 0; i < ladder.length; i++) {
            const v = ladder[i];
            if (v >= base) add(v);
        }
        let x = Math.ceil(base / 10) * 10;
        for (let k = 0; k < 6; k++) {
            add(x + k * 10);
        }
        const arr = Array.from(set).filter(function (q) { return q > 0; }).sort(function (a, b) { return a - b; });
        const out = [];
        for (let i = 0; i < arr.length && out.length < 10; i++) {
            if (out.indexOf(arr[i]) === -1) out.push(arr[i]);
        }
        return out;
    }
    function positionPanel(clientX, clientY) {
        const pad = 8;
        const w = panel.offsetWidth || 240;
        const h = panel.offsetHeight || 200;
        /* position:fixed — координаты относительно вьюпорта, без scrollX/Y */
        let left = clientX + pad;
        let top = clientY + pad;
        const maxL = window.innerWidth - w - pad;
        const maxT = window.innerHeight - h - pad;
        if (left > maxL) left = Math.max(pad, maxL);
        if (top > maxT) top = Math.max(pad, maxT);
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }
    function openPanel(td, ev) {
        if (!WF_CAN_EDIT) return;
        activeTd = td;
        hideErr();
        const filter = td.getAttribute('data-filter') || '';
        const order = td.getAttribute('data-order') || '';
        const date = td.getAttribute('data-plan-date') || '';
        const gofro = parseInt(td.getAttribute('data-gofro') || '0', 10) || 0;
        const cur = parseInt(td.getAttribute('data-wf') || '0', 10) || 0;
        const rem = parseInt(td.getAttribute('data-remaining') || '0', 10) || 0;
        titleEl.textContent = filter;
        metaEl.textContent = 'Заявка ' + order + ' · ' + formatDateRu(date) + ' · каркасы: ' + cur + ' · г/п (справочно): ' + gofro + ' · остаток фильтров: ' + rem;
        inputEl.value = cur > 0 ? String(cur) : '';
        const quick = buildQuickQty(gofro, rem, cur);
        gridEl.innerHTML = '';
        quick.forEach(function (q) {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = String(q);
            b.title = 'Поставить ' + q + ' шт.';
            b.addEventListener('click', function () {
                submitQty(q);
            });
            gridEl.appendChild(b);
        });
        panel.hidden = false;
        function place() {
            const rect = td.getBoundingClientRect();
            const cx = (typeof ev.clientX === 'number' && !Number.isNaN(ev.clientX)) ? ev.clientX : rect.left + rect.width / 2;
            const cy = (typeof ev.clientY === 'number' && !Number.isNaN(ev.clientY)) ? ev.clientY : rect.top + rect.height / 2;
            positionPanel(cx, cy);
        }
        requestAnimationFrame(function () {
            place();
            requestAnimationFrame(function () {
                inputEl.focus();
                if (inputEl.value !== '') {
                    inputEl.select();
                }
            });
        });
    }
    function updateTdVisual(td, qty, opts) {
        opts = opts || {};
        const tr = td.closest('tr');
        const prev = opts.prevQty !== undefined
            ? opts.prevQty
            : (parseInt(td.getAttribute('data-wf') || '0', 10) || 0);
        td.setAttribute('data-wf', String(qty));
        let span = td.querySelector('.wf-main-qty');
        if (qty > 0) {
            if (!span) {
                span = document.createElement('span');
                span.className = 'wf-main-qty';
                td.appendChild(span);
            }
            span.textContent = String(qty);
        } else if (span) {
            span.remove();
        }
        if (tr) {
            syncWfPlanFromRow(tr);
            if (!opts.skipDebtSync) {
                syncDebtFromPastWfPlan(tr);
            }
            wfRefreshGofroPlanFillsForRow(tr);
        }
        recalcGroupFooters();
    }
    function submitQty(qty) {
        if (!activeTd) return;
        hideErr();
        const prevQty = parseInt(activeTd.getAttribute('data-wf') || '0', 10) || 0;
        const rowKey = activeTd.getAttribute('data-row-key') || '';
        const order = activeTd.getAttribute('data-order') || '';
        const filter = activeTd.getAttribute('data-filter') || '';
        const planDate = activeTd.getAttribute('data-plan-date') || '';
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                action: 'set_wireframe_cell',
                source_row_key: rowKey,
                order_number: order,
                filter_name: filter,
                plan_date: planDate,
                qty: qty
            })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.j || !res.j.ok) {
                    showErr((res.j && res.j.error) ? res.j.error : 'Ошибка сохранения');
                    return;
                }
                updateTdVisual(activeTd, res.j.qty, { prevQty: prevQty });
                closePanel();
            })
            .catch(function () {
                showErr('Сеть или сервер недоступны.');
            });
    }
    document.querySelectorAll('.wf-date-cell--editable').forEach(function (td) {
        td.addEventListener('click', function (ev) {
            ev.stopPropagation();
            openPanel(td, ev);
        });
        td.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                openPanel(td, { clientX: td.getBoundingClientRect().left, clientY: td.getBoundingClientRect().top });
            }
        });
    });
    btnClose.addEventListener('click', closePanel);
    if (btnInputClear) {
        btnInputClear.addEventListener('click', function () {
            inputEl.value = '';
            hideErr();
            inputEl.focus();
        });
    }
    btnClear.addEventListener('click', function () {
        submitQty(0);
    });
    btnApplyCustom.addEventListener('click', function () {
        const v = parseInt(String(inputEl.value).trim(), 10);
        if (isNaN(v) || v < 0) {
            showErr('Введите неотрицательное целое число.');
            return;
        }
        submitQty(v);
    });
    inputEl.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
            ev.preventDefault();
            btnApplyCustom.click();
        }
    });
    document.addEventListener('click', function (ev) {
        if (debtExpandPopover && !debtExpandPopover.hidden) {
            const inPopover = debtExpandPopover.contains(ev.target);
            const onDebtCell = ev.target.closest('td.debt-cell');
            if (!inPopover && !onDebtCell) {
                closeDebtExpandPopover();
            }
        }
        if (panel.hidden) return;
        if (ev.target === panel || panel.contains(ev.target)) return;
        if (activeTd && activeTd.contains(ev.target)) return;
        closePanel();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !panel.hidden) closePanel();
        if (ev.key === 'Escape' && debtExpandPopover && !debtExpandPopover.hidden) closeDebtExpandPopover();
    });
    document.querySelectorAll('tbody tr.wf-data-row').forEach(function (tr) {
        const rowKey = tr.getAttribute('data-row-key') || '';
        const state = {
            row: tr,
            rowKey: rowKey,
            order: tr.getAttribute('data-order') || '',
            filter: tr.getAttribute('data-filter') || '',
        };
        rowStateMap.set(rowKey, state);
        wfPlanByRow[rowKey] = Object.assign({}, initialWireframePlanAll[rowKey] || {});
        tr.querySelectorAll('td.wf-date-cell--editable').forEach(function (td) {
            bindDebtDropOnDateCell(td, state);
        });
        syncDebtFromPastWfPlan(tr);
        wfRefreshGofroPlanFillsForRow(tr);
    });
    recalcGroupFooters();
})();
    </script>
    <?php endif; ?>
</div>
