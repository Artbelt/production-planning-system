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
            $payload = label_print_build_payload((string)$row['order_number'], (string)$row['filter_label']);
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
    <style>
        :root {
            --primary: #2563eb;
            --accent: #dc2626;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-800: #1f2937;
            /* 1 мм → 5 px на экране (этикетка 65×38 ≈ 325×190) */
            --mm: 5px;
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
        .badge-failed { background: #fee2e2; color: #b91c1c; }
        .empty { text-align: center; color: var(--gray-500); padding: 20px; font-size: 14px; }
        .btn-cancel {
            background: var(--accent); color: #fff; border: none; border-radius: 4px;
            padding: 4px 8px; cursor: pointer; font-size: 12px;
        }
        .count-pill {
            display: inline-block; min-width: 28px; padding: 2px 8px; border-radius: 4px;
            background: var(--primary); color: #fff; font-weight: 600; font-size: 13px;
        }

        /* —— макет этикетки 65×38 мм —— */
        .tpl-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        @media (min-width: 780px) {
            .tpl-layout { grid-template-columns: 1fr 300px; align-items: start; }
        }
        .label-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px;
            background: #e8eef5;
            border-radius: 6px;
            border: 1px dashed #94a3b8;
        }
        .label-sheet {
            position: relative;
            width: calc(65 * var(--mm));
            height: calc(38 * var(--mm));
            background: #fff;
            border: 1px solid #334155;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            padding: calc(1.5 * var(--mm)) calc(2 * var(--mm));
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .label-sheet.show-grid {
            background-image:
                linear-gradient(to right, rgba(37,99,238,0.10) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(37,99,238,0.10) 1px, transparent 1px);
            background-size: var(--mm) var(--mm);
        }
        .lbl-hand-box {
            position: absolute;
            top: calc(1.5 * var(--mm));
            right: calc(1.5 * var(--mm));
            width: calc(15 * var(--mm));
            height: calc(15 * var(--mm));
            border: calc(0.45 * var(--mm)) solid #111;
            box-sizing: border-box;
            background: transparent;
            pointer-events: none;
        }
        .lbl-top {
            min-height: 0;
            padding-right: calc(17 * var(--mm)); /* место под квадрат 15 мм + отступ */
        }
        .lbl-name {
            font-size: calc(8.4 * var(--mm));
            font-weight: 700;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lbl-analog {
            margin-top: calc(0.4 * var(--mm));
            font-size: calc(5.6 * var(--mm));
            line-height: 1.1;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .lbl-analog:empty,
        .lbl-analog.is-empty { display: none; }
        .lbl-flags {
            margin-top: calc(0.6 * var(--mm));
            display: flex;
            flex-wrap: wrap;
            gap: calc(0.8 * var(--mm));
            font-size: calc(4.8 * var(--mm));
            line-height: 1;
        }
        .lbl-flag {
            border: 1px solid #111;
            padding: calc(0.4 * var(--mm)) calc(0.8 * var(--mm));
            border-radius: calc(0.4 * var(--mm));
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .lbl-flag.off { display: none; }
        .lbl-bottom {
            display: grid;
            grid-template-columns: 1.1fr 1.1fr 0.8fr;
            gap: calc(0.4 * var(--mm)) calc(1 * var(--mm));
            font-size: calc(5 * var(--mm));
            line-height: 1.15;
            border-top: 1px solid #cbd5e1;
            padding-top: calc(0.8 * var(--mm));
        }
        .lbl-bottom .cell-qty .v {
            font-size: calc(6.5 * var(--mm));
            font-weight: 800;
        }
        .lbl-bottom .span2 {
            grid-column: span 2;
        }
        .lbl-bottom .k {
            display: block;
            font-size: calc(3.6 * var(--mm));
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .lbl-bottom .v {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .label-caption {
            font-size: 12px;
            color: var(--gray-500);
            text-align: center;
        }
        .tpl-form label {
            display: block;
            font-size: 12px;
            color: var(--gray-700);
            margin-bottom: 4px;
            margin-top: 8px;
        }
        .tpl-form label:first-child { margin-top: 0; }
        .tpl-form input[type="text"],
        .tpl-form input[type="number"],
        .tpl-form input[type="date"] {
            width: 100%;
            padding: 7px 9px;
            border: 1px solid var(--gray-200);
            border-radius: 4px;
            font-size: 14px;
        }
        .tpl-form .row-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 13px;
        }
        .tpl-hint {
            margin-top: 12px;
            font-size: 12px;
            color: var(--gray-500);
            line-height: 1.4;
        }
        .scale-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 13px;
        }
        .scale-row input { width: 64px; }
        .src-tag {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 6px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #475569;
            font-size: 10px;
            font-weight: 600;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php
$samplePayload = [
    'filter_name' => 'AF 1601',
    'analog_name' => 'Mann CU 1829',
    'has_prefilter' => true,
    'has_glueing' => false,
    'order_number' => '2548',
    'box_number' => 'B12',
    'produced_at' => date('Y-m-d'),
    'operator' => label_print_current_operator() ?: 'Иванов',
    'batch_count' => 25,
];
if (!empty($pending)) {
    $p0 = json_decode((string)$pending[0]['label_payload'], true);
    if (is_array($p0)) {
        foreach (['filter_name', 'analog_name', 'order_number', 'box_number', 'produced_at', 'operator', 'batch_count'] as $k) {
            if (isset($p0[$k]) && (string)$p0[$k] !== '') {
                $samplePayload[$k] = $p0[$k];
            }
        }
        if (array_key_exists('has_prefilter', $p0)) {
            $samplePayload['has_prefilter'] = (bool)$p0['has_prefilter'];
        }
        if (array_key_exists('has_glueing', $p0)) {
            $samplePayload['has_glueing'] = (bool)$p0['has_glueing'];
        }
    } else {
        $samplePayload = array_merge(
            $samplePayload,
            label_print_build_payload(
                (string)$pending[0]['order_number'],
                (string)$pending[0]['filter_label'],
                0,
                $pdo,
                ['operator' => label_print_current_operator()]
            )
        );
    }
}
$sampleQty = (int)($samplePayload['batch_count'] ?? 0);
?>
<div class="wrap">
    <h1>Очередь печати этикеток</h1>
    <p class="meta">В очереди: <strong><?= count($pending) ?></strong> · автообновление отключено на время настройки макета</p>
    <div class="nav">
        <a href="tasks_corrugation.php">Модуль ГМ</a>
        <a href="javascript:location.reload()">Обновить</a>
    </div>

    <div class="card">
        <h2>Макет этикетки 65 × 38 мм</h2>
        <p class="tpl-hint" style="margin-top:0;margin-bottom:12px;">
            Черновик состава полей. Меняйте значения справа — превью обновляется сразу.
        </p>
        <div class="tpl-layout">
            <div class="label-stage">
                <div id="labelSheet" class="label-sheet show-grid" aria-label="Превью этикетки 65 на 38 мм">
                    <div class="lbl-hand-box" title="Количество вручную"></div>
                    <div class="lbl-top">
                        <div class="lbl-name" id="pvName"><?= htmlspecialchars($samplePayload['filter_name']) ?></div>
                        <div class="lbl-analog<?= $samplePayload['analog_name'] === '' ? ' is-empty' : '' ?>" id="pvAnalog"><?= htmlspecialchars($samplePayload['analog_name']) ?></div>
                        <div class="lbl-flags" id="pvFlags">
                            <span class="lbl-flag<?= $samplePayload['has_prefilter'] ? '' : ' off' ?>" id="pvPrefilter">Предфильтр</span>
                            <span class="lbl-flag<?= $samplePayload['has_glueing'] ? '' : ' off' ?>" id="pvGlueing">Проливка</span>
                        </div>
                    </div>
                    <div class="lbl-bottom">
                        <div>
                            <span class="k">Заявка</span>
                            <span class="v" id="pvOrder"><?= htmlspecialchars($samplePayload['order_number']) ?></span>
                        </div>
                        <div>
                            <span class="k">Коробка</span>
                            <span class="v" id="pvBox"><?= htmlspecialchars($samplePayload['box_number']) ?></span>
                        </div>
                        <div class="cell-qty">
                            <span class="k">Кол-во</span>
                            <span class="v" id="pvQty"><?= $sampleQty > 0 ? (int)$sampleQty : '—' ?></span>
                        </div>
                        <div class="span2">
                            <span class="k">Дата</span>
                            <span class="v" id="pvDate"><?= htmlspecialchars(date('d.m.Y', strtotime($samplePayload['produced_at']))) ?></span>
                        </div>
                        <div>
                            <span class="k">Оператор</span>
                            <span class="v" id="pvOperator"><?= htmlspecialchars($samplePayload['operator']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="label-caption">масштаб: <span id="scaleLabel">5</span> px / мм · реальный размер 65×38&nbsp;мм</div>
            </div>
            <div class="tpl-form">
                <label for="inpName">Наименование фильтра <span class="src-tag">выпуск</span></label>
                <input type="text" id="inpName" value="<?= htmlspecialchars($samplePayload['filter_name']) ?>">

                <label for="inpAnalog">Наименование аналога <span class="src-tag">comment ANALOG_FILTER=</span></label>
                <input type="text" id="inpAnalog" value="<?= htmlspecialchars($samplePayload['analog_name']) ?>" placeholder="пусто = не печатать">

                <label class="row-check"><input type="checkbox" id="inpPrefilter" <?= $samplePayload['has_prefilter'] ? 'checked' : '' ?>> Предфильтр <span class="src-tag">panel_filter_structure</span></label>
                <label class="row-check"><input type="checkbox" id="inpGlueing" <?= $samplePayload['has_glueing'] ? 'checked' : '' ?>> Проливка <span class="src-tag">panel_filter_structure</span></label>

                <label for="inpOrder">Номер заявки <span class="src-tag">выпуск</span></label>
                <input type="text" id="inpOrder" value="<?= htmlspecialchars($samplePayload['order_number']) ?>">

                <label for="inpBox">Номер коробки <span class="src-tag">panel_filter_structure.box</span></label>
                <input type="text" id="inpBox" value="<?= htmlspecialchars($samplePayload['box_number']) ?>">

                <label for="inpQty">Количество <span class="src-tag">выпуск count</span></label>
                <input type="number" id="inpQty" min="0" value="<?= (int)$sampleQty ?>">

                <label for="inpDate">Дата изготовления <span class="src-tag">дата выпуска</span></label>
                <input type="date" id="inpDate" value="<?= htmlspecialchars($samplePayload['produced_at']) ?>">

                <label for="inpOperator">Оператор <span class="src-tag">авторизация full_name</span></label>
                <input type="text" id="inpOperator" value="<?= htmlspecialchars($samplePayload['operator']) ?>">

                <div class="scale-row">
                    <label for="inpScale" style="margin:0;">Масштаб</label>
                    <input type="number" id="inpScale" min="2" max="10" step="1" value="5">
                    <span>px/мм</span>
                </div>

                <label class="row-check">
                    <input type="checkbox" id="inpGrid" checked>
                    Сетка 1 мм
                </label>

                <p class="tpl-hint">
                    Справа вверху — пустой квадрат 15×15&nbsp;мм для количества от руки.
                    Внизу: заявка / коробка / кол-во (из выпуска), затем дата / оператор.
                </p>
            </div>
        </div>
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
                            <?php if ($row['status'] === 'pending'): ?>
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
function formatDateRu(iso) {
    if (!iso) return '—';
    const p = iso.split('-');
    if (p.length !== 3) return iso;
    return p[2] + '.' + p[1] + '.' + p[0];
}
function syncPreview() {
    document.getElementById('pvName').textContent = document.getElementById('inpName').value || '—';
    const analog = (document.getElementById('inpAnalog').value || '').trim();
    const pvAnalog = document.getElementById('pvAnalog');
    pvAnalog.textContent = analog;
    pvAnalog.classList.toggle('is-empty', analog === '');
    document.getElementById('pvPrefilter').classList.toggle('off', !document.getElementById('inpPrefilter').checked);
    document.getElementById('pvGlueing').classList.toggle('off', !document.getElementById('inpGlueing').checked);
    document.getElementById('pvOrder').textContent = document.getElementById('inpOrder').value || '—';
    document.getElementById('pvBox').textContent = document.getElementById('inpBox').value || '—';
    const qty = parseInt(document.getElementById('inpQty').value, 10);
    document.getElementById('pvQty').textContent = (qty > 0 ? String(qty) : '—');
    document.getElementById('pvDate').textContent = formatDateRu(document.getElementById('inpDate').value);
    document.getElementById('pvOperator').textContent = document.getElementById('inpOperator').value || '—';
}
function syncScale() {
    const n = Math.max(2, Math.min(10, parseInt(document.getElementById('inpScale').value, 10) || 5));
    document.documentElement.style.setProperty('--mm', n + 'px');
    document.getElementById('scaleLabel').textContent = String(n);
    document.getElementById('inpScale').value = String(n);
}
function syncGrid() {
    document.getElementById('labelSheet').classList.toggle('show-grid', document.getElementById('inpGrid').checked);
}
['inpName','inpAnalog','inpOrder','inpBox','inpQty','inpDate','inpOperator'].forEach(id => {
    document.getElementById(id).addEventListener('input', syncPreview);
});
document.getElementById('inpPrefilter').addEventListener('change', syncPreview);
document.getElementById('inpGlueing').addEventListener('change', syncPreview);
document.getElementById('inpScale').addEventListener('input', syncScale);
document.getElementById('inpGrid').addEventListener('change', syncGrid);

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
