<?php
/**
 * API заявок на бобинорезку (ручной раскрой) с участка U2.
 * JSON: list / filters / submit / cancel → bobbin_cut_requests + roll_plans + cut_plans.
 * Полосы — позиции из panel_filter_structure (ширина из paper_package_panel).
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

function bobbinJsonExit(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../auth/includes/config.php';
    require_once __DIR__ . '/../auth/includes/auth-functions.php';
    require_once __DIR__ . '/settings.php';

    initAuthSystem();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!defined('BOBBIN_BALE_FORMAT_MM')) {
        define('BOBBIN_BALE_FORMAT_MM', (float)($width_of_main_roll ?? 1200));
        define('BOBBIN_STRIP_MIN', 1.0);
        define('BOBBIN_STRIP_MAX', max(1.0, BOBBIN_BALE_FORMAT_MM));
    }

    $auth = new AuthManager();
    $session = $auth->checkSession();
    if (!$session) {
        bobbinJsonExit(['ok' => false, 'error' => 'Не авторизован'], 401);
    }

    $db = Database::getInstance();
    $users = $db->select('SELECT * FROM auth_users WHERE id = ?', [$session['user_id']]);
    if (!is_array($users)) {
        $users = [];
    }
    $user = $users[0] ?? null;
    $userName = (string)($user['full_name'] ?? $session['full_name'] ?? 'Пользователь');

    $userDepartments = $db->select("
        SELECT ud.department_code, r.name as role_name
        FROM auth_user_departments ud
        JOIN auth_roles r ON ud.role_id = r.id
        WHERE ud.user_id = ?
    ", [$session['user_id']]);
    if (!is_array($userDepartments)) {
        $userDepartments = [];
    }

    $currentDepartment = 'U2';

    $canAccess = false;
    foreach ($userDepartments as $dept) {
        if (($dept['department_code'] ?? '') === 'U2'
            && in_array($dept['role_name'] ?? '', ['assembler', 'corr_operator', 'supervisor', 'director'], true)
        ) {
            $canAccess = true;
            break;
        }
    }
    if (!$canAccess) {
        bobbinJsonExit(['ok' => false, 'error' => 'Нет доступа'], 403);
    }

    $mysqli = @new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    if ($mysqli->connect_errno) {
        bobbinJsonExit(['ok' => false, 'error' => 'Ошибка БД: ' . $mysqli->connect_error], 500);
    }
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    bobbinJsonExit(['ok' => false, 'error' => 'Ошибка инициализации: ' . $e->getMessage()], 500);
}

function bobbinIsAllowedWidth(float $width): bool
{
    return $width >= BOBBIN_STRIP_MIN - 0.001
        && $width <= BOBBIN_STRIP_MAX + 0.001;
}

function bobbinColumnExists(mysqli $mysqli, string $table, string $column): bool
{
    $tableEsc = $mysqli->real_escape_string($table);
    $columnEsc = $mysqli->real_escape_string($column);
    $res = $mysqli->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = '{$tableEsc}'
           AND COLUMN_NAME = '{$columnEsc}'"
    );
    return $res && (int)$res->fetch_row()[0] > 0;
}

function bobbinEnsureBaleIdVarchar(mysqli $mysqli, string $table): void
{
    if (!bobbinIsBaseTable($mysqli, $table) || !bobbinColumnExists($mysqli, $table, 'bale_id')) {
        return;
    }
    $tableEsc = str_replace('`', '``', $table);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE 'bale_id'");
    if (!$res) {
        return;
    }
    $col = $res->fetch_assoc();
    if (!$col) {
        return;
    }
    $typeLower = strtolower((string)($col['Type'] ?? ''));
    if (preg_match('/^varchar\((\d+)\)$/', $typeLower, $m) && (int)$m[1] >= 50) {
        return;
    }
    $nullSql = (strtoupper((string)($col['Null'] ?? '')) === 'YES') ? 'NULL' : 'NOT NULL';
    @$mysqli->query("ALTER TABLE `{$tableEsc}` MODIFY COLUMN `bale_id` VARCHAR(64) {$nullSql}");
}

function bobbinEnsureColumnAllowsValue(mysqli $mysqli, string $table, string $column, string $value): void
{
    if (!bobbinColumnExists($mysqli, $table, $column)) {
        return;
    }

    $tableEsc = str_replace('`', '``', $table);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '" . $mysqli->real_escape_string($column) . "'");
    if (!$res) {
        return;
    }
    $col = $res->fetch_assoc();
    if (!$col) {
        return;
    }

    $type = (string)($col['Type'] ?? '');
    $nullSql = (strtoupper((string)($col['Null'] ?? '')) === 'YES') ? 'NULL' : 'NOT NULL';
    $default = $col['Default'];
    $defaultSql = '';
    if ($default !== null && $default !== '') {
        $defaultSql = " DEFAULT '" . $mysqli->real_escape_string((string)$default) . "'";
    } elseif (strtoupper((string)($col['Null'] ?? '')) === 'YES') {
        $defaultSql = ' DEFAULT NULL';
    }

    $typeLower = strtolower($type);
    $colEsc = str_replace('`', '``', $column);

    if (stripos($typeLower, 'enum(') === 0) {
        if (stripos($type, "'" . $value . "'") !== false) {
            return;
        }
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);
        $vals = $matches[1] ?? [];
        $vals[] = $value;
        $vals = array_values(array_unique($vals));
        $enumList = implode(',', array_map(
            static function ($v) {
                return "'" . str_replace("'", "''", $v) . "'";
            },
            $vals
        ));
        @$mysqli->query("ALTER TABLE `{$tableEsc}` MODIFY COLUMN `{$colEsc}` ENUM({$enumList}) {$nullSql}{$defaultSql}");
        return;
    }

    if (preg_match('/^(var)?char\((\d+)\)$/i', $typeLower, $m)) {
        $len = (int)$m[2];
        if ($len < strlen($value)) {
            @$mysqli->query("ALTER TABLE `{$tableEsc}` MODIFY COLUMN `{$colEsc}` VARCHAR(64) {$nullSql}{$defaultSql}");
        }
    }
}

function bobbinTableType(mysqli $mysqli, string $table): string
{
    $tableEsc = $mysqli->real_escape_string($table);
    $typeRes = $mysqli->query(
        "SELECT TABLE_TYPE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tableEsc}'"
    );
    return $typeRes ? (string)($typeRes->fetch_row()[0] ?? '') : '';
}

function bobbinIsBaseTable(mysqli $mysqli, string $table): bool
{
    return strcasecmp(bobbinTableType($mysqli, $table), 'BASE TABLE') === 0;
}

function bobbinEnsureSchema(mysqli $mysqli): void
{
    $ok = $mysqli->query("
        CREATE TABLE IF NOT EXISTS bobbin_cut_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_name VARCHAR(255) NOT NULL,
            department VARCHAR(10) NOT NULL,
            quantity INT NOT NULL,
            comment_text VARCHAR(500) NULL,
            desired_delivery_time DATETIME NULL,
            order_number VARCHAR(50) NULL,
            is_completed TINYINT(1) DEFAULT 0,
            completed_at TIMESTAMP NULL,
            is_cancelled TINYINT(1) DEFAULT 0,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_dept (user_name, department),
            KEY idx_order_number (order_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    if (!$ok) {
        throw new RuntimeException('Не удалось создать bobbin_cut_requests: ' . $mysqli->error);
    }

    $rollBase = null;
    if (bobbinIsBaseTable($mysqli, 'roll_plan')) {
        $rollBase = 'roll_plan';
    } elseif (bobbinIsBaseTable($mysqli, 'roll_plans')) {
        $rollBase = 'roll_plans';
    }

    if ($rollBase !== null) {
        if (!bobbinColumnExists($mysqli, $rollBase, 'plan_date')) {
            $mysqli->query("ALTER TABLE `{$rollBase}` ADD COLUMN plan_date DATE NULL");
        }
        if (!bobbinColumnExists($mysqli, $rollBase, 'work_date')) {
            $mysqli->query("ALTER TABLE `{$rollBase}` ADD COLUMN work_date DATE NULL");
        }
        if (!bobbinColumnExists($mysqli, $rollBase, 'done')) {
            $mysqli->query("ALTER TABLE `{$rollBase}` ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
        }
        bobbinEnsureBaleIdVarchar($mysqli, $rollBase);
    } elseif (bobbinTableType($mysqli, 'roll_plans') === '' && bobbinTableType($mysqli, 'roll_plan') === '') {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS roll_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(50) NOT NULL,
                bale_id VARCHAR(50) NOT NULL,
                plan_date DATE NULL,
                work_date DATE NULL,
                done TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_order_bale (order_number, bale_id),
                KEY idx_plan_date (plan_date),
                KEY idx_work_date (work_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    $hasCut = $mysqli->query("SHOW TABLES LIKE 'cut_plans'");
    if (!$hasCut || $hasCut->num_rows === 0) {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS cut_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(50) NOT NULL,
                bale_id VARCHAR(50) NOT NULL,
                strip_no INT NULL,
                material VARCHAR(255) NULL,
                filter VARCHAR(255) NULL,
                paper VARCHAR(255) NULL,
                width DECIMAL(12,2) NULL,
                height DECIMAL(12,2) NULL,
                length DECIMAL(12,2) NULL,
                format VARCHAR(50) NULL,
                source VARCHAR(50) NULL,
                manual TINYINT(1) DEFAULT 1,
                KEY idx_order_bale (order_number, bale_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    if (bobbinIsBaseTable($mysqli, 'cut_plans') || bobbinTableType($mysqli, 'cut_plans') !== '') {
        bobbinEnsureBaleIdVarchar($mysqli, 'cut_plans');
        bobbinEnsureColumnAllowsValue($mysqli, 'cut_plans', 'source', 'manual');
    }
}

/**
 * Карта фильтров: filter => [filter, paper, width, height, pleats, material]
 * Длина на 1 шт (мм) = pleats * 2 * height (как в NP_cut_plan).
 */
