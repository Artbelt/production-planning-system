<?php
/**
 * API: список гофропакетов из paper_package_salon с опциональной фильтрацией по размерам.
 * GET-параметры: width, height, pleats_count (пустые = не фильтровать).
 */
require_once __DIR__ . '/settings.php';

header('Content-Type: application/json; charset=utf-8');

$width      = isset($_GET['width']) && $_GET['width'] !== '' ? trim($_GET['width']) : null;
$height     = isset($_GET['height']) && $_GET['height'] !== '' ? trim($_GET['height']) : null;
$pleats_count = isset($_GET['pleats_count']) && $_GET['pleats_count'] !== '' ? trim($_GET['pleats_count']) : null;

try {
    $pdo = new PDO(
        "mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4",
        $mysql_user,
        $mysql_user_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $sql = "SELECT p_p_name, p_p_width, p_p_height, p_p_pleats_count";
    $params = [];

    // Проверяем наличие колонок p_p_material, p_p_supplier (могут отсутствовать в старых БД)
    $cols = $pdo->query("SHOW COLUMNS FROM paper_package_salon")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('p_p_material', $cols)) {
        $sql .= ", p_p_material";
    }
    if (in_array('p_p_supplier', $cols)) {
        $sql .= ", p_p_supplier";
    }
    if (in_array('p_p_remark', $cols)) {
        $sql .= ", p_p_remark";
    }

    $sql .= " FROM paper_package_salon WHERE 1=1";

    if ($width !== null && is_numeric(str_replace(',', '.', $width))) {
        $sql .= " AND ROUND(COALESCE(p_p_width, 0), 2) = ROUND(?, 2)";
        $params[] = str_replace(',', '.', $width);
    }
    if ($height !== null && is_numeric(str_replace(',', '.', $height))) {
        $sql .= " AND ROUND(COALESCE(p_p_height, 0), 2) = ROUND(?, 2)";
        $params[] = str_replace(',', '.', $height);
    }
    if ($pleats_count !== null && (is_numeric($pleats_count) || $pleats_count === '0')) {
        $sql .= " AND COALESCE(p_p_pleats_count, 0) = ?";
        $params[] = (int) $pleats_count;
    }

    $sql .= " ORDER BY p_p_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $list = [];
    foreach ($rows as $r) {
        $item = [
            'name'    => $r['p_p_name'] ?? '',
            'width'   => $r['p_p_width'] !== null && $r['p_p_width'] !== '' ? $r['p_p_width'] : '—',
            'height'  => $r['p_p_height'] !== null && $r['p_p_height'] !== '' ? $r['p_p_height'] : '—',
            'pleats'  => $r['p_p_pleats_count'] !== null && $r['p_p_pleats_count'] !== '' ? (int)$r['p_p_pleats_count'] : '—',
        ];
        if (isset($r['p_p_material'])) {
            $item['material'] = $r['p_p_material'] ?? '—';
        }
        if (isset($r['p_p_supplier'])) {
            $item['supplier'] = $r['p_p_supplier'] ?? '—';
        }
        if (isset($r['p_p_remark'])) {
            $item['remark'] = $r['p_p_remark'] ?? '—';
        }
        $list[] = $item;
    }

    echo json_encode(['ok' => true, 'items' => $list], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
