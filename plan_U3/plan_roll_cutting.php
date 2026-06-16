<?php
/**
 * Планирование порезки бухт под план сборки гофропакетов (третий уровень после gofro_build_plan).
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
$pageTitle = 'План порезки бухт под гофропакеты';
$loadError = '';

const ROLL_HALF_LEN_M = 600.0;

function normalizeFilterKeyRoll(string $name): string
{
    $name = preg_replace('/\[.*$/u', '', $name);
    $name = trim($name);
    return mb_strtoupper($name, 'UTF-8');
}

function normalizeTextKeyRoll(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name));
    return mb_strtoupper($name, 'UTF-8');
}

/**
 * Колонки шкалы: каждый календарный день от $startIso до последней даты в плане гофро / назначениях порезки.
 * Старт обычно min(сегодня, первая запланированная порезка бухты, первый день плана г/п), чтобы не обрезать
 * приход с бухт слева и корректно считать накопительное покрытие в нижней таблице.
 *
 * @param array<string, true> $buildPlanDates
 * @return list<string>
 */
function expandTimelineDatesFromStartThroughPlanMax(array $buildPlanDates, string $startIso, string $todayIso): array
{
    if ($buildPlanDates === []) {
        return [];
    }
    $maxK = max(array_keys($buildPlanDates));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $maxK)) {
        return [];
    }
    $endStr = max($maxK, $todayIso);
    $start = new DateTimeImmutable($startIso);
    $end = new DateTimeImmutable($endStr);
    if ($start > $end) {
        return [$endStr];
    }
    $out = [];
    $c = $start;
    while ($c <= $end) {
        $out[] = $c->format('Y-m-d');
        $c = $c->modify('+1 day');
    }

    return $out;
}

function ensureCorrugationPlanV2TableRoll(PDO $pdo): void
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

function ensureGofroRollCutScheduleTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gofro_roll_cut_schedule (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(64) NOT NULL,
            bale_id INT NOT NULL,
            cut_date DATE NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_gofro_roll_cut_order_bale (order_number, bale_id),
            KEY idx_gofro_roll_cut_date (cut_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Таблица и поле даты порезки на бумагорезке по участку (как в cut_operator: U2 — roll_plan.plan_date, U3+ — roll_plans.work_date).
 *
 * @return array{0:string,1:string}|null [table, dateColumn]
 */
function pickRollPlanTableForCuts(PDO $pdo, string $dbName): ?array
{
    if ($dbName === 'plan') {
        $chk = $pdo->query("SHOW TABLES LIKE " . $pdo->quote('roll_plan'));
        if ($chk && (int)$chk->rowCount() > 0) {
            return ['roll_plan', 'plan_date'];
        }

        return null;
    }
    foreach (['roll_plans', 'roll_plan'] as $t) {
        $chk = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t));
        if ($chk && (int)$chk->rowCount() > 0) {
            return [$t, 'work_date'];
        }
    }

    return null;
}

/**
 * Суммарное число бухт на дату по всем участкам из roll_plan / roll_plans (логика как cut_operator/index.php).
 *
 * @return array<string, int>
 */
function aggregateRollPlanBaleCountsByDateRange(string $minD, string $maxD): array
{
    $byDate = [];
    foreach (['plan', 'plan_u3', 'plan_u4', 'plan_u5'] as $dbName) {
        try {
            $pdoDept = getPdo($dbName);
            $src = pickRollPlanTableForCuts($pdoDept, $dbName);
            if ($src === null) {
                continue;
            }
            [$table, $dcol] = $src;
            $sql = 'SELECT `' . $dcol . '` AS d, COUNT(*) AS c FROM `' . $table . '` WHERE `' . $dcol . '` BETWEEN ? AND ? GROUP BY `' . $dcol . '`';
            $st = $pdoDept->prepare($sql);
            $st->execute([$minD, $maxD]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dk = trim((string)($row['d'] ?? ''));
                if ($dk === '') {
                    continue;
                }
                $byDate[$dk] = ($byDate[$dk] ?? 0) + (int)($row['c'] ?? 0);
            }
        } catch (Throwable $e) {
            // нет БД/таблицы — пропуск участка
        }
    }

    return $byDate;
}

/**
 * Строки gofro_roll_cut_schedule (U3), для которых нет той же пары заявка+бухта+дата в roll_plans — чтобы не считать дважды.
 *
 * @param array<string, int> $byDate
 */