function bobbinLoadFilterMap(mysqli $mysqli): array
{
    $sql = "
        SELECT
            TRIM(pfs.filter) AS filter_name,
            TRIM(pfs.paper_package) AS paper,
            CAST(ppp.p_p_width AS DECIMAL(12,2)) AS width,
            CAST(ppp.p_p_height AS DECIMAL(12,2)) AS height,
            CAST(COALESCE(ppp.p_p_pleats_count, 0) AS UNSIGNED) AS pleats,
            TRIM(COALESCE(ppp.p_p_material, '')) AS material
        FROM panel_filter_structure pfs
        INNER JOIN paper_package_panel ppp ON ppp.p_p_name = pfs.paper_package
        WHERE TRIM(pfs.filter) <> ''
          AND ppp.p_p_width IS NOT NULL
          AND CAST(ppp.p_p_width AS DECIMAL(12,2)) > 0
        ORDER BY filter_name
    ";
    $res = $mysqli->query($sql);
    if (!$res) {
        throw new RuntimeException('Ошибка чтения фильтров: ' . $mysqli->error);
    }
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $name = (string)$row['filter_name'];
        if ($name === '' || isset($map[$name])) {
            continue;
        }
        $map[$name] = [
            'filter' => $name,
            'paper' => (string)($row['paper'] ?? ''),
            'width' => round((float)$row['width'], 1),
            'height' => round((float)($row['height'] ?? 0), 1),
            'pleats' => (int)($row['pleats'] ?? 0),
            'material' => (string)($row['material'] ?? ''),
        ];
    }
    return $map;
}

