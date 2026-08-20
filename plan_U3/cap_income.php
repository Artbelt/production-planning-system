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

require_once('tools/tools.php');
require_once('settings.php');
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u3');

$user_id = $session['user_id'];
$user_name = $session['full_name'] ?? 'Пользователь';

require_once('cap_db_init.php');

// === AJAX: заявки, содержащие указанную крышку ===
if (isset($_GET['orders'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $cap = trim((string)($_GET['cap'] ?? ''));
        $archive = isset($_GET['archive']) && $_GET['archive'] == '1';

        if ($cap === '') {
            echo json_encode(['orders' => [], 'has_archive' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $hideCondition = $archive ? 'o.hide = 1' : '(o.hide IS NULL OR o.hide = 0)';
        $capNorm = mb_strtoupper($cap, 'UTF-8');

        $stmt = $pdo->prepare("
            SELECT DISTINCT o.order_number
            FROM orders o
            INNER JOIN round_filter_structure rfs ON rfs.filter = o.`filter`
            WHERE $hideCondition
              AND (
                    UPPER(TRIM(COALESCE(rfs.up_cap, ''))) = ?
                 OR UPPER(TRIM(COALESCE(rfs.down_cap, ''))) = ?
              )
            ORDER BY o.order_number DESC
        ");
        $stmt->execute([$capNorm, $capNorm]);
        $orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $hasArchive = false;
        if (!$archive) {
            $stmtArch = $pdo->prepare("
                SELECT 1
                FROM orders o
                INNER JOIN round_filter_structure rfs ON rfs.filter = o.`filter`
                WHERE o.hide = 1
                  AND (
                        UPPER(TRIM(COALESCE(rfs.up_cap, ''))) = ?
                     OR UPPER(TRIM(COALESCE(rfs.down_cap, ''))) = ?
                  )
                LIMIT 1
            ");
            $stmtArch->execute([$capNorm, $capNorm]);
            $hasArchive = (bool)$stmtArch->fetchColumn();
        }

        echo json_encode([
            'orders' => $orders,
            'has_archive' => $hasArchive,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['orders' => [], 'has_archive' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$caps_list = [];
$stmt = $pdo->query("SELECT DISTINCT cap_name FROM cap_stock ORDER BY cap_name");
if ($stmt) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $caps_list[] = $row['cap_name'];
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Прием крышек на склад - U3</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 10px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 15px;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #6495ed;
            padding-bottom: 6px;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 20px;
        }
        .form-group {
            margin-bottom: 12px;
        }
        label {
            display: block;
            margin-bottom: 3px;
            font-weight: bold;
            color: #555;
            font-size: 13px;
        }
        input[type="date"],
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
            box-sizing: border-box;
        }
        select.archive-mode {
            border-color: #d97706;
            background: #fffbeb;
        }
        button {
            background: #6495ed;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            width: 100%;
            margin-top: 5px;
        }
        button:hover {
            background: #4169e1;
        }
        .message {
            padding: 10px;
            margin-bottom: 12px;
            border-radius: 4px;
            display: none;
            font-size: 13px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        #cap_name {
            font-size: 14px;
        }
        #archiveHint {
            display: none;
            margin-top: 4px;
            font-size: 12px;
            color: #b45309;
        }
        #archiveHint.visible {
            display: block;
        }
    </style>
    <script>
        const capsList = <?php echo json_encode($caps_list, JSON_UNESCAPED_UNICODE); ?>;
        let archiveMode = false;
        let ordersUpdateTimeout = null;

        function setupAutocomplete() {
            const input = document.getElementById('cap_name');
            const datalist = document.getElementById('caps_datalist');

            capsList.forEach(cap => {
                const option = document.createElement('option');
                option.value = cap;
                datalist.appendChild(option);
            });
        }

        function resetOrdersSelect(placeholderText) {
            const select = document.getElementById('order_number');
            select.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = placeholderText;
            select.appendChild(placeholder);
            select.classList.remove('archive-mode');
            document.getElementById('archiveHint').classList.remove('visible');
        }

        async function updateOrdersList(capName, useArchive) {
            const select = document.getElementById('order_number');
            const hint = document.getElementById('archiveHint');
            const cap = (capName || '').trim();
            archiveMode = !!useArchive;

            if (cap === '') {
                archiveMode = false;
                resetOrdersSelect('-- Сначала укажите крышку --');
                return;
            }

            try {
                const url = '?orders=1&cap=' + encodeURIComponent(cap)
                    + (archiveMode ? '&archive=1' : '');
                const res = await fetch(url);
                const data = await res.json();
                const orders = Array.isArray(data.orders) ? data.orders : [];
                const hasArchive = !!data.has_archive;

                select.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = archiveMode
                    ? '-- Выберите архивную заявку --'
                    : '-- Выберите заявку --';
                select.appendChild(placeholder);

                if (orders.length === 0) {
                    const empty = document.createElement('option');
                    empty.value = '';
                    empty.disabled = true;
                    empty.textContent = archiveMode
                        ? '-- Нет архивных заявок с этой крышкой --'
                        : '-- Нет актуальных заявок с этой крышкой --';
                    select.appendChild(empty);

                    if (!archiveMode && hasArchive) {
                        const expand = document.createElement('option');
                        expand.value = '__archive__';
                        expand.textContent = '▾ Показать архивные заявки с этой крышкой';
                        select.appendChild(expand);
                    } else if (archiveMode) {
                        const back = document.createElement('option');
                        back.value = '__active__';
                        back.textContent = '↩ Вернуться к актуальным заявкам';
                        select.appendChild(back);
                    }
                } else {
                    orders.forEach(order => {
                        const option = document.createElement('option');
                        option.value = order;
                        option.textContent = archiveMode ? order + ' (архив)' : order;
                        select.appendChild(option);
                    });

                    if (archiveMode) {
                        const back = document.createElement('option');
                        back.value = '__active__';
                        back.textContent = '↩ Вернуться к актуальным заявкам';
                        select.appendChild(back);
                    }
                }

                select.classList.toggle('archive-mode', archiveMode);
                hint.classList.toggle('visible', archiveMode);
            } catch (err) {
                console.error('Ошибка загрузки заявок:', err);
                resetOrdersSelect('-- Ошибка загрузки заявок --');
            }
        }

        function handleCapInput() {
            clearTimeout(ordersUpdateTimeout);
            ordersUpdateTimeout = setTimeout(() => {
                updateOrdersList(document.getElementById('cap_name').value, false);
            }, 400);
        }

        function handleOrderSelectChange() {
            const select = document.getElementById('order_number');
            const cap = document.getElementById('cap_name').value;

            if (select.value === '__archive__') {
                updateOrdersList(cap, true);
            } else if (select.value === '__active__') {
                updateOrdersList(cap, false);
            }
        }

        window.onload = function() {
            setupAutocomplete();
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date').value = today;
            resetOrdersSelect('-- Сначала укажите крышку --');

            const capInput = document.getElementById('cap_name');
            capInput.addEventListener('input', handleCapInput);
            capInput.addEventListener('change', handleCapInput);
            document.getElementById('order_number').addEventListener('change', handleOrderSelectChange);
            document.getElementById('incomeForm').addEventListener('submit', function(e) {
                const order = document.getElementById('order_number').value;
                if (!order || order === '__archive__' || order === '__active__') {
                    e.preventDefault();
                    alert('Выберите заявку из списка');
                }
            });
        };
    </script>
</head>
<body>
    <div class="container">
        <h1>Прием крышек на склад</h1>
        
        <?php
        // Показываем сообщение об успехе если есть
        if (isset($_GET['success']) && $_GET['success'] == 1) {
            $cap = htmlspecialchars($_GET['cap'] ?? '');
            $qty = htmlspecialchars($_GET['qty'] ?? '');
            echo '<div class="message success" style="display: block;">';
            echo '✓ Крышки приняты: <strong>' . $cap . '</strong> - <strong>' . $qty . ' шт</strong>';
            echo '</div>';
        }
        ?>
        
        <div id="message" class="message"></div>
        
        <form id="incomeForm" method="POST" action="cap_income_process.php">
            <div class="form-group">
                <label for="date">Дата поступления *</label>
                <input type="date" id="date" name="date" required>
            </div>
            
            <div class="form-group">
                <label for="cap_name">Название крышки *</label>
                <input type="text" id="cap_name" name="cap_name" list="caps_datalist" required 
                       placeholder="Введите название крышки" autocomplete="off">
                <datalist id="caps_datalist"></datalist>
            </div>
            
            <div class="form-group">
                <label for="quantity">Количество *</label>
                <input type="number" id="quantity" name="quantity" min="1" step="1" required 
                       placeholder="Введите количество">
            </div>
            
            <div class="form-group">
                <label for="order_number">Заявка *</label>
                <select id="order_number" name="order_number" required>
                    <option value="">-- Сначала укажите крышку --</option>
                </select>
                <div id="archiveHint">Показаны архивные (скрытые) заявки с этой крышкой</div>
            </div>
            
            <button type="submit">Принять на склад</button>
        </form>
    </div>
</body>
</html>
