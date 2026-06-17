<?php
// Ввод изготовленных каркасов (наружный / внутренний) по заявкам
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

initAuthSystem();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U3');
}

function wfEnsureManufacturedWireframesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS manufactured_wireframes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date_of_production DATE NOT NULL,
            wireframe_name VARCHAR(255) NOT NULL,
            part_type ENUM('ext', 'int') NOT NULL,
            count_of_parts INT NOT NULL DEFAULT 0,
            order_number VARCHAR(64) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_mw_date (date_of_production),
            KEY idx_mw_order (order_number),
            KEY idx_mw_name_order_type (wireframe_name(191), order_number, part_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * @return list<array{name: string, part_type: string}>
 */
function wfDiscoverWireframeCodes(PDO $pdo, string $like): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT wf_name, part_type FROM (
            SELECT TRIM(ppr.p_p_ext_wireframe) AS wf_name, 'ext' AS part_type
            FROM paper_package_round ppr
            INNER JOIN round_filter_structure rfs
                ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            INNER JOIN orders o ON o.`filter` = rfs.`filter`
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(ppr.p_p_ext_wireframe, '')) != ''
              AND ppr.p_p_ext_wireframe LIKE ?
            UNION ALL
            SELECT TRIM(ppr.p_p_int_wireframe), 'int'
            FROM paper_package_round ppr
            INNER JOIN round_filter_structure rfs
                ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            INNER JOIN orders o ON o.`filter` = rfs.`filter`
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(ppr.p_p_int_wireframe, '')) != ''
              AND ppr.p_p_int_wireframe LIKE ?
            UNION ALL
            SELECT TRIM(wr.w_name) AS wf_name,
                CASE
                    WHEN UPPER(TRIM(ppr.p_p_ext_wireframe)) = UPPER(TRIM(wr.w_name)) THEN 'ext'
                    ELSE 'int'
                END AS part_type
            FROM wireframe_round wr
            INNER JOIN paper_package_round ppr
                ON UPPER(TRIM(ppr.p_p_ext_wireframe)) = UPPER(TRIM(wr.w_name))
                OR UPPER(TRIM(ppr.p_p_int_wireframe)) = UPPER(TRIM(wr.w_name))
            INNER JOIN round_filter_structure rfs
                ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            INNER JOIN orders o ON o.`filter` = rfs.`filter`
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(wr.w_name, '')) != ''
              AND wr.w_name LIKE ?
        ) AS discovered
        WHERE TRIM(COALESCE(wf_name, '')) != ''
    ");
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['wf_name'] ?? ''));
        $type = (string) ($row['part_type'] ?? '');
        if ($name === '' || ($type !== 'ext' && $type !== 'int')) {
            continue;
        }
        $key = mb_strtoupper($name, 'UTF-8') . '|' . $type;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['name' => $name, 'part_type' => $type];
    }

    return $out;
}

function wfLoadDirectActiveLines(PDO $pdo, string $wfNorm, string $partType): array
{
    $col = $partType === 'int' ? 'ppr.p_p_int_wireframe' : 'ppr.p_p_ext_wireframe';
    $sql = "
        SELECT
            agg.order_number,
            agg.filter_name,
            GREATEST(0, agg.ordered - COALESCE(mw.wf_qty, 0)) AS wf_need
        FROM (
            SELECT
                o.order_number,
                o.`filter` AS filter_name,
                SUM(o.`count`) AS ordered
            FROM orders o
            INNER JOIN round_filter_structure rfs ON o.`filter` = rfs.`filter`
            INNER JOIN paper_package_round ppr
                ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND UPPER(TRIM($col)) = ?
            GROUP BY o.order_number, o.`filter`
        ) agg
        LEFT JOIN (
            SELECT
                order_number,
                UPPER(TRIM(wireframe_name)) AS wf_u,
                part_type,
                SUM(COALESCE(count_of_parts, 0)) AS wf_qty
            FROM manufactured_wireframes
            GROUP BY order_number, UPPER(TRIM(wireframe_name)), part_type
        ) mw
            ON mw.order_number = agg.order_number
           AND mw.wf_u = ?
           AND mw.part_type = ?
        WHERE GREATEST(0, agg.ordered - COALESCE(mw.wf_qty, 0)) > 0
        ORDER BY agg.order_number, agg.filter_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$wfNorm, $wfNorm, $partType]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[] = [
            'order_number' => (string) ($row['order_number'] ?? ''),
            'filter_name' => (string) ($row['filter_name'] ?? ''),
            'remaining' => (int) ($row['wf_need'] ?? 0),
        ];
    }
    return $lines;
}

