<?php
/**
 * Заявка на порезку бумаги (бухты) с участка U4.
 * UI аналогичен laser_request.php; задания пишутся в roll_plan → оператор бобинорезки.
 */
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');
require_once('tools/tools.php');

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

$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Заявки всегда от участка U4
$currentDepartment = 'U4';
$ALLOWED_COMPONENT = 'Бухта 0101';

function canAccessPaperCutRequests($userDepartments) {
    foreach ($userDepartments as $dept) {
        if ($dept['department_code'] === 'U4') {
            $role = $dept['role_name'];
            return in_array($role, ['assembler', 'corr_operator', 'supervisor', 'director'], true);
        }
    }
    return false;
}

if (!canAccessPaperCutRequests($userDepartments)) {
    header('Location: main.php');
    exit;
}

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

$success_message = null;
$error_message = null;

function paperCutColumnExists(mysqli $mysqli, string $table, string $column): bool
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

function paperCutEnsureSchema(mysqli $mysqli): void
{
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS paper_cut_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_name VARCHAR(255) NOT NULL,
            department VARCHAR(10) NOT NULL,
            component_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            desired_delivery_time DATETIME NULL,
            order_number VARCHAR(50) NULL,
            is_completed BOOLEAN DEFAULT FALSE,
            completed_at TIMESTAMP NULL,
            is_cancelled BOOLEAN DEFAULT FALSE,
            cancelled_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_dept (user_name, department),
            KEY idx_order_number (order_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS roll_plan (
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

    if (!paperCutColumnExists($mysqli, 'roll_plan', 'plan_date')) {
        $mysqli->query("ALTER TABLE roll_plan ADD COLUMN plan_date DATE NULL");
    }
    if (!paperCutColumnExists($mysqli, 'roll_plan', 'work_date')) {
        $mysqli->query("ALTER TABLE roll_plan ADD COLUMN work_date DATE NULL");
    }
    if (!paperCutColumnExists($mysqli, 'roll_plan', 'done')) {
        $mysqli->query("ALTER TABLE roll_plan ADD COLUMN done TINYINT(1) NOT NULL DEFAULT 0");
    }

    // Совместимость с cut_operator (читает roll_plans)
    $hasRollPlans = $mysqli->query("SHOW TABLES LIKE 'roll_plans'");
    if ($hasRollPlans && $hasRollPlans->num_rows === 0) {
        @$mysqli->query("CREATE OR REPLACE VIEW roll_plans AS SELECT * FROM roll_plan");
    }

    $mysqli->query("
        CREATE TABLE IF NOT EXISTS cut_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) NOT NULL,
            bale_id VARCHAR(50) NOT NULL,
            filter VARCHAR(255) NULL,
            paper VARCHAR(255) NULL,
            width DECIMAL(12,2) NULL,
            height DECIMAL(12,2) NULL,
            length DECIMAL(12,2) NULL,
            format VARCHAR(50) NULL,
            KEY idx_order_bale (order_number, bale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    paperCutEnsureColumnAllowsValue($mysqli, 'cut_plans', 'material', 'plane');
    paperCutEnsureColumnAllowsValue($mysqli, 'cut_plans', 'source', 'manual');
}

/**
 * ENUM/короткий VARCHAR: гарантируем, что значение можно записать.
 */
function paperCutEnsureColumnAllowsValue(mysqli $mysqli, string $table, string $column, string $value): void
{
    if (!paperCutColumnExists($mysqli, $table, $column)) {
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
        $mysqli->query("ALTER TABLE `{$tableEsc}` MODIFY COLUMN `{$colEsc}` ENUM({$enumList}) {$nullSql}{$defaultSql}");
        return;
    }

    if (preg_match('/^(var)?char\((\d+)\)$/i', $typeLower, $m)) {
        $len = (int)$m[2];
        if ($len < strlen($value)) {
            $mysqli->query("ALTER TABLE `{$tableEsc}` MODIFY COLUMN `{$colEsc}` VARCHAR(64) {$nullSql}{$defaultSql}");
        }
    }
}

function paperCutSyncCompletedStatus(mysqli $mysqli, string $userName, string $department): void
{
    $sql = "SELECT id, order_number FROM paper_cut_requests
            WHERE user_name = ? AND department = ?
              AND (is_cancelled IS NULL OR is_cancelled = 0)
              AND (is_completed IS NULL OR is_completed = 0)
              AND order_number IS NOT NULL AND order_number != ''";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $userName, $department);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($pending as $req) {
        $orderNumber = $req['order_number'];
        $total = 0;
        $doneCnt = 0;
        foreach (paperCutWritableRollTables($mysqli) as $table) {
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
                "UPDATE paper_cut_requests
                 SET is_completed = 1, completed_at = NOW()
                 WHERE id = ?"
            );
            $upd->bind_param('i', $req['id']);
            $upd->execute();
            $upd->close();
        }
    }
}

function paperCutWritableRollTables(mysqli $mysqli): array
{
    $tables = [];
    foreach (['roll_plan', 'roll_plans'] as $t) {
        $chk = $mysqli->query("SHOW TABLES LIKE '{$t}'");
        if (!$chk || $chk->num_rows === 0) {
            continue;
        }
        $typeRes = $mysqli->query(
            "SELECT TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'"
        );
        $type = $typeRes ? (string)($typeRes->fetch_row()[0] ?? '') : '';
        // VIEW пишет в базовую roll_plan — отдельно не дублируем
        if (strcasecmp($type, 'VIEW') === 0) {
            continue;
        }
        $tables[] = $t;
    }
    if ($tables === []) {
        $tables[] = 'roll_plan';
    }
    return $tables;
}

function paperCutInsertIntoRollTable(
    mysqli $mysqli,
    string $table,
    string $orderNumber,
    string $baleId,
    string $workDate
): void {
    $hasPlanDate = paperCutColumnExists($mysqli, $table, 'plan_date');
    $hasWorkDate = paperCutColumnExists($mysqli, $table, 'work_date');

    if ($hasPlanDate && $hasWorkDate) {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, plan_date, work_date, done)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE plan_date = VALUES(plan_date), work_date = VALUES(work_date)"
        );
        $ins->bind_param('ssss', $orderNumber, $baleId, $workDate, $workDate);
    } elseif ($hasWorkDate) {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, work_date, done)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE work_date = VALUES(work_date)"
        );
        $ins->bind_param('sss', $orderNumber, $baleId, $workDate);
    } else {
        $ins = $mysqli->prepare(
            "INSERT INTO `{$table}` (order_number, bale_id, plan_date, done)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE plan_date = VALUES(plan_date)"
        );
        $ins->bind_param('sss', $orderNumber, $baleId, $workDate);
    }
    if (!$ins || !$ins->execute()) {
        $err = $ins ? $ins->error : $mysqli->error;
        throw new RuntimeException("Ошибка записи в {$table}: " . $err);
    }
    $ins->close();
}

