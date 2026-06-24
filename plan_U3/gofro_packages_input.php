<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Инициализация системы авторизации
initAuthSystem();

// Запуск сессии
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new AuthManager();
$session = $auth->checkSession();

if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Проверяем, есть ли у пользователя доступ к цеху U3
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U3' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U3');
}

// === ДИАГНОСТИКА paper_package_round (откройте ?debug_ppr=1 в браузере) ===
if (isset($_GET['debug_ppr'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        $total = $pdo->query("SELECT COUNT(*) FROM paper_package_round")->fetchColumn();
        $sample = $pdo->query("SELECT p_p_name FROM paper_package_round LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
        $testQuery = trim($_GET['test_q'] ?? 'гофро');
        $like = "%{$testQuery}%";
        $stmt = $pdo->prepare("SELECT p_p_name FROM paper_package_round WHERE p_p_name LIKE ? LIMIT 10");
        $stmt->execute([$like]);
        $matched = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode([
            'ok' => true,
            'table' => 'paper_package_round',
            'total_rows' => (int)$total,
            'sample_names' => $sample,
            'test_query' => $testQuery,
            'matched_count' => count($matched),
            'matched' => $matched,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// === АВТОДОПОЛНЕНИЕ (с подсказками по активным заявкам: заявка, позиция, остаток) ===
if (isset($_GET['q'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        $query = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($query, 'UTF-8') < 4) {
            echo json_encode([]);
            exit;
        }

        $like = '%' . $query . '%';

        $stmt = $pdo->prepare("
            SELECT DISTINCT TRIM(ppr.p_p_name) AS p_p_name
            FROM paper_package_round ppr
            INNER JOIN round_filter_structure rfs
                ON UPPER(TRIM(rfs.filter_package)) = UPPER(TRIM(ppr.p_p_name))
            INNER JOIN orders o ON o.`filter` = rfs.`filter`
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(rfs.filter_package, '')) != ''
              AND ppr.p_p_name LIKE ?
            LIMIT 10
        ");
        $stmt->execute([$like]);
        $catalogNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Гофропакеты как они записаны у чужих брендов (есть analog → эталон), только по активным заявкам
        $stmtBrand = $pdo->prepare("
            SELECT DISTINCT TRIM(rfs_brand.filter_package) AS pkg_name
            FROM orders o
            INNER JOIN round_filter_structure rfs_brand ON o.`filter` = rfs_brand.`filter`
            INNER JOIN round_filter_structure rfs_native
                ON rfs_brand.analog IS NOT NULL
               AND TRIM(rfs_brand.analog) != ''
               AND UPPER(TRIM(rfs_brand.analog)) = UPPER(TRIM(rfs_native.`filter`))
               AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
            WHERE (o.hide IS NULL OR o.hide != 1)
              AND TRIM(COALESCE(rfs_brand.filter_package, '')) != ''
              AND (
                    UPPER(TRIM(rfs_brand.filter_package)) LIKE UPPER(?)
                 OR UPPER(TRIM(COALESCE(rfs_native.filter_package, ''))) LIKE UPPER(?)
              )
            LIMIT 15
        ");
        $stmtBrand->execute([$like, $like]);
        $brandPkgNames = $stmtBrand->fetchAll(PDO::FETCH_COLUMN);

        $names = [];
        $seenNorm = [];
        foreach ($catalogNames as $n) {
            $n = trim((string)$n);
            if ($n === '') {
                continue;
            }
            $k = mb_strtoupper($n, 'UTF-8');
            if (isset($seenNorm[$k])) {
                continue;
            }
            $seenNorm[$k] = true;
            $names[] = $n;
        }
        foreach ($brandPkgNames as $n) {
            $n = trim((string)$n);
            if ($n === '') {
                continue;
            }
            $k = mb_strtoupper($n, 'UTF-8');
            if (isset($seenNorm[$k])) {
                continue;
            }
            $seenNorm[$k] = true;
            $names[] = $n;
        }

        $maxSuggestions = 18;
        if (count($names) > $maxSuggestions) {
            $names = array_slice($names, 0, $maxSuggestions);
        }

        if (empty($names)) {
            echo json_encode([]);
            exit;
        }

        $normKeys = [];
        foreach ($names as $n) {
            $k = mb_strtoupper(trim((string)$n), 'UTF-8');
            if ($k !== '') {
                $normKeys[$k] = true;
            }
        }
        $keyList = array_keys($normKeys);
        $placeholders = implode(',', array_fill(0, count($keyList), '?'));

        $linesByPkgU = [];
        $analogLinesByPkgU = [];
        if (!empty($keyList)) {
            // Остаток по гофро: как в gofro_build_plan — max(0, (заказ-выпуск фильтров) - (внесено гофро - выпуск фильтров))
            $sqlLines = "
                SELECT
                    agg.pkg_u,
                    agg.order_number,
                    agg.filter_name,
                    GREATEST(
                        0,
                        GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))
                        - (COALESCE(mp.gofro_qty, 0) - COALESCE(prod.produced, 0))
                    ) AS gofro_need
                FROM (
                    SELECT
                        UPPER(TRIM(rfs.filter_package)) AS pkg_u,
                        o.order_number,
                        o.`filter` AS filter_name,
                        SUM(o.`count`) AS ordered
                    FROM orders o
                    INNER JOIN round_filter_structure rfs ON o.`filter` = rfs.`filter`
                    WHERE (o.hide IS NULL OR o.hide != 1)
                      AND UPPER(TRIM(rfs.filter_package)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(rfs.filter_package)), o.order_number, o.`filter`
                ) agg
                LEFT JOIN (
                    SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
                    FROM manufactured_production
                    GROUP BY name_of_order, name_of_filter
                ) prod
                    ON prod.name_of_order = agg.order_number
                   AND prod.name_of_filter = agg.filter_name
                LEFT JOIN (
                    SELECT
                        name_of_order,
                        UPPER(TRIM(name_of_parts)) AS parts_u,
                        SUM(COALESCE(count_of_parts, 0)) AS gofro_qty
                    FROM manufactured_parts
                    GROUP BY name_of_order, UPPER(TRIM(name_of_parts))
                ) mp
                    ON mp.name_of_order = agg.order_number
                   AND mp.parts_u = agg.pkg_u
                WHERE GREATEST(
                    0,
                    GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))
                    - (COALESCE(mp.gofro_qty, 0) - COALESCE(prod.produced, 0))
                ) > 0
                ORDER BY agg.order_number, agg.filter_name
            ";
            $stmtLines = $pdo->prepare($sqlLines);
            $stmtLines->execute($keyList);
            foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pkgU = (string)($row['pkg_u'] ?? '');
                if ($pkgU === '') {
                    continue;
                }
                if (!isset($linesByPkgU[$pkgU])) {
                    $linesByPkgU[$pkgU] = [];
                }
                $gofroNeed = (int)($row['gofro_need'] ?? 0);
                $linesByPkgU[$pkgU][] = [
                    'order_number' => (string)($row['order_number'] ?? ''),
                    'filter_name' => (string)($row['filter_name'] ?? ''),
                    'remaining' => $gofroNeed,
                ];
            }

            // Позиции «под чужим брендом»: в заявке фильтр бренда, analog → эталон с тем же гофропакетом
            // (эталон: analog пустой, как в NP_build_plan load_native_forms)
            $sqlAnalog = "
                SELECT
                    agg.pkg_u,
                    agg.order_number,
                    agg.filter_name,
                    agg.native_filter_name,
                    agg.brand_filter_package,
                    GREATEST(
                        0,
                        GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))
                        - (COALESCE(mp.gofro_qty, 0) - COALESCE(prod.produced, 0))
                    ) AS gofro_need
                FROM (
                    SELECT
                        UPPER(TRIM(rfs_native.filter_package)) AS pkg_u,
                        o.order_number,
                        o.`filter` AS filter_name,
                        MAX(TRIM(rfs_native.`filter`)) AS native_filter_name,
                        MAX(TRIM(COALESCE(rfs_brand.filter_package, ''))) AS brand_filter_package,
                        SUM(o.`count`) AS ordered
                    FROM orders o
                    INNER JOIN round_filter_structure rfs_brand ON o.`filter` = rfs_brand.`filter`
                    INNER JOIN round_filter_structure rfs_native
                        ON rfs_brand.analog IS NOT NULL
                       AND TRIM(rfs_brand.analog) != ''
                       AND UPPER(TRIM(rfs_brand.analog)) = UPPER(TRIM(rfs_native.`filter`))
                       AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
                    WHERE (o.hide IS NULL OR o.hide != 1)
                      AND UPPER(TRIM(rfs_native.filter_package)) IN ($placeholders)
                    GROUP BY UPPER(TRIM(rfs_native.filter_package)), o.order_number, o.`filter`
                ) agg
                LEFT JOIN (
                    SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
                    FROM manufactured_production
                    GROUP BY name_of_order, name_of_filter
                ) prod
                    ON prod.name_of_order = agg.order_number
                   AND prod.name_of_filter = agg.filter_name
                LEFT JOIN (
                    SELECT
                        name_of_order,
                        UPPER(TRIM(name_of_parts)) AS parts_u,
                        SUM(COALESCE(count_of_parts, 0)) AS gofro_qty
                    FROM manufactured_parts
                    GROUP BY name_of_order, UPPER(TRIM(name_of_parts))
                ) mp
                    ON mp.name_of_order = agg.order_number
                   AND mp.parts_u = UPPER(TRIM(agg.brand_filter_package))
                WHERE TRIM(COALESCE(agg.brand_filter_package, '')) != ''
                  AND GREATEST(
                    0,
                    GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))
                    - (COALESCE(mp.gofro_qty, 0) - COALESCE(prod.produced, 0))
                ) > 0
                ORDER BY agg.order_number, agg.filter_name
            ";
            $stmtAnalog = $pdo->prepare($sqlAnalog);
            $stmtAnalog->execute($keyList);
            foreach ($stmtAnalog->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pkgU = (string)($row['pkg_u'] ?? '');
                if ($pkgU === '') {
                    continue;
                }
                $orderNumber = (string)($row['order_number'] ?? '');
                $filterName = (string)($row['filter_name'] ?? '');
                $alreadyDirect = false;
                foreach (($linesByPkgU[$pkgU] ?? []) as $d) {
                    if (($d['order_number'] ?? '') === $orderNumber && ($d['filter_name'] ?? '') === $filterName) {
                        $alreadyDirect = true;
                        break;
                    }
                }
                if ($alreadyDirect) {
                    continue;
                }
                if (!isset($analogLinesByPkgU[$pkgU])) {
                    $analogLinesByPkgU[$pkgU] = [];
                }
                $gofroNeed = (int)($row['gofro_need'] ?? 0);
                $analogLinesByPkgU[$pkgU][] = [
                    'order_number' => $orderNumber,
                    'filter_name' => $filterName,
                    'native_filter_name' => (string)($row['native_filter_name'] ?? ''),
                    'brand_filter_package' => (string)($row['brand_filter_package'] ?? ''),
                    'remaining' => $gofroNeed,
                ];
            }
        }

        $out = [];
        foreach ($names as $name) {
            $pkgU = mb_strtoupper(trim((string)$name), 'UTF-8');
            $out[] = [
                'name' => (string)$name,
                'active_lines' => $linesByPkgU[$pkgU] ?? [],
                'analog_active_lines' => $analogLinesByPkgU[$pkgU] ?? [],
            ];
        }

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === ПОЛУЧЕНИЕ ЗАЯВОК ПО ГОФРОПАКЕТУ (с суммарным остатком по заявке; surplus — лишнее) ===
if (isset($_GET['orders']) && isset($_GET['part'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_once __DIR__ . '/../auth/includes/db.php';
        $pdo = getPdo('plan_u3');
        $part = $_GET['part'];
        $partNorm = mb_strtoupper(trim((string)$part), 'UTF-8');
        if ($partNorm === '') {
            echo json_encode([]);
            exit;
        }

        $gofroLineExpr = "
            GREATEST(
                0,
                GREATEST(0, agg.ordered - COALESCE(prod.produced, 0))
                - (COALESCE(mp.gofro_qty, 0) - COALESCE(prod.produced, 0))
            )
        ";

        // Прямые позиции + чужие бренды (analog → эталон с тем же гофропакетом)
        $sql = "
            SELECT
                z.order_number,
                SUM(z.gofro_line) AS remaining_total
            FROM (
                SELECT
                    agg.order_number,
                    {$gofroLineExpr} AS gofro_line
                FROM (
                    SELECT
                        o.order_number,
                        o.`filter` AS filter_name,
                        SUM(o.`count`) AS ordered
                    FROM orders o
                    INNER JOIN round_filter_structure rfs ON o.`filter` = rfs.`filter`
                    WHERE (o.hide IS NULL OR o.hide != 1)
                      AND UPPER(TRIM(rfs.filter_package)) = ?
                    GROUP BY o.order_number, o.`filter`
                ) agg
                LEFT JOIN (
                    SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
                    FROM manufactured_production
                    GROUP BY name_of_order, name_of_filter
                ) prod
                    ON prod.name_of_order = agg.order_number
                   AND prod.name_of_filter = agg.filter_name
                LEFT JOIN (
                    SELECT
                        name_of_order,
                        UPPER(TRIM(name_of_parts)) AS parts_u,
                        SUM(COALESCE(count_of_parts, 0)) AS gofro_qty
                    FROM manufactured_parts
                    GROUP BY name_of_order, UPPER(TRIM(name_of_parts))
                ) mp
                    ON mp.name_of_order = agg.order_number
                   AND mp.parts_u = ?

                UNION ALL

                SELECT
                    agg.order_number,
                    {$gofroLineExpr} AS gofro_line
                FROM (
                    SELECT
                        o.order_number,
                        o.`filter` AS filter_name,
                        MAX(TRIM(COALESCE(rfs_brand.filter_package, ''))) AS brand_filter_package,
                        SUM(o.`count`) AS ordered
                    FROM orders o
                    INNER JOIN round_filter_structure rfs_brand ON o.`filter` = rfs_brand.`filter`
                    INNER JOIN round_filter_structure rfs_native
                        ON rfs_brand.analog IS NOT NULL
                       AND TRIM(rfs_brand.analog) != ''
                       AND UPPER(TRIM(rfs_brand.analog)) = UPPER(TRIM(rfs_native.`filter`))
                       AND (rfs_native.analog IS NULL OR TRIM(COALESCE(rfs_native.analog, '')) = '')
                    WHERE (o.hide IS NULL OR o.hide != 1)
                      AND UPPER(TRIM(rfs_native.filter_package)) = ?
                    GROUP BY o.order_number, o.`filter`
                ) agg
                LEFT JOIN (
                    SELECT name_of_order, name_of_filter, SUM(count_of_filters) AS produced
                    FROM manufactured_production
                    GROUP BY name_of_order, name_of_filter
                ) prod
                    ON prod.name_of_order = agg.order_number
                   AND prod.name_of_filter = agg.filter_name
                LEFT JOIN (
                    SELECT
                        name_of_order,
                        UPPER(TRIM(name_of_parts)) AS parts_u,
                        SUM(COALESCE(count_of_parts, 0)) AS gofro_qty
                    FROM manufactured_parts
                    GROUP BY name_of_order, UPPER(TRIM(name_of_parts))
                ) mp
                    ON mp.name_of_order = agg.order_number
                   AND mp.parts_u = UPPER(TRIM(agg.brand_filter_package))
                WHERE TRIM(COALESCE(agg.brand_filter_package, '')) != ''
            ) z
            GROUP BY z.order_number
            ORDER BY remaining_total DESC, z.order_number
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$partNorm, $partNorm, $partNorm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $remaining = (int)($row['remaining_total'] ?? 0);
            $entry = [
                'order_number' => (string)($row['order_number'] ?? ''),
                'remaining_total' => $remaining,
            ];
            if ($remaining <= 0) {
                $entry['surplus'] = true;
            }
            $out[] = $entry;
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === СОХРАНЕНИЕ В БД ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    $data = json_decode(file_get_contents("php://input"), true);

    $date = $data['date'] ?? null;
    $parts = $data['parts'] ?? [];

    // Получаем данные пользователя из сессии авторизации
    $user_id = $session['user_id'] ?? null;
    $user_name = null;
    
    // Пытаемся получить имя пользователя из разных источников
    if (isset($session['full_name']) && !empty($session['full_name'])) {
        $user_name = $session['full_name'];
    } elseif (isset($_SESSION['auth_full_name']) && !empty($_SESSION['auth_full_name'])) {
        $user_name = $_SESSION['auth_full_name'];
    } else {
        // Если не нашли в сессии, получаем из базы данных
        try {
            $db = Database::getInstance();
            $users = $db->select("SELECT full_name FROM auth_users WHERE id = ?", [$user_id]);
            if (!empty($users) && isset($users[0]['full_name'])) {
                $user_name = $users[0]['full_name'];
            } else {
                $user_name = 'Неизвестный пользователь';
            }
        } catch (Exception $e) {
            $user_name = 'Неизвестный пользователь';
        }
    }
    
    // Если user_id не получен, пытаемся получить из сессии
    if (!$user_id && isset($_SESSION['auth_user_id'])) {
        $user_id = $_SESSION['auth_user_id'];
    }

    $stmt = $pdo->prepare("
    INSERT INTO manufactured_parts 
    (date_of_production, name_of_parts, count_of_parts, name_of_order) 
    VALUES (?, ?, ?, ?)
");

    foreach ($parts as $p) {
        // Сохраняем в manufactured_parts
        $stmt->execute([
            $date,
            $p['name'],
            $p['produced'],
            $p['order_number']
        ]);
    }

    echo json_encode(["status" => "ok"]);
    exit;
}

// === ПОЛУЧЕНИЕ СПИСКА ЗАЯВОК ===
try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    $orders = $pdo->query("SELECT DISTINCT order_number FROM orders WHERE hide IS NULL OR hide = 0")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $orders = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Ввод изготовленных гофропакетов</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="bg-gray-100 p-4">
<div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md p-4 space-y-4">
    <h1 class="text-xl font-bold text-center">Ввод изготовленных гофропакетов</h1>

    <div>
        <label class="block text-sm font-medium">Дата производства</label>
        <input type="date" id="prodDate" class="w-full border rounded px-3 py-2">
    </div>

    <div class="flex justify-between items-end mt-2 flex-wrap gap-2">
        <div>

        </div>
        <div class="text-right">
            <span class="text-sm text-gray-500">Всего изготовлено:</span><br>
            <span id="totalCount" class="text-lg font-semibold">0 шт</span>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full table-auto text-sm border mt-4">
            <thead class="bg-gray-200">
            <tr>
                <th class="border px-2 py-1">Наименование</th>
                <th class="border px-2 py-1">Заявка</th>
                <th class="border px-2 py-1">Изготовлено</th>
                <th class="border px-2 py-1">Удалить</th>
            </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>

    <div class="space-y-2">
        <button onclick="openModal()" class="w-full bg-blue-500 text-white py-2 rounded hover:bg-blue-600">
            Добавить гофропакет
        </button>
        <button onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">
            Сохранить смену
        </button>
    </div>
</div>

<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-lg">
            <h2 class="text-lg font-semibold mb-4">Добавить гофропакет</h2>

            <!-- Наименование -->
            <label class="block text-sm">Наименование</label>
            <div class="relative mb-2">
                <input type="text" id="modalName" class="w-full border px-3 py-2 rounded" placeholder="минимум 4 символа (например 1600 или AF16)" oninput="autocompletePart(this.value); handlePartInput(this.value)" onblur="updateOrdersList(this.value)">
                <ul id="partSuggestions" class="absolute z-10 bg-white border w-full rounded shadow hidden max-h-72 overflow-y-auto text-left"></ul>
            </div>

            <!-- Номер заявки -->
            <label class="block text-sm">Номер заявки</label>
            <select id="modalOrder" class="w-full border px-3 py-2 rounded mb-1" onchange="updateSurplusWarning()">
                <option value="">-- Выберите заявку --</option>
                <?php foreach ($orders as $order): ?>
                    <option value="<?= htmlspecialchars($order) ?>"><?= htmlspecialchars($order) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="surplusOrderHint" class="hidden mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                Лишний гофропакет: по этой заявке потребность уже закрыта, ввод будет учтён сверх плана.
            </div>
            <div id="surplusListHint" class="hidden mb-2 rounded border border-amber-200 bg-amber-50/80 px-3 py-2 text-xs text-amber-800">
                По этому гофропакету нет незакрытой потребности. Ниже — активные заявки с этой позицией для учёта лишних гофропакетов.
            </div>

            <!-- Количество -->
            <label class="block text-sm">Изготовлено</label>
            <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded mb-4" placeholder="150">

            <!-- Кнопки -->
            <div class="flex justify-end gap-2">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button onclick="addPart()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
            </div>
        </div>
    </div>
</div>

<script>
    function openModal() {
        const modal = document.getElementById('modal');
        modal.classList.remove('hidden');

        // Установим фокус в поле "Наименование" через короткую задержку
        setTimeout(() => {
            document.getElementById('modalName')?.focus();
        }, 100); // небольшая задержка, чтобы DOM успел "развернуться"
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modalName').value = '';
        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        document.getElementById('partSuggestions').classList.add('hidden');
        document.getElementById('surplusOrderHint').classList.add('hidden');
        document.getElementById('surplusListHint').classList.add('hidden');
        window._ordersByPart = [];
        // Восстанавливаем полный список заявок при закрытии модального окна
        updateOrdersList('');
    }

    function addPart() {
        const name = document.getElementById('modalName').value.trim();
        const order = document.getElementById('modalOrder').value.trim();
        const count = document.getElementById('modalCount').value.trim();

        if (!name || !order || !count) return alert('Заполните все поля!');

        const row = document.createElement('tr');
        row.innerHTML = `
        <td class="border px-2 py-1">${name}</td>
        <td class="border px-2 py-1">${order}</td>
        <td class="border px-2 py-1">${count}</td>
        <td class="border px-2 py-1 text-center">
          <button onclick="this.closest('tr').remove();  updateTotalCount();" class="text-red-500">✖</button>
        </td>
      `;
        document.getElementById('tableBody').appendChild(row);
        closeModal();
        updateTotalCount()

    }

    async function submitForm() {
        const date = document.getElementById('prodDate').value;
        const parts = [];

        document.querySelectorAll('#tableBody tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            parts.push({
                name: cells[0].innerText,
                order_number: cells[1].innerText,
                produced: parseInt(cells[2].innerText)
            });
        });

        if (!date || parts.length === 0) {
            alert('Заполните дату и добавьте хотя бы один гофропакет');
            return;
        }

        const payload = { date, parts };

        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
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
            updateOrdersList('');
            if (q.length > 0 && q.length < 4) {
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
                updateOrdersList('');
                return;
            }

            suggestions.forEach(item => {
                const name = typeof item === 'string' ? item : (item.name || '');
                const lines = (item && Array.isArray(item.active_lines)) ? item.active_lines : [];
                const analogLines = (item && Array.isArray(item.analog_active_lines)) ? item.analog_active_lines : [];
                const hasDirect = lines.length > 0;
                const hasAnalog = analogLines.length > 0;

                const li = document.createElement('li');
                li.className = 'px-3 py-2 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-b-0';

                const title = document.createElement('div');
                title.className = 'font-medium text-gray-900';
                title.textContent = name;
                li.appendChild(title);

                const sub = document.createElement('div');
                sub.className = 'mt-1 text-xs text-gray-600 space-y-0.5 pl-0.5';
                if (!hasDirect && !hasAnalog) {
                    const row = document.createElement('div');
                    row.className = 'italic text-gray-400';
                    row.textContent = 'Нет незакрытой потребности — можно внести как лишнее в активную заявку';
                    sub.appendChild(row);
                } else {
                    if (hasDirect) {
                        const shown = lines.slice(0, SUGGESTION_LINES_CAP);
                        shown.forEach(ln => {
                            const row = document.createElement('div');
                            const ord = (ln.order_number || '').trim();
                            const flt = (ln.filter_name || '').trim();
                            const rem = parseInt(ln.remaining, 10);
                            const remTxt = isNaN(rem) ? '—' : rem;
                            row.textContent = `Заявка ${ord} · ${flt} · нужно гофро ${remTxt} шт`;
                            sub.appendChild(row);
                        });
                        if (lines.length > SUGGESTION_LINES_CAP) {
                            const more = document.createElement('div');
                            more.className = 'text-gray-400 italic';
                            more.textContent = `… и ещё ${lines.length - SUGGESTION_LINES_CAP}`;
                            sub.appendChild(more);
                        }
                    }
                    if (hasAnalog) {
                        const hdr = document.createElement('div');
                        hdr.className = 'mt-2 text-[11px] font-semibold text-amber-900';
                        hdr.textContent = 'Чужие бренды: вводите наименование гофро как в справочнике у бренда (столбец ниже)';
                        sub.appendChild(hdr);
                        const shownA = analogLines.slice(0, SUGGESTION_ANALOG_CAP);
                        shownA.forEach(ln => {
                            const row = document.createElement('div');
                            const ord = (ln.order_number || '').trim();
                            const flt = (ln.filter_name || '').trim();
                            const nat = (ln.native_filter_name || '').trim();
                            const brandPkg = (ln.brand_filter_package || '').trim();
                            const rem = parseInt(ln.remaining, 10);
                            const remTxt = isNaN(rem) ? '—' : rem;
                            const natPart = nat ? ` → эталон ${nat}` : '';
                            const gofroForInput = brandPkg || name;
                            row.textContent = `Заявка ${ord} · ${flt}${natPart} · гофро в бренде: «${gofroForInput}» · нужно гофро ${remTxt} шт`;
                            sub.appendChild(row);
                        });
                        if (analogLines.length > SUGGESTION_ANALOG_CAP) {
                            const more = document.createElement('div');
                            more.className = 'text-gray-400 italic';
                            more.textContent = `… и ещё ${analogLines.length - SUGGESTION_ANALOG_CAP}`;
                            sub.appendChild(more);
                        }
                    }
                }
                li.appendChild(sub);

                li.onclick = () => {
                    document.getElementById('modalName').value = name;
                    list.classList.add('hidden');
                    updateOrdersList(name);
                };
                list.appendChild(li);
            });

            list.classList.remove('hidden');
        } catch (err) {
            console.error('Ошибка запроса гофропакетов:', err);
        }
    }

    function updateSurplusWarning() {
        const select = document.getElementById('modalOrder');
        const hint = document.getElementById('surplusOrderHint');
        const selected = select.options[select.selectedIndex];
        const isSurplus = selected && selected.dataset.surplus === '1';
        hint.classList.toggle('hidden', !isSurplus);
    }

    async function updateOrdersList(partName) {
        const select = document.getElementById('modalOrder');
        const listHint = document.getElementById('surplusListHint');
        const currentValue = select.value;
        
        try {
            let orders = [];
            if (partName && partName.trim() !== '') {
                const res = await fetch('?orders=1&part=' + encodeURIComponent(partName.trim()));
                orders = await res.json();
            } else {
                // Если гофропакет не выбран, показываем все заявки
                orders = <?= json_encode($orders) ?>;
            }
            window._ordersByPart = Array.isArray(orders) ? orders : [];

            // Очищаем список
            select.innerHTML = '<option value="">-- Выберите заявку --</option>';
            document.getElementById('surplusOrderHint').classList.add('hidden');
            
            const hasNeed = orders.some(entry => {
                if (typeof entry !== 'object' || entry === null || Array.isArray(entry)) {
                    return true;
                }
                return !entry.surplus && parseInt(entry.remaining_total, 10) > 0;
            });
            const hasSurplus = orders.some(entry =>
                typeof entry === 'object' && entry !== null && !Array.isArray(entry) && entry.surplus
            );
            listHint.classList.toggle('hidden', !(partName && partName.trim() !== '' && !hasNeed && hasSurplus));

            // Добавляем заявки
            if (orders.length === 0 && partName && partName.trim() !== '') {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '-- Нет заявок с этим гофропакетом --';
                option.disabled = true;
                select.appendChild(option);
            } else {
                const needOrders = [];
                const surplusOrders = [];
                orders.forEach(entry => {
                    const isSurplus = typeof entry === 'object' && entry !== null && !Array.isArray(entry) && !!entry.surplus;
                    if (isSurplus) {
                        surplusOrders.push(entry);
                    } else {
                        needOrders.push(entry);
                    }
                });

                const appendOrderOption = (entry) => {
                    const option = document.createElement('option');
                    const num = typeof entry === 'string' ? entry : String(entry.order_number || '').trim();
                    const rem = (typeof entry === 'object' && entry !== null && !Array.isArray(entry))
                        ? parseInt(entry.remaining_total, 10)
                        : NaN;
                    const isSurplus = typeof entry === 'object' && entry !== null && !Array.isArray(entry) && !!entry.surplus;
                    option.value = num;
                    if (isSurplus) {
                        option.dataset.surplus = '1';
                        option.textContent = `${num} — лишнее (потребность 0 шт)`;
                    } else if (!Number.isNaN(rem)) {
                        option.textContent = `${num} — нужно гофро ${rem} шт`;
                    } else {
                        option.textContent = num;
                    }
                    if (num === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                };

                needOrders.forEach(appendOrderOption);
                if (surplusOrders.length > 0 && needOrders.length > 0) {
                    const separator = document.createElement('option');
                    separator.disabled = true;
                    separator.textContent = '— лишние (потребность закрыта) —';
                    select.appendChild(separator);
                }
                surplusOrders.forEach(appendOrderOption);
            }
            updateSurplusWarning();
        } catch (err) {
            console.error('Ошибка загрузки заявок:', err);
        }
    }

    // Обработчик для обновления списка заявок при вводе гофропакета
    let partUpdateTimeout;
    function handlePartInput(value) {
        clearTimeout(partUpdateTimeout);
        partUpdateTimeout = setTimeout(() => {
            if (value && value.trim() !== '') {
                updateOrdersList(value.trim());
            } else {
                updateOrdersList('');
            }
        }, 500); // Задержка 500мс для избежания лишних запросов
    }


    document.addEventListener('click', e => {
        const list = document.getElementById('partSuggestions');
        if (!document.getElementById('modalName').contains(e.target)) {
            list.classList.add('hidden');
        }
    });
</script>
<script>
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const modal = document.getElementById('modal');
            const isVisible = !modal.classList.contains('hidden');

            if (isVisible) {
                // Enter внутри модального окна — добавить гофропакет
                e.preventDefault(); // чтобы не было случайных сабмитов форм
                addPart();
                updateTotalCount();
            } else {
                // Enter вне модального окна — открыть модальное окно
                e.preventDefault();
                openModal();
            }
        }
    });
    function updateTotalCount() {
        let total = 0;
        document.querySelectorAll('#tableBody tr').forEach(row => {
            const count = parseInt(row.children[2]?.innerText || 0);
            total += isNaN(count) ? 0 : count;
        });
        const el = document.getElementById('totalCount');
        el.textContent = `${total} шт`;

        // Анимация
        el.classList.remove('scale-110');
        void el.offsetWidth;
        el.classList.add('scale-110');
        setTimeout(() => el.classList.remove('scale-110'), 200);
    }

</script>
</body>
</html>

