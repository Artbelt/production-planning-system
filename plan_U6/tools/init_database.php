<?php
/**
 * Инициализация БД plan_u6 для участка U6.
 * Запуск: /plan_U6/tools/init_database.php (требуется доступ supervisor/director).
 */
require_once __DIR__ . '/../../auth/includes/config.php';
require_once __DIR__ . '/../../auth/includes/auth-functions.php';
require_once __DIR__ . '/../../auth/includes/db.php';

initAuthSystem();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    http_response_code(403);
    exit('Требуется авторизация');
}

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$allowed = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['supervisor', 'director', 'admin'], true)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('Недостаточно прав');
}

header('Content-Type: text/html; charset=utf-8');

try {
    $pdoRoot = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS plan_u6 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    $pdo = getPdo('plan_u6');
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
      order_number tinytext DEFAULT NULL,
      workshop tinytext DEFAULT NULL,
      `filter` tinytext DEFAULT NULL,
      `count` int(5) DEFAULT NULL,
      marking text DEFAULT NULL,
      personal_packaging text DEFAULT NULL,
      personal_label text DEFAULT NULL,
      group_packaging text DEFAULT NULL,
      packaging_rate int(5) DEFAULT NULL,
      group_label text DEFAULT NULL,
      remark text DEFAULT NULL,
      hide int(11) DEFAULT 0,
      cut_ready tinyint(1) DEFAULT 0,
      cut_confirmed tinyint(1) DEFAULT 0,
      plan_ready tinyint(1) DEFAULT 0,
      corr_ready tinyint(1) DEFAULT 0,
      build_ready tinyint(1) DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Таблицы по образцу plan_u4 / plan_u3 / plan_u5
    $tablesLike = [
        'filter_structure' => ['db' => 'plan_u4', 'table' => 'round_filter_structure'],
        'editor_access_time' => ['db' => 'plan_u4', 'table' => 'editor_access_time'],
        'manufactured_production' => ['db' => 'plan_u4', 'table' => 'manufactured_production'],
        'laser_requests' => ['db' => 'plan_u5', 'table' => 'laser_requests'],
        'laser_components' => ['db' => 'plan_u5', 'table' => 'laser_components'],
        'bobbin_cut_requests' => ['db' => 'plan_u3', 'table' => 'bobbin_cut_requests'],
        'roll_plans' => ['db' => 'plan_u3', 'table' => 'roll_plans'],
        'cut_plans' => ['db' => 'plan_u3', 'table' => 'cut_plans'],
    ];
    $created = [];
    foreach ($tablesLike as $u6Table => $src) {
        try {
            $pdoRoot->exec("CREATE TABLE IF NOT EXISTS plan_u6.`$u6Table` LIKE {$src['db']}.`{$src['table']}`");
            $created[] = $u6Table;
        } catch (Throwable $e) {
            // VIEW roll_plans на источнике — создадим пустую таблицу позже через bobbin API
            $created[] = $u6Table . ' (skip: ' . $e->getMessage() . ')';
        }
    }

    // Колонка team нужна для product_output.php (как в plan_u5)
    $hasTeam = (int)$pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = 'plan_u6'
          AND TABLE_NAME = 'manufactured_production'
          AND COLUMN_NAME = 'team'
    ")->fetchColumn();
    if ($hasTeam === 0) {
        $pdo->exec("ALTER TABLE manufactured_production ADD COLUMN team INT(11) DEFAULT NULL");
    }

    // Строка по умолчанию для editor_access_time (иначе is_edit_access_granted() падает)
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM editor_access_time")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO editor_access_time (access_time) VALUES ('2000-01-01 00:00:00')");
    }

    echo '<h1>БД plan_u6 готова</h1>';
    echo '<p>Таблица <code>orders</code> создана или уже существует.</p>';
    echo '<p>Таблицы по образцу U4: <code>' . implode('</code>, <code>', $created) . '</code>.</p>';
    echo '<p><a href="../main.php">Перейти к заявкам U6</a></p>';
    echo '<p><a href="../product_output.php">Внести выпущенную продукцию U6</a></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Ошибка: ' . htmlspecialchars($e->getMessage());
}