function paperCutInsertRollAndCut(mysqli $mysqli, string $orderNumber, int $quantity, string $workDate, string $componentName): void
{
    $rollTables = paperCutWritableRollTables($mysqli);

    for ($i = 1; $i <= $quantity; $i++) {
        $baleId = (string)$i;

        foreach ($rollTables as $table) {
            paperCutInsertIntoRollTable($mysqli, $table, $orderNumber, $baleId, $workDate);
        }

        // Детали для cut_operator (фильтр = название комплектующего)
        $cutCols = ['order_number', 'bale_id'];
        $cutVals = [$orderNumber, $baleId];
        $cutTypes = 'ss';

        // На plan_u4 strip_no часто NOT NULL без default
        if (paperCutColumnExists($mysqli, 'cut_plans', 'strip_no')) {
            $cutCols[] = 'strip_no';
            $cutVals[] = 1;
            $cutTypes .= 'i';
        }
        if (paperCutColumnExists($mysqli, 'cut_plans', 'filter')) {
            $cutCols[] = 'filter';
            $cutVals[] = $componentName;
            $cutTypes .= 's';
        }
        if (paperCutColumnExists($mysqli, 'cut_plans', 'paper')) {
            $cutCols[] = 'paper';
            $cutVals[] = $componentName;
            $cutTypes .= 's';
        }
        // material — тип бумаги, не название комплектующего
        if (paperCutColumnExists($mysqli, 'cut_plans', 'material')) {
            $cutCols[] = 'material';
            $cutVals[] = 'plane';
            $cutTypes .= 's';
        }
        if (paperCutColumnExists($mysqli, 'cut_plans', 'source')) {
            $cutCols[] = 'source';
            $cutVals[] = 'manual';
            $cutTypes .= 's';
        }
        if (paperCutColumnExists($mysqli, 'cut_plans', 'manual')) {
            $cutCols[] = 'manual';
            $cutVals[] = 1;
            $cutTypes .= 'i';
        }
        // Формат бухты 0101 — 222 мм
        if (paperCutColumnExists($mysqli, 'cut_plans', 'format')) {
            $cutCols[] = 'format';
            $cutVals[] = 222;
            $cutTypes .= 'i';
        }
        foreach (['width', 'height', 'length'] as $numCol) {
            if (paperCutColumnExists($mysqli, 'cut_plans', $numCol)) {
                $cutCols[] = $numCol;
                $cutVals[] = 0;
                $cutTypes .= 'i';
            }
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
}

paperCutEnsureSchema($mysqli);
paperCutSyncCompletedStatus($mysqli, $user['full_name'] ?? '', $currentDepartment);

// Отправка заявки
if (isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    $component_name = trim($_POST['component_name'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);
    $desired_delivery_date = trim($_POST['desired_delivery_date'] ?? '');

    if ($component_name !== $ALLOWED_COMPONENT) {
        $error_message = 'Доступно только комплектующее «Бухта 0101».';
    } elseif ($quantity < 1 || $quantity > 100) {
        $error_message = 'Укажите количество бухт от 1 до 100.';
    } else {
        $datetime = null;
        $workDate = date('Y-m-d');
        if ($desired_delivery_date !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $desired_delivery_date);
            if ($dt && $dt->format('Y-m-d') === $desired_delivery_date) {
                $workDate = $desired_delivery_date;
                $datetime = $desired_delivery_date . ' 00:00:00';
            } else {
                $error_message = 'Некорректная дата поставки.';
            }
        }

        if (!$error_message) {
            $mysqli->begin_transaction();
            try {
                $userName = $user['full_name'] ?? 'Пользователь';
                $insReq = $mysqli->prepare(
                    "INSERT INTO paper_cut_requests
                        (user_name, department, component_name, quantity, desired_delivery_time)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $insReq->bind_param('sssis', $userName, $currentDepartment, $component_name, $quantity, $datetime);
                $insReq->execute();
                $requestId = (int)$mysqli->insert_id;
                $insReq->close();

                $orderNumber = 'U4-PC-' . $requestId;
                $updOrder = $mysqli->prepare(
                    "UPDATE paper_cut_requests SET order_number = ? WHERE id = ?"
                );
                $updOrder->bind_param('si', $orderNumber, $requestId);
                $updOrder->execute();
                $updOrder->close();

                paperCutInsertRollAndCut($mysqli, $orderNumber, $quantity, $workDate, $component_name);

                $mysqli->commit();
                $success_message = "Заявка отправлена. Создано заданий для бобинорезки: {$quantity} (заявка {$orderNumber}).";
            } catch (Throwable $e) {
                $mysqli->rollback();
                $error_message = 'Ошибка при создании заявки: ' . $e->getMessage();
            }
        }
    }
}

// Отмена заявки
if (isset($_POST['action']) && $_POST['action'] === 'cancel_request' && isset($_POST['request_id'])) {
    $request_id = (int)$_POST['request_id'];
    $userName = $user['full_name'] ?? '';

    $check_sql = "SELECT id, order_number, is_completed, is_cancelled
                  FROM paper_cut_requests
                  WHERE id = ? AND user_name = ? AND department = ?";
    $stmt = $mysqli->prepare($check_sql);
    $stmt->bind_param('iss', $request_id, $userName, $currentDepartment);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        $error_message = 'Заявка не найдена или у вас нет прав на её отмену!';
    } elseif (!empty($request['is_completed'])) {
        $error_message = 'Нельзя отменить выполненную заявку!';
    } elseif (!empty($request['is_cancelled'])) {
        $error_message = 'Заявка уже отменена!';
    } else {
        $mysqli->begin_transaction();
        try {
            $upd = $mysqli->prepare(
                "UPDATE paper_cut_requests
                 SET is_cancelled = 1, cancelled_at = NOW()
                 WHERE id = ?"
            );
            $upd->bind_param('i', $request_id);
            $upd->execute();
            $upd->close();

            $orderNumber = $request['order_number'] ?? '';
            if ($orderNumber !== '') {
                $remaining = [];
                foreach (paperCutWritableRollTables($mysqli) as $table) {
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

                if (empty($remaining)) {
                    $delCut = $mysqli->prepare("DELETE FROM cut_plans WHERE order_number = ?");
                    $delCut->bind_param('s', $orderNumber);
                    $delCut->execute();
                    $delCut->close();
                } else {
                    $placeholders = implode(',', array_fill(0, count($remaining), '?'));
                    $types = str_repeat('s', count($remaining) + 1);
                    $params = array_merge([$orderNumber], $remaining);
                    $sql = "DELETE FROM cut_plans WHERE order_number = ? AND bale_id NOT IN ({$placeholders})";
                    $delCut = $mysqli->prepare($sql);
                    $delCut->bind_param($types, ...$params);
                    $delCut->execute();
                    $delCut->close();
                }
            }

            $mysqli->commit();
            $success_message = 'Заявка успешно отменена!';
        } catch (Throwable $e) {
            $mysqli->rollback();
            $error_message = 'Ошибка при отмене: ' . $e->getMessage();
        }
    }
}

$userName = $user['full_name'] ?? '';
$user_requests_query = "SELECT * FROM paper_cut_requests
                        WHERE user_name = ? AND department = ?
                        ORDER BY created_at DESC LIMIT 10";
$stmt = $mysqli->prepare($user_requests_query);
$stmt->bind_param('ss', $userName, $currentDepartment);
$stmt->execute();
$user_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Заявка на порезку бумаги</title>
    <style>
        :root{
            --bg-solid: #f8fafc;
            --panel: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --accent-solid: #667eea;
            --accent-ink: #ffffff;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
        }
        body{
            margin: 0;
            background: var(--bg-solid);
            color: var(--ink);
            font: 16px/1.6 "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
        }
        .container{ max-width: 800px; margin: 0 auto; padding: 24px; }
        .panel{
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 20px;
        }
        .section-title{
            font-size: 24px;
            font-weight: 600;
            color: var(--ink);
            margin: 0 0 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }
        .form-group{ margin-bottom: 20px; }
        label{ display: block; margin-bottom: 8px; font-weight: 500; color: var(--ink); }
        input, select{
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: #fff;
            color: var(--ink);
            outline: none;
            transition: all 0.2s;
            font-size: 14px;
            box-sizing: border-box;
        }
        input:focus, select:focus{
            border-color: var(--accent-solid);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .btn{
            background: var(--accent-solid);
            color: var(--accent-ink);
            border: none;
            border-radius: var(--radius-sm);
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn:hover{ opacity: 0.9; transform: translateY(-1px); }
        .success-message{
            background: #dcfce7; border: 1px solid #bbf7d0; color: #166534;
            padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px;
        }
        .error-message{
            background: #fecaca; border: 1px solid #f87171; color: #991b1b;
            padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px;
        }
        .table-wrapper{ overflow-x: auto; margin-top: 20px; -webkit-overflow-scrolling: touch; }
        .requests-table{ width: 100%; min-width: 300px; border-collapse: collapse; }
        .requests-table th, .requests-table td{
            padding: 12px; text-align: left; border-bottom: 1px solid var(--border);
        }
        .requests-table th{ background: #f8fafc; font-weight: 600; }
        .status-completed{ color: #059669; font-weight: 500; }
        .status-pending{ color: #d97706; font-weight: 500; }
        .status-cancelled{ color: #dc2626; font-weight: 500; }
        .btn-cancel{
            background: #dc2626; color: white; border: none;
            padding: 6px 12px; border-radius: var(--radius-sm);
            font-size: 12px; font-weight: 500; cursor: pointer;
        }
        .btn-cancel:hover{ opacity: 0.9; }
        .hint{ color: var(--muted); font-size: 13px; margin-top: 6px; }
        @media (max-width: 768px) {
            .requests-table th, .requests-table td{ padding: 8px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="panel">
            <div class="section-title">Заявка на порезку бумаги</div>

            <?php if ($success_message): ?>
                <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="submit_request">

                <div class="form-group">
                    <label for="component_name">Комплектующие:</label>
                    <select id="component_name" name="component_name" required>
                        <option value="<?= htmlspecialchars($ALLOWED_COMPONENT) ?>" selected>
                            <?= htmlspecialchars($ALLOWED_COMPONENT) ?>
                        </option>
                    </select>
                    <div class="hint">Доступен только один тип: Бухта 0101</div>
                </div>

                <div class="form-group">
                    <label for="quantity">Количество бухт:</label>
                    <input type="number" id="quantity" name="quantity" required min="1" max="100"
                           placeholder="Введите количество бухт">
                </div>

                <div class="form-group">
                    <label for="desired_delivery_date">Дата порезки:</label>
                    <input type="date" id="desired_delivery_date" name="desired_delivery_date">
                    <div class="hint">Дата, на которую задание появится у оператора бобинорезки. Если не указать — сегодня.</div>
                </div>

                <button type="submit" class="btn">Отправить заявку</button>
            </form>
        </div>

        <div class="panel">
            <div class="section-title">Ваши последние заявки</div>
            <div class="table-wrapper">
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>Комплектующие</th>
                        <th>Кол-во</th>
                        <th>№ задания</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($user_requests) > 0): ?>
                        <?php foreach ($user_requests as $request): ?>
                            <tr>
                                <td><?= htmlspecialchars($request['component_name']) ?></td>
                                <td><?= (int)$request['quantity'] ?></td>
                                <td><?= htmlspecialchars($request['order_number'] ?? '—') ?></td>
                                <td>
                                    <?php
                                    $status_class = 'status-pending';
                                    $status_text = 'В работе';
                                    if (!empty($request['is_cancelled'])) {
                                        $status_class = 'status-cancelled';
                                        $status_text = 'Отменено';
                                    } elseif (!empty($request['is_completed'])) {
                                        $status_class = 'status-completed';
                                        $status_text = 'Выполнено';
                                    }
                                    ?>
                                    <span class="<?= $status_class ?>"><?= $status_text ?></span>
                                </td>
                                <td>
                                    <?php if (empty($request['is_completed']) && empty($request['is_cancelled'])): ?>
                                        <form method="POST" action="" style="display: inline-block;"
                                              onsubmit="return confirm('Отменить заявку и убрать невыполненные бухты из плана бобинорезки?');">
                                            <input type="hidden" name="action" value="cancel_request">
                                            <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                            <button type="submit" class="btn-cancel">Отменить</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: var(--muted); font-size: 12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--muted); padding: 20px;">
                                У вас пока нет заявок
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('desired_delivery_date');
            if (dateInput) {
                const today = new Date();
                dateInput.min = today.toISOString().split('T')[0];
                if (!dateInput.value) {
                    dateInput.value = dateInput.min;
                }
            }
        });
    </script>
</body>
</html>
<?php
$mysqli->close();
?>