function wfLoadAnalogActiveLines(PDO $pdo, string $wfNorm, string $partType): array
{
    $col = $partType === 'int' ? 'ppr.p_p_int_wireframe' : 'ppr.p_p_ext_wireframe';
    $sql = "
        SELECT
            agg.order_number,
            agg.filter_name,
            agg.native_filter_name,
            agg.brand_wireframe_name,
            GREATEST(0, agg.ordered - COALESCE(mw.wf_qty, 0)) AS wf_need
        FROM (
            SELECT
                o.order_number,
                o.`filter` AS filter_name,
                MAX(TRIM(rfs_native.`filter`)) AS native_filter_name,
                MAX(TRIM($col)) AS brand_wireframe_name,
                SUM(o.`count`) AS ordered
            FROM orders o
            INNER JOIN round_filter_structure rfs_brand ON o.`filter` = rfs_brand.`filter`
            INNER JOIN round_filter_structure rfs_native
                ON rfs_brand.analog IS NOT NULL
               AND TRIM(rfs_brand.analog) != ''
               AND UPPER(TRIM(rfs_brand.analog)) = UPPER(TRIM(rfs_native.`filter`))
               AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
            INNER JOIN paper_package_round ppr
                ON UPPER(TRIM(rfs_native.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND UPPER(TRIM($col)) = ?
            GROUP BY o.order_number, o.`filter`
        ) agg
        LEFT JOIN (
            SELECT
                order_number,
                UPPER(TRIM(wireframe_name)) AS wf_u,
                part_type,
                SUM(COALESCE(count_of_parts, 0)) AS wf_qty
            FROM manufactured_wireframes
            GROUP BY order_number, UPPER(TRIM(wireframe_name)), part_type
        ) mw
            ON mw.order_number = agg.order_number
           AND mw.wf_u = UPPER(TRIM(agg.brand_wireframe_name))
           AND mw.part_type = ?
        WHERE TRIM(COALESCE(agg.brand_wireframe_name, '')) != ''
          AND GREATEST(0, agg.ordered - COALESCE(mw.wf_qty, 0)) > 0
        ORDER BY agg.order_number, agg.filter_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$wfNorm, $partType]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[] = [
            'order_number' => (string) ($row['order_number'] ?? ''),
            'filter_name' => (string) ($row['filter_name'] ?? ''),
            'native_filter_name' => (string) ($row['native_filter_name'] ?? ''),
            'brand_wireframe_name' => (string) ($row['brand_wireframe_name'] ?? ''),
            'remaining' => (int) ($row['wf_need'] ?? 0),
        ];
    }
    return $lines;
}

function wfFilterAnalogLines(array $directLines, array $analogLines): array
{
    $filtered = [];
    foreach ($analogLines as $ln) {
        $orderNumber = (string) ($ln['order_number'] ?? '');
        $filterName = (string) ($ln['filter_name'] ?? '');
        $alreadyDirect = false;
        foreach ($directLines as $d) {
            if (($d['order_number'] ?? '') === $orderNumber && ($d['filter_name'] ?? '') === $filterName) {
                $alreadyDirect = true;
                break;
            }
        }
        if (!$alreadyDirect) {
            $filtered[] = $ln;
        }
    }
    return $filtered;
}

function wfPartTypeLabel(string $partType): string
{
    return $partType === 'int' ? 'внутр.' : 'нар.';
}

