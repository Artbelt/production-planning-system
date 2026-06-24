<?php
// Ввод изготовленных каркасов по фильтрам (1 цифра = 1 комплект)
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

function wfNormalizeKey(string $value): string
{
    return mb_strtoupper(trim($value), 'UTF-8');
}

/**
 * @return array{ext: string, int: string}
 */
function wfWireframesFromPackage(PDO $pdo, string $packageName): array
{
    $stmt = $pdo->prepare("
        SELECT
            TRIM(COALESCE(p_p_ext_wireframe, '')) AS ext_wf,
            TRIM(COALESCE(p_p_int_wireframe, '')) AS int_wf
        FROM paper_package_round
        WHERE UPPER(TRIM(p_p_name)) = UPPER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$packageName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'ext' => trim((string) ($row['ext_wf'] ?? '')),
        'int' => trim((string) ($row['int_wf'] ?? '')),
    ];
}

/**
 * @return array{
 *     filter_name: string,
 *     ext_wireframe: ?string,
 *     int_wireframe: ?string,
 *     is_brand: bool,
 *     native_filter_name: ?string
 * }
 */
function wfGetFilterWireframes(PDO $pdo, string $filterName): array
{
    $filterNorm = wfNormalizeKey($filterName);
    $result = [
        'filter_name' => trim($filterName),
        'ext_wireframe' => null,
        'int_wireframe' => null,
        'is_brand' => false,
        'native_filter_name' => null,
    ];

    if ($filterNorm === '') {
        return $result;
    }

    $stmt = $pdo->prepare("
        SELECT
            TRIM(rfs.`filter`) AS filter_name,
            TRIM(COALESCE(rfs.analog, '')) AS analog,
            TRIM(COALESCE(rfs.filter_package, '')) AS filter_package
        FROM round_filter_structure rfs
        WHERE UPPER(TRIM(rfs.`filter`)) = ?
        LIMIT 1
    ");
    $stmt->execute([$filterNorm]);
    $rfs = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rfs) {
        return $result;
    }

    $result['filter_name'] = trim((string) ($rfs['filter_name'] ?? $filterName));
    $analog = trim((string) ($rfs['analog'] ?? ''));

    if ($analog === '') {
        $packageName = trim((string) ($rfs['filter_package'] ?? ''));
        if ($packageName !== '') {
            $wf = wfWireframesFromPackage($pdo, $packageName);
            if ($wf['ext'] !== '') {
                $result['ext_wireframe'] = $wf['ext'];
            }
            if ($wf['int'] !== '') {
                $result['int_wireframe'] = $wf['int'];
            }
        }
        return $result;
    }

    $result['is_brand'] = true;
    $stmtNative = $pdo->prepare("
        SELECT
            TRIM(rfs_native.`filter`) AS native_filter_name,
            TRIM(COALESCE(rfs_native.filter_package, '')) AS filter_package
        FROM round_filter_structure rfs_native
        WHERE UPPER(TRIM(rfs_native.`filter`)) = UPPER(TRIM(?))
          AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
        LIMIT 1
    ");
    $stmtNative->execute([$analog]);
    $native = $stmtNative->fetch(PDO::FETCH_ASSOC);
    if (!$native) {
        return $result;
    }

    $result['native_filter_name'] = trim((string) ($native['native_filter_name'] ?? '')) ?: null;
    $packageName = trim((string) ($native['filter_package'] ?? ''));
    if ($packageName === '') {
        return $result;
    }

    $wf = wfWireframesFromPackage($pdo, $packageName);
    if ($wf['ext'] !== '') {
        $result['ext_wireframe'] = $wf['ext'];
    }
    if ($wf['int'] !== '') {
        $result['int_wireframe'] = $wf['int'];
    }

    return $result;
}

function wfRemainingForWireframe(PDO $pdo, string $orderNumber, string $wireframeName, string $partType, int $ordered): int
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(count_of_parts), 0) AS qty
        FROM manufactured_wireframes
        WHERE order_number = ?
          AND UPPER(TRIM(wireframe_name)) = ?
          AND part_type = ?
    ");
    $stmt->execute([$orderNumber, wfNormalizeKey($wireframeName), $partType]);
    $made = (int) $stmt->fetchColumn();

    return max(0, $ordered - $made);
}

