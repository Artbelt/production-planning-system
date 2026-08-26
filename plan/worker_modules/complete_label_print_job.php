<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    require_once __DIR__ . '/label_print_lib.php';

    $pdo = getPdo('plan');
    label_print_ensure_table($pdo);

    // POST или GET (GET удобен для Delphi WinInet)
    $jobId = isset($_REQUEST['job_id']) ? (int)$_REQUEST['job_id'] : 0;
    $status = trim((string)($_REQUEST['status'] ?? ''));
    $errorMessage = trim((string)($_REQUEST['error_message'] ?? ''));

    $allowed = ['printing', 'done', 'failed', 'cancelled'];
    if ($jobId < 1 || !in_array($status, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Некорректные параметры'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Агент (printing/done/failed) — с токеном; отмена с UI — без токена
    if ($status !== 'cancelled' && !label_print_check_token(label_print_request_token())) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Неверный токен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $printedAtSql = ($status === 'done') ? ', printed_at = NOW()' : '';
    $stmt = $pdo->prepare("
        UPDATE label_print_jobs
        SET status = ?, error_message = ?{$printedAtSql}
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([
        $status,
        $errorMessage !== '' ? $errorMessage : null,
        $jobId,
    ]);

    if ($stmt->rowCount() < 1) {
        echo json_encode(['success' => false, 'message' => 'Задание не найдено'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Статус обновлён'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
