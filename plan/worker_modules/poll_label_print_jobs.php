<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    require_once __DIR__ . '/label_print_lib.php';

    if (!label_print_check_token(label_print_request_token())) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Неверный токен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = getPdo('plan');
    label_print_ensure_table($pdo);

    $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;

    $stmt = $pdo->prepare("
        SELECT id, manufactured_id, order_number, filter_label, label_payload, copies, status, created_at
        FROM label_print_jobs
        WHERE status = 'pending'
        ORDER BY created_at ASC, id ASC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $jobs = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)$row['label_payload'], true);
        if (!is_array($payload)) {
            $payload = label_print_build_payload(
                (string)$row['order_number'],
                (string)$row['filter_label'],
                0,
                $pdo
            );
        }
        $jobs[] = [
            'id' => (int)$row['id'],
            'manufactured_id' => $row['manufactured_id'] !== null ? (int)$row['manufactured_id'] : null,
            'order_number' => (string)$row['order_number'],
            'filter_label' => (string)$row['filter_label'],
            'copies' => (int)$row['copies'],
            'status' => (string)$row['status'],
            'created_at' => (string)$row['created_at'],
            'label' => $payload,
            'pdf_url' => 'label_pdf.php?job_id=' . (int)$row['id'],
        ];
    }

    // Простой текст для Delphi 7 (без JSON)
    // id|copies|filter|analog|prefilter|glueing|order|box|date|operator
    $format = strtolower(trim((string)($_GET['format'] ?? 'json')));
    if ($format === 'plain') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "OK\n";
        foreach ($jobs as $j) {
            echo label_print_plain_job_line($j) . "\n";
        }
        exit;
    }

    echo json_encode([
        'success' => true,
        'jobs' => $jobs,
        'count' => count($jobs),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