function bobbinWritableRollTables(mysqli $mysqli): array
{
    $tables = [];
    foreach (['roll_plan', 'roll_plans'] as $t) {
        if (!bobbinIsBaseTable($mysqli, $t)) {
            continue;
        }
        $tables[] = $t;
    }
    if ($tables === [] && strcasecmp(bobbinTableType($mysqli, 'roll_plans'), 'VIEW') === 0) {
        $tables[] = 'roll_plans';
    }
    if ($tables === []) {
        throw new RuntimeException('Не найдена таблица плана порезки (roll_plan / roll_plans)');
    }
    return $tables;
}

function bobbinInsertIntoRollTable(
    mysqli $mysqli,
    string $table,
    string $orderNumber,
    string $baleId,
    string $workDate
): void {
    $hasPlanDate = bobbinColumnExists($mysqli, $table, 'plan_date');
    $hasWorkDate = bobbinColumnExists($mysqli, $table, 'work_date');

    if ($hasPlanDate && $hasWorkDate) {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, plan_date, work_date, done)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE plan_date = VALUES(plan_date), work_date = VALUES(work_date)"
        );
        if (!$ins) {
            throw new RuntimeException("Ошибка подготовки {$table}: " . $mysqli->error);
        }
        $ins->bind_param('ssss', $orderNumber, $baleId, $workDate, $workDate);
    } elseif ($hasWorkDate) {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, work_date, done)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE work_date = VALUES(work_date)"
        );
        if (!$ins) {
            throw new RuntimeException("Ошибка подготовки {$table}: " . $mysqli->error);
        }
        $ins->bind_param('sss', $orderNumber, $baleId, $workDate);
    } else {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, plan_date, done)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE plan_date = VALUES(plan_date)"
        );
        if (!$ins) {
            throw new RuntimeException("Ошибка подготовки {$table}: " . $mysqli->error);
        }
        $ins->bind_param('sss', $orderNumber, $baleId, $workDate);
    }
    if (!$ins->execute()) {
        throw new RuntimeException("Ошибка записи в {$table}: " . $ins->error);
    }
    $ins->close();
}

