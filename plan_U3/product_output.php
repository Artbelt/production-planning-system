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

require_once __DIR__ . '/../auth/includes/db.php';

// === АВТОДОПОЛНЕНИЕ ===
if (isset($_GET['q'])) {
    header('Content-Type: application/json');
    try {
        $pdo = getPdo('plan_u3');
        $query = $_GET['q'];
        $stmt = $pdo->prepare("SELECT DISTINCT filter FROM round_filter_structure WHERE filter LIKE ? LIMIT 10");
        $stmt->execute(["%$query%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === ПОЛУЧЕНИЕ ЗАЯВОК ПО ФИЛЬТРУ ===
if (isset($_GET['orders']) && isset($_GET['filter'])) {
    header('Content-Type: application/json');
    try {
        $pdo = getPdo('plan_u3');
        $filter = $_GET['filter'];
        $stmt = $pdo->prepare("
            SELECT DISTINCT order_number 
            FROM orders 
            WHERE filter = ? 
            AND (hide IS NULL OR hide = 0)
            ORDER BY order_number
        ");
        $stmt->execute([$filter]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// === СОХРАНЕНИЕ В БД ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getPdo('plan_u3');
    $data = json_decode(file_get_contents("php://input"), true);

    $date = $data['date'] ?? null;
    $products = $data['products'] ?? [];

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
    INSERT INTO manufactured_production 
    (date_of_production, name_of_filter, count_of_filters, name_of_order) 
    VALUES (?, ?, ?, ?)
");

    // Подготовка запросов для работы с крышками
    $stmt_caps = $pdo->prepare("SELECT up_cap, down_cap FROM round_filter_structure WHERE filter = ?");
    $stmt_stock_check = $pdo->prepare("SELECT current_quantity FROM cap_stock WHERE cap_name = ?");
    // Используем INSERT ... ON DUPLICATE KEY UPDATE для надежного обновления cap_stock
    $stmt_stock_upsert = $pdo->prepare("
        INSERT INTO cap_stock (cap_name, current_quantity) 
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE 
            current_quantity = VALUES(current_quantity),
            last_updated = CURRENT_TIMESTAMP
    ");
    $stmt_movement = $pdo->prepare("
        INSERT INTO cap_movements 
        (date, cap_name, operation_type, quantity, order_number, filter_name, production_date, user_id, user_name)
        VALUES (?, ?, 'PRODUCTION_OUT', ?, ?, ?, ?, ?, ?)
    ");

    foreach ($products as $p) {
        // Сохраняем в manufactured_production
        $stmt->execute([
            $date,
            $p['name'],
            $p['produced'],
            $p['order_number']
        ]);

        // Получаем информацию о крышках для фильтра
        $stmt_caps->execute([$p['name']]);
        $caps_data = $stmt_caps->fetch(PDO::FETCH_ASSOC);

        if ($caps_data) {
            // Обрабатываем верхнюю крышку
            if (!empty($caps_data['up_cap'])) {
                $cap_name = trim($caps_data['up_cap']);
                $quantity = (int)$p['produced'];
                
                // Проверяем остаток в cap_stock
                $stmt_stock_check->execute([$cap_name]);
                $stock_row = $stmt_stock_check->fetch(PDO::FETCH_ASSOC);
                $current_qty = $stock_row ? (int)$stock_row['current_quantity'] : 0;
                
                // Списываем крышку (уменьшаем остаток)
                $new_qty = max(0, $current_qty - $quantity);
                
                // Обновляем cap_stock (создаем запись, если её нет, или обновляем существующую)
                $stmt_stock_upsert->execute([$cap_name, $new_qty]);
                
                // Записываем движение в cap_movements
                $stmt_movement->execute([
                    $date,
                    $cap_name,
                    $quantity,
                    $p['order_number'],
                    $p['name'],
                    $date,
                    $user_id,
                    $user_name
                ]);
            }

            // Обрабатываем нижнюю крышку
            if (!empty($caps_data['down_cap'])) {
                $cap_name = trim($caps_data['down_cap']);
                $quantity = (int)$p['produced'];
                
                // Проверяем остаток в cap_stock
                $stmt_stock_check->execute([$cap_name]);
                $stock_row = $stmt_stock_check->fetch(PDO::FETCH_ASSOC);
                $current_qty = $stock_row ? (int)$stock_row['current_quantity'] : 0;
                
                // Списываем крышку (уменьшаем остаток)
                $new_qty = max(0, $current_qty - $quantity);
                
                // Обновляем cap_stock (создаем запись, если её нет, или обновляем существующую)
                $stmt_stock_upsert->execute([$cap_name, $new_qty]);
                
                // Записываем движение в cap_movements
                $stmt_movement->execute([
                    $date,
                    $cap_name,
                    $quantity,
                    $p['order_number'],
                    $p['name'],
                    $date,
                    $user_id,
                    $user_name
                ]);
            }
        }
    }

    echo json_encode(["status" => "ok"]);
    exit;
}

// === ПОЛУЧЕНИЕ СПИСКА ЗАЯВОК ===
try {
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
    <title>Сменная продукция</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="bg-gray-100 p-4">
<div class="max-w-2xl mx-auto bg-white rounded-xl shadow-md p-4 space-y-4">
    <h1 class="text-xl font-bold text-center">Сменная продукция</h1>

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
            Добавить изделие
        </button>
        <button onclick="submitForm()" class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700">
            Сохранить смену
        </button>
    </div>
</div>

<div id="modal" class="fixed inset-0 bg-black bg-opacity-40 z-50 overflow-y-auto hidden">
    <div class="flex justify-center items-start min-h-screen pt-12 px-4">
        <div class="bg-white p-6 rounded shadow w-full max-w-sm">
            <h2 class="text-lg font-semibold mb-4">Добавить изделие</h2>

            <!-- Наименование -->
            <label class="block text-sm">Наименование</label>
            <div class="relative mb-2">
                <input type="text" id="modalName" class="w-full border px-3 py-2 rounded" placeholder="AF1600" oninput="autocompleteFilter(this.value); handleFilterInput(this.value)" onblur="updateOrdersList(this.value)">
                <ul id="filterSuggestions" class="absolute z-10 bg-white border w-full rounded shadow hidden max-h-48 overflow-y-auto"></ul>
            </div>

            <!-- Номер заявки -->
            <label class="block text-sm">Номер заявки</label>
            <select id="modalOrder" class="w-full border px-3 py-2 rounded mb-2">
                <option value="">-- Выберите заявку --</option>
                <?php foreach ($orders as $order): ?>
                    <option value="<?= htmlspecialchars($order) ?>"><?= htmlspecialchars($order) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Количество -->
            <label class="block text-sm">Изготовлено</label>
            <input type="number" id="modalCount" class="w-full border px-3 py-2 rounded mb-4" placeholder="150">

            <!-- Кнопки -->
            <div class="flex justify-end gap-2">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-300 rounded">Отмена</button>
                <button onclick="addProduct()" class="px-4 py-2 bg-blue-500 text-white rounded">Добавить</button>
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
        document.getElementById('filterSuggestions').classList.add('hidden');
        // Восстанавливаем полный список заявок при закрытии модального окна
        updateOrdersList('');
    }

    function addProduct() {
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
        const products = [];

        document.querySelectorAll('#tableBody tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            products.push({
                name: cells[0].innerText,
                order_number: cells[1].innerText,
                produced: parseInt(cells[2].innerText)
            });
        });

        if (!date || products.length === 0) {
            alert('Заполните дату и добавьте хотя бы одно изделие');
            return;
        }

        const payload = { date, products };

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

    async function autocompleteFilter(query) {
        const list = document.getElementById('filterSuggestions');
        list.innerHTML = '';
        if (query.length < 2) {
            list.classList.add('hidden');
            // Если поле пустое, показываем все заявки
            updateOrdersList('');
            return;
        }

        try {
            const res = await fetch('?q=' + encodeURIComponent(query));
            const suggestions = await res.json();

            if (!suggestions.length) {
                const li = document.createElement('li');
                li.textContent = 'Нет совпадений';
                li.className = 'px-3 py-2 text-gray-400';
                list.appendChild(li);
                list.classList.remove('hidden');
                // Очищаем список заявок, если нет совпадений
                updateOrdersList('');
                return;
            }

            suggestions.forEach(text => {
                const li = document.createElement('li');
                li.textContent = text;
                li.className = 'px-3 py-2 hover:bg-blue-100 cursor-pointer';
                li.onclick = () => {
                    document.getElementById('modalName').value = text;
                    list.classList.add('hidden');
                    // Обновляем список заявок при выборе фильтра
                    updateOrdersList(text);
                };
                list.appendChild(li);
            });

            list.classList.remove('hidden');
        } catch (err) {
            console.error('Ошибка запроса фильтров:', err);
        }
    }

    async function updateOrdersList(filterName) {
        const select = document.getElementById('modalOrder');
        const currentValue = select.value;
        
        try {
            let orders = [];
            if (filterName && filterName.trim() !== '') {
                const res = await fetch('?orders=1&filter=' + encodeURIComponent(filterName.trim()));
                orders = await res.json();
            } else {
                // Если фильтр не выбран, показываем все заявки
                orders = <?= json_encode($orders) ?>;
            }

            // Очищаем список
            select.innerHTML = '<option value="">-- Выберите заявку --</option>';
            
            // Добавляем заявки
            if (orders.length === 0 && filterName && filterName.trim() !== '') {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '-- Нет заявок с этим фильтром --';
                option.disabled = true;
                select.appendChild(option);
            } else {
                orders.forEach(order => {
                    const option = document.createElement('option');
                    option.value = order;
                    option.textContent = order;
                    if (order === currentValue) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
            }
        } catch (err) {
            console.error('Ошибка загрузки заявок:', err);
        }
    }

    // Обработчик для обновления списка заявок при вводе фильтра
    let filterUpdateTimeout;
    function handleFilterInput(value) {
        clearTimeout(filterUpdateTimeout);
        filterUpdateTimeout = setTimeout(() => {
            if (value && value.trim() !== '') {
                updateOrdersList(value.trim());
            } else {
                updateOrdersList('');
            }
        }, 500); // Задержка 500мс для избежания лишних запросов
    }


    document.addEventListener('click', e => {
        const list = document.getElementById('filterSuggestions');
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
                // Enter внутри модального окна — добавить изделие
                e.preventDefault(); // чтобы не было случайных сабмитов форм
                addProduct();
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
