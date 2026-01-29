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

// Проверяем, есть ли у пользователя доступ к цеху U5
$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, ud.is_active
    FROM auth_user_departments ud
    WHERE ud.user_id = ? AND ud.department_code = 'U5' AND ud.is_active = 1
", [$session['user_id']]);

if (empty($userDepartments)) {
    die('У вас нет доступа к цеху U5');
}

$fullName = $session['full_name'] ?? '';
$userFirstName = $fullName ? trim(explode(' ', $fullName)[0]) : 'Пользователь';
if ($userFirstName === '') $userFirstName = 'Пользователь';

// ========= DB =========
function pdo_u5(): PDO {
    return new PDO(
        "mysql:host=127.0.0.1;dbname=plan_u5;charset=utf8mb4",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

// ========= API: список фильтров по заявке =========
// GET ?filters=1&order=29-35-25  → ["Panther AirMax  T-Rex (без коробки)", ...]
if (isset($_GET['filters']) && isset($_GET['order'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = pdo_u5();
        $order = trim($_GET['order']);
        $stmt = $pdo->prepare("
            SELECT DISTINCT `filter`
            FROM `orders`
            WHERE `order_number` = ?
              AND (`hide` IS NULL OR `hide` = 0)
            ORDER BY `filter`
        ");
        $stmt->execute([$order]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        echo json_encode([]);
    }
    exit;
}

// ========= API: строгая проверка "фильтр есть в этой заявке" =========
// GET ?check=1&order=29-35-25&filter=...
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $order  = trim($_GET['order']  ?? '');
    $filter = $_GET['filter'] ?? ''; // берём как есть (без trim), чтобы не терять пробелы
    if ($order === '' || $filter === '') { echo json_encode(['exists'=>false]); exit; }
    try {
        $pdo = pdo_u5();
        $stmt = $pdo->prepare("
            SELECT 1
            FROM `orders`
            WHERE BINARY `order_number` = BINARY ?
              AND BINARY `filter`       = BINARY ?
              AND (`hide` IS NULL OR `hide` = 0)
            LIMIT 1
        ");
        $stmt->execute([$order, $filter]);
        echo json_encode(['exists' => (bool)$stmt->fetchColumn()]);
    } catch (Throwable $e) {
        echo json_encode(['exists' => false]);
    }
    exit;
}

// ========= API: список фильтров с остатками (активные заявки) =========
// GET ?filters_with_balance=1 → ["AF1600", "Panther AirMax...", ...]
if (isset($_GET['filters_with_balance'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = pdo_u5();
        $stmt = $pdo->query("
            SELECT DISTINCT o.filter
            FROM orders o
            LEFT JOIN manufactured_production mp
                ON BINARY mp.name_of_order = BINARY o.order_number
                AND BINARY mp.name_of_filter = BINARY o.filter
            WHERE (o.hide IS NULL OR o.hide = 0)
            GROUP BY o.order_number, o.filter, o.`count`
            HAVING (o.`count` - COALESCE(SUM(mp.count_of_filters), 0)) > 0
            ORDER BY o.filter
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        echo json_encode([]);
    }
    exit;
}

// ========= API: остатки по фильтру из всех активных заявок =========
// GET ?filter_balance=1&filter=AF1600 → [{"order_number":"29-35-25","plan_count":100,"produced_count":50,"remaining_count":50}, ...]
if (isset($_GET['filter_balance'])) {
    header('Content-Type: application/json; charset=utf-8');
    $filter = $_GET['filter'] ?? '';
    if ($filter === '') { echo json_encode([]); exit; }
    try {
        $pdo = pdo_u5();
        $stmt = $pdo->prepare("
            SELECT 
                o.order_number,
                o.`count` as plan_count,
                COALESCE(SUM(mp.count_of_filters), 0) as produced_count,
                (o.`count` - COALESCE(SUM(mp.count_of_filters), 0)) as remaining_count
            FROM `orders` o
            LEFT JOIN `manufactured_production` mp 
                ON BINARY mp.name_of_order = BINARY o.order_number 
                AND BINARY mp.name_of_filter = BINARY o.filter
            WHERE BINARY o.filter = BINARY ?
              AND (o.hide IS NULL OR o.hide = 0)
            GROUP BY o.order_number, o.`count`
            HAVING remaining_count > 0
            ORDER BY o.order_number
        ");
        $stmt->execute([$filter]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Преобразуем в числовые типы
        foreach ($result as &$row) {
            $row['plan_count'] = (int)$row['plan_count'];
            $row['produced_count'] = (int)$row['produced_count'];
            $row['remaining_count'] = (int)$row['remaining_count'];
        }
        echo json_encode($result);
    } catch (Throwable $e) {
        echo json_encode([]);
    }
    exit;
}

// ========= API: сохранение смены =========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = pdo_u5();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $date     = $data['date']     ?? null;
        $brigade  = $data['brigade']  ?? null;
        $products = $data['products'] ?? [];

        if (!$date || !$brigade || !is_array($products) || count($products) === 0) {
            echo json_encode(['status'=>'error','message'=>'Пустые данные']); exit;
        }

        // Строгая серверная валидация каждой пары (order, filter)
        $chk = $pdo->prepare("
            SELECT 1 FROM `orders`
            WHERE BINARY `order_number` = BINARY ?
              AND BINARY `filter`       = BINARY ?
              AND (`hide` IS NULL OR `hide` = 0)
            LIMIT 1
        ");
        $invalid = [];
        foreach ($products as $p) {
            // НЕ трогаем пробелы в name/order_number
            $name  = array_key_exists('name', $p) ? (string)$p['name'] : '';
            $order = array_key_exists('order_number', $p) ? (string)$p['order_number'] : '';
            if ($name === '' || $order === '') { $invalid[] = $p; continue; }
            $chk->execute([$order, $name]);
            if (!$chk->fetchColumn()) {
                $invalid[] = ['name'=>$name,'order_number'=>$order];
            }
        }
        if ($invalid) {
            echo json_encode(['status'=>'error','code'=>'INVALID_ITEMS','invalid'=>$invalid]); exit;
        }

        // Сохранение
        $ins = $pdo->prepare("
            INSERT INTO `manufactured_production`
            (`date_of_production`, `name_of_filter`, `count_of_filters`, `name_of_order`, `team`)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($products as $p) {
            $ins->execute([
                $date,
                (string)$p['name'],
                (int)$p['produced'],
                (string)$p['order_number'],
                (int)$brigade,
            ]);
        }

        echo json_encode(['status'=>'ok']);
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    }
    exit;
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сменная продукция</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="bg-gray-100 p-4" data-user-name="<?= htmlspecialchars($userFirstName) ?>">
<div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md p-4 space-y-4">
    <h1 class="text-xl font-bold text-center">Сменная продукция</h1>

    <div>
        <label class="block text-sm font-medium">Дата производства</label>
        <input type="date" id="prodDate" class="w-full border rounded px-3 py-2" value="<?= htmlspecialchars($today) ?>">
    </div>

    <div class="flex justify-between items-end mt-2 flex-wrap gap-2">
        <div>
            <label class="block text-sm font-medium">Бригада</label>
            <div class="flex gap-4 mt-2">
                <label class="flex items-center gap-1"><input type="radio" name="brigade" value="1" checked> 1</label>
                <label class="flex items-center gap-1"><input type="radio" name="brigade" value="2"> 2</label>
                <label class="flex items-center gap-1"><input type="radio" name="brigade" value="3"> 3</label>
                <label class="flex items-center gap-1"><input type="radio" name="brigade" value="4"> 4</label>                
            </div>
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
                <th class="border px-2 py-1">!</th>
                <th class="border px-2 py-1">Удалить</th>
            </tr>
            </thead>
            <tbody id="tableBody"></tbody>
        </table>
    </div>

    <div class="space-y-2">
        <button onclick="openModal()" class="w-full bg-blue-500 text-white py-2 rounded hover:bg-blue-600">
            Добавить изделие
        </button>
        <button type="button" id="btnSaveShift" onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700 disabled:opacity-60 disabled:cursor-not-allowed">
            Сохранить смену
        </button>
    </div>
</div>

<!-- MODAL: сначала фильтр → заявки с остатками → заявка → количество -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-md">
            <h2 class="text-lg font-semibold mb-4">Добавить изделие</h2>

            <!-- 1. Фильтр -->
            <label class="block text-sm font-medium">1. Наименование (фильтр)</label>
            <select id="modalName" class="w-full border px-3 py-2 rounded mb-3">
                <option value="">— Выберите фильтр —</option>
            </select>

            <!-- 2. Заявки с остатками по выбранному фильтру -->
            <div id="ordersBlock" class="hidden mb-3">
                <label class="block text-sm font-medium mb-1">2. Активные заявки с остатком по этому фильтру</label>
                <div id="ordersList" class="border rounded p-2 bg-gray-50 max-h-40 overflow-y-auto space-y-1 text-sm"></div>
                <input type="hidden" id="modalOrder" value="">
            </div>

            <!-- 3. Количество -->
            <div id="countBlock" class="hidden mb-3">
                <label class="block text-sm font-medium">3. Изготовлено, шт</label>
                <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded" placeholder="150" min="1">
            </div>

            <!-- Оценка корректности выбора -->
            <div id="correctnessBlock" class="hidden mb-4 p-3 rounded text-sm" role="alert"></div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button type="button" onclick="addProduct()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
            </div>
        </div>
    </div>
</div>

<script>
    const userName = document.body.dataset.userName || 'Пользователь';

    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function statusCell(content, extraClass='') {
        return `<span class="inline-flex items-center gap-1 ${extraClass}">${content}</span>`;
    }

    let currentFilterBalance = [];

    async function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        document.getElementById('ordersBlock').classList.add('hidden');
        document.getElementById('countBlock').classList.add('hidden');
        document.getElementById('correctnessBlock').classList.add('hidden');
        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        currentFilterBalance = [];
        await loadFiltersWithBalance();
        setTimeout(() => document.getElementById('modalName')?.focus(), 50);
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modalName').value = '';
        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        document.getElementById('ordersBlock').classList.add('hidden');
        document.getElementById('countBlock').classList.add('hidden');
        document.getElementById('correctnessBlock').classList.add('hidden');
        currentFilterBalance = [];
    }

    async function loadFiltersWithBalance() {
        const sel = document.getElementById('modalName');
        sel.innerHTML = '<option value="">— Загрузка… —</option>';
        try {
            const res = await fetch('?filters_with_balance=1');
            const arr = await res.json();
            sel.innerHTML = '<option value="">— Выберите фильтр —</option>';
            if (arr && arr.length) {
                for (const f of arr) {
                    const o = document.createElement('option');
                    o.value = f;
                    o.textContent = f;
                    sel.appendChild(o);
                }
            }
        } catch (e) {
            sel.innerHTML = '<option value="">Ошибка загрузки</option>';
        }
    }

    async function onFilterChange() {
        const filter = document.getElementById('modalName').value;
        const ordersBlock = document.getElementById('ordersBlock');
        const ordersList = document.getElementById('ordersList');
        const countBlock = document.getElementById('countBlock');
        const correctnessBlock = document.getElementById('correctnessBlock');

        document.getElementById('modalOrder').value = '';
        document.getElementById('modalCount').value = '';
        countBlock.classList.add('hidden');
        correctnessBlock.classList.add('hidden');
        currentFilterBalance = [];

        if (!filter) {
            ordersBlock.classList.add('hidden');
            return;
        }

        ordersList.innerHTML = 'Загрузка…';
        ordersBlock.classList.remove('hidden');
        try {
            const res = await fetch(`?filter_balance=1&filter=${encodeURIComponent(filter)}`);
            const balance = await res.json();
            currentFilterBalance = balance || [];
        } catch (e) {
            currentFilterBalance = [];
        }

        if (currentFilterBalance.length === 0) {
            ordersList.innerHTML = '<span class="text-gray-500">Нет активных заявок с остатком по этому фильтру</span>';
            return;
        }

        ordersList.innerHTML = '';
        for (const item of currentFilterBalance) {
            const order = item.order_number;
            const rem = item.remaining_count;
            const plan = item.plan_count;
            const produced = item.produced_count;
            const div = document.createElement('button');
            div.type = 'button';
            div.className = 'w-full text-left px-2 py-1.5 rounded border border-transparent hover:bg-blue-100 hover:border-blue-300 focus:outline-none focus:ring-1 focus:ring-blue-400 order-item';
            div.dataset.order = order;
            div.textContent = `Заявка ${order} — осталось ${rem} из ${plan} (сделано ${produced})`;
            div.addEventListener('click', () => selectOrder(order));
            ordersList.appendChild(div);
        }
        if (currentFilterBalance.length === 1) {
            selectOrder(currentFilterBalance[0].order_number);
        }
    }

    function selectOrder(order) {
        document.getElementById('modalOrder').value = order;
        document.querySelectorAll('.order-item').forEach(el => {
            el.classList.toggle('bg-blue-100', el.dataset.order === order);
            el.classList.toggle('border-blue-400', el.dataset.order === order);
        });
        document.getElementById('countBlock').classList.remove('hidden');
        document.getElementById('modalCount').focus();
        updateCorrectness();
    }

    function updateCorrectness() {
        const block = document.getElementById('correctnessBlock');
        const filter = document.getElementById('modalName').value;
        const order = document.getElementById('modalOrder').value;
        const count = parseInt(document.getElementById('modalCount').value, 10) || 0;

        block.classList.remove('hidden');
        block.removeAttribute('class');
        block.className = 'mb-4 p-3 rounded text-sm ';

        if (!order || !filter) {
            block.classList.add('hidden');
            return;
        }

        const item = currentFilterBalance.find(x => x.order_number === order);
        if (!item) {
            block.classList.add('hidden');
            return;
        }

        const remaining = item.remaining_count;
        const totalRemaining = currentFilterBalance.reduce((s, x) => s + x.remaining_count, 0);
        const multi = currentFilterBalance.length > 1;

        if (count <= 0) {
            block.textContent = userName + ', введите количество изготовленных шт.';
            block.classList.add('bg-gray-100', 'text-gray-700');
            return;
        }

        if (count > remaining) {
            if (multi) {
                block.classList.add('bg-red-200', 'border-2', 'border-red-600', 'text-red-900', 'font-semibold', 'text-base', 'py-4', 'px-4');
                const other = currentFilterBalance.filter(x => x.order_number !== order);
                const parts = other.map(x => x.order_number + ' (' + x.remaining_count + ')').join(', ');
                block.innerHTML = '<span class="text-lg">⚠️ ВНИМАНИЕ!</span><br>' + userName + ', вы вводите больше остатка по заявке ' + escapeHtml(order) + ' (осталось ' + remaining + ' шт, введено ' + count + ' шт). ' +
                    '<strong>Скорее всего, вы относите продукцию не к той заявке!</strong> Разделите между заявками: ' + escapeHtml(order) + ' (макс. ' + remaining + ') и ' + escapeHtml(parts) + '.';
            } else {
                block.classList.add('bg-amber-50', 'border', 'border-amber-300', 'text-amber-900');
                block.innerHTML = userName + ', вы вводите больше остатка по заявке ' + escapeHtml(order) + ' (осталось ' + remaining + ' шт, введено ' + count + ' шт). По этому фильтру только одна заявка — возможно, так и задумано.';
            }
            return;
        }

        if (multi && count >= totalRemaining) {
            block.classList.add('bg-amber-100', 'border', 'border-amber-300', 'text-amber-900');
            block.innerHTML = userName + ', вы вводите ' + count + ' шт — этого хватает, чтобы закрыть <strong>все</strong> заявки по этому фильтру (' + totalRemaining + ' шт). Сейчас продукция отнесена только к заявке ' + escapeHtml(order) + '. Убедитесь, что это верно.';
            return;
        }

        if (multi && count === remaining) {
            block.classList.add('bg-green-50', 'border', 'border-green-300', 'text-green-800');
            block.innerHTML = `✅ Закроет заявку ${escapeHtml(order)} полностью. По другим заявкам остаётся остаток — не забудьте внести их отдельно.`;
            return;
        }

        if (count === remaining && !multi) {
            block.classList.add('bg-green-50', 'border', 'border-green-300', 'text-green-800');
            block.textContent = `✅ Закроет заявку ${order} полностью (осталось ${remaining} шт).`;
            return;
        }

        block.classList.add('bg-gray-100', 'text-gray-700');
        block.textContent = `Остаток по заявке ${order}: ${remaining - count} шт.`;
    }

    document.getElementById('modalName').addEventListener('change', onFilterChange);
    document.getElementById('modalCount').addEventListener('input', updateCorrectness);

    function addProduct() {
        const order = document.getElementById('modalOrder').value;
        const name = document.getElementById('modalName').value;
        const count = document.getElementById('modalCount').value.trim();

        if (!order || !name || !count) {
            alert(userName + ', выберите фильтр, заявку и введите количество.');
            return;
        }
        const countNum = parseInt(count, 10);
        if (countNum <= 0) {
            alert(userName + ', количество должно быть больше 0.');
            return;
        }

        const item = currentFilterBalance.find(x => x.order_number === order);
        if (item && countNum > item.remaining_count) {
            const multi = currentFilterBalance.length > 1;
            let msg;
            if (multi) {
                msg = '⚠️ ВНИМАНИЕ! ' + userName + ', вы вносите больше остатка по одной заявке (введено ' + countNum + ', осталось ' + item.remaining_count + '). По этому фильтру есть другие заявки с остатком — скорее всего, нужно разделить продукцию!\n\nПродолжить всё равно?';
            } else {
                msg = userName + ', превышение остатка (введено ' + countNum + ', осталось ' + item.remaining_count + '). Альтернативных заявок нет. Продолжить?';
            }
            if (!confirm(msg)) return;
        }

        const row = document.createElement('tr');
        row.setAttribute('data-valid', 'pending');
        row.dataset.name = name;
        row.dataset.order = order;

        row.innerHTML = `
    <td class="border px-2 py-1 whitespace-pre" data-col="name">${escapeHtml(name)}</td>
    <td class="border px-2 py-1"               data-col="order">${escapeHtml(order)}</td>
    <td class="border px-2 py-1"               data-col="count">${escapeHtml(count)}</td>
    <td class="border px-2 py-1 text-center"   data-status>${statusCell('Проверка…', 'text-gray-500')}</td>
    <td class="border px-2 py-1 text-center">
      <button type="button" onclick="this.closest('tr').remove(); updateTotalCount();" class="text-red-500">✖</button>
    </td>`;
        document.getElementById('tableBody').appendChild(row);
        closeModal();
        updateTotalCount();
        validateRow(row);
    }

    async function validateRow(row){
        const name  = row.dataset.name;   // точная строка
        const order = row.dataset.order;  // точная строка
        const cell  = row.querySelector('[data-status]');

        try{
            const res = await fetch(`?check=1&order=${encodeURIComponent(order)}&filter=${encodeURIComponent(name)}`);
            const js = await res.json();
            if(js && js.exists){
                row.setAttribute('data-valid','1');
                cell.innerHTML = statusCell('✅','text-green-600 font-semibold');
            }else{
                row.setAttribute('data-valid','0');
                cell.innerHTML = statusCell('❗ Нет в заявке','text-red-600 font-semibold');
            }
        }catch(e){
            row.setAttribute('data-valid','0');
            cell.innerHTML = statusCell('❗ Ошибка проверки','text-red-600 font-semibold');
        }
    }

    async function submitForm(){
        const btn = document.getElementById('btnSaveShift');
        if (btn.disabled) return;

        const date = document.getElementById('prodDate').value;
        const brigade = document.querySelector('input[name="brigade"]:checked').value;
        const rows = Array.from(document.querySelectorAll('#tableBody tr'));

        if(!date || rows.length===0){ alert(userName + ', заполните дату и добавьте хотя бы одно изделие'); return; }

        // Запрет сохранять при несоответствиях
        const bad = rows.filter(r => r.getAttribute('data-valid') !== '1');
        if(bad.length){
            alert(userName + ', есть позиции, которых нет в выбранных заявках. Исправьте или удалите.');
            return;
        }

        btn.disabled = true;
        const prevText = btn.textContent;
        btn.textContent = 'Сохранение…';
        let submitted = false;

        try {
            const products = rows.map(row => ({
                name:         row.dataset.name,          // точные строки
                order_number: row.dataset.order,
                produced:     parseInt(row.querySelector('[data-col="count"]').textContent)
            }));

            const res = await fetch(window.location.href, {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({date, brigade: parseInt(brigade), products})
            });
            const js = await res.json();
            if(js.status==='ok'){
                submitted = true;
                alert('Смена успешно сохранена');
                location.reload();
                return;
            }
            if(js.code==='INVALID_ITEMS'){
                const inv = new Set((js.invalid||[]).map(x=>`${x.order_number}||${x.name}`));
                document.querySelectorAll('#tableBody tr').forEach(row=>{
                    const k = `${row.dataset.order}||${row.dataset.name}`;
                    if(inv.has(k)){
                        row.setAttribute('data-valid','0');
                        row.querySelector('[data-status]').innerHTML = statusCell('❗ Нет в заявке','text-red-600 font-semibold');
                    }
                });
                alert(userName + ', сервер отклонил сохранение: есть позиции, которых нет в заявках.');
            }else{
                alert(userName + ', ошибка при сохранении: ' + (js.message || 'неизвестно'));
            }
        } catch (e) {
            alert(userName + ', ошибка при сохранении: ' + (e.message || 'неизвестно'));
        } finally {
            if (!submitted) {
                btn.disabled = false;
                btn.textContent = prevText;
            }
        }
    }

    function updateTotalCount(){
        let total = 0;
        document.querySelectorAll('#tableBody tr').forEach(row=>{
            const n = parseInt(row.querySelector('[data-col="count"]').textContent || '0');
            if(!isNaN(n)) total += n;
        });
        document.getElementById('totalCount').textContent = `${total} шт`;
    }

    // UX: Enter — внутри модалки добавляет, снаружи открывает
    document.addEventListener('keydown', function(e){
        if(e.key==='Enter'){
            const modal = document.getElementById('modal');
            const isVisible = !modal.classList.contains('hidden');
            if(isVisible){
                e.preventDefault();
                addProduct();
            }else{
                e.preventDefault();
                openModal();
            }
        }
    });
</script>
</body>
</html>