function bobbinInsertStrip(
    mysqli $mysqli,
    string $orderNumber,
    string $baleId,
    int $stripNo,
    array $strip,
    float $lengthM = 0.0
): void {
    $width = (float)$strip['width'];
    $height = (float)($strip['height'] ?? 0);
    $filter = (string)($strip['filter'] ?? '');
    $paper = (string)($strip['paper'] ?? '');
    $material = (string)($strip['material'] ?? '');

    $cutCols = ['order_number', 'bale_id'];
    $cutVals = [$orderNumber, $baleId];
    $cutTypes = 'ss';

    if (bobbinColumnExists($mysqli, 'cut_plans', 'strip_no')) {
        $cutCols[] = 'strip_no';
        $cutVals[] = $stripNo;
        $cutTypes .= 'i';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'filter')) {
        $cutCols[] = 'filter';
        $cutVals[] = $filter;
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'paper')) {
        $cutCols[] = 'paper';
        $cutVals[] = $paper;
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'material') && $material !== '') {
        bobbinEnsureColumnAllowsValue($mysqli, 'cut_plans', 'material', $material);
        $cutCols[] = 'material';
        $cutVals[] = $material;
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'source')) {
        $cutCols[] = 'source';
        $cutVals[] = 'manual';
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'manual')) {
        $cutCols[] = 'manual';
        $cutVals[] = 1;
        $cutTypes .= 'i';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'format')) {
        $cutCols[] = 'format';
        $cutVals[] = (string)(int)BOBBIN_BALE_FORMAT_MM;
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'width')) {
        $cutCols[] = 'width';
        $cutVals[] = $width;
        $cutTypes .= 'd';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'height')) {
        $cutCols[] = 'height';
        $cutVals[] = $height;
        $cutTypes .= 'd';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'length')) {
        $cutCols[] = 'length';
        $cutVals[] = $lengthM;
        $cutTypes .= 'd';
    }

    $placeholders = implode(',', array_fill(0, count($cutCols), '?'));
    $colList = implode(', ', $cutCols);
    $cut = $mysqli->prepare("INSERT INTO cut_plans ({$colList}) VALUES ({$placeholders})");
    if (!$cut) {
        throw new RuntimeException('Ошибка подготовки cut_plans: ' . $mysqli->error);
    }
    $cut->bind_param($cutTypes, ...$cutVals);
    if (!$cut->execute()) {
        throw new RuntimeException('Ошибка записи cut_plans: ' . $cut->error);
    }
    $cut->close();
}

