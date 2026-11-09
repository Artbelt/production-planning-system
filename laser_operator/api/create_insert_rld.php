<?php
/**
 * Сборка RLD-вставки по шаблону RDWorks (вставка.rld):
 * синий контур — режим cut, чёрные линии — режим dot.
 */

function laser_insert_pack_f64($value) {
    $bytes = pack('d', (float)$value);
    if (pack('S', 1) !== "\x01\x00") {
        $bytes = strrev($bytes);
    }
    return $bytes;
}

function laser_insert_put_f64($buf, $offset, $value) {
    return substr($buf, 0, $offset) . laser_insert_pack_f64($value) . substr($buf, $offset + 8);
}

function laser_insert_build_rld($width, $height, array $lineYs) {
    $templatePath = dirname(__DIR__) . '/templates/insert_base.rld';
    $template = file_get_contents($templatePath);
    if ($template === false || strlen($template) < 139299) {
        throw new RuntimeException('Шаблон RLD не найден');
    }

    // Заголовок фигур начинается с 138507. Счётчик объектов — uint32 по смещению 138503.
    // Каждая следующая фигура дописывается сразу после предыдущей, без зазора.
    $prefix = substr($template, 0, 138507);
    $rectTpl = substr($template, 138507, 507);
    $lineTpl = substr($template, 139014, 285);

    $cx = 1800.0;
    $cy = 1000.0;
    $xmin = $cx - $width / 2;
    $xmax = $cx + $width / 2;
    $ymin = $cy - $height / 2;
    $ymax = $cy + $height / 2;

    $count = 1 + count($lineYs);
    $prefix = substr_replace($prefix, pack('V', $count), 138503, 4);

    $rect = $rectTpl;
    $rectPairs = array(
        19 => $xmin, 27 => $xmax, 35 => $ymin, 43 => $ymax,
        65 => $xmin, 73 => $ymax, 81 => $xmax, 89 => $ymax,
        139 => $xmax, 147 => $ymax, 155 => $xmax, 163 => $ymin,
        213 => $xmax, 221 => $ymin, 229 => $xmin, 237 => $ymin,
        287 => $xmin, 295 => $ymin, 303 => $xmin, 311 => $ymax,
    );
    foreach ($rectPairs as $offset => $value) {
        $rect = laser_insert_put_f64($rect, $offset, $value);
    }

    $parts = array($prefix, $rect);
    $lineNo = 2;
    foreach ($lineYs as $yFromBottom) {
        $y = $ymin + $yFromBottom;
        $x1 = $xmin + 1.0;
        $x2 = $xmax - 1.0;
        $line = $lineTpl;
        $line[10] = chr(ord('0') + $lineNo);
        $linePairs = array(
            19 => $x1, 27 => $x2, 35 => $y, 43 => $y,
            65 => $x1, 73 => $y, 81 => $x2, 89 => $y,
        );
        foreach ($linePairs as $offset => $value) {
            $line = laser_insert_put_f64($line, $offset, $value);
        }
        $parts[] = $line;
        $lineNo++;
    }

    return implode('', $parts);
}

if (PHP_SAPI === 'cli') {
    return;
}

define('AUTH_SYSTEM', true);
require_once '../../auth/includes/config.php';
require_once '../../auth/includes/auth-functions.php';

initAuthSystem();

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Нужна авторизация';
    exit;
}

require_once dirname(__DIR__) . '/daily_auth_load.php';
laser_operator_load_daily_auth_file(['json_errors' => true]);
laser_operator_require_same_calendar_day($auth, true);

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT r.name as role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$hasLaserOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'], true)) {
        $hasLaserOperatorAccess = true;
        break;
    }
}

if (!$hasLaserOperatorAccess) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Нет доступа';
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Некорректный запрос';
    exit;
}

$width = isset($payload['width']) ? (float)$payload['width'] : 0;
$height = isset($payload['height']) ? (float)$payload['height'] : 0;
$lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : array();

if ($width <= 2 || $width > 10000 || $height <= 0 || $height > 10000 || count($lines) < 1 || count($lines) > 4) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Проверьте размеры и число линий';
    exit;
}

$lineYs = array();
foreach ($lines as $lineY) {
    $y = (float)$lineY;
    if ($y <= 0 || $y >= $height) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Линия выходит за прямоугольник';
        exit;
    }
    $lineYs[] = $y;
}

try {
    $rld = laser_insert_build_rld($width, $height, $lineYs);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Не удалось собрать RLD';
    exit;
}

$nameW = str_replace('.', '_', rtrim(rtrim(sprintf('%.3f', $width), '0'), '.'));
$nameH = str_replace('.', '_', rtrim(rtrim(sprintf('%.3f', $height), '0'), '.'));
$filename = 'insert_' . $nameW . 'x' . $nameH . '_' . count($lineYs) . '.rld';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($rld));
echo $rld;
