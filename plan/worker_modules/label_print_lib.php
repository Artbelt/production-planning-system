<?php

function label_print_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS label_print_jobs (
        id INT(11) NOT NULL AUTO_INCREMENT,
        manufactured_id INT(11) DEFAULT NULL,
        order_number VARCHAR(50) NOT NULL DEFAULT '',
        filter_label TEXT NOT NULL,
        label_payload TEXT NOT NULL,
        copies INT(11) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        printed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        INDEX idx_status_created (status, created_at),
        INDEX idx_manufactured (manufactured_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Разбор filter_label вида "AF 1601 [h48] 183" / "1601 [48] 199".
 * @return array{name:string,height:string,width:string}
 */
function label_print_parse_filter_label(string $filterLabel): array
{
    $name = trim($filterLabel);
    $height = '';
    $width = '';

    if (preg_match('/^(.*?)\s*\[h?(\d+)\]\s*(.*)$/u', $filterLabel, $m)) {
        $name = trim($m[1]);
        $height = $m[2];
        $rest = trim($m[3]);
        if (preg_match('/^(\d+)/', $rest, $wm)) {
            $width = $wm[1];
        }
    }

    return [
        'name' => $name !== '' ? $name : $filterLabel,
        'height' => $height,
        'width' => $width,
    ];
}

/** Из comment вида "... ANALOG_FILTER=Mann_CU_1829 ..." */
function label_print_parse_analog_from_comment(?string $comment): string
{
    if ($comment === null || $comment === '') {
        return '';
    }
    if (preg_match('/ANALOG_FILTER=([^\s]+)/i', $comment, $m)) {
        return str_replace('_', ' ', trim($m[1]));
    }
    return '';
}

/**
 * Ищем строку справочника по filter_label / имени фильтра.
 * @return array<string,mixed>|null
 */
function label_print_lookup_panel_structure(PDO $pdo, string $filterLabel, string $filterName): ?array
{
    $candidates = [];
    foreach ([$filterLabel, $filterName] as $c) {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $candidates, true)) {
            $candidates[] = $c;
        }
    }
    if ($candidates === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $stmt = $pdo->prepare("
        SELECT filter, box, comment, prefilter, glueing
        FROM panel_filter_structure
        WHERE filter IN ($placeholders)
        LIMIT 1
    ");
    $stmt->execute($candidates);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    // Иногда имя в плане чуть отличается — пробуем точное совпадение по TRIM
    $stmt2 = $pdo->prepare("
        SELECT filter, box, comment, prefilter, glueing
        FROM panel_filter_structure
        WHERE TRIM(filter) = ?
        LIMIT 1
    ");
    $stmt2->execute([$filterName]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    return $row2 ?: null;
}

function label_print_current_operator(): string
{
    try {
        if (!defined('AUTH_SYSTEM')) {
            define('AUTH_SYSTEM', true);
        }
        require_once __DIR__ . '/../../auth/includes/config.php';
        require_once __DIR__ . '/../../auth/includes/auth-functions.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $auth = new AuthManager();
        $session = $auth->checkSession();
        if (is_array($session) && !empty($session['full_name'])) {
            return trim((string)$session['full_name']);
        }
    } catch (Throwable $e) {
        // без авторизации — пусто
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    foreach (['full_name', 'user_name', 'auth_user_name'] as $key) {
        if (!empty($_SESSION[$key])) {
            return trim((string)$_SESSION[$key]);
        }
    }
    return '';
}

/**
 * Полный payload этикетки.
 *
 * @param array{batch_count?:int,produced_at?:string,operator?:string} $extra
 */
function label_print_build_payload(
    string $orderNumber,
    string $filterLabel,
    int $batchCount = 0,
    ?PDO $pdo = null,
    array $extra = []
): array {
    $parsed = label_print_parse_filter_label($filterLabel);
    $filterName = $parsed['name'];

    $analog = '';
    $box = '';
    $hasPrefilter = false;
    $hasGlueing = false;

    if ($pdo instanceof PDO) {
        $struct = label_print_lookup_panel_structure($pdo, $filterLabel, $filterName);
        if ($struct) {
            $box = trim((string)($struct['box'] ?? ''));
            $analog = label_print_parse_analog_from_comment(
                isset($struct['comment']) ? (string)$struct['comment'] : null
            );
            $hasPrefilter = trim((string)($struct['prefilter'] ?? '')) !== '';
            $hasGlueing = trim((string)($struct['glueing'] ?? '')) !== '';
        }
    }

    $producedAt = trim((string)($extra['produced_at'] ?? ''));
    if ($producedAt === '') {
        $producedAt = date('Y-m-d');
    }
    $operator = trim((string)($extra['operator'] ?? ''));
    if ($operator === '') {
        $operator = label_print_current_operator();
    }
    if ($batchCount <= 0 && isset($extra['batch_count'])) {
        $batchCount = (int)$extra['batch_count'];
    }

    return [
        'filter_name' => $filterName,
        'analog_name' => $analog,
        'has_prefilter' => $hasPrefilter,
        'has_glueing' => $hasGlueing,
        'order_number' => $orderNumber,
        'box_number' => $box,
        'produced_at' => $producedAt,
        'operator' => $operator,
        // служебные / совместимость
        'filter_width' => $parsed['width'],
        'height' => $parsed['height'],
        'filter_label' => $filterLabel,
        'batch_count' => $batchCount,
    ];
}

function label_print_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/label_print_config.php';
    }
    return $cfg;
}

function label_print_check_token(?string $token): bool
{
    $cfg = label_print_config();
    $expected = (string)($cfg['token'] ?? '');
    if ($expected === '') {
        return false;
    }
    return hash_equals($expected, (string)$token);
}

function label_print_request_token(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($headers['X-Label-Print-Token'])) {
        return (string)$headers['X-Label-Print-Token'];
    }
    if (isset($headers['x-label-print-token'])) {
        return (string)$headers['x-label-print-token'];
    }
    return (string)($_GET['token'] ?? $_POST['token'] ?? '');
}

/** Поле для plain-формата Delphi: убираем | и переводы строк */
function label_print_plain_field(string $value): string
{
    $value = str_replace(["\r", "\n", "|"], ['', ' ', ' '], $value);
    return trim($value);
}

/**
 * Строка для Print.exe:
 * id|copies|filter|analog|prefilter|glueing|order|box|date|operator|qty
 */
function label_print_plain_job_line(array $job): string
{
    $L = is_array($job['label'] ?? null) ? $job['label'] : [];
    $qty = (int)($L['batch_count'] ?? 0);
    return implode('|', [
        (int)$job['id'],
        (int)$job['copies'],
        label_print_plain_field((string)($L['filter_name'] ?? '')),
        label_print_plain_field((string)($L['analog_name'] ?? '')),
        !empty($L['has_prefilter']) ? '1' : '0',
        !empty($L['has_glueing']) ? '1' : '0',
        label_print_plain_field((string)($L['order_number'] ?? ($job['order_number'] ?? ''))),
        label_print_plain_field((string)($L['box_number'] ?? '')),
        label_print_plain_field((string)($L['produced_at'] ?? '')),
        label_print_plain_field((string)($L['operator'] ?? '')),
        (string)$qty,
    ]);
}
