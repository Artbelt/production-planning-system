<?php
/**
 * JSON: даты плана раскроя / гофрирования / сборки по заявке и фильтру (для active_positions).
 */

require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
if (!$auth->checkSession()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/../auth/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$order = isset($_POST['order_number']) ? trim((string) $_POST['order_number']) : '';
$filter = isset($_POST['filter']) ? trim((string) $_POST['filter']) : '';

if ($order === '' || $filter === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order и filter обязательны']);
    exit;
}

$pdo = getPdo('plan_u3');

/** Соответствие имени фильтра в планах (полное имя или «база» до « [»). */
function filterMatchSql(string $columnExpr): string {
    return "(
        TRIM($columnExpr) = TRIM(:f)
        OR TRIM(SUBSTRING_INDEX($columnExpr, ' [', 1)) = TRIM(:f2)
    )";
}

$cut = [];
$corr = [];
$build = [];

try {
    // Раскрой: дата смены в roll_plans по бухтам, где в cut_plans есть этот фильтр
    $sqlCut = "
        SELECT DISTINCT r.work_date AS d
        FROM roll_plans r
        INNER JOIN cut_plans c
            ON c.order_number = r.order_number AND c.bale_id = r.bale_id
        WHERE r.order_number = :ord
          AND " . filterMatchSql('c.filter') . "
        ORDER BY r.work_date
    ";
    $st = $pdo->prepare($sqlCut);
    $st->execute([':ord' => $order, ':f' => $filter, ':f2' => $filter]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['d'])) {
            $cut[] = ['date' => (string) $row['d']];
        }
    }
} catch (Throwable $e) {
    $cut = [];
}

try {
    $sqlCorr = "
        SELECT cp.day_date AS d, SUM(cp.qty) AS q
        FROM corrugation_plans cp
        WHERE cp.order_number = :ord
          AND " . filterMatchSql('cp.filter') . "
        GROUP BY cp.day_date
        ORDER BY cp.day_date
    ";
    $st = $pdo->prepare($sqlCorr);
    $st->execute([':ord' => $order, ':f' => $filter, ':f2' => $filter]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['d'])) {
            $corr[] = ['date' => (string) $row['d'], 'qty' => (int) ($row['q'] ?? 0)];
        }
    }
} catch (Throwable $e) {
    $corr = [];
}

try {
    $sqlBuild = "
        SELECT bp.day_date AS d, SUM(bp.qty) AS q
        FROM build_plans bp
        WHERE bp.order_number = :ord
          AND bp.shift = 'D'
          AND " . filterMatchSql('bp.filter') . "
        GROUP BY bp.day_date
        ORDER BY bp.day_date
    ";
    $st = $pdo->prepare($sqlBuild);
    $st->execute([':ord' => $order, ':f' => $filter, ':f2' => $filter]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['d'])) {
            $build[] = ['date' => (string) $row['d'], 'qty' => (int) ($row['q'] ?? 0)];
        }
    }
} catch (Throwable $e) {
    $build = [];
}

echo json_encode([
    'ok' => true,
    'cut' => $cut,
    'corrugation' => $corr,
    'build' => $build,
], JSON_UNESCAPED_UNICODE);