function wfCalcKitRemaining(int $remainingExt, int $remainingInt, ?string $extWireframe, ?string $intWireframe): int
{
    $hasExt = $extWireframe !== null && $extWireframe !== '';
    $hasInt = $intWireframe !== null && $intWireframe !== '';

    if ($hasExt && $hasInt) {
        return min($remainingExt, $remainingInt);
    }
    if ($hasExt) {
        return $remainingExt;
    }
    if ($hasInt) {
        return $remainingInt;
    }

    return 0;
}

/**
 * @return list<array{
 *     order_number: string,
 *     remaining_ext: int,
 *     remaining_int: int,
 *     remaining_kits: int
 * }>
 */
function wfLoadFilterOrderKitLines(PDO $pdo, string $filterName, ?array $meta = null): array
{
    $meta = $meta ?? wfGetFilterWireframes($pdo, $filterName);
    $extWireframe = $meta['ext_wireframe'] ?? null;
    $intWireframe = $meta['int_wireframe'] ?? null;

    if (($extWireframe === null || $extWireframe === '') && ($intWireframe === null || $intWireframe === '')) {
        return [];
    }

    $filterNorm = wfNormalizeKey($meta['filter_name'] ?? $filterName);
    $stmt = $pdo->prepare("
        SELECT o.order_number, SUM(o.`count`) AS ordered
        FROM orders o
        WHERE (o.hide IS NULL OR o.hide != 1)
          AND UPPER(TRIM(o.`filter`)) = ?
        GROUP BY o.order_number
        ORDER BY o.order_number
    ");
    $stmt->execute([$filterNorm]);

    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderNumber = (string) ($row['order_number'] ?? '');
        $ordered = (int) ($row['ordered'] ?? 0);
        if ($orderNumber === '' || $ordered <= 0) {
            continue;
        }

        $remainingExt = 0;
        $remainingInt = 0;
        if ($extWireframe) {
            $remainingExt = wfRemainingForWireframe($pdo, $orderNumber, $extWireframe, 'ext', $ordered);
        }
        if ($intWireframe) {
            $remainingInt = wfRemainingForWireframe($pdo, $orderNumber, $intWireframe, 'int', $ordered);
        }

        $remainingKits = wfCalcKitRemaining($remainingExt, $remainingInt, $extWireframe, $intWireframe);
        if ($remainingKits <= 0) {
            continue;
        }

        $lines[] = [
            'order_number' => $orderNumber,
            'remaining_ext' => $remainingExt,
            'remaining_int' => $remainingInt,
            'remaining_kits' => $remainingKits,
        ];
    }

    return $lines;
}

/**
 * @return list<string>
 */
function wfDiscoverFilters(PDO $pdo, string $like): array
{
    $stmt = $pdo->prepare("
        SELECT DISTINCT filter_name FROM (
            SELECT TRIM(o.`filter`) AS filter_name
            FROM orders o
            INNER JOIN round_filter_structure rfs ON o.`filter` = rfs.`filter`
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(o.`filter`, '')) != ''
              AND o.`filter` LIKE ?
            UNION
            SELECT TRIM(o.`filter`) AS filter_name
            FROM orders o
            INNER JOIN round_filter_structure rfs_brand ON o.`filter` = rfs_brand.`filter`
            INNER JOIN round_filter_structure rfs_native
                ON rfs_brand.analog IS NOT NULL
               AND TRIM(rfs_brand.analog) != ''
               AND UPPER(TRIM(rfs_brand.analog)) = UPPER(TRIM(rfs_native.`filter`))
               AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND rfs_native.`filter` LIKE ?
        ) AS discovered
        WHERE TRIM(COALESCE(filter_name, '')) != ''
        ORDER BY filter_name
        LIMIT 25
    ");
    $stmt->execute([$like, $like]);

    $out = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $key = wfNormalizeKey($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $name;
    }

    return $out;
}

