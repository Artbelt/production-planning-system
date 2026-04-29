<?php
/**
 * JSON: расход РР (пластиковых) вставок за период по факту выпуска из manufactured_production.
 */
require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Нет доступа к цеху U3']);
    exit;
}

require_once __DIR__ . '/../auth/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$dateFrom = $_GET['date_from'] ?? $_POST['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? $_POST['date_to'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    echo json_encode(['ok' => false, 'error' => 'Укажите период: даты в формате ГГГГ-ММ-ДД']);
    exit;
}

if ($dateFrom > $dateTo) {
    echo json_encode(['ok' => false, 'error' => 'Дата начала позже даты окончания']);
    exit;
}

try {
    $pdo = getPdo('plan_u3');

    $sql = "
        SELECT TRIM(rfs.plastic_insertion) AS insertion_name,
               SUM(mp.count_of_filters) AS total_qty
        FROM manufactured_production mp
        INNER JOIN round_filter_structure rfs ON rfs.filter = mp.name_of_filter
        WHERE mp.date_of_production >= :df AND mp.date_of_production <= :dt
          AND rfs.plastic_insertion IS NOT NULL
          AND TRIM(rfs.plastic_insertion) <> ''
        GROUP BY TRIM(rfs.plastic_insertion)
        ORDER BY insertion_name
    ";
    $st = $pdo->prepare($sql);
    $st->execute(['df' => $dateFrom, 'dt' => $dateTo]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalInserts = 0;
    foreach ($rows as $r) {
        $totalInserts += (int) $r['total_qty'];
    }

    $stSkip = $pdo->prepare("
        SELECT COALESCE(SUM(mp.count_of_filters), 0) AS skipped_qty
        FROM manufactured_production mp
        LEFT JOIN round_filter_structure rfs ON rfs.filter = mp.name_of_filter
        WHERE mp.date_of_production >= ? AND mp.date_of_production <= ?
          AND (
            rfs.filter IS NULL
            OR rfs.plastic_insertion IS NULL
            OR TRIM(rfs.plastic_insertion) = ''
          )
    ");
    $stSkip->execute([$dateFrom, $dateTo]);
    $skippedRow = $stSkip->fetch(PDO::FETCH_ASSOC);
    $skippedQty = (int) ($skippedRow['skipped_qty'] ?? 0);

    echo json_encode([
        'ok' => true,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'rows' => array_map(static function ($r) {
            return [
                'insertion_name' => $r['insertion_name'],
                'total_qty' => (int) $r['total_qty'],
            ];
        }, $rows),
        'total_with_insert' => $totalInserts,
        'skipped_filters_qty' => $skippedQty,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ошибка запроса к базе данных']);
}
