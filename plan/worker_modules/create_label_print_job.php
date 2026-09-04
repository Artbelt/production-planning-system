<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    require_once __DIR__ . '/label_print_lib.php';
    require_once __DIR__ . '/worker_auth_lib.php';

    $operatorUser = worker_auth_require_user();
    $pdo = getPdo('plan');
    label_print_ensure_table($pdo);

    $manufacturedId = isset($_POST['manufactured_id']) ? (int)$_POST['manufactured_id'] : 0;
    $orderNumber = trim((string)($_POST['order_number'] ?? ''));
    $filterLabel = trim((string)($_POST['filter_label'] ?? ''));
    $copies = isset($_POST['copies']) ? (int)$_POST['copies'] : 1;
    $batchCount = isset($_POST['batch_count']) ? (int)$_POST['batch_count'] : 0;
    $producedAt = '';

    if ($filterLabel === '' || $copies < 1) {
        echo json_encode(['success' => false, 'message' => 'Укажите фильтр и количество копий (≥ 1)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($copies > 500) {
        echo json_encode(['success' => false, 'message' => 'Слишком много копий (макс. 500)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($manufacturedId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, order_number, filter_label, count, date_of_production
            FROM manufactured_corrugated_packages
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$manufacturedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Запись изготовления не найдена'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $orderNumber = (string)$row['order_number'];
        $filterLabel = (string)$row['filter_label'];
        if ($batchCount <= 0) {
            $batchCount = (int)$row['count'];
        }
        if (!empty($row['date_of_production'])) {
            $producedAt = (string)$row['date_of_production'];
        }
    }

    $operatorName = trim((string)($operatorUser['full_name'] ?? ''));
    if ($operatorName === '') {
        $operatorName = label_print_current_operator();
    }

    $payload = label_print_build_payload($orderNumber, $filterLabel, $batchCount, $pdo, [
        'produced_at' => $producedAt,
        'operator' => $operatorName,
    ]);

    $ins = $pdo->prepare("
        INSERT INTO label_print_jobs
            (manufactured_id, order_number, filter_label, label_payload, copies, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $ins->execute([
        $manufacturedId > 0 ? $manufacturedId : null,
        $orderNumber,
        $filterLabel,
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        $copies,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Задание добавлено в очередь печати',
        'job_id' => (int)$pdo->lastInsertId(),
        'label' => $payload,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