function wfFormatWireframesLabel(?array $meta): string
{
    if (!$meta) {
        return '';
    }
    $parts = [];
    if (!empty($meta['ext_wireframe'])) {
        $parts[] = 'нар. ' . $meta['ext_wireframe'];
    }
    if (!empty($meta['int_wireframe'])) {
        $parts[] = 'внутр. ' . $meta['int_wireframe'];
    }

    return implode(' · ', $parts);
}

function wfFormatRemainingLabel(array $line, ?array $meta): string
{
    $kits = (int) ($line['remaining_kits'] ?? 0);
    $hasExt = !empty($meta['ext_wireframe']);
    $hasInt = !empty($meta['int_wireframe']);

    if ($hasExt && $hasInt) {
        return sprintf(
            'нужно %d компл. (нар. %d, внутр. %d)',
            $kits,
            (int) ($line['remaining_ext'] ?? 0),
            (int) ($line['remaining_int'] ?? 0)
        );
    }

    return 'нужно ' . $kits . ' компл.';
}

// === АВТОДОПОЛНЕНИЕ ПО ФИЛЬТРУ ===
if (isset($_GET['filter_q'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        wfEnsureManufacturedWireframesTable($pdo);

        $query = trim((string) ($_GET['filter_q'] ?? ''));
        if (mb_strlen($query, 'UTF-8') < 3) {
            echo json_encode([]);
            exit;
        }

        $like = '%' . $query . '%';
        $filters = wfDiscoverFilters($pdo, $like);
        $maxSuggestions = 18;
        if (count($filters) > $maxSuggestions) {
            $filters = array_slice($filters, 0, $maxSuggestions);
        }

        $out = [];
        foreach ($filters as $filterName) {
            $meta = wfGetFilterWireframes($pdo, $filterName);
            if (empty($meta['ext_wireframe']) && empty($meta['int_wireframe'])) {
                continue;
            }

            $out[] = [
                'filter_name' => $meta['filter_name'],
                'ext_wireframe' => $meta['ext_wireframe'],
                'int_wireframe' => $meta['int_wireframe'],
                'wireframes_label' => wfFormatWireframesLabel($meta),
                'is_brand' => (bool) ($meta['is_brand'] ?? false),
                'native_filter_name' => $meta['native_filter_name'],
                'active_lines' => wfLoadFilterOrderKitLines($pdo, $filterName, $meta),
            ];
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === ЗАЯВКИ ПО ФИЛЬТРУ ===
if (isset($_GET['filter_orders']) && isset($_GET['filter'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        wfEnsureManufacturedWireframesTable($pdo);

        $filterName = trim((string) ($_GET['filter'] ?? ''));
        if ($filterName === '') {
            echo json_encode([]);
            exit;
        }

        $meta = wfGetFilterWireframes($pdo, $filterName);
        $lines = wfLoadFilterOrderKitLines($pdo, $filterName, $meta);
        $out = [];
        foreach ($lines as $line) {
            $out[] = [
                'order_number' => $line['order_number'],
                'remaining_kits' => $line['remaining_kits'],
                'remaining_ext' => $line['remaining_ext'],
                'remaining_int' => $line['remaining_int'],
                'remaining_label' => wfFormatRemainingLabel($line, $meta),
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
} catch (Exception $e) {
    // страница откроется без предзагрузки заявок
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
    <p class="text-sm text-gray-500 text-center">Поиск по фильтру · одна цифра = один комплект (нар. + внутр.)</p>

    <div>
        <label class="block text-sm font-medium">Дата производства</label>
        <input type="date" id="prodDate" class="w-full border rounded px-3 py-2">
    </div>

    <div class="flex justify-between items-end mt-2 flex-wrap gap-2">
        <div></div>
        <div class="text-right">
            <span class="text-sm text-gray-500">Всего комплектов:</span><br>
            <span id="totalCount" class="text-lg font-semibold">0</span>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full table-auto text-sm border mt-4">
            <thead class="bg-gray-200">
            <tr>
                <th class="border px-2 py-1">Фильтр</th>
                <th class="border px-2 py-1">Каркасы</th>
                <th class="border px-2 py-1">Заявка</th>
                <th class="border px-2 py-1">Комплектов</th>
                <th class="border px-2 py-1">Удалить</th>
            </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>

    <div class="space-y-2">
        <button type="button" onclick="openModal()" class="w-full bg-blue-500 text-white py-2 rounded hover:bg-blue-600">
            Добавить позицию
        </button>
        <button type="button" onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">
            Сохранить смену
        </button>
    </div>
</div>

<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-lg">
            <h2 class="text-lg font-semibold mb-4">Добавить комплект</h2>

            <label class="block text-sm">Фильтр</label>
            <div class="relative mb-2">
                <input type="text" id="modalFilter" class="w-full border px-3 py-2 rounded" placeholder="минимум 3 символа" oninput="autocompleteFilter(this.value); handleFilterInput()">
                <input type="hidden" id="modalExtWireframe" value="">
                <input type="hidden" id="modalIntWireframe" value="">
                <ul id="filterSuggestions" class="absolute z-10 bg-white border w-full rounded shadow hidden max-h-72 overflow-y-auto text-left"></ul>
            </div>

            <div id="modalWireframesHint" class="text-xs text-gray-600 mb-2 hidden"></div>

            <label class="block text-sm">Номер заявки</label>
            <select id="modalOrder" class="w-full border px-3 py-2 rounded mb-2">
                <option value="">-- Выберите заявку --</option>
            </select>

            <label class="block text-sm">Комплектов</label>
            <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded mb-4" placeholder="150" min="1">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button type="button" onclick="addKit()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
            </div>
        </div>
    </div>
</div>

<script>
    let selectedFilterMeta = null;

    function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        setTimeout(() => document.getElementById('modalFilter')?.focus(), 100);
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modalFilter').value = '';
        document.getElementById('modalExtWireframe').value = '';
        document.getElementById('modalIntWireframe').value = '';
        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        document.getElementById('filterSuggestions').classList.add('hidden');
        document.getElementById('modalWireframesHint').classList.add('hidden');
        selectedFilterMeta = null;
        updateOrdersList();
    }

    function setSelectedFilter(item) {
        selectedFilterMeta = {
            filter_name: item.filter_name || '',
            ext_wireframe: item.ext_wireframe || '',
            int_wireframe: item.int_wireframe || '',
            wireframes_label: item.wireframes_label || '',
            is_brand: !!item.is_brand,
            native_filter_name: item.native_filter_name || ''
        };

        document.getElementById('modalFilter').value = selectedFilterMeta.filter_name;
        document.getElementById('modalExtWireframe').value = selectedFilterMeta.ext_wireframe;
        document.getElementById('modalIntWireframe').value = selectedFilterMeta.int_wireframe;

        const hint = document.getElementById('modalWireframesHint');
        let hintText = selectedFilterMeta.wireframes_label;
        if (selectedFilterMeta.is_brand && selectedFilterMeta.native_filter_name) {
            hintText += ' · эталон: ' + selectedFilterMeta.native_filter_name;
        }
        hint.textContent = hintText;
        hint.classList.toggle('hidden', hintText === '');

        updateOrdersList();
    }

    function buildWireframesLabel(extWireframe, intWireframe) {
        const parts = [];
        if (extWireframe) {
            parts.push('нар. ' + extWireframe);
        }
        if (intWireframe) {
            parts.push('внутр. ' + intWireframe);
        }
        return parts.join(' · ');
    }

    function addKit() {
        const filterName = document.getElementById('modalFilter').value.trim();
        const extWireframe = document.getElementById('modalExtWireframe').value.trim();
        const intWireframe = document.getElementById('modalIntWireframe').value.trim();
        const order = document.getElementById('modalOrder').value.trim();
        const count = parseInt(document.getElementById('modalCount').value.trim(), 10);

        if (!filterName || (!extWireframe && !intWireframe) || !order || !count || count <= 0) {
            alert('Заполните все поля и выберите фильтр из подсказок!');
            return;
        }

        const row = document.createElement('tr');
        row.dataset.filterName = filterName;
        row.dataset.orderNumber = order;
        row.dataset.kits = String(count);
        row.dataset.extWireframe = extWireframe;
        row.dataset.intWireframe = intWireframe;

        const wireframesLabel = buildWireframesLabel(extWireframe, intWireframe);
        row.innerHTML = `
        <td class="border px-2 py-1">${escapeHtml(filterName)}</td>
        <td class="border px-2 py-1 text-xs">${escapeHtml(wireframesLabel)}</td>
        <td class="border px-2 py-1">${escapeHtml(order)}</td>
        <td class="border px-2 py-1">${escapeHtml(String(count))}</td>
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

    function rowToParts(row) {
        const kits = parseInt(row.dataset.kits || '0', 10);
        const orderNumber = row.dataset.orderNumber || '';
        const extWireframe = row.dataset.extWireframe || '';
        const intWireframe = row.dataset.intWireframe || '';
        const parts = [];

        if (extWireframe && kits > 0) {
            parts.push({
                name: extWireframe,
                part_type: 'ext',
                order_number: orderNumber,
                produced: kits
            });
        }
        if (intWireframe && kits > 0) {
            parts.push({
                name: intWireframe,
                part_type: 'int',
                order_number: orderNumber,
                produced: kits
            });
        }

        return parts;
    }

    async function submitForm() {
        const date = document.getElementById('prodDate').value;
        const parts = [];

        document.querySelectorAll('#tableBody tr').forEach(row => {
            parts.push(...rowToParts(row));
        });

        if (!date || parts.length === 0) {
            alert('Заполните дату и добавьте хотя бы одну позицию');
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

    const SUGGESTION_LINES_CAP = 12;

    async function autocompleteFilter(query) {
        const list = document.getElementById('filterSuggestions');
        list.innerHTML = '';
        const q = query.trim();

        if (q.length < 3) {
            list.classList.add('hidden');
            document.getElementById('modalExtWireframe').value = '';
            document.getElementById('modalIntWireframe').value = '';
            document.getElementById('modalWireframesHint').classList.add('hidden');
            selectedFilterMeta = null;
            updateOrdersList();
            if (q.length > 0) {
                const li = document.createElement('li');
                li.className = 'px-3 py-2 text-gray-500 text-xs';
                li.textContent = 'Введите не менее 3 символов для поиска';
                list.appendChild(li);
                list.classList.remove('hidden');
            }
            return;
        }

        try {
            const res = await fetch('?filter_q=' + encodeURIComponent(q));
            const suggestions = await res.json();

            if (!suggestions.length) {
                const li = document.createElement('li');
                li.textContent = 'Нет совпадений';
                li.className = 'px-3 py-2 text-gray-400';
                list.appendChild(li);
                list.classList.remove('hidden');
                selectedFilterMeta = null;
                updateOrdersList();
                return;
            }

            suggestions.forEach(item => {
                const filterName = item.filter_name || '';
                const wireframesLabel = item.wireframes_label || '';
                const lines = Array.isArray(item.active_lines) ? item.active_lines : [];

                const li = document.createElement('li');
                li.className = 'px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';

                const title = document.createElement('div');
                title.className = 'font-medium text-gray-900';
                title.textContent = filterName;
                li.appendChild(title);

                if (wireframesLabel) {
                    const wf = document.createElement('div');
                    wf.className = 'text-xs text-gray-600 mt-0.5';
                    wf.textContent = wireframesLabel;
                    li.appendChild(wf);
                }

                if (item.is_brand && item.native_filter_name) {
                    const brand = document.createElement('div');
                    brand.className = 'text-[11px] text-amber-800';
                    brand.textContent = 'Бренд · эталон: ' + item.native_filter_name;
                    li.appendChild(brand);
                }

                const sub = document.createElement('div');
                sub.className = 'mt-1 text-xs text-gray-600 space-y-0.5 pl-0.5';
                if (!lines.length) {
                    const row = document.createElement('div');
                    row.className = 'italic text-gray-400';
                    row.textContent = 'Нет активных позиций в открытых заявках';
                    sub.appendChild(row);
                } else {
                    lines.slice(0, SUGGESTION_LINES_CAP).forEach(ln => {
                        const row = document.createElement('div');
                        const kits = parseInt(ln.remaining_kits, 10) || 0;
                        const remExt = parseInt(ln.remaining_ext, 10);
                        const remInt = parseInt(ln.remaining_int, 10);
                        const hasBoth = item.ext_wireframe && item.int_wireframe;
                        row.textContent = hasBoth
                            ? `Заявка ${ln.order_number} · ${kits} компл. (нар. ${remExt}, внутр. ${remInt})`
                            : `Заявка ${ln.order_number} · ${kits} компл.`;
                        sub.appendChild(row);
                    });
                    if (lines.length > SUGGESTION_LINES_CAP) {
                        const more = document.createElement('div');
                        more.className = 'text-gray-400 italic';
                        more.textContent = `… и ещё ${lines.length - SUGGESTION_LINES_CAP}`;
                        sub.appendChild(more);
                    }
                }
                li.appendChild(sub);

                li.onclick = () => {
                    setSelectedFilter(item);
                    list.classList.add('hidden');
                };
                list.appendChild(li);
            });

            list.classList.remove('hidden');
        } catch (err) {
            console.error('Ошибка поиска фильтров:', err);
        }
    }

    async function updateOrdersList() {
        const select = document.getElementById('modalOrder');
        const currentValue = select.value;
        const filterName = document.getElementById('modalFilter').value.trim();
        const extWireframe = document.getElementById('modalExtWireframe').value.trim();
        const intWireframe = document.getElementById('modalIntWireframe').value.trim();

        try {
            let orders = [];
            if (filterName && (extWireframe || intWireframe)) {
                const url = '?filter_orders=1&filter=' + encodeURIComponent(filterName);
                const res = await fetch(url);
                orders = await res.json();
            }

            select.innerHTML = '<option value="">-- Выберите заявку --</option>';

            if (!orders.length && filterName && (extWireframe || intWireframe)) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '-- Нет заявок с этим фильтром --';
                option.disabled = true;
                select.appendChild(option);
            } else {
                orders.forEach(entry => {
                    const option = document.createElement('option');
                    const num = String(entry.order_number || '').trim();
                    const label = entry.remaining_label || (`${num} — нужно ${entry.remaining_kits} компл.`);
                    option.value = num;
                    option.textContent = `${num} — ${label.replace(/^нужно\s+/i, '')}`;
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

    let filterUpdateTimeout;
    function handleFilterInput() {
        clearTimeout(filterUpdateTimeout);
        filterUpdateTimeout = setTimeout(() => {
            const filterName = document.getElementById('modalFilter').value.trim();
            if (!filterName || !selectedFilterMeta || selectedFilterMeta.filter_name !== filterName) {
                document.getElementById('modalExtWireframe').value = '';
                document.getElementById('modalIntWireframe').value = '';
                document.getElementById('modalWireframesHint').classList.add('hidden');
                selectedFilterMeta = null;
            }
            updateOrdersList();
        }, 500);
    }

    document.addEventListener('click', e => {
        const list = document.getElementById('filterSuggestions');
        const filterInput = document.getElementById('modalFilter');
        if (!filterInput.contains(e.target) && !list.contains(e.target)) {
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
            addKit();
        } else {
            e.preventDefault();
            openModal();
        }
    });

    function updateTotalCount() {
        let total = 0;
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const count = parseInt(row.dataset.kits || '0', 10);
            total += Number.isNaN(count) ? 0 : count;
        });
        const el = document.getElementById('totalCount');
        el.textContent = String(total);
        el.classList.remove('scale-110');
        void el.offsetWidth;
        el.classList.add('scale-110');
        setTimeout(() => el.classList.remove('scale-110'), 200);
    }

    document.getElementById('prodDate').valueAsDate = new Date();
</script>
</body>
</html>