// === АВТОДОПОЛНЕНИЕ ===
if (isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        wfEnsureManufacturedWireframesTable($pdo);

        $query = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($query, 'UTF-8') < 4) {
            echo json_encode([]);
            exit;
        }

        $like = '%' . $query . '%';
        $codes = wfDiscoverWireframeCodes($pdo, $like);
        $maxSuggestions = 18;
        if (count($codes) > $maxSuggestions) {
            $codes = array_slice($codes, 0, $maxSuggestions);
        }

        $out = [];
        foreach ($codes as $code) {
            $name = (string) $code['name'];
            $partType = (string) $code['part_type'];
            $wfNorm = mb_strtoupper(trim($name), 'UTF-8');
            $direct = wfLoadDirectActiveLines($pdo, $wfNorm, $partType);
            $analog = wfFilterAnalogLines($direct, wfLoadAnalogActiveLines($pdo, $wfNorm, $partType));
            $out[] = [
                'name' => $name,
                'part_type' => $partType,
                'part_type_label' => wfPartTypeLabel($partType),
                'active_lines' => $direct,
                'analog_active_lines' => $analog,
            ];
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === ЗАЯВКИ ПО КАРКАСУ ===
if (isset($_GET['orders']) && isset($_GET['part'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        wfEnsureManufacturedWireframesTable($pdo);

        $part = trim((string) ($_GET['part'] ?? ''));
        $partType = (string) ($_GET['part_type'] ?? 'ext');
        if ($partType !== 'ext' && $partType !== 'int') {
            $partType = 'ext';
        }
        $partNorm = mb_strtoupper($part, 'UTF-8');
        if ($partNorm === '') {
            echo json_encode([]);
            exit;
        }

        $col = $partType === 'int' ? 'ppr.p_p_int_wireframe' : 'ppr.p_p_ext_wireframe';
        $sql = "
            SELECT
                z.order_number,
                SUM(z.wf_line) AS remaining_total
            FROM (
                SELECT
                    agg.order_number,
                    GREATEST(0, agg.ordered - COALESCE(mw.wf_qty, 0)) AS wf_line
                FROM (
                    SELECT
                        o.order_number,
                        o.`filter` AS filter_name,
                        SUM(o.`count`) AS ordered
                    FROM orders o
                    INNER JOIN round_filter_structure rfs ON o.`filter` = rfs.`filter`
                    INNER JOIN paper_package_round ppr
                        ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
                    WHERE (o.hide IS NULL OR o.hide != 1)
                      AND UPPER(TRIM($col)) = ?
                    GROUP BY o.order_number, o.`filter`
                ) agg
                LEFT JOIN (
                    SELECT
                        order_number,
                        UPPER(TRIM(wireframe_name)) AS wf_u,
                        part_type,
                        SUM(COALESCE(count_of_parts, 0)) AS wf_qty
                    FROM manufactured_wireframes
                    GROUP BY order_number, UPPER(TRIM(wireframe_name)), part_type
                ) mw
                    ON mw.order_number = agg.order_number
                   AND mw.wf_u = ?
                   AND mw.part_type = ?
            ) z
            WHERE z.wf_line > 0
            GROUP BY z.order_number
            ORDER BY z.order_number
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$partNorm, $partNorm, $partType]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'order_number' => (string) ($row['order_number'] ?? ''),
                'remaining_total' => (int) ($row['remaining_total'] ?? 0),
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === СОХРАНЕНИЕ ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    wfEnsureManufacturedWireframesTable($pdo);

    $data = json_decode(file_get_contents('php://input'), true);
    $date = $data['date'] ?? null;
    $parts = $data['parts'] ?? [];

    $user_id = $session['user_id'] ?? null;
    if (!$user_id && isset($_SESSION['auth_user_id'])) {
        $user_id = $_SESSION['auth_user_id'];
    }

    if (!$date || !is_array($parts) || $parts === []) {
        echo json_encode(['status' => 'error', 'message' => 'Нет данных для сохранения']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO manufactured_wireframes
            (date_of_production, wireframe_name, part_type, count_of_parts, order_number, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($parts as $p) {
        $name = trim((string) ($p['name'] ?? ''));
        $partType = (string) ($p['part_type'] ?? '');
        $orderNumber = trim((string) ($p['order_number'] ?? ''));
        $produced = (int) ($p['produced'] ?? 0);
        if ($name === '' || $orderNumber === '' || $produced <= 0) {
            continue;
        }
        if ($partType !== 'ext' && $partType !== 'int') {
            continue;
        }
        $stmt->execute([$date, $name, $partType, $produced, $orderNumber, $user_id]);
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    wfEnsureManufacturedWireframesTable($pdo);
    $orders = $pdo->query("SELECT DISTINCT order_number FROM orders WHERE hide IS NULL OR hide = 0")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $orders = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ввод изготовленных каркасов</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="bg-gray-100 p-4">
<div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md p-4 space-y-4">
    <h1 class="text-xl font-bold text-center">Ввод изготовленных каркасов</h1>

    <div>
        <label class="block text-sm font-medium">Дата производства</label>
        <input type="date" id="prodDate" class="w-full border rounded px-3 py-2">
    </div>

    <div class="flex justify-between items-end mt-2 flex-wrap gap-2">
        <div></div>
        <div class="text-right">
            <span class="text-sm text-gray-500">Всего изготовлено:</span><br>
            <span id="totalCount" class="text-lg font-semibold">0 шт</span>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full table-auto text-sm border mt-4">
            <thead class="bg-gray-200">
            <tr>
                <th class="border px-2 py-1">Каркас</th>
                <th class="border px-2 py-1">Тип</th>
                <th class="border px-2 py-1">Заявка</th>
                <th class="border px-2 py-1">Изготовлено</th>
                <th class="border px-2 py-1">Удалить</th>
            </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>

    <div class="space-y-2">
        <button type="button" onclick="openModal()" class="w-full bg-blue-500 text-white py-2 rounded hover:bg-blue-600">
            Добавить каркас
        </button>
        <button type="button" onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">
            Сохранить смену
        </button>
    </div>
</div>

<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-lg">
            <h2 class="text-lg font-semibold mb-4">Добавить каркас</h2>

            <label class="block text-sm">Каркас</label>
            <div class="relative mb-2">
                <input type="text" id="modalName" class="w-full border px-3 py-2 rounded" placeholder="минимум 4 символа" oninput="autocompletePart(this.value); handlePartInput(this.value)" onblur="updateOrdersList()">
                <input type="hidden" id="modalPartType" value="">
                <ul id="partSuggestions" class="absolute z-10 bg-white border w-full rounded shadow hidden max-h-72 overflow-y-auto text-left"></ul>
            </div>

            <div id="modalTypeHint" class="text-xs text-gray-500 mb-2 hidden"></div>

            <label class="block text-sm">Номер заявки</label>
            <select id="modalOrder" class="w-full border px-3 py-2 rounded mb-2">
                <option value="">-- Выберите заявку --</option>
                <?php foreach ($orders as $order): ?>
                    <option value="<?= htmlspecialchars($order) ?>"><?= htmlspecialchars($order) ?></option>
                <?php endforeach; ?>
            </select>

            <label class="block text-sm">Изготовлено</label>
            <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded mb-4" placeholder="150">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button type="button" onclick="addPart()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
            </div>
        </div>
    </div>
</div>

<script>
    const PART_TYPE_LABELS = { ext: 'нар.', int: 'внутр.' };

    function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        setTimeout(() => document.getElementById('modalName')?.focus(), 100);
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modalName').value = '';
        document.getElementById('modalPartType').value = '';
        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        document.getElementById('partSuggestions').classList.add('hidden');
        document.getElementById('modalTypeHint').classList.add('hidden');
        updateOrdersList();
    }

    function setSelectedPart(name, partType, partTypeLabel) {
        document.getElementById('modalName').value = name;
        document.getElementById('modalPartType').value = partType;
        const hint = document.getElementById('modalTypeHint');
        hint.textContent = 'Тип: ' + (partTypeLabel || PART_TYPE_LABELS[partType] || partType);
        hint.classList.remove('hidden');
        updateOrdersList();
    }

    function addPart() {
        const name = document.getElementById('modalName').value.trim();
        const partType = document.getElementById('modalPartType').value.trim();
        const order = document.getElementById('modalOrder').value.trim();
        const count = document.getElementById('modalCount').value.trim();

        if (!name || !partType || !order || !count) {
            alert('Заполните все поля и выберите каркас из подсказок!');
            return;
        }

        const typeLabel = PART_TYPE_LABELS[partType] || partType;
        const row = document.createElement('tr');
        row.dataset.partType = partType;
        row.innerHTML = `
        <td class="border px-2 py-1">${escapeHtml(name)}</td>
        <td class="border px-2 py-1">${escapeHtml(typeLabel)}</td>
        <td class="border px-2 py-1">${escapeHtml(order)}</td>
        <td class="border px-2 py-1">${escapeHtml(count)}</td>
        <td class="border px-2 py-1 text-center">
          <button type="button" onclick="this.closest('tr').remove(); updateTotalCount();" class="text-red-500">✖</button>
        </td>
      `;
        document.getElementById('tableBody').appendChild(row);
        closeModal();
        updateTotalCount();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async function submitForm() {
        const date = document.getElementById('prodDate').value;
        const parts = [];

        document.querySelectorAll('#tableBody tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            parts.push({
                name: cells[0].innerText,
                part_type: row.dataset.partType || 'ext',
                order_number: cells[2].innerText,
                produced: parseInt(cells[3].innerText, 10)
            });
        });

        if (!date || parts.length === 0) {
            alert('Заполните дату и добавьте хотя бы один каркас');
            return;
        }

        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ date, parts })
        });

        const result = await res.json();
        if (result.status === 'ok') {
            alert('Смена успешно сохранена');
            location.reload();
        } else {
            alert('Ошибка при сохранении');
        }
    }

    const SUGGESTION_LINES_CAP = 18;
    const SUGGESTION_ANALOG_CAP = 14;

    async function autocompletePart(query) {
        const list = document.getElementById('partSuggestions');
        list.innerHTML = '';
        const q = query.trim();
        if (q.length < 4) {
            list.classList.add('hidden');
            document.getElementById('modalPartType').value = '';
            document.getElementById('modalTypeHint').classList.add('hidden');
            updateOrdersList();
            if (q.length > 0) {
                const li = document.createElement('li');
                li.className = 'px-3 py-2 text-gray-500 text-xs';
                li.textContent = 'Введите не менее 4 символов для поиска';
                list.appendChild(li);
                list.classList.remove('hidden');
            }
            return;
        }

        try {
            const res = await fetch('?q=' + encodeURIComponent(q));
            const suggestions = await res.json();

            if (!suggestions.length) {
                const li = document.createElement('li');
                li.textContent = 'Нет совпадений';
                li.className = 'px-3 py-2 text-gray-400';
                list.appendChild(li);
                list.classList.remove('hidden');
                updateOrdersList();
                return;
            }

            suggestions.forEach(item => {
                const name = item.name || '';
                const partType = item.part_type || 'ext';
                const typeLabel = item.part_type_label || PART_TYPE_LABELS[partType] || partType;
                const lines = Array.isArray(item.active_lines) ? item.active_lines : [];
                const analogLines = Array.isArray(item.analog_active_lines) ? item.analog_active_lines : [];

                const li = document.createElement('li');
                li.className = 'px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';

                const title = document.createElement('div');
                title.className = 'font-medium text-gray-900';
                title.textContent = name + ' (' + typeLabel + ')';
                li.appendChild(title);

                const sub = document.createElement('div');
                sub.className = 'mt-1 text-xs text-gray-600 space-y-0.5 pl-0.5';
                if (!lines.length && !analogLines.length) {
                    const row = document.createElement('div');
                    row.className = 'italic text-gray-400';
                    row.textContent = 'Нет активных позиций в открытых заявках';
                    sub.appendChild(row);
                } else {
                    lines.slice(0, SUGGESTION_LINES_CAP).forEach(ln => {
                        const row = document.createElement('div');
                        row.textContent = `Заявка ${ln.order_number} · ${ln.filter_name} · нужно ${ln.remaining} шт`;
                        sub.appendChild(row);
                    });
                    if (lines.length > SUGGESTION_LINES_CAP) {
                        const more = document.createElement('div');
                        more.className = 'text-gray-400 italic';
                        more.textContent = `… и ещё ${lines.length - SUGGESTION_LINES_CAP}`;
                        sub.appendChild(more);
                    }
                    if (analogLines.length) {
                        const hdr = document.createElement('div');
                        hdr.className = 'mt-2 text-[11px] font-semibold text-amber-900';
                        hdr.textContent = 'Чужие бренды (аналог → эталон)';
                        sub.appendChild(hdr);
                        analogLines.slice(0, SUGGESTION_ANALOG_CAP).forEach(ln => {
                            const row = document.createElement('div');
                            const nat = ln.native_filter_name ? ` → эталон ${ln.native_filter_name}` : '';
                            const brandWf = ln.brand_wireframe_name || name;
                            row.textContent = `Заявка ${ln.order_number} · ${ln.filter_name}${nat} · каркас в бренде: «${brandWf}» · нужно ${ln.remaining} шт`;
                            sub.appendChild(row);
                        });
                    }
                }
                li.appendChild(sub);

                li.onclick = () => {
                    setSelectedPart(name, partType, typeLabel);
                    list.classList.add('hidden');
                };
                list.appendChild(li);
            });

            list.classList.remove('hidden');
        } catch (err) {
            console.error('Ошибка поиска каркасов:', err);
        }
    }

    async function updateOrdersList() {
        const select = document.getElementById('modalOrder');
        const currentValue = select.value;
        const partName = document.getElementById('modalName').value.trim();
        const partType = document.getElementById('modalPartType').value.trim();

        try {
            let orders = [];
            if (partName && partType) {
                const url = '?orders=1&part=' + encodeURIComponent(partName) + '&part_type=' + encodeURIComponent(partType);
                const res = await fetch(url);
                orders = await res.json();
            } else {
                orders = <?= json_encode($orders) ?>;
            }

            select.innerHTML = '<option value="">-- Выберите заявку --</option>';

            if (!orders.length && partName && partType) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '-- Нет заявок с этим каркасом --';
                option.disabled = true;
                select.appendChild(option);
            } else {
                orders.forEach(entry => {
                    const option = document.createElement('option');
                    const num = typeof entry === 'string' ? entry : String(entry.order_number || '').trim();
                    const rem = (typeof entry === 'object' && entry !== null)
                        ? parseInt(entry.remaining_total, 10)
                        : NaN;
                    option.value = num;
                    option.textContent = Number.isNaN(rem) ? num : `${num} — нужно ${rem} шт`;
                    if (num === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            }
        } catch (err) {
            console.error('Ошибка загрузки заявок:', err);
        }
    }

    let partUpdateTimeout;
    function handlePartInput() {
        clearTimeout(partUpdateTimeout);
        partUpdateTimeout = setTimeout(() => {
            const name = document.getElementById('modalName').value.trim();
            if (!name) {
                document.getElementById('modalPartType').value = '';
                document.getElementById('modalTypeHint').classList.add('hidden');
            }
            updateOrdersList();
        }, 500);
    }

    document.addEventListener('click', e => {
        const list = document.getElementById('partSuggestions');
        const nameInput = document.getElementById('modalName');
        if (!nameInput.contains(e.target) && !list.contains(e.target)) {
            list.classList.add('hidden');
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') {
            return;
        }
        const modal = document.getElementById('modal');
        if (!modal.classList.contains('hidden')) {
            e.preventDefault();
            addPart();
        } else {
            e.preventDefault();
            openModal();
        }
    });

    function updateTotalCount() {
        let total = 0;
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const count = parseInt(row.children[3]?.innerText || '0', 10);
            total += Number.isNaN(count) ? 0 : count;
        });
        const el = document.getElementById('totalCount');
        el.textContent = `${total} шт`;
        el.classList.remove('scale-110');
        void el.offsetWidth;
        el.classList.add('scale-110');
        setTimeout(() => el.classList.remove('scale-110'), 200);
    }

    document.getElementById('prodDate').valueAsDate = new Date();
</script>
</body>
</html>