function bobbinSyncCompletedStatus(mysqli $mysqli, string $userName, string $department): void
{
    $sql = "SELECT id, order_number FROM bobbin_cut_requests
            WHERE user_name = ? AND department = ?
              AND (is_cancelled IS NULL OR is_cancelled = 0)
              AND (is_completed IS NULL OR is_completed = 0)
              AND order_number IS NOT NULL AND order_number != ''";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $userName, $department);
    if (!$stmt->execute()) {
        $stmt->close();
        return;
    }
    $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($pending as $req) {
        $orderNumber = $req['order_number'];
        $total = 0;
        $doneCnt = 0;
        foreach (bobbinWritableRollTables($mysqli) as $table) {
            $chk = $mysqli->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) AS done_cnt
                 FROM `{$table}` WHERE order_number = ?"
            );
            if (!$chk) {
                continue;
            }
            $chk->bind_param('s', $orderNumber);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();
            $t = (int)($row['total'] ?? 0);
            $d = (int)($row['done_cnt'] ?? 0);
            if ($t > $total) {
                $total = $t;
                $doneCnt = $d;
            }
        }
        if ($total > 0 && $doneCnt >= $total) {
            $upd = $mysqli->prepare(
                'UPDATE bobbin_cut_requests SET is_completed = 1, completed_at = NOW() WHERE id = ?'
            );
            if ($upd) {
                $upd->bind_param('i', $req['id']);
                $upd->execute();
                $upd->close();
            }
        }
    }
}

function bobbinNormalizeBales(array $rawBales, array $filterMap): array
{
    if ($rawBales === []) {
        throw new InvalidArgumentException('Добавьте хотя бы одну бухту');
    }
    if (count($rawBales) > 100) {
        throw new InvalidArgumentException('Слишком много бухт (макс. 100)');
    }

    $normalized = [];
    $usedNames = [];
    foreach ($rawBales as $baleIdx => $bale) {
        $stripsIn = $bale['strips'] ?? null;
        if (!is_array($stripsIn) || $stripsIn === []) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': нет полос');
        }

        $name = trim((string)($bale['name'] ?? ''));
        $name = preg_replace('/\s+/u', ' ', $name);
        if ($name === '') {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': не указано название');
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($name) > 50) {
                $name = mb_substr($name, 0, 50);
            }
        } elseif (strlen($name) > 50) {
            $name = substr($name, 0, 50);
        }
        if (isset($usedNames[$name])) {
            throw new InvalidArgumentException('Дублируется название бухты: ' . $name);
        }
        $usedNames[$name] = true;

        $lengthM = isset($bale['length_m']) ? (float)$bale['length_m'] : 0.0;
        if ($lengthM <= 0) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': укажите длину порезки в метрах');
        }
        if ($lengthM > 99999) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': слишком большая длина');
        }
        $lengthM = round($lengthM, 1);

        $expanded = [];
        $sum = 0.0;
        foreach ($stripsIn as $s) {
            $filterName = trim((string)($s['filter'] ?? ''));
            $qty = isset($s['qty']) ? (int)$s['qty'] : 1;
            if ($qty < 1) {
                $qty = 1;
            }
            if ($qty > 50) {
                throw new InvalidArgumentException('Слишком много одинаковых полос');
            }
            if ($filterName === '' || !isset($filterMap[$filterName])) {
                throw new InvalidArgumentException(
                    'Бухта #' . ($baleIdx + 1) . ': неизвестный фильтр «' . $filterName . '»'
                );
            }
            $info = $filterMap[$filterName];
            if (!bobbinIsAllowedWidth((float)$info['width'])) {
                throw new InvalidArgumentException(
                    'Недопустимая ширина фильтра ' . $filterName . ': ' . $info['width'] . ' мм'
                );
            }
            for ($i = 0; $i < $qty; $i++) {
                $expanded[] = $info;
                $sum += (float)$info['width'];
            }
        }

        if ($sum > BOBBIN_BALE_FORMAT_MM + 0.01) {
            throw new InvalidArgumentException(
                'Бухта #' . ($baleIdx + 1) . ': сумма ширин ' . round($sum, 1)
                . ' мм превышает формат ' . (int)BOBBIN_BALE_FORMAT_MM . ' мм'
            );
        }
        if ($expanded === []) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': пустая');
        }

        $normalized[] = [
            'name' => $name,
            'length_m' => $lengthM,
            'strips' => $expanded,
            'sum' => round($sum, 1),
        ];
    }

    return $normalized;
}

function bobbinJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    bobbinEnsureSchema($mysqli);
    bobbinSyncCompletedStatus($mysqli, $userName, $currentDepartment);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';
    $body = [];
    if ($method === 'POST') {
        $body = bobbinJsonBody();
        if ($action === '' && isset($body['action'])) {
            $action = (string)$body['action'];
        }
    }

    if ($method === 'GET' && $action === 'filters') {
        $map = bobbinLoadFilterMap($mysqli);
        bobbinJsonExit([
            'ok' => true,
            'filters' => array_values($map),
            'format_mm' => BOBBIN_BALE_FORMAT_MM,
        ]);
    }

    if ($method === 'GET' && ($action === 'list' || $action === '')) {
        $stmt = $mysqli->prepare(
            "SELECT id, quantity, comment_text, desired_delivery_time, order_number,
                    is_completed, is_cancelled, created_at
             FROM bobbin_cut_requests
             WHERE user_name = ? AND department = ?
             ORDER BY created_at DESC LIMIT 10"
        );
        if (!$stmt) {
            throw new RuntimeException('Ошибка чтения заявок: ' . $mysqli->error);
        }
        $stmt->bind_param('ss', $userName, $currentDepartment);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        bobbinJsonExit([
            'ok' => true,
            'requests' => $rows,
            'format_mm' => BOBBIN_BALE_FORMAT_MM,
        ]);
    }

    if ($method === 'POST' && $action === 'submit') {
        $workDate = trim((string)($body['work_date'] ?? ''));
        $comment = trim((string)($body['comment'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($comment) > 500) {
            $comment = mb_substr($comment, 0, 500);
        } elseif (strlen($comment) > 500) {
            $comment = substr($comment, 0, 500);
        }

        if ($workDate === '') {
            $workDate = date('Y-m-d');
        }
        $dt = DateTime::createFromFormat('Y-m-d', $workDate);
        if (!$dt || $dt->format('Y-m-d') !== $workDate) {
            throw new InvalidArgumentException('Некорректная дата порезки');
        }

        $filterMap = bobbinLoadFilterMap($mysqli);
        $bales = bobbinNormalizeBales(is_array($body['bales'] ?? null) ? $body['bales'] : [], $filterMap);
        $quantity = count($bales);
        $datetime = $workDate . ' 00:00:00';

        $mysqli->begin_transaction();
        try {
            $insReq = $mysqli->prepare(
                "INSERT INTO bobbin_cut_requests
                    (user_name, department, quantity, comment_text, desired_delivery_time)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$insReq) {
                throw new RuntimeException('Ошибка подготовки заявки: ' . $mysqli->error);
            }
            $commentStore = $comment !== '' ? $comment : '';
            $insReq->bind_param('ssiss', $userName, $currentDepartment, $quantity, $commentStore, $datetime);
            $insReq->execute();
            $requestId = (int)$mysqli->insert_id;
            $insReq->close();

            $orderNumber = 'U2-BC-' . $requestId;
            $updOrder = $mysqli->prepare(
                'UPDATE bobbin_cut_requests SET order_number = ? WHERE id = ?'
            );
            $updOrder->bind_param('si', $orderNumber, $requestId);
            $updOrder->execute();
            $updOrder->close();

            $rollTables = bobbinWritableRollTables($mysqli);
            foreach ($bales as $bale) {
                $baleId = (string)$bale['name'];
                $lengthM = (float)($bale['length_m'] ?? 0);
                foreach ($rollTables as $table) {
                    bobbinInsertIntoRollTable($mysqli, $table, $orderNumber, $baleId, $workDate);
                }
                $stripNo = 1;
                foreach ($bale['strips'] as $strip) {
                    bobbinInsertStrip(
                        $mysqli,
                        $orderNumber,
                        $baleId,
                        $stripNo++,
                        $strip,
                        $lengthM
                    );
                }
            }

            $mysqli->commit();
            bobbinJsonExit([
                'ok' => true,
                'message' => "Заявка отправлена. Бухт: {$quantity} (задание {$orderNumber}).",
                'order_number' => $orderNumber,
                'request_id' => $requestId,
                'quantity' => $quantity,
            ]);
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    }

    if ($method === 'POST' && $action === 'cancel') {
        $requestId = (int)($body['request_id'] ?? 0);
        if ($requestId < 1) {
            throw new InvalidArgumentException('Не указан request_id');
        }

        $check = $mysqli->prepare(
            "SELECT id, order_number, is_completed, is_cancelled
             FROM bobbin_cut_requests
             WHERE id = ? AND user_name = ? AND department = ?"
        );
        if (!$check) {
            throw new RuntimeException('Ошибка чтения заявки: ' . $mysqli->error);
        }
        $check->bind_param('iss', $requestId, $userName, $currentDepartment);
        $check->execute();
        $request = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$request) {
            throw new InvalidArgumentException('Заявка не найдена или нет прав');
        }
        if (!empty($request['is_completed'])) {
            throw new InvalidArgumentException('Нельзя отменить выполненную заявку');
        }
        if (!empty($request['is_cancelled'])) {
            throw new InvalidArgumentException('Заявка уже отменена');
        }

        $mysqli->begin_transaction();
        try {
            $upd = $mysqli->prepare(
                'UPDATE bobbin_cut_requests SET is_cancelled = 1, cancelled_at = NOW() WHERE id = ?'
            );
            $upd->bind_param('i', $requestId);
            $upd->execute();
            $upd->close();

            $orderNumber = (string)($request['order_number'] ?? '');
            if ($orderNumber !== '') {
                $remaining = [];
                foreach (bobbinWritableRollTables($mysqli) as $table) {
                    $delRoll = $mysqli->prepare(
                        "DELETE FROM `{$table}`
                         WHERE order_number = ? AND (done IS NULL OR done = 0)"
                    );
                    if ($delRoll) {
                        $delRoll->bind_param('s', $orderNumber);
                        $delRoll->execute();
                        $delRoll->close();
                    }

                    $left = $mysqli->prepare(
                        "SELECT bale_id FROM `{$table}` WHERE order_number = ?"
                    );
                    if ($left) {
                        $left->bind_param('s', $orderNumber);
                        $left->execute();
                        $resLeft = $left->get_result();
                        while ($row = $resLeft->fetch_assoc()) {
                            $remaining[(string)$row['bale_id']] = true;
                        }
                        $left->close();
                    }
                }
                $remaining = array_keys($remaining);

                if ($remaining === []) {
                    $delCut = $mysqli->prepare('DELETE FROM cut_plans WHERE order_number = ?');
                    if ($delCut) {
                        $delCut->bind_param('s', $orderNumber);
                        $delCut->execute();
                        $delCut->close();
                    }
                } else {
                    $placeholders = implode(',', array_fill(0, count($remaining), '?'));
                    $types = str_repeat('s', count($remaining) + 1);
                    $params = array_merge([$orderNumber], $remaining);
                    $sql = "DELETE FROM cut_plans WHERE order_number = ? AND bale_id NOT IN ({$placeholders})";
                    $delCut = $mysqli->prepare($sql);
                    if ($delCut) {
                        $delCut->bind_param($types, ...$params);
                        $delCut->execute();
                        $delCut->close();
                    }
                }
            }

            $mysqli->commit();
            bobbinJsonExit(['ok' => true, 'message' => 'Заявка отменена']);
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    }

    bobbinJsonExit(['ok' => false, 'error' => 'Неизвестное действие'], 400);
} catch (InvalidArgumentException $e) {
    bobbinJsonExit(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    bobbinJsonExit(['ok' => false, 'error' => $e->getMessage()], 500);
}
