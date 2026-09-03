<?php
require_once __DIR__ . '/../../auth/includes/db.php';
require_once __DIR__ . '/label_print_lib.php';

$pdo = getPdo('plan');
label_print_ensure_table($pdo);

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if (!label_print_check_token(label_print_request_token())) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Неверный токен'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
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
    echo json_encode(['success' => true, 'jobs' => $jobs, 'count' => count($jobs)], JSON_UNESCAPED_UNICODE);
    exit;
}

$pendingStmt = $pdo->query("
    SELECT id, order_number, filter_label, label_payload, copies, status, created_at, error_message
    FROM label_print_jobs
    WHERE status IN ('pending', 'printing')
    ORDER BY FIELD(status, 'printing', 'pending'), created_at ASC, id ASC
    LIMIT 100
");
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$failedStmt = $pdo->query("
    SELECT id, order_number, filter_label, copies, status, created_at, error_message, printed_at
    FROM label_print_jobs
    WHERE status = 'failed'
    ORDER BY updated_at DESC
    LIMIT 20
");
$failed = $failedStmt->fetchAll(PDO::FETCH_ASSOC);

$doneStmt = $pdo->query("
    SELECT id, order_number, filter_label, copies, printed_at
    FROM label_print_jobs
    WHERE status = 'done'
    ORDER BY printed_at DESC, id DESC
    LIMIT 15
");
$done = $doneStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Очередь печати этикеток</title>
    <meta http-equiv="refresh" content="8">
    <style>
        :root {
            --primary: #2563eb;
            --accent: #dc2626;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-800: #1f2937;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: var(--gray-800);
            padding: 16px;
            line-height: 1.45;
        }
        .wrap { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 1.25rem; margin-bottom: 8px; text-align: center; }
        .meta { text-align: center; color: var(--gray-500); font-size: 13px; margin-bottom: 16px; }
        .nav { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-bottom: 16px; }
        .nav a {
            background: var(--primary); color: #fff; text-decoration: none;
            padding: 8px 14px; border-radius: 4px; font-size: 14px; font-weight: 500;
        }
        .card {
            background: #fff; border: 1px solid var(--gray-200); border-radius: 6px;
            padding: 12px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.05);
        }
        h2 { font-size: 1rem; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid var(--gray-200); padding: 6px 8px; text-align: center; }
        th { background: var(--gray-100); color: var(--gray-700); }
        td.left { text-align: left; }
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
        }
        .badge-pending { background: #dbeafe; color: #1d4ed8; }
        .badge-printing { background: #fef3c7; color: #b45309; }
        .empty { text-align: center; color: var(--gray-500); padding: 20px; font-size: 14px; }
        .btn-cancel {
            background: var(--accent); color: #fff; border: none; border-radius: 4px;
            padding: 4px 8px; cursor: pointer; font-size: 12px;
        }
        .count-pill {
            display: inline-block; min-width: 28px; padding: 2px 8px; border-radius: 4px;
            background: var(--primary); color: #fff; font-weight: 600; font-size: 13px;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Очередь печати этикеток</h1>
    <p class="meta">Обновление каждые 8 сек · в очереди: <strong><?= count($pending) ?></strong></p>
    <div class="nav">
        <a href="tasks_corrugation.php">Модуль ГМ</a>
        <a href="javascript:location.reload()">Обновить</a>
    </div>

    <div class="card">
        <h2>Ожидают печати</h2>
        <?php if (empty($pending)): ?>
            <div class="empty">Очередь пуста</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>Статус</th>
                    <th>Заявка</th>
                    <th>Фильтр</th>
                    <th>Копии</th>
                    <th>Создано</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row):
                    $payload = json_decode((string)$row['label_payload'], true);
                    $h = is_array($payload) ? ($payload['height'] ?? '') : '';
                    $w = is_array($payload) ? ($payload['filter_width'] ?? '') : '';
                    $extra = trim(($h !== '' ? 'h' . $h : '') . ($w !== '' ? ' / ' . $w . 'мм' : ''));
                    ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td>
                            <?php if ($row['status'] === 'printing'): ?>
                                <span class="badge badge-printing">печатается</span>
                            <?php else: ?>
                                <span class="badge badge-pending">ожидает</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($row['order_number'] ?: '—') ?></strong></td>
                        <td class="left">
                            <?= htmlspecialchars($row['filter_label']) ?>
                            <?php if ($extra !== ''): ?>
                                <div style="color:var(--gray-500);font-size:11px;"><?= htmlspecialchars($extra) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="count-pill"><?= (int)$row['copies'] ?></span></td>
                        <td><?= htmlspecialchars($row['created_at'] ? date('H:i:s', strtotime($row['created_at'])) : '—') ?></td>
                        <td>
                            <?php if (in_array($row['status'], ['pending', 'printing'], true)): ?>
                                <button type="button" class="btn-cancel" onclick="cancelJob(<?= (int)$row['id'] ?>)">Отменить</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (!empty($failed)): ?>
    <div class="card">
        <h2>Ошибки печати</h2>
        <table>
            <thead>
            <tr><th>#</th><th>Заявка</th><th>Фильтр</th><th>Копии</th><th>Ошибка</th></tr>
            </thead>
            <tbody>
            <?php foreach ($failed as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= htmlspecialchars($row['order_number'] ?: '—') ?></td>
                    <td class="left"><?= htmlspecialchars($row['filter_label']) ?></td>
                    <td><?= (int)$row['copies'] ?></td>
                    <td class="left" style="color:var(--accent);font-size:12px;"><?= htmlspecialchars($row['error_message'] ?: 'ошибка') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($done)): ?>
    <div class="card">
        <h2>Недавно напечатано</h2>
        <table>
            <thead>
            <tr><th>#</th><th>Заявка</th><th>Фильтр</th><th>Копии</th><th>Время</th></tr>
            </thead>
            <tbody>
            <?php foreach ($done as $row): ?>
                <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= htmlspecialchars($row['order_number'] ?: '—') ?></td>
                    <td class="left"><?= htmlspecialchars($row['filter_label']) ?></td>
                    <td><?= (int)$row['copies'] ?></td>
                    <td><?= htmlspecialchars($row['printed_at'] ? date('H:i:s', strtotime($row['printed_at'])) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
async function cancelJob(id) {
    if (!confirm('Убрать задание #' + id + ' из очереди?')) return;
    try {
        const body = new URLSearchParams({ job_id: String(id), status: 'cancelled' });
        const res = await fetch('complete_label_print_job.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        });
        const data = await res.json();
        if (data.success) location.reload();
        else alert(data.message || 'Ошибка');
    } catch (e) {
        alert('Ошибка сети');
    }
}
</script>
</body>
</html>
