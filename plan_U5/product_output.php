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

// ========= API: остатки по фильтру из всех активных заявок =========
// GET ?filter_balance=1&filter=AF1600 → [{"order":"29-35-25","plan":100,"produced":50,"remaining":50}, ...]
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

// ========= Данные для формы =========
try {
    $pdo = pdo_u5();
    $orders = $pdo->query("
        SELECT DISTINCT `order_number`
        FROM `orders`
        WHERE (`hide` IS NULL OR `hide` = 0)
        ORDER BY `order_number`
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $orders = [];
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
<body class="bg-gray-100 p-4">
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
        <button onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">
            Сохранить смену
        </button>
    </div>
</div>

<!-- MODAL -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-sm">
            <h2 class="text-lg font-semibold mb-4">Добавить изделие</h2>

            <!-- Номер заявки -->
            <label class="block text-sm">Номер заявки</label>
            <select id="modalOrder" class="w-full border px-3 py-2 rounded mb-2">
                <option value="">-- Выберите заявку --</option>
                <?php foreach ($orders as $order): ?>
                    <option value="<?= htmlspecialchars($order) ?>"><?= htmlspecialchars($order) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Наименование (строго из выбранной заявки) -->
            <label class="block text-sm">Наименование</label>
            <select id="modalName" class="w-full border px-3 py-2 rounded mb-2" disabled>
                <option value="">Сначала выберите заявку</option>
            </select>

            <!-- Блок подсказок об остатках -->
            <div id="filterBalanceHint" class="hidden mb-2 p-3 bg-blue-50 border border-blue-200 rounded text-sm">
                <div class="font-semibold text-blue-800 mb-1">Остатки по этому фильтру в заявках:</div>
                <div id="balanceList" class="space-y-1 text-blue-700"></div>
            </div>

            <!-- Количество -->
            <label class="block text-sm">Изготовлено</label>
            <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded mb-2" placeholder="150" min="1">
            
            <!-- Предупреждение о превышении остатка -->
            <div id="countWarning" class="hidden mb-2 p-2 bg-yellow-50 border border-yellow-300 rounded text-sm text-yellow-800"></div>
            
            <!-- Предупреждение о других заявках -->
            <div id="otherOrdersWarning" class="hidden mb-4 p-2 bg-orange-50 border border-orange-300 rounded text-sm text-orange-800"></div>

            <div class="flex justify-end gap-2">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button onclick="addProduct()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
            </div>
        </div>
    </div>
</div>

<script>
    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function statusCell(content, extraClass='') {
        return `<span class="inline-flex items-center gap-1 ${extraClass}">${content}</span>`;
    }

    function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        const order = document.getElementById('modalOrder').value;
        loadFiltersForOrder(order);
        setTimeout(()=>document.getElementById('modalOrder')?.focus(), 50);
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modalOrder').value = '';
        const sel = document.getElementById('modalName');
        sel.innerHTML = '<option value="">Сначала выберите заявку</option>';
        sel.disabled = true;
        document.getElementById('modalCount').value = '';
        hideBalanceHints();
        currentFilterBalance = [];
    }

    // Подгружаем точные строки filter для выбранной заявки
    async function loadFiltersForOrder(order){
        const sel = document.getElementById('modalName');
        sel.innerHTML=''; sel.disabled = true;
        hideBalanceHints();
        if(!order){
            sel.innerHTML = '<option value="">Сначала выберите заявку</option>';
            return;
        }
        try{
            const res = await fetch(`?filters=1&order=${encodeURIComponent(order)}`);
            const arr = await res.json();
            if(!arr || arr.length===0){
                sel.innerHTML = '<option value="">В этой заявке нет позиций</option>';
                return;
            }
            for(const f of arr){
                const o = document.createElement('option');
                o.value = f; o.textContent = f;
                sel.appendChild(o);
            }
            sel.disabled = false;
        }catch(e){
            sel.innerHTML = '<option value="">Ошибка загрузки</option>';
        }
    }
    document.getElementById('modalOrder').addEventListener('change', async e=>{
        await loadFiltersForOrder(e.target.value);
        // Если уже был выбран фильтр, обновляем подсказки
        const selectedFilter = document.getElementById('modalName').value;
        if(selectedFilter){
            await loadFilterBalance(selectedFilter);
        }
    });

    // Скрываем все подсказки
    function hideBalanceHints(){
        document.getElementById('filterBalanceHint').classList.add('hidden');
        document.getElementById('countWarning').classList.add('hidden');
        document.getElementById('otherOrdersWarning').classList.add('hidden');
    }

    // Загружаем остатки по фильтру из всех активных заявок
    let currentFilterBalance = [];
    async function loadFilterBalance(filter){
        if(!filter){
            hideBalanceHints();
            currentFilterBalance = [];
            return;
        }
        try{
            const res = await fetch(`?filter_balance=1&filter=${encodeURIComponent(filter)}`);
            const balance = await res.json();
            currentFilterBalance = balance || [];
            showFilterBalance();
            checkCountWarning();
        }catch(e){
            hideBalanceHints();
            currentFilterBalance = [];
        }
    }

    // Показываем подсказки об остатках
    function showFilterBalance(){
        const hintDiv = document.getElementById('filterBalanceHint');
        const balanceList = document.getElementById('balanceList');
        
        if(currentFilterBalance.length === 0){
            hintDiv.classList.add('hidden');
            return;
        }
        
        const selectedOrder = document.getElementById('modalOrder').value;
        let html = '';
        let hasOtherOrders = false;
        
        for(const item of currentFilterBalance){
            const order = item.order_number;
            const remaining = item.remaining_count;
            const plan = item.plan_count;
            const produced = item.produced_count;
            const isSelected = order === selectedOrder;
            
            if(!isSelected){
                hasOtherOrders = true;
            }
            
            const statusClass = isSelected ? 'font-semibold text-blue-900' : '';
            const prefix = isSelected ? '' : '  ';
            html += `<div class="${statusClass}">${prefix} ${escapeHtml(order)}: ост. ${remaining} из ${plan} (сделано ${produced})</div>`;
        }
        
        balanceList.innerHTML = html;
        hintDiv.classList.remove('hidden');
        
        // Предупреждение о других заявках
        const otherWarning = document.getElementById('otherOrdersWarning');
        if(hasOtherOrders && selectedOrder){
            const otherCount = currentFilterBalance.filter(x => x.order_number !== selectedOrder).length;
            const otherTotal = currentFilterBalance
                .filter(x => x.order_number !== selectedOrder)
                .reduce((sum, x) => sum + x.remaining_count, 0);
            otherWarning.innerHTML = `⚠️ ВНИМАНИЕ: Этот фильтр есть еще в ${otherCount} активной заявке(ах) с остатком ${otherTotal} шт. Убедитесь, что правильно распределяете продукцию между заявками!`;
            otherWarning.classList.remove('hidden');
        }else{
            otherWarning.classList.add('hidden');
        }
    }

    // Проверяем введенное количество и показываем предупреждения
    function checkCountWarning(){
        const countInput = document.getElementById('modalCount');
        const count = parseInt(countInput.value) || 0;
        const warningDiv = document.getElementById('countWarning');
        const selectedOrder = document.getElementById('modalOrder').value;
        const selectedFilter = document.getElementById('modalName').value;
        
        if(count <= 0 || !selectedOrder || !selectedFilter){
            warningDiv.classList.add('hidden');
            return;
        }
        
        // Находим остаток по выбранной заявке
        const currentOrderBalance = currentFilterBalance.find(x => x.order_number === selectedOrder);
        
        if(!currentOrderBalance){
            warningDiv.classList.add('hidden');
            return;
        }
        
        const remaining = currentOrderBalance.remaining_count;
        
        if(count > remaining){
            warningDiv.innerHTML = `⚠️ Превышение остатка! По заявке ${escapeHtml(selectedOrder)} осталось только ${remaining} шт, а вы вводите ${count} шт. Проверьте правильность!`;
            warningDiv.className = 'mb-2 p-2 bg-yellow-50 border border-yellow-300 rounded text-sm text-yellow-800';
            warningDiv.classList.remove('hidden');
        }else if(count === remaining){
            warningDiv.innerHTML = `✅ Это закроет заявку ${escapeHtml(selectedOrder)} полностью (осталось ${remaining} шт)`;
            warningDiv.className = 'mb-2 p-2 bg-green-50 border border-green-300 rounded text-sm text-green-800';
            warningDiv.classList.remove('hidden');
        }else{
            warningDiv.classList.add('hidden');
        }
    }

    // Подписываемся на изменения фильтра и количества
    document.getElementById('modalName').addEventListener('change', function(e){
        loadFilterBalance(e.target.value);
    });
    
    document.getElementById('modalCount').addEventListener('input', function(){
        checkCountWarning();
    });

    function addProduct(){
        const order = document.getElementById('modalOrder').value;
        const name  = document.getElementById('modalName').value; // берём как есть, без trim!
        const count = document.getElementById('modalCount').value.trim();

        if(!order || !name || !count){ alert('Заполните все поля!'); return; }
        const countNum = parseInt(count);
        if(countNum <= 0){ alert('Количество должно быть > 0'); return; }

        // Дополнительная проверка на превышение остатка
        const currentOrderBalance = currentFilterBalance.find(x => x.order_number === order);
        if(currentOrderBalance && countNum > currentOrderBalance.remaining_count){
            const confirmMsg = `ВНИМАНИЕ! Вы вводите ${countNum} шт, а по заявке ${order} осталось только ${currentOrderBalance.remaining_count} шт.\n\n` +
                              `Возможно, часть продукции нужно отнести к другой заявке?\n\n` +
                              `Продолжить все равно?`;
            if(!confirm(confirmMsg)){
                return;
            }
        }

        const row = document.createElement('tr');
        row.setAttribute('data-valid','pending'); // pending|1|0

        // Храним точные значения (включая двойные пробелы и пр.)
        row.dataset.name  = name;
        row.dataset.order = order;

        row.innerHTML = `
    <td class="border px-2 py-1 whitespace-pre" data-col="name">${escapeHtml(name)}</td>
    <td class="border px-2 py-1"               data-col="order">${escapeHtml(order)}</td>
    <td class="border px-2 py-1"               data-col="count">${escapeHtml(count)}</td>
    <td class="border px-2 py-1 text-center"   data-status>${statusCell('Проверка…','text-gray-500')}</td>
    <td class="border px-2 py-1 text-center">
      <button onclick="this.closest('tr').remove(); updateTotalCount();" class="text-red-500">✖</button>
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
        const date = document.getElementById('prodDate').value;
        const brigade = document.querySelector('input[name="brigade"]:checked').value;
        const rows = Array.from(document.querySelectorAll('#tableBody tr'));

        if(!date || rows.length===0){ alert('Заполните дату и добавьте хотя бы одно изделие'); return; }

        // Запрет сохранять при несоответствиях
        const bad = rows.filter(r => r.getAttribute('data-valid') !== '1');
        if(bad.length){
            alert('Есть позиции, которых нет в выбранных заявках. Исправьте или удалите.');
            return;
        }

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
            alert('Смена успешно сохранена');
            location.reload();
        }else if(js.code==='INVALID_ITEMS'){
            const inv = new Set((js.invalid||[]).map(x=>`${x.order_number}||${x.name}`));
            document.querySelectorAll('#tableBody tr').forEach(row=>{
                const k = `${row.dataset.order}||${row.dataset.name}`;
                if(inv.has(k)){
                    row.setAttribute('data-valid','0');
                    row.querySelector('[data-status]').innerHTML = statusCell('❗ Нет в заявке','text-red-600 font-semibold');
                }
            });
            alert('Сервер отклонил сохранение: есть позиции, которых нет в заявках.');
        }else{
            alert('Ошибка при сохранении: ' + (js.message || 'неизвестно'));
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
