<?php
/**
 * Актуальная потребность в индивидуальных коробках по активным заявкам всех участков.
 */

define('AUTH_SYSTEM', true);
require_once __DIR__ . '/../auth/includes/config.php';
require_once __DIR__ . '/../auth/includes/auth-functions.php';

initAuthSystem();

$auth = new AuthManager();
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

$db = Database::getInstance();
$userDepartments = $db->select("
    SELECT ud.department_code, r.name AS role_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$hasPressOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'box_operator'], true)) {
        $hasPressOperatorAccess = true;
        break;
    }
}

if (!$hasPressOperatorAccess) {
    die('У вас нет доступа к модулю оператора тигельного пресса');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Потребность актуальная — Оператор тигельного пресса</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px 10px;
        }
        .container { max-width: 960px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #2c3e50; margin-bottom: 4px; }
        .header p { font-size: 14px; color: #7f8c8d; line-height: 1.5; }
        .controls {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn {
            padding: 8px 14px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .section {
            background: white;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        .summary-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            padding: 12px 14px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .summary-card strong { font-size: 22px; color: #2c3e50; }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        table th,
        table td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            font-size: 12px;
            white-space: nowrap;
        }
        table tr:hover { background: #f8fbff; }
        .box-name { font-weight: 700; color: #1f2937; white-space: nowrap; }
        .box-total { font-weight: 700; color: #e67e22; white-space: nowrap; }
        .box-breakdown { color: #475569; line-height: 1.5; }
        .text-right { text-align: right; }
        .empty-state,
        .loading-state,
        .error-state {
            text-align: center;
            padding: 28px 16px;
            color: #7f8c8d;
            font-size: 14px;
        }
        .error-state { color: #dc2626; }
        .warn-box {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fff7ed;
            border: 1px solid #fdba74;
            color: #9a3412;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            body { padding: 10px 8px; }
            .summary-card { flex-direction: column; align-items: flex-start; }
            table { font-size: 13px; }
            table th, table td { padding: 8px; }
            .box-name, .box-total { white-space: normal; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Потребность актуальная</h1>
            <p>Незакрытые позиции активных заявок У2 и У5.<br>Остаток = заказано − изготовлено фильтров.</p>
        </div>

        <div class="controls">
            <button type="button" class="btn btn-primary" id="btn-refresh">Обновить</button>
            <a href="index.php" class="btn btn-secondary">← Назад</a>
        </div>

        <div class="section">
            <div class="section-title">Коробки в активных заявках</div>
            <div id="warnings"></div>
            <div id="summary" class="summary-card" style="display:none;">
                <span>Общая потребность</span>
                <strong id="summary-total">0</strong>
            </div>
            <div id="list-state" class="loading-state">Загрузка...</div>
            <div id="table-wrap" class="table-wrap" style="display:none;">
                <table>
                    <thead>
                        <tr>
                            <th>Коробка</th>
                            <th class="text-right">Итого</th>
                            <th>По участкам</th>
                        </tr>
                    </thead>
                    <tbody id="box-table-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const listStateEl = document.getElementById('list-state');
        const tableWrapEl = document.getElementById('table-wrap');
        const tableBodyEl = document.getElementById('box-table-body');
        const warningsEl = document.getElementById('warnings');
        const summaryEl = document.getElementById('summary');
        const summaryTotalEl = document.getElementById('summary-total');

        const deptOrder = ['U2', 'U5'];

        function formatQty(value) {
            return Number(value || 0).toLocaleString('ru-RU');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDeptBreakdown(byDepartment, departments) {
            const labelMap = {};
            (departments || []).forEach((dept) => {
                labelMap[dept.code] = dept.label;
            });

            const parts = deptOrder
                .filter((code) => Number(byDepartment?.[code] || 0) > 0)
                .map((code) => (labelMap[code] || code) + ' ' + formatQty(byDepartment[code]));

            return parts.join('; ');
        }

        function showWarnings(errors) {
            warningsEl.innerHTML = '';
            if (!errors || !errors.length) return;
            const box = document.createElement('div');
            box.className = 'warn-box';
            box.textContent = 'Часть участков недоступна: ' + errors.join('; ');
            warningsEl.appendChild(box);
        }

        function renderBoxTable(boxes, departments) {
            tableBodyEl.innerHTML = '';

            if (!boxes.length) {
                tableWrapEl.style.display = 'none';
                listStateEl.style.display = 'block';
                listStateEl.className = 'empty-state';
                listStateEl.textContent = 'Нет незакрытой потребности в коробках по активным заявкам.';
                summaryEl.style.display = 'none';
                return;
            }

            listStateEl.style.display = 'none';
            tableWrapEl.style.display = 'block';
            summaryEl.style.display = 'flex';
            summaryTotalEl.textContent = formatQty(boxes.reduce((sum, item) => sum + item.total, 0)) + ' шт';

            boxes.forEach((item) => {
                const row = document.createElement('tr');
                const breakdown = formatDeptBreakdown(item.by_department, departments);

                row.innerHTML =
                    '<td class="box-name">' + escapeHtml(item.box_name) + '</td>' +
                    '<td class="box-total text-right">' + formatQty(item.total) + ' шт</td>' +
                    '<td class="box-breakdown">' + (breakdown ? escapeHtml(breakdown) : '—') + '</td>';

                row.title = item.box_name + ': ' + formatQty(item.total) + ' шт' + (breakdown ? ' (' + breakdown + ')' : '');
                tableBodyEl.appendChild(row);
            });
        }

        async function loadSummary() {
            listStateEl.style.display = 'block';
            listStateEl.className = 'loading-state';
            listStateEl.textContent = 'Загрузка...';
            tableWrapEl.style.display = 'none';
            tableBodyEl.innerHTML = '';

            try {
                const response = await fetch('api/box_demand.php?action=list');
                const payload = await response.json();
                if (!payload.ok) {
                    throw new Error(payload.error || 'Ошибка загрузки');
                }
                showWarnings(payload.data.errors || []);
                renderBoxTable(payload.data.boxes || [], payload.data.departments || []);
            } catch (error) {
                tableWrapEl.style.display = 'none';
                listStateEl.className = 'error-state';
                listStateEl.textContent = error.message || 'Не удалось загрузить данные';
                summaryEl.style.display = 'none';
            }
        }

        document.getElementById('btn-refresh').addEventListener('click', loadSummary);
        loadSummary();
    </script>
</body>
</html>