function mergeGofroScheduleExclusiveCounts(PDO $pdoU3, string $minD, string $maxD, array &$byDate): void
{
    try {
        ensureGofroRollCutScheduleTable($pdoU3);
        $rollU3 = pickRollPlanTableForCuts($pdoU3, 'plan_u3');
        if ($rollU3 === null) {
            $st = $pdoU3->prepare('
                SELECT cut_date AS d, COUNT(*) AS c
                FROM gofro_roll_cut_schedule
                WHERE cut_date BETWEEN ? AND ?
                GROUP BY cut_date
            ');
            $st->execute([$minD, $maxD]);
        } else {
            [$rt, $rcol] = $rollU3;
            $st = $pdoU3->prepare('
                SELECT g.cut_date AS d, COUNT(*) AS c
                FROM gofro_roll_cut_schedule g
                WHERE g.cut_date BETWEEN ? AND ?
                  AND NOT EXISTS (
                    SELECT 1 FROM `' . $rt . '` r
                    WHERE r.order_number = g.order_number
                      AND r.bale_id = g.bale_id
                      AND r.`' . $rcol . '` = g.cut_date
                  )
                GROUP BY g.cut_date
            ');
            $st->execute([$minD, $maxD]);
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dk = trim((string)($row['d'] ?? ''));
            if ($dk === '') {
                continue;
            }
            $byDate[$dk] = ($byDate[$dk] ?? 0) + (int)($row['c'] ?? 0);
        }
    } catch (Throwable $e) {
        // игнорируем
    }
}

/**
 * Таблица roll_plans (центральный план порезки U3), в духе NP_roll_plan.php — для оператора бумагорезки и отчётов.
 *
 * @return bool есть ли столбец done (влияет на удаление только незавершённых строк)
 */
function ensureRollPlansTableRollCutting(PDO $pdo): bool
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roll_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL,
            bale_id INT NOT NULL,
            work_date DATE NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_order_bale (order_number, bale_id),
            KEY idx_order_date (order_number, work_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stCol = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'roll_plans'
          AND COLUMN_NAME = 'done'
    ");
    $hasDone = ((int)$stCol->fetchColumn() > 0);
    if (!$hasDone) {
        try {
            $pdo->exec('ALTER TABLE roll_plans ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0');
            $hasDone = true;
        } catch (Throwable $e) {
            return false;
        }
    }

    return $hasDone;
}

/**
 * Записать план порезки в roll_plans по текущему gofro_roll_cut_schedule (назначенные на дату бухты).
 *
 * @throws Throwable при ошибке БД
 */
function syncRollPlansFromGofroRollCutSchedule(PDO $pdo, string $order): void
{
    ensureGofroRollCutScheduleTable($pdo);
    $hasDone = ensureRollPlansTableRollCutting($pdo);

    $doneBales = [];
    if ($hasDone) {
        $stDone = $pdo->prepare('SELECT bale_id FROM roll_plans WHERE order_number = ? AND done = 1');
        $stDone->execute([$order]);
        while ($row = $stDone->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int)($row['bale_id'] ?? 0);
            if ($bid > 0) {
                $doneBales[$bid] = true;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if ($hasDone) {
            $pdo->prepare('DELETE FROM roll_plans WHERE order_number = ? AND (done IS NULL OR done = 0)')->execute([$order]);
            $ins = $pdo->prepare('INSERT INTO roll_plans (order_number, bale_id, work_date, done) VALUES (?, ?, ?, 0)');
        } else {
            $pdo->prepare('DELETE FROM roll_plans WHERE order_number = ?')->execute([$order]);
            $ins = $pdo->prepare('INSERT INTO roll_plans (order_number, bale_id, work_date) VALUES (?, ?, ?)');
        }

        $stG = $pdo->prepare('SELECT bale_id, cut_date FROM gofro_roll_cut_schedule WHERE order_number = ? AND cut_date IS NOT NULL');
        $stG->execute([$order]);
        $chkBale = $pdo->prepare('SELECT 1 FROM cut_plans WHERE order_number = ? AND bale_id = ? LIMIT 1');

        while ($row = $stG->fetch(PDO::FETCH_ASSOC)) {
            $bid = (int)($row['bale_id'] ?? 0);
            $cd = trim((string)($row['cut_date'] ?? ''));
            if ($bid <= 0 || $cd === '') {
                continue;
            }
            if (isset($doneBales[$bid])) {
                continue;
            }
            $chkBale->execute([$order, $bid]);
            if (!(bool)$chkBale->fetchColumn()) {
                continue;
            }
            $ins->execute([$order, $bid, $cd]);
        }

        try {
            $stCnt = $pdo->prepare('SELECT COUNT(*) FROM roll_plans WHERE order_number = ?');
            $stCnt->execute([$order]);
            $plannedCount = (int)$stCnt->fetchColumn();
            $stUpd = $pdo->prepare('UPDATE orders SET plan_ready = ? WHERE order_number = ?');
            $stUpd->execute([$plannedCount > 0 ? 1 : 0, $order]);
        } catch (Throwable $e) {
            // поле plan_ready может отсутствовать
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string, mixed> $b
 */
function renderBaleCardHtml(array $b, string $oidEsc, int $bid): string
{
    $rawMat = trim((string)($b['material'] ?? ''));
    $matParts = [];
    foreach (preg_split('/\s*,\s*/u', $rawMat) as $p) {
        $p = trim($p);
        if ($p === '' || strcasecmp($p, 'Simple') === 0) {
            continue;
        }
        $matParts[] = $p;
    }
    $matShown = implode(', ', $matParts);
    $metaHtml = $matShown !== ''
        ? '<div class="bale-meta">' . htmlspecialchars($matShown, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';

    $strips = $b['strips'] ?? null;
    $linesHtml = '';
    if (is_array($strips) && $strips !== []) {
        foreach ($strips as $st) {
            $filterRaw = trim((string)($st['filter'] ?? ''));
            $fltEsc = htmlspecialchars($filterRaw, ENT_QUOTES, 'UTF-8');
            $pkgKey = trim((string)($st['package_key'] ?? ''));
            $pkgAttr = $pkgKey !== ''
                ? ' data-package-key="' . htmlspecialchars($pkgKey, ENT_QUOTES, 'UTF-8') . '"'
                : '';
            $gq = (int)($st['gp_qty'] ?? 0);
            if ($gq > 0) {
                $gpBlock = '<span class="bale-strip__gp"><strong>' . $gq . '</strong> шт</span>';
            } else {
                $gpBlock = '<span class="bale-strip__gp bale-strip__gp--none">нет расчёта г/п</span>';
            }
            $linesHtml .= '<div class="bale-strip"' . $pkgAttr . '>'
                . '<span class="bale-strip__filter">' . ($filterRaw !== '' ? $fltEsc : '—') . '</span>'
                . ' <span class="bale-strip__sep">→</span> '
                . $gpBlock
                . '</div>';
        }
    } else {
        $gp = (int)($b['gp_qty'] ?? 0);
        $domPk = trim((string)($b['package_key'] ?? ''));
        $pkgAttr = $domPk !== ''
            ? ' data-package-key="' . htmlspecialchars($domPk, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        $linesHtml = '<div class="bale-strip"' . $pkgAttr . '><span class="bale-strip__gp"><strong>' . $gp . '</strong> шт</span></div>';
    }
    $lenM = trim((string)($b['length_m'] ?? '0'));
    $lenEsc = htmlspecialchars($lenM !== '' ? $lenM : '0', ENT_QUOTES, 'UTF-8');
    return '<div class="bale-card" draggable="true" data-order="' . $oidEsc . '" data-bale-id="' . $bid . '" data-length-m="' . htmlspecialchars((string)($b['length_m'] ?? '0'), ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="bale-title">Бухта #' . $bid
        . ' <span class="bale-title__len">' . $lenEsc . ' м</span></div>'
        . $metaHtml
        . '<div class="bale-strips">' . $linesHtml . '</div>'
        . '</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $rawBody = file_get_contents('php://input');
    $payload = [];
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $action = (string)($payload['action'] ?? '');
    if ($action === 'set_bale_cut_date') {
        try {
            ensureGofroRollCutScheduleTable($pdo);
            $order = trim((string)($payload['order_number'] ?? ''));
            $baleId = (int)($payload['bale_id'] ?? 0);
            $cutDateRaw = trim((string)($payload['cut_date'] ?? ''));
            if ($order === '' || $baleId <= 0) {
                throw new RuntimeException('Некорректная бухта.');
            }
            if ($cutDateRaw === '' || $cutDateRaw === '__pool__') {
                $chkBale = $pdo->prepare('SELECT 1 FROM cut_plans WHERE order_number = ? AND bale_id = ? LIMIT 1');
                $chkBale->execute([$order, $baleId]);
                if (!(bool)$chkBale->fetchColumn()) {
                    throw new RuntimeException('Бухта не относится к раскрою этой заявки.');
                }
                $pdo->prepare('DELETE FROM gofro_roll_cut_schedule WHERE order_number = ? AND bale_id = ?')->execute([$order, $baleId]);
                echo json_encode(['ok' => true, 'cut_date' => null], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutDateRaw)) {
                throw new RuntimeException('Некорректная дата.');
            }
            if ($cutDateRaw < $todayIso) {
                throw new RuntimeException('Дата порезки не может быть в прошлом.');
            }
            $chkBale = $pdo->prepare('SELECT 1 FROM cut_plans WHERE order_number = ? AND bale_id = ? LIMIT 1');
            $chkBale->execute([$order, $baleId]);
            if (!(bool)$chkBale->fetchColumn()) {
                throw new RuntimeException('Бухта не относится к раскрою этой заявки.');
            }
            $sql = "
                INSERT INTO gofro_roll_cut_schedule (order_number, bale_id, cut_date)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE cut_date = VALUES(cut_date), updated_at = CURRENT_TIMESTAMP
            ";
            $pdo->prepare($sql)->execute([$order, $baleId, $cutDateRaw]);
            echo json_encode(['ok' => true, 'cut_date' => $cutDateRaw], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($action === 'save_roll_cutting_plan') {
        try {
            $order = trim((string)($payload['order_number'] ?? ''));
            if ($order === '') {
                throw new RuntimeException('Не указана заявка.');
            }
            $chk = $pdo->prepare('SELECT 1 FROM cut_plans WHERE order_number = ? LIMIT 1');
            $chk->execute([$order]);
            if (!(bool)$chk->fetchColumn()) {
                throw new RuntimeException('По заявке нет раскроя (cut_plans).');
            }
            syncRollPlansFromGofroRollCutSchedule($pdo, $order);
            echo json_encode([
                'ok' => true,
                'message' => 'План порезки записан в roll_plans (как при сохранении в плане раскроя U3).',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}

$perBaleMin = 40.0;
if (isset($_GET['per_bale_min']) && $_GET['per_bale_min'] !== '') {
    $perBaleMin = max(5.0, min(120.0, (float)str_replace(',', '.', (string)$_GET['per_bale_min'])));
}
$shiftHours = 7.0;
if (isset($_GET['shift_hours']) && $_GET['shift_hours'] !== '') {
    $shiftHours = max(1.0, min(24.0, (float)str_replace(',', '.', (string)$_GET['shift_hours'])));
}

/** Заявки, по которым есть план г/п с сегодня (для выбора). */
$selectableOrders = [];
try {
    ensureCorrugationPlanV2TableRoll($pdo);
    $stOrd = $pdo->query("
        SELECT DISTINCT order_number AS o
        FROM corrugation_plan_v2
        WHERE qty > 0 AND plan_date >= " . $pdo->quote($todayIso) . "
        ORDER BY order_number
    ");
    while ($row = $stOrd->fetch(PDO::FETCH_ASSOC)) {
        $o = trim((string)($row['o'] ?? ''));
        if ($o !== '') {
            $selectableOrders[] = $o;
        }
    }
} catch (Throwable $e) {
    if ($loadError === '') {
        $loadError = $e->getMessage();
    }
}

$selectedOrder = trim((string)($_GET['order'] ?? ''));
$allowedOrderSet = array_fill_keys($selectableOrders, true);
if ($selectedOrder !== '' && !isset($allowedOrderSet[$selectedOrder])) {
    $selectedOrder = '';
}

$planDates = [];
/** [package_key][date] => qty day */
$demandDaily = [];
/** @var list<string> */
$packageOrder = [];
$ordersList = [];
$filterMetaByKey = [];
$balesJson = [];
$scheduleByOrderBale = [];
/** @var array<string, int> дата Y-m-d => бухты на бумагорезке: roll_plan/roll_plans по всем участкам + gofro без дубля с roll_plans U3 */
$cutScheduleCountByDate = [];

if ($loadError === '' && $selectedOrder !== '') {
    $ordersList = [$selectedOrder];
    $buildPlanDates = [];
    /** Левая граница шкалы: не только «сегодня», иначе обрезается первая запланированная порезка и ломается покрытие. */
    $timelineStartIso = $todayIso;
    try {
        ensureGofroRollCutScheduleTable($pdo);
        $stMinCut = $pdo->prepare('
            SELECT MIN(cut_date) AS mn
            FROM gofro_roll_cut_schedule
            WHERE order_number = ? AND cut_date IS NOT NULL
        ');
        $stMinCut->execute([$selectedOrder]);
        $mnCut = trim((string)$stMinCut->fetchColumn());
        if ($mnCut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mnCut) && $mnCut < $timelineStartIso) {
            $timelineStartIso = $mnCut;
        }
    } catch (Throwable $e) {
        // игнорируем
    }
    try {
        ensureCorrugationPlanV2TableRoll($pdo);
        $stMinPlan = $pdo->prepare('
            SELECT MIN(plan_date) AS mn
            FROM corrugation_plan_v2
            WHERE order_number = ? AND qty > 0
        ');
        $stMinPlan->execute([$selectedOrder]);
        $mnPlan = trim((string)$stMinPlan->fetchColumn());
        if ($mnPlan !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mnPlan) && $mnPlan < $timelineStartIso) {
            $timelineStartIso = $mnPlan;
        }
    } catch (Throwable $e) {
        // игнорируем
    }
    try {
        ensureCorrugationPlanV2TableRoll($pdo);
        $stmt = $pdo->prepare("
            SELECT DISTINCT plan_date AS d
            FROM corrugation_plan_v2
            WHERE order_number = ? AND qty > 0 AND plan_date >= ?
            ORDER BY plan_date
        ");
        $stmt->execute([$selectedOrder, $timelineStartIso]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $d = trim((string)($row['d'] ?? ''));
            if ($d !== '') {
                $buildPlanDates[$d] = true;
            }
        }
        ensureGofroRollCutScheduleTable($pdo);
        $stmt2 = $pdo->prepare("
            SELECT DISTINCT cut_date AS d
            FROM gofro_roll_cut_schedule
            WHERE order_number = ? AND cut_date >= ?
        ");
        $stmt2->execute([$selectedOrder, $timelineStartIso]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $d = trim((string)($row['d'] ?? ''));
            if ($d !== '') {
                $buildPlanDates[$d] = true;
            }
        }
        $stMaxCorr = $pdo->prepare('
            SELECT MAX(plan_date) AS mx
            FROM corrugation_plan_v2
            WHERE order_number = ? AND qty > 0
        ');
        $stMaxCorr->execute([$selectedOrder]);
        $mxCorr = trim((string)($stMaxCorr->fetchColumn()));
        if ($mxCorr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mxCorr)) {
            $buildPlanDates[$mxCorr] = true;
        }
        $stMaxCut = $pdo->prepare('
            SELECT MAX(cut_date) AS mx
            FROM gofro_roll_cut_schedule
            WHERE order_number = ? AND cut_date IS NOT NULL
        ');
        $stMaxCut->execute([$selectedOrder]);
        $mxCut = trim((string)($stMaxCut->fetchColumn()));
        if ($mxCut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $mxCut)) {
            $buildPlanDates[$mxCut] = true;
        }
        $planDates = expandTimelineDatesFromStartThroughPlanMax($buildPlanDates, $timelineStartIso, $todayIso);
    } catch (Throwable $e) {
        if ($loadError === '') {
            $loadError = $e->getMessage();
        }
        $planDates = [];
    }

    try {
        ensureCorrugationPlanV2TableRoll($pdo);
        $stmt = $pdo->prepare("
            SELECT package_key, package_name, plan_date, SUM(qty) AS qty
            FROM corrugation_plan_v2
            WHERE order_number = ? AND qty > 0 AND plan_date >= ?
            GROUP BY package_key, package_name, plan_date
        ");
        $stmt->execute([$selectedOrder, $timelineStartIso]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pkRaw = trim((string)($r['package_key'] ?? ''));
            // Тот же ключ, что у бухт в gp_by_package (normalizeTextKeyRoll / план гофро).
            $pk = $pkRaw !== '' ? normalizeTextKeyRoll($pkRaw) : '';
            $pn = trim((string)($r['package_name'] ?? ''));
            $pd = trim((string)($r['plan_date'] ?? ''));
            $q = (int)($r['qty'] ?? 0);
            if ($pk === '' || $pk === '-' || $pd === '' || $q <= 0) {
                continue;
            }
            if (!isset($demandDaily[$pk])) {
                $demandDaily[$pk] = ['name' => ($pn !== '' ? $pn : $pk), 'by_date' => []];
                $packageOrder[] = $pk;
            }
            if (!isset($demandDaily[$pk]['by_date'][$pd])) {
                $demandDaily[$pk]['by_date'][$pd] = 0;
            }
            $demandDaily[$pk]['by_date'][$pd] += $q;
            if ($pn !== '' && ($demandDaily[$pk]['name'] === '' || $demandDaily[$pk]['name'] === $pk)) {
                $demandDaily[$pk]['name'] = $pn;
            }
        }
    } catch (Throwable $e) {
        if ($loadError === '') {
            $loadError = $e->getMessage();
        }
    }

    try {
        ensureGofroRollCutScheduleTable($pdo);
        $stSch = $pdo->prepare('SELECT order_number, bale_id, cut_date FROM gofro_roll_cut_schedule WHERE order_number = ?');
        $stSch->execute([$selectedOrder]);
        foreach ($stSch->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $o = trim((string)($sr['order_number'] ?? ''));
            $b = (int)($sr['bale_id'] ?? 0);
            $cd = trim((string)($sr['cut_date'] ?? ''));
            if ($o !== '' && $b > 0 && $cd !== '') {
                $scheduleByOrderBale[$o . "\t" . $b] = $cd;
            }
        }

        $stBales = $pdo->prepare("
            SELECT
                order_number,
                bale_id,
                TRIM(filter) AS filter_name,
                SUM(COALESCE(NULLIF(fact_length, 0), `length`)) AS total_len,
                GROUP_CONCAT(DISTINCT NULLIF(TRIM(material), '') ORDER BY material SEPARATOR ', ') AS materials
            FROM cut_plans
            WHERE order_number = ?
            GROUP BY order_number, bale_id, TRIM(filter)
            ORDER BY bale_id, total_len DESC
        ");
        $stBales->execute([$selectedOrder]);

        $rawFilters = [];
        $baleRows = [];
        while ($row = $stBales->fetch(PDO::FETCH_ASSOC)) {
            $baleRows[] = $row;
            $f = trim((string)($row['filter_name'] ?? ''));
            if ($f !== '') {
                $rawFilters[$f] = true;
            }
        }

        if (!empty($rawFilters)) {
            $rawFilterList = array_keys($rawFilters);
            $phf = implode(',', array_fill(0, count($rawFilterList), '?'));
            $sqlMeta = "
                SELECT
                    rfs.filter AS filter_name,
                    rfs.filter_package AS filter_package,
                    ppr.p_p_fold_height AS fold_height,
                    ppr.p_p_fold_count AS fold_count
                FROM round_filter_structure rfs
                LEFT JOIN paper_package_round ppr
                    ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                WHERE rfs.filter IN ($phf)
            ";
            $stmtMeta = $pdo->prepare($sqlMeta);
            $stmtMeta->execute($rawFilterList);
            foreach ($stmtMeta->fetchAll(PDO::FETCH_ASSOC) as $metaRow) {
                $metaKey = normalizeFilterKeyRoll((string)($metaRow['filter_name'] ?? ''));
                if ($metaKey === '') {
                    continue;
                }
                $pkg = trim((string)($metaRow['filter_package'] ?? ''));
                $filterMetaByKey[$metaKey] = [
                    'filter_package' => $pkg,
                    'fold_height' => (float)($metaRow['fold_height'] ?? 0),
                    'fold_count' => (float)($metaRow['fold_count'] ?? 0),
                ];
            }
        }

        $packagesPerRollForFilter = static function (string $filterRaw) use ($filterMetaByKey): array {
            $metaKey = normalizeFilterKeyRoll($filterRaw);
            $meta = $filterMetaByKey[$metaKey] ?? null;
            $foldHeight = (float)($meta['fold_height'] ?? 0);
            $foldCount = (float)($meta['fold_count'] ?? 0);
            $package = trim((string)($meta['filter_package'] ?? ''));
            $packageKey = $package !== '' ? normalizeTextKeyRoll($package) : '';
            // Длина материала на г/п — как в NP_cut_plan.php (total_length_m на полосу):
            // (p_p_fold_height * 2 * p_p_fold_count) / 1000, без +1 мм на ребро.
            $packLengthM = ($foldHeight > 0 && $foldCount > 0)
                ? (($foldHeight * 2) * $foldCount) / 1000
                : 0.0;
            // Полубухта 600 м: сколько г/п помещается по длине (без повторного «/2» —
            // числитель уже 600 м, см. NP_cut_plan HALF_BALE_LEN и need_units).
            $packagesPerRoll = $packLengthM > 0 ? (int)floor(600 / $packLengthM) : 0;
            if ($packagesPerRoll <= 0 && $packageKey !== '') {
                $packagesPerRoll = max(1, 100);
            }
            $qtyPerHalfRoll = $packagesPerRoll > 0 ? max(1, $packagesPerRoll) : 0;
            return [$packageKey, $package !== '' ? $package : $packageKey, $qtyPerHalfRoll];
        };

        $byBale = [];
        foreach ($baleRows as $row) {
            $ord = trim((string)($row['order_number'] ?? ''));
            $bid = (int)($row['bale_id'] ?? 0);
            if ($ord === '' || $bid <= 0) {
                continue;
            }
            $key = $ord . "\t" . $bid;
            if (!isset($byBale[$key])) {
                $byBale[$key] = [
                    'order_number' => $ord,
                    'bale_id' => $bid,
                    'materials' => [],
                    'gp_by_package' => [],
                    'strips' => [],
                ];
            }
            $len = (float)($row['total_len'] ?? 0);
            $mat = trim((string)($row['materials'] ?? ''));
            if ($mat !== '') {
                foreach (preg_split('/\s*,\s*/u', $mat) as $m) {
                    $m = trim($m);
                    if ($m !== '') {
                        $byBale[$key]['materials'][$m] = true;
                    }
                }
            }
            $filterName = trim((string)($row['filter_name'] ?? ''));
            [$pk, $pname, $qph] = $packagesPerRollForFilter($filterName);
            if ($pk === '' || $qph <= 0) {
                $byBale[$key]['strips'][] = [
                    'filter' => $filterName,
                    /** Длина одной полосы = длина бухты (полубухта), не сумма материала по полосам в БД. */
                    'length_m' => round(ROLL_HALF_LEN_M, 1),
                    'package_name' => '',
                    'package_key' => $pk,
                    'gp_qty' => 0,
                ];
                continue;
            }
            $halves = (int)floor($len / ROLL_HALF_LEN_M);
            $add = $halves * $qph;
            if ($add <= 0) {
                $byBale[$key]['strips'][] = [
                    'filter' => $filterName,
                    'length_m' => round(ROLL_HALF_LEN_M, 1),
                    'package_name' => $pname,
                    'package_key' => $pk,
                    'gp_qty' => 0,
                ];
                continue;
            }
            if (!isset($byBale[$key]['gp_by_package'][$pk])) {
                $byBale[$key]['gp_by_package'][$pk] = ['name' => $pname, 'qty' => 0];
            }
            $byBale[$key]['gp_by_package'][$pk]['qty'] += $add;
            $byBale[$key]['strips'][] = [
                'filter' => $filterName,
                'length_m' => round(ROLL_HALF_LEN_M, 1),
                'package_name' => $pname,
                'package_key' => $pk,
                'gp_qty' => $add,
            ];
        }

        foreach ($byBale as $key => $info) {
            $ord = $info['order_number'];
            $bid = $info['bale_id'];
            $gpMap = $info['gp_by_package'];
            $strips = $info['strips'] ?? [];
            usort($strips, static function (array $a, array $b): int {
                return strcmp((string)($a['filter'] ?? ''), (string)($b['filter'] ?? ''));
            });
            $dominantPk = '';
            $dominantQty = 0;
            $dominantName = '';
            foreach ($gpMap as $pk => $rec) {
                $q = (int)($rec['qty'] ?? 0);
                if ($q > $dominantQty) {
                    $dominantQty = $q;
                    $dominantPk = $pk;
                    $dominantName = (string)($rec['name'] ?? $pk);
                }
            }
            $matKeys = [];
            foreach (array_keys($info['materials']) as $mk) {
                $mk = trim((string)$mk);
                if ($mk === '' || strcasecmp($mk, 'Simple') === 0) {
                    continue;
                }
                $matKeys[] = $mk;
            }
            $matStr = implode(', ', $matKeys);
            $cutDate = $scheduleByOrderBale[$key] ?? null;
            /** Метраж бухты = метраж одной полосы (полубухта 600 м); число полос не суммируется в заголовок. */
            $balesJson[] = [
                'order_number' => $ord,
                'bale_id' => $bid,
                'length_m' => round(ROLL_HALF_LEN_M, 1),
                'material' => $matStr,
                'package_key' => $dominantPk,
                'package_name' => $dominantName,
                'gp_qty' => $dominantQty,
                'gp_by_package' => (object)$gpMap,
                'strips' => $strips,
                'cut_date' => $cutDate,
            ];
        }
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
        $balesJson = [];
    }
}

if ($loadError === '' && $planDates !== []) {
    try {
        $minD = $planDates[0];
        $maxD = $planDates[count($planDates) - 1];
        $cutScheduleCountByDate = aggregateRollPlanBaleCountsByDateRange($minD, $maxD);
        mergeGofroScheduleExclusiveCounts($pdo, $minD, $maxD, $cutScheduleCountByDate);
    } catch (Throwable $e) {
        $cutScheduleCountByDate = [];
    }
}

$coveragePayload = [
    'dates' => $planDates,
    'order_number' => $selectedOrder,
    'per_bale_min' => $perBaleMin,
    'shift_hours' => $shiftHours,
    'cut_schedule_counts' => $cutScheduleCountByDate === [] ? new stdClass() : $cutScheduleCountByDate,
    'packages' => [],
    'demand_daily' => [],
    'bales' => $balesJson,
];

foreach ($packageOrder as $pk) {
    if (!isset($demandDaily[$pk])) {
        continue;
    }
    $coveragePayload['packages'][] = ['key' => $pk, 'name' => $demandDaily[$pk]['name']];
    $coveragePayload['demand_daily'][$pk] = $demandDaily[$pk]['by_date'];
}

$coverageJson = json_encode($coveragePayload, JSON_UNESCAPED_UNICODE);
if ($coverageJson === false) {
    $coverageJson = '{}';
}
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
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 13px/1.35 "Segoe UI", Roboto, Arial, sans-serif;
        }
        .wrap { max-width: 100%; margin: 0 auto; padding: 14px; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .muted { color: var(--muted); font-size: 12px; }
        h1 { margin: 0 0 6px; font-size: 20px; }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 10px;
        }
        .toolbar a, .toolbar-btn {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--ink);
            font-size: 12px;
            text-decoration: none;
            cursor: pointer;
        }
        .toolbar a:hover, .toolbar-btn:hover { border-color: #c7d2fe; background: #f8faff; }
        .toolbar-btn--primary { background: var(--accent); color: #fff; border-color: #1e47c5; }
        .layout-main {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }
        .pool-panel {
            margin-top: 0;
        }
        .pool-panel h2 {
            margin: 0 0 8px;
            font-size: 14px;
        }
        #bale-pool {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 8px;
            align-content: flex-start;
        }
        #bale-pool .bale-card {
            flex: 0 0 auto;
            max-width: 200px;
        }
        .timeline-scroll {
            overflow-x: auto;
            padding-bottom: 6px;
        }
        .timeline {
            display: flex;
            gap: 10px;
            min-width: min-content;
        }
        .day-column {
            flex: 0 0 168px;
            width: 168px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fafbfc;
            display: flex;
            flex-direction: column;
            min-height: 120px;
        }
        .day-column.day-column--overload {
            border-color: #f87171;
            background: #fef2f2;
            box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.25);
        }
        .day-column--weekend .day-head { background: #eef2ff; }
        .day-head {
            padding: 8px;
            border-bottom: 1px solid var(--border);
            background: #f3f4f6;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        .day-head .d-label { font-weight: 700; font-size: 13px; }
        .day-head .day-load {
            margin-top: 4px;
            font-size: 10px;
            line-height: 1.25;
            color: #64748b;
            font-weight: 500;
            min-height: 1.35em;
        }
        .day-head .day-load strong { color: #0f172a; font-weight: 600; }
        .day-drop {
            flex: 1;
            padding: 8px;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .day-drop.day-drop--drag {
            outline: 2px dashed var(--accent);
            background: #eff6ff;
        }
        .bale-card {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px;
            background: #fff;
            cursor: grab;
            font-size: 11px;
            line-height: 1.35;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }
        .bale-card:active { cursor: grabbing; }
        .bale-card .bale-title { font-weight: 700; font-size: 12px; margin-bottom: 4px; color: #0f172a; }
        .bale-title__len {
            font-weight: 600;
            color: #0369a1;
            font-variant-numeric: tabular-nums;
        }
        .bale-card .bale-meta { color: #475569; }
        .bale-strips {
            margin-top: 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 10px;
            line-height: 1.3;
            color: #334155;
        }
        .bale-strip {
            padding: 4px 6px;
            border-radius: 6px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
        }
        .bale-strip__filter { font-weight: 600; color: #0f172a; }
        .bale-strip--pkg-active {
            background: #fee2e2 !important;
            border-color: #f87171 !important;
            box-shadow: 0 0 0 1px rgba(220, 38, 38, 0.35);
        }
        .bale-strip__sep { color: #94a3b8; margin: 0 2px; }
        .bale-strip__gp--none { color: #94a3b8; font-weight: 500; }
        .coverage-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Оформление таблицы покрытия — в духе active_positions */
        table.roll-cov-table.has-frozen-cols {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            line-height: 1.15;
            table-layout: auto;
        }
        table.roll-cov-table.has-frozen-cols th,
        table.roll-cov-table.has-frozen-cols td {
            background-clip: padding-box;
            padding: 2px 5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            white-space: nowrap;
            vertical-align: middle;
        }
        table.roll-cov-table.has-frozen-cols th:last-child,
        table.roll-cov-table.has-frozen-cols td:last-child {
            border-right: 0;
        }
        table.roll-cov-table.has-frozen-cols tr:last-child td {
            border-bottom: 0;
        }
        table.roll-cov-table.has-frozen-cols th {
            background: #f9fafb;
            font-weight: 600;
            font-size: 11px;
            color: #374151;
        }
        table.roll-cov-table.has-frozen-cols tbody tr:hover td {
            background: #fafbfc;
        }
        table.roll-cov-table.has-frozen-cols tbody tr:hover td.frozen-col {
            background: #fafbfc;
        }
        table.roll-cov-table.has-frozen-cols .frozen-col {
            position: sticky !important;
            left: 0;
            z-index: 20;
            background: #fff;
        }
        table.roll-cov-table.has-frozen-cols thead .frozen-col,
        table.roll-cov-table.has-frozen-cols thead th.pkg-head {
            background: #f9fafb;
            z-index: 40;
        }
        table.roll-cov-table.has-frozen-cols .frozen-col.frozen-col--last {
            box-shadow: 1px 0 0 0 var(--border);
        }
        table.roll-cov-table.has-frozen-cols th.date-col,
        table.roll-cov-table.has-frozen-cols td.date-col {
            text-align: center;
        }
        table.roll-cov-table.has-frozen-cols td.num {
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        table.roll-cov-table.has-frozen-cols th.pkg-head,
        table.roll-cov-table.has-frozen-cols td.pkg-head {
            text-align: left;
            font-weight: 600;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Ячейки дат: мелкая цифра — потребность в этот день, крупная — г/п с порезки в этот день */
        table.roll-cov-table td.roll-cov-cell {
            position: relative;
            font-variant-numeric: tabular-nums;
            min-height: 28px;
            min-width: 40px;
        }
        table.roll-cov-table td.roll-cov-cell .cell-plan-hint,
        table.roll-cov-table td.roll-cov-cell .cell-gofro-qty {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            font-variant-numeric: tabular-nums;
            line-height: 1;
            white-space: nowrap;
        }
        table.roll-cov-table td.roll-cov-cell .cell-plan-hint {
            position: absolute;
            left: 2px;
            top: 2px;
            z-index: 1;
            min-width: 15px;
            height: 15px;
            padding: 0 3px;
            border-radius: 999px;
            font-size: 8px;
            font-weight: 700;
            color: #475569;
            background: #e2e8f0;
            border: 1px solid #cbd5e1;
            box-shadow: 0 1px 0 rgba(15, 23, 42, 0.06);
            pointer-events: none;
        }
        table.roll-cov-table td.roll-cov-cell .cell-gofro-qty {
            min-width: 22px;
            height: 22px;
            padding: 0 5px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            background: #fff;
            border: 1.5px solid #64748b;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.1);
        }
        .cov-sup-bales {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 3px;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
        }
        table.roll-cov-table td.roll-cov-cell .cell-gofro-qty--bale {
            cursor: pointer;
        }
        table.roll-cov-table td.roll-cov-cell .cell-gofro-qty--bale.cov-sup-bale--selected {
            outline: 2px solid #b91c1c;
            background: #fef2f2;
            border-color: #b91c1c;
            box-shadow: 0 0 0 1px rgba(185, 28, 28, 0.35);
            z-index: 2;
            position: relative;
        }
        table.roll-cov-table tr.roll-cov-tr--bale-filter-hidden {
            display: none;
        }
        table.roll-cov-table td.roll-cov-cell .cell-gofro-qty--bale.cov-sup-bale--hl {
            outline: 2px solid #d97706;
            background: #fffbeb;
            border-color: #d97706;
            box-shadow: 0 0 0 1px rgba(217, 119, 6, 0.35);
            z-index: 2;
            position: relative;
        }
        table.roll-cov-table td.roll-cov-cell.gofro-coverage-cell {
            background: #dcfce7;
            box-shadow: inset 0 0 0 1px rgba(22, 163, 74, 0.35);
        }
        table.roll-cov-table td.roll-cov-cell.gofro-coverage-gap {
            background: #fee2e2;
            box-shadow: inset 0 0 0 1px rgba(220, 38, 38, 0.35);
        }
        /**
         * Частичное покрытие: слева направо (слева — доля покрытия, справа — нехватка).
         * --cov-fill-pct: ширина зелёной зоны слева (0%…100%), из JS как avail/req.
         */
        table.roll-cov-table td.roll-cov-cell.roll-cov-partial-lr {
            --cov-fill-pct: 0%;
            background: linear-gradient(to right,
                #dcfce7 0%,
                #dcfce7 var(--cov-fill-pct),
                #fee2e2 var(--cov-fill-pct),
                #fee2e2 100%);
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.55);
        }
        table.roll-cov-table td.roll-cov-cell.gofro-coverage-cell .cell-gofro-qty {
            border-color: #16a34a;
            background: #f0fdf4;
        }
        table.roll-cov-table td.roll-cov-cell.gofro-coverage-gap .cell-gofro-qty {
            border-color: #dc2626;
            background: #fef2f2;
        }
        table.roll-cov-table td.roll-cov-cell.roll-cov-partial-lr .cell-gofro-qty {
            border-color: #64748b;
            background: #fff;
        }
        table.roll-cov-table td.roll-cov-cell.roll-cov-partial-lr .cell-plan-hint {
            border-color: #94a3b8;
        }
        table.roll-cov-table.has-frozen-cols tbody tr.roll-cov-tr--active td.frozen-col {
            background: #fef2f2;
        }
        table.roll-cov-table td.roll-cov-pkg-select[data-package-key] {
            cursor: pointer;
            border-radius: 6px;
            outline: none;
        }
        table.roll-cov-table td.roll-cov-pkg-select[data-package-key]:hover {
            background: rgba(220, 38, 38, 0.08);
        }
        table.roll-cov-table td.roll-cov-pkg-select[data-package-key]:focus-visible {
            box-shadow: inset 0 0 0 2px rgba(220, 38, 38, 0.45);
        }
        table.roll-cov-table td.roll-cov-pkg-select--active {
            background: #fee2e2 !important;
            font-weight: 700;
            box-shadow: inset 0 0 0 1px #dc2626;
        }
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            font-size: 11px;
            margin-bottom: 8px;
        }
        .sw { display: inline-block; width: 14px; height: 14px; border-radius: 3px; margin-right: 4px; vertical-align: middle; border: 1px solid #ccc; }
        .sw.sw--split {
            background: linear-gradient(to right, #dcfce7 0%, #dcfce7 55%, #fee2e2 55%, #fee2e2 100%);
        }
        .order-pick-panel { margin-bottom: 12px; }
        .order-pick-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        .order-pick-form label {
            font-weight: 600;
            font-size: 13px;
        }
        .order-pick-form select {
            min-width: 220px;
            max-width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font: inherit;
            background: #fff;
        }
        .order-pick-hint {
            margin: 10px 0 0;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="toolbar">
        <a href="gofro_build_plan.php">← План сборки гофропакетов</a>
        <?php $canSaveRollPlan = ($loadError === '' && $selectedOrder !== '' && $planDates !== []); ?>
        <button type="button" class="toolbar-btn" id="btnSaveRollPlan"<?= $canSaveRollPlan ? '' : ' disabled' ?> title="Записать назначения дат в roll_plans (общий план порезки U3 для оператора бумагорезки)">Сохранить план</button>
        <button type="button" class="toolbar-btn" id="btnReload">Обновить</button>
    </div>

    <?php if ($loadError !== ''): ?>
        <div class="panel">Ошибка: <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
        <div class="panel order-pick-panel">
            <form class="order-pick-form" method="get" action="">
                <label for="order-select">Заявка</label>
                <select id="order-select" name="order">
                    <option value=""><?= empty($selectableOrders) ? '— нет заявок в плане г/п —' : '— выберите заявку —' ?></option>
                    <?php foreach ($selectableOrders as $ordOpt):
                        $sel = ($selectedOrder !== '' && $selectedOrder === $ordOpt) ? ' selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($ordOpt, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>><?= htmlspecialchars($ordOpt, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (abs($perBaleMin - 40.0) > 0.001): ?>
                    <input type="hidden" name="per_bale_min" value="<?= htmlspecialchars((string)$perBaleMin, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <?php if (abs($shiftHours - 7.0) > 0.001): ?>
                    <input type="hidden" name="shift_hours" value="<?= htmlspecialchars((string)$shiftHours, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <button type="submit" class="toolbar-btn">Показать</button>
            </form>
            <?php if ($selectedOrder !== ''): ?>
                <p class="order-pick-hint" style="margin-bottom:0;">Текущая заявка: <strong><?= htmlspecialchars($selectedOrder, ENT_QUOTES, 'UTF-8') ?></strong></p>
            <?php endif; ?>
        </div>

        <?php if (empty($selectableOrders)): ?>
            <div class="panel muted">Нет заявок с количеством в плане гофропакетов с сегодняшней даты. Заполните план на странице сборки гофропакетов.</div>
        <?php elseif ($selectedOrder === ''): ?>
            <div class="panel muted">Выберите номер заявки в списке выше и нажмите «Показать» — появятся шкала дат, бухты из раскроя этой заявки и таблица покрытия плана г/п только по ней.</div>
        <?php elseif ($planDates === []): ?>
            <div class="panel muted">Для заявки <strong><?= htmlspecialchars($selectedOrder, ENT_QUOTES, 'UTF-8') ?></strong> нет дат в плане гофропакетов и нет запланированных здесь порезок в доступном диапазоне — нечего отображать на шкале.</div>
        <?php else: ?>
        <div class="panel">
            <h2 style="margin:0 0 10px;font-size:15px;">План порезки бухт</h2>
            <p class="muted" style="margin:-4px 0 10px;font-size:11px;">Подсветка полос на бухтах: нажмите на <strong>название гофропакета</strong> в первом столбце таблицы «Покрытие потребности…» ниже — подсветятся все полосы с этим гофропакетом; повторный клик, клик по датам таблицы или мимо — снять (<kbd>Esc</kbd>). По <strong>цифре прихода с бухты</strong> в ячейке даты: клик — оставить в таблице только строки гофропакетов, куда входит эта бухта; повторный клик по выделенной цифре или <kbd>Esc</kbd> — показать все строки.</p>
            <div class="layout-main">
                <div class="timeline-scroll">
                    <div class="timeline" id="timeline">
                        <?php foreach ($planDates as $d):
                            $dt = DateTime::createFromFormat('Y-m-d', $d);
                            $label = $dt ? $dt->format('d.m') : $d;
                            $isWeekend = $dt ? in_array((int)$dt->format('N'), [6, 7], true) : false;
                            $wclass = $isWeekend ? ' day-column--weekend' : '';
                            ?>
                            <div class="day-column<?= $wclass ?>" data-cut-date="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="day-head">
                                    <div class="d-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="day-load" data-cut-date="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>"></div>
                                </div>
                                <div class="day-drop" data-drop-date="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php
                                    foreach ($balesJson as $b) {
                                        if (($b['cut_date'] ?? '') !== $d) {
                                            continue;
                                        }
                                        $oid = htmlspecialchars($b['order_number'], ENT_QUOTES, 'UTF-8');
                                        $bid = (int)$b['bale_id'];
                                        echo renderBaleCardHtml($b, $oid, $bid);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="pool-panel panel" style="margin:0;padding:10px;">
                    <h2>Не распределённые бухты</h2>
                    <div id="bale-pool" class="day-drop" data-drop-pool="1" style="min-height:72px;background:#f8fafc;border-radius:8px;border:1px dashed #94a3b8;">
                        <?php
                        $inPool = 0;
                        foreach ($balesJson as $b) {
                            if (($b['cut_date'] ?? null) !== null && $b['cut_date'] !== '') {
                                continue;
                            }
                            $inPool++;
                            $oid = htmlspecialchars($b['order_number'], ENT_QUOTES, 'UTF-8');
                            $bid = (int)$b['bale_id'];
                            echo renderBaleCardHtml($b, $oid, $bid);
                        }
                        if ($inPool === 0) {
                            echo '<p class="muted" style="margin:0;width:100%;">Все бухты распределены по датам или в раскрое нет бухт по этой заявке.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2 style="margin:0 0 8px;font-size:15px;">Покрытие потребности в гофропакетах</h2>
            <div class="legend">
                <span>Мелкая цифра — <strong>потребность в г/п в этот день</strong>; крупная(ые) — <strong>приход с порезки в этот день</strong> (если несколько бухт — отдельные кружки; наведите на число — подсветятся вклады этой же бухты в других гофропакетах). Цвет с потребностью — <strong>накопительно</strong> (остаток от порезки в прошлые даты строки). Клик по <strong>названию гофропакета</strong> в первом столбце — подсветка полос на бухтах.</span>
                <span><span class="sw" style="background:#fee2e2;"></span> не хватает</span>
                <span><span class="sw sw--split" title="Слева зелёное — доля покрытия, справа красное — нехватка"></span> частично (слева направо)</span>
                <span><span class="sw" style="background:#dcfce7;"></span> достаточно</span>
            </div>
            <div class="coverage-wrap">
                <table class="roll-cov-table has-frozen-cols" id="coverage-table">
                    <thead>
                    <tr>
                        <th class="pkg-head frozen-col frozen-col--last">Гофропакет</th>
                        <?php foreach ($planDates as $d):
                            $dt = DateTime::createFromFormat('Y-m-d', $d);
                            $label = $dt ? $dt->format('d.m') : $d;
                            ?>
                            <th class="num date-col"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody id="coverage-body">
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php if ($loadError === '' && $selectedOrder !== '' && $planDates !== []): ?>
<script>
(function () {
    const state = <?= $coverageJson ?>;

    function normPkgKeyRoll(k) {
        return String(k == null ? '' : k).replace(/\s+/g, ' ').trim().toUpperCase();
    }

    function cutDateIso(b) {
        const s = String(b.cut_date == null ? '' : b.cut_date);
        if (s.length >= 10 && /^\d{4}-\d{2}-\d{2}/.test(s)) {
            return s.slice(0, 10);
        }
        return s.trim();
    }

    /** Кол-во г/п по ключу пакета (устойчиво к регистру/пробелам vs ключи из PHP JSON). */
    function gpQtyForPackage(gpObj, pkgKey) {
        if (!gpObj || typeof gpObj !== 'object') {
            return 0;
        }
        const want = normPkgKeyRoll(pkgKey);
        if (Object.prototype.hasOwnProperty.call(gpObj, pkgKey)) {
            const m = gpObj[pkgKey];
            return Number(m && m.qty) || 0;
        }
        let sum = 0;
        for (const k of Object.keys(gpObj)) {
            if (normPkgKeyRoll(k) === want) {
                const m = gpObj[k];
                sum += Number(m && m.qty) || 0;
            }
        }
        return sum;
    }

    function dailySupplyOnDate(pkgKey, dateStr) {
        const wantD = String(dateStr || '').slice(0, 10);
        let sum = 0;
        for (const b of state.bales) {
            if (cutDateIso(b) !== wantD) {
                continue;
            }
            sum += gpQtyForPackage(b.gp_by_package, pkgKey);
        }
        return sum;
    }

    /** Вклад по бухтам в строку гофропакета на дату (для разбивки чисел и подсветки). */
    function supplyByBaleParts(pkgKey, dateStr) {
        const wantD = String(dateStr || '').slice(0, 10);
        const out = [];
        for (const b of state.bales) {
            if (cutDateIso(b) !== wantD) {
                continue;
            }
            const q = gpQtyForPackage(b.gp_by_package, pkgKey);
            if (q <= 0) {
                continue;
            }
            out.push({
                order: String(b.order_number || ''),
                baleId: parseInt(String(b.bale_id), 10) || 0,
                qty: q,
            });
        }
        return out;
    }

    function findBale(order, baleId) {
        const o = String(order);
        const id = parseInt(String(baleId), 10) || 0;
        return state.bales.find(function (x) { return String(x.order_number) === o && (x.bale_id | 0) === id; });
    }

    /** Ключ «заявка|bale_id» из data-bale-key (разбор с конца по последнему «|»). */
    function parseOrderBaleKey(baleKey) {
        const s = String(baleKey || '');
        const i = s.lastIndexOf('|');
        if (i <= 0) {
            return { order: '', baleId: 0 };
        }
        return { order: s.slice(0, i), baleId: parseInt(s.slice(i + 1), 10) || 0 };
    }

    /** Сколько г/п этой бухты даёт по ключу гофропакета (по данным плана). */
    function gpQtyFromBaleForPkg(baleKey, pkgKey) {
        const ob = parseOrderBaleKey(baleKey);
        const b = findBale(ob.order, ob.baleId);
        if (!b) {
            return 0;
        }
        return gpQtyForPackage(b.gp_by_package, pkgKey);
    }

    /**
     * Фон ячейки потребности: полностью зелёный / красный / частично — градиент слева направо (доля слева = avail/req).
     * Жёлтый «малый запас» убран — при накопительном учёте он почти всегда вводил в заблуждение.
     */
    function applyCoverageCellStyle(td, req, avail) {
        td.className = 'num date-col date-cell roll-cov-cell';
        td.style.removeProperty('--cov-fill-pct');
        if (req <= 0) {
            return;
        }
        if (avail <= 0) {
            td.classList.add('gofro-coverage-gap');
            return;
        }
        if (avail < req) {
            const pct = Math.min(100, Math.max(0, (avail / req) * 100));
            td.classList.add('roll-cov-partial-lr');
            td.style.setProperty('--cov-fill-pct', pct.toFixed(2) + '%');
            return;
        }
        td.classList.add('gofro-coverage-cell');
    }

    function renderCoverage() {
        const tbody = document.getElementById('coverage-body');
        if (!tbody) return;
        tbody.textContent = '';
        const pkgs = state.packages || [];
        for (const p of pkgs) {
            const pk = p.key;
            const name = p.name || pk;
            const byD = state.demand_daily[pk] || {};
            const tr = document.createElement('tr');
            const nameTd = document.createElement('td');
            nameTd.className = 'pkg-head frozen-col frozen-col--last roll-cov-pkg-select';
            if (pk) {
                nameTd.setAttribute('data-package-key', pk);
                nameTd.setAttribute('tabindex', '0');
                nameTd.setAttribute('role', 'button');
                nameTd.setAttribute('aria-label', 'Подсветить на бухтах полосы с гофропакетом «' + String(name).replace(/"/g, '') + '»');
            }
            nameTd.textContent = name;
            tr.appendChild(nameTd);
            /** Накопительный остаток г/п после обработки предыдущих дат (приход − списание потребности). */
            let balance = 0;
            for (const d of state.dates) {
                const s = dailySupplyOnDate(pk, d);
                const parts = supplyByBaleParts(pk, d);
                balance += s;
                const r = Number(byD[d]) || 0;
                const availForCover = balance;
                const td = document.createElement('td');
                applyCoverageCellStyle(td, r, r > 0 ? availForCover : 0);
                let tip = 'Дата ' + d + '\nПриход с порезки в этот день: ' + (s > 0 ? s + ' шт.' : 'нет');
                if (r > 0) {
                    tip += '\nПотребность в этот день: ' + r + ' шт.'
                        + '\nДоступно к покрытию (накопительно, с приходом в этот день): ' + availForCover + ' шт.';
                    if (availForCover > 0 && availForCover < r) {
                        tip += '\nДоля покрытия: ' + (Math.round((availForCover / r) * 1000) / 10) + '% (слева — покрыто, справа — нехватка).';
                    }
                }
                if (r > 0) {
                    balance = Math.max(0, balance - r);
                    tip += '\nОстаток после списания потребности этого дня: ' + balance + ' шт.';
                }
                td.title = tip;
                if (r > 0) {
                    const hint = document.createElement('span');
                    hint.className = 'cell-plan-hint';
                    hint.textContent = String(r);
                    td.appendChild(hint);
                }
                if (parts.length > 0) {
                    const wrap = document.createElement('span');
                    wrap.className = 'cov-sup-bales';
                    parts.sort(function (a, b) { return a.baleId - b.baleId; }).forEach(function (part) {
                        const badge = document.createElement('span');
                        badge.className = 'cell-gofro-qty cell-gofro-qty--bale';
                        badge.textContent = String(part.qty);
                        badge.dataset.baleKey = part.order + '|' + String(part.baleId);
                        badge.setAttribute('tabindex', '0');
                        badge.setAttribute('role', 'button');
                        badge.setAttribute('aria-pressed', 'false');
                        badge.setAttribute('aria-label', 'Фильтр по бухте №' + part.baleId + ', ' + part.qty + ' шт. по «' + String(name).replace(/"/g, '') + '»');
                        badge.title = 'Бухта №' + part.baleId + ' — ' + part.qty + ' шт. г/п по «' + String(name).replace(/"/g, '') + '»'
                            + '\nНаведите — подсветить ту же бухту в других строках.'
                            + '\nКлик — только строки этой бухты; повторный клик или Escape — сброс.';
                        wrap.appendChild(badge);
                    });
                    td.appendChild(wrap);
                }
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }
        if (pkgs.length === 0) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = (state.dates.length || 0) + 1;
            td.className = 'muted';
            td.style.textAlign = 'center';
            td.textContent = 'Нет строк гофропакетов в плане.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        }
        syncCoveragePackageHighlight();
        syncCoverageBaleRowFilter();
    }

    function bumpCutScheduleCount(dateStr, delta) {
        if (!dateStr || !delta) {
            return;
        }
        const k = String(dateStr).slice(0, 10);
        if (!state.cut_schedule_counts || Array.isArray(state.cut_schedule_counts)) {
            state.cut_schedule_counts = {};
        }
        const cur = parseInt(String(state.cut_schedule_counts[k] || 0), 10) || 0;
        state.cut_schedule_counts[k] = Math.max(0, cur + delta);
    }

    function applyScheduleDiff(prevRaw, nextRaw) {
        const p = prevRaw ? String(prevRaw).slice(0, 10) : '';
        const n = nextRaw ? String(nextRaw).slice(0, 10) : '';
        if (p === n) {
            return;
        }
        if (p) {
            bumpCutScheduleCount(p, -1);
        }
        if (n) {
            bumpCutScheduleCount(n, +1);
        }
    }

    function updateDayLoads() {
        const perBaleMin = parseFloat(String(state.per_bale_min)) || 40;
        const shiftHours = parseFloat(String(state.shift_hours)) || 7;
        const shiftMin = shiftHours * 60;
        let counts = state.cut_schedule_counts || {};
        if (Array.isArray(counts)) {
            counts = {};
        }
        for (const d of state.dates) {
            let mine = 0;
            for (const b of state.bales) {
                const cd = String(b.cut_date || '').slice(0, 10);
                if (cd === d) {
                    mine += 1;
                }
            }
            const fromDb = parseInt(String(counts[d] ?? 0), 10) || 0;
            const totalBales = Math.max(fromDb, mine);
            const otherBales = Math.max(0, totalBales - mine);
            const totalMin = totalBales * perBaleMin;
            const overload = totalMin > shiftMin + 1e-6;
            const col = document.querySelector('.day-column[data-cut-date="' + d + '"]');
            if (col) {
                col.classList.toggle('day-column--overload', overload);
            }
            const loadEl = document.querySelector('.day-load[data-cut-date="' + d + '"]');
            if (loadEl) {
                const hAll = (totalMin / 60).toFixed(2);
                loadEl.innerHTML = '<strong>' + hAll + '</strong> ч';
                loadEl.title = 'Все заявки на бумагорезке (U2 roll_plan + U3–U5 roll_plans, как у оператора; плюс только gofro_roll_cut_schedule без пары в roll_plans U3): '
                    + totalBales + ' бухт на дату. '
                    + 'Время: ' + hAll + ' ч при ' + perBaleMin + ' мин/бухта, лимит смены ' + shiftHours + ' ч. '
                    + 'Эта заявка: ' + mine + ' б., остальные заявки: ' + otherBales + ' б.';
            }
        }
    }

    /** Подсветка при наведении: все бейджи прихода с той же бухты (заявка|id) в таблице покрытия. */
    function wireCoverageBaleSupplyHover() {
        const tbody = document.getElementById('coverage-body');
        if (!tbody || tbody.dataset.baleHoverWired === '1') {
            return;
        }
        tbody.dataset.baleHoverWired = '1';
        function clearCovBaleHl() {
            tbody.querySelectorAll('.cov-sup-bale--hl').forEach(function (n) {
                n.classList.remove('cov-sup-bale--hl');
            });
        }
        tbody.addEventListener('mouseover', function (e) {
            const badge = e.target.closest('.cell-gofro-qty--bale');
            if (!badge || !badge.dataset.baleKey) {
                clearCovBaleHl();
                return;
            }
            const k = badge.dataset.baleKey;
            tbody.querySelectorAll('.cell-gofro-qty--bale').forEach(function (n) {
                n.classList.toggle('cov-sup-bale--hl', n.dataset.baleKey === k);
            });
        });
        tbody.addEventListener('mouseout', function (e) {
            const to = e.relatedTarget;
            if (!to || !tbody.contains(to)) {
                clearCovBaleHl();
            }
        });
        tbody.addEventListener('click', function (e) {
            const badge = e.target.closest('.cell-gofro-qty--bale');
            if (!badge || !badge.dataset.baleKey) {
                return;
            }
            e.stopPropagation();
            const k = String(badge.dataset.baleKey);
            activeCoveragePackageKey = '';
            syncCoveragePackageHighlight();
            if (activeBaleFilterKey === k) {
                activeBaleFilterKey = '';
            } else {
                activeBaleFilterKey = k;
            }
            syncCoverageBaleRowFilter();
        });
    }

    function moveCardDom(card, targetDrop) {
        targetDrop.appendChild(card);
    }

    /** Подсветка полос на бухтах по ключу гофропакета (клик в 1-м столбце таблицы покрытия). */
    let activeCoveragePackageKey = '';
    /** Фильтр строк таблицы покрытия: только гофропакеты с приходом с выбранной бухты (ключ «заявка|id»). */
    let activeBaleFilterKey = '';

    function syncCoverageBaleRowFilter() {
        const tbody = document.getElementById('coverage-body');
        if (!tbody) {
            return;
        }
        const fk = String(activeBaleFilterKey || '');
        tbody.querySelectorAll('.cell-gofro-qty--bale').forEach(function (n) {
            const on = fk !== '' && String(n.dataset.baleKey || '') === fk;
            n.classList.toggle('cov-sup-bale--selected', on);
            n.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        if (!fk) {
            tbody.querySelectorAll('tr.roll-cov-tr--bale-filter-hidden').forEach(function (tr) {
                tr.classList.remove('roll-cov-tr--bale-filter-hidden');
            });
            return;
        }
        tbody.querySelectorAll('tr').forEach(function (tr) {
            const nameTd = tr.querySelector('td.roll-cov-pkg-select[data-package-key]');
            if (!nameTd) {
                return;
            }
            const pk = String(nameTd.dataset.packageKey || '');
            const show = pk && gpQtyFromBaleForPkg(fk, pk) > 0;
            tr.classList.toggle('roll-cov-tr--bale-filter-hidden', !show);
        });
    }

    function clearCoveragePackageHighlight() {
        document.querySelectorAll('.bale-strip--pkg-active').forEach(function (el) {
            el.classList.remove('bale-strip--pkg-active');
        });
        document.querySelectorAll('.roll-cov-pkg-select--active').forEach(function (el) {
            el.classList.remove('roll-cov-pkg-select--active');
        });
        document.querySelectorAll('.roll-cov-tr--active').forEach(function (el) {
            el.classList.remove('roll-cov-tr--active');
        });
    }

    function applyCoveragePackageHighlight(key) {
        if (!key) {
            return;
        }
        document.querySelectorAll('.bale-strip[data-package-key]').forEach(function (el) {
            if (String(el.dataset.packageKey || '') !== key) {
                return;
            }
            el.classList.add('bale-strip--pkg-active');
        });
        document.querySelectorAll('#coverage-table td.roll-cov-pkg-select[data-package-key]').forEach(function (td) {
            if (String(td.dataset.packageKey || '') !== key) {
                return;
            }
            td.classList.add('roll-cov-pkg-select--active');
            const tr = td.closest('tr');
            if (tr) {
                tr.classList.add('roll-cov-tr--active');
            }
        });
    }

    function syncCoveragePackageHighlight() {
        clearCoveragePackageHighlight();
        if (!activeCoveragePackageKey) {
            return;
        }
        applyCoveragePackageHighlight(activeCoveragePackageKey);
    }

    function wireCoveragePackageHighlight() {
        document.addEventListener('click', function (e) {
            const cell = e.target.closest('#coverage-table td.roll-cov-pkg-select[data-package-key]');
            if (cell) {
                activeBaleFilterKey = '';
                syncCoverageBaleRowFilter();
                const k = String(cell.dataset.packageKey || '');
                if (!k) {
                    return;
                }
                if (activeCoveragePackageKey === k) {
                    activeCoveragePackageKey = '';
                } else {
                    activeCoveragePackageKey = k;
                }
                syncCoveragePackageHighlight();
                return;
            }
            if (e.target.closest('#coverage-table tbody') && activeCoveragePackageKey) {
                activeCoveragePackageKey = '';
                syncCoveragePackageHighlight();
                return;
            }
            if (!e.target.closest('#coverage-table') && activeCoveragePackageKey) {
                activeCoveragePackageKey = '';
                syncCoveragePackageHighlight();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                let handled = false;
                if (activeCoveragePackageKey) {
                    activeCoveragePackageKey = '';
                    syncCoveragePackageHighlight();
                    handled = true;
                }
                if (activeBaleFilterKey) {
                    activeBaleFilterKey = '';
                    syncCoverageBaleRowFilter();
                    handled = true;
                }
                if (handled) {
                    return;
                }
            }
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            const badge = e.target.closest('.cell-gofro-qty--bale');
            if (badge && badge.dataset.baleKey) {
                e.preventDefault();
                badge.click();
                return;
            }
            const cell = e.target.closest('#coverage-table td.roll-cov-pkg-select[data-package-key]');
            if (!cell) {
                return;
            }
            e.preventDefault();
            cell.click();
        });
    }

    async function persistCutDate(order, baleId, cutDate) {
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_bale_cut_date',
                order_number: order,
                bale_id: baleId,
                cut_date: cutDate || ''
            })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Ошибка сохранения');
        const b = findBale(order, baleId);
        if (b) b.cut_date = data.cut_date || null;
    }

    function wireDrag() {
        let dragged = null;
        document.querySelectorAll('.bale-card').forEach(function (card) {
            card.addEventListener('dragstart', function (e) {
                dragged = card;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.order + '|' + card.dataset.baleId);
                card.style.opacity = '0.5';
            });
            card.addEventListener('dragend', function () {
                card.style.opacity = '1';
                document.querySelectorAll('.day-drop--drag').forEach(function (n) { n.classList.remove('day-drop--drag'); });
            });
        });
        document.querySelectorAll('.day-drop').forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                e.preventDefault();
                zone.classList.add('day-drop--drag');
                e.dataTransfer.dropEffect = 'move';
            });
            zone.addEventListener('dragleave', function () {
                zone.classList.remove('day-drop--drag');
            });
            zone.addEventListener('drop', async function (e) {
                e.preventDefault();
                zone.classList.remove('day-drop--drag');
                if (!dragged) return;
                const order = dragged.dataset.order;
                const baleId = parseInt(dragged.dataset.baleId, 10);
                const pool = zone.getAttribute('data-drop-pool');
                const date = zone.getAttribute('data-drop-date');
                const bBefore = findBale(order, baleId);
                const prevDate = bBefore && bBefore.cut_date ? String(bBefore.cut_date).slice(0, 10) : '';
                try {
                    if (pool) {
                        await persistCutDate(order, baleId, '');
                        moveCardDom(dragged, document.getElementById('bale-pool'));
                        applyScheduleDiff(prevDate, '');
                    } else if (date) {
                        await persistCutDate(order, baleId, date);
                        moveCardDom(dragged, zone);
                        applyScheduleDiff(prevDate, date);
                    }
                    renderCoverage();
                    updateDayLoads();
                } catch (err) {
                    alert(err.message || String(err));
                }
            });
        });
    }

    document.getElementById('btnReload') && document.getElementById('btnReload').addEventListener('click', function () {
        location.reload();
    });

    const btnSaveRollPlan = document.getElementById('btnSaveRollPlan');
    if (btnSaveRollPlan) {
        btnSaveRollPlan.addEventListener('click', async function () {
            if (btnSaveRollPlan.disabled) {
                return;
            }
            const ord = String(state.order_number || '').trim();
            if (!ord) {
                alert('Не выбрана заявка.');
                return;
            }
            btnSaveRollPlan.disabled = true;
            try {
                const res = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_roll_cutting_plan',
                        order_number: ord,
                    }),
                });
                const data = await res.json().catch(function () { return {}; });
                if (!data.ok) {
                    throw new Error(data.error || data.message || 'Ошибка сохранения');
                }
                alert(data.message || 'План сохранён.');
            } catch (err) {
                alert(err.message || String(err));
            } finally {
                btnSaveRollPlan.disabled = false;
            }
        });
    }

    renderCoverage();
    updateDayLoads();
    wireCoveragePackageHighlight();
    wireCoverageBaleSupplyHover();
    wireDrag();
})();
</script>
<?php endif; ?>
</body>
</html>
