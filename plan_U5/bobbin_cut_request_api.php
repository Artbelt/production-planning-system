<?php
/**
 * API заявок на бобинорезку (обечайка) с участка U5.
 * JSON: list / submit / cancel → bobbin_cut_requests + roll_plans + cut_plans.
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

    // Константы формата обечайки
    if (!defined('BOBBIN_BALE_FORMAT_MM')) {
        define('BOBBIN_BALE_FORMAT_MM', 240.0);
        define('BOBBIN_STRIP_MIN', 10.0);
        define('BOBBIN_STRIP_MAX', 35.0);
        define('BOBBIN_STRIP_STEP', 2.5);
        define('BOBBIN_FILTER_LABEL', 'Обечайка');
        define('BOBBIN_REST_MIN', 20.0);
        define('BOBBIN_REST_MAX', 30.0);
    }
    if (!defined('BOBBIN_MATERIAL')) {
        define('BOBBIN_MATERIAL', 'н/т полотно 1,2 мм');
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

    $currentDepartment = 'U5';

    $canAccess = false;
    foreach ($userDepartments as $dept) {
        if (($dept['department_code'] ?? '') === 'U5'
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

function bobbinAllowedWidths(): array
{
    $widths = [];
    for ($w = BOBBIN_STRIP_MIN; $w <= BOBBIN_STRIP_MAX + 0.001; $w += BOBBIN_STRIP_STEP) {
        $widths[] = round($w, 1);
    }
    return $widths;
}

function bobbinIsAllowedWidth(float $width): bool
{
    foreach (bobbinAllowedWidths() as $allowed) {
        if (abs($allowed - $width) < 0.01) {
            return true;
        }
    }
    return false;
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

    // На U5 roll_plans часто VIEW на roll_plan — ALTER только для BASE TABLE
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
                KEY idx_order_bale (order_number, bale_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    if (bobbinIsBaseTable($mysqli, 'cut_plans') || bobbinTableType($mysqli, 'cut_plans') !== '') {
        bobbinEnsureColumnAllowsValue($mysqli, 'cut_plans', 'material', BOBBIN_MATERIAL);
        bobbinEnsureColumnAllowsValue($mysqli, 'cut_plans', 'source', 'manual');
    }
}

function bobbinWritableRollTables(mysqli $mysqli): array
{
    $tables = [];
    // Предпочитаем базовую таблицу; VIEW пропускаем (пишем в underlying roll_plan)
    foreach (['roll_plan', 'roll_plans'] as $t) {
        if (!bobbinIsBaseTable($mysqli, $t)) {
            continue;
        }
        $tables[] = $t;
    }
    // Если есть только обновляемый VIEW roll_plans — пишем в него
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
    float $width
): void {
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
        $cutVals[] = BOBBIN_FILTER_LABEL . ' ' . rtrim(rtrim(number_format($width, 1, '.', ''), '0'), '.');
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'paper')) {
        $cutCols[] = 'paper';
        $cutVals[] = BOBBIN_FILTER_LABEL;
        $cutTypes .= 's';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'material')) {
        $cutCols[] = 'material';
        $cutVals[] = BOBBIN_MATERIAL;
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
        $cutVals[] = (int)BOBBIN_BALE_FORMAT_MM;
        $cutTypes .= 'i';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'width')) {
        $cutCols[] = 'width';
        $cutVals[] = $width;
        $cutTypes .= 'd';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'height')) {
        $cutCols[] = 'height';
        $cutVals[] = 0.0;
        $cutTypes .= 'd';
    }
    if (bobbinColumnExists($mysqli, 'cut_plans', 'length')) {
        $cutCols[] = 'length';
        $cutVals[] = 0.0;
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

function bobbinNormalizeBales(array $rawBales): array
{
    if ($rawBales === []) {
        throw new InvalidArgumentException('Добавьте хотя бы одну бухту');
    }
    if (count($rawBales) > 100) {
        throw new InvalidArgumentException('Слишком много бухт (макс. 100)');
    }

    $normalized = [];
    foreach ($rawBales as $baleIdx => $bale) {
        $stripsIn = $bale['strips'] ?? null;
        if (!is_array($stripsIn) || $stripsIn === []) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': нет полос');
        }

        $expanded = [];
        $sum = 0.0;
        foreach ($stripsIn as $s) {
            $width = isset($s['width']) ? (float)$s['width'] : 0.0;
            $qty = isset($s['qty']) ? (int)$s['qty'] : 1;
            if ($qty < 1) {
                $qty = 1;
            }
            if ($qty > 50) {
                throw new InvalidArgumentException('Слишком много полос одной ширины');
            }
            if (!bobbinIsAllowedWidth($width)) {
                throw new InvalidArgumentException(
                    'Недопустимая ширина ' . $width . ' мм (допустимо 10–35 с шагом 2.5)'
                );
            }
            for ($i = 0; $i < $qty; $i++) {
                $expanded[] = round($width, 1);
                $sum += $width;
            }
        }

        if ($sum > BOBBIN_BALE_FORMAT_MM + 0.01) {
            throw new InvalidArgumentException(
                'Бухта #' . ($baleIdx + 1) . ': сумма ширин ' . round($sum, 1)
                . ' мм превышает формат ' . (int)BOBBIN_BALE_FORMAT_MM . ' мм'
            );
        }
        $rest = round(BOBBIN_BALE_FORMAT_MM - $sum, 1);
        if ($rest < BOBBIN_REST_MIN - 0.01 || $rest > BOBBIN_REST_MAX + 0.01) {
            throw new InvalidArgumentException(
                'Бухта #' . ($baleIdx + 1) . ': остаток ' . $rest
                . ' мм вне допуска ' . (int)BOBBIN_REST_MIN . '–' . (int)BOBBIN_REST_MAX . ' мм'
            );
        }
        if ($expanded === []) {
            throw new InvalidArgumentException('Бухта #' . ($baleIdx + 1) . ': пустая');
        }

        $normalized[] = ['widths' => $expanded, 'sum' => round($sum, 1)];
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
            'widths' => bobbinAllowedWidths(),
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

        $bales = bobbinNormalizeBales(is_array($body['bales'] ?? null) ? $body['bales'] : []);
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

            $orderNumber = 'U5-BC-' . $requestId;
            $updOrder = $mysqli->prepare(
                'UPDATE bobbin_cut_requests SET order_number = ? WHERE id = ?'
            );
            $updOrder->bind_param('si', $orderNumber, $requestId);
            $updOrder->execute();
            $updOrder->close();

            $rollTables = bobbinWritableRollTables($mysqli);
            foreach ($bales as $i => $bale) {
                $baleId = (string)($i + 1);
                foreach ($rollTables as $table) {
                    bobbinInsertIntoRollTable($mysqli, $table, $orderNumber, $baleId, $workDate);
                }
                $stripNo = 1;
                foreach ($bale['widths'] as $width) {
                    bobbinInsertStrip($mysqli, $orderNumber, $baleId, $stripNo++, (float)$width);
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
