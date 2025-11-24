<?php
/**
 * Страница статистики оператора тигельного пресса
 * Отображение статистики за выбранный период
 */

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once '../auth/includes/config.php';
require_once '../auth/includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    header('Location: ../auth/login.php');
    exit;
}

// Получаем информацию о пользователе и его роли
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Проверяем, есть ли доступ к модулю оператора пресса
$hasPressOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'box_operator'])) {
        $hasPressOperatorAccess = true;
        break;
    }
}

if (!$hasPressOperatorAccess) {
    die("У вас нет доступа к модулю оператора тигельного пресса");
}

// Подключение к БД пресса
$pressDbConfig = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => '',
    'name' => 'press_module'
];

// Получаем параметры периода
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Функция для получения статистики за период
function getStatistics($pressDbConfig, $dateFrom, $dateTo) {
    $mysqli = new mysqli($pressDbConfig['host'], $pressDbConfig['user'], $pressDbConfig['pass'], $pressDbConfig['name']);
    
    if ($mysqli->connect_errno) {
        return [
            'die_cut' => [],
            'glued' => [],
            'die_cut_total' => 0,
            'glued_total' => 0,
            'die_cut_by_box' => [],
            'glued_by_box' => [],
            'die_cut_by_brand' => [],
            'glued_by_brand' => []
        ];
    }
    
    // Высеченные заготовки за период
    $dieCutData = [];
    $stmt = $mysqli->prepare("
        SELECT 
            shift_date,
            brand_name,
            box_name,
            SUM(quantity) as total_quantity,
            COUNT(*) as records_count,
            GROUP_CONCAT(DISTINCT operator_name SEPARATOR ', ') as operators
        FROM press_die_cut_blanks 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY shift_date, brand_name, box_name
        ORDER BY shift_date DESC, box_name
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dieCutData[] = $row;
    }
    $stmt->close();
    
    // Склеенные коробки за период
    $gluedData = [];
    $stmt = $mysqli->prepare("
        SELECT 
            shift_date,
            brand_name,
            box_name,
            SUM(quantity) as total_quantity,
            COUNT(*) as records_count,
            GROUP_CONCAT(DISTINCT operator_name SEPARATOR ', ') as operators
        FROM press_glued_boxes 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY shift_date, brand_name, box_name
        ORDER BY shift_date DESC, box_name
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gluedData[] = $row;
    }
    $stmt->close();
    
    // Общие итоги
    $stmt = $mysqli->prepare("SELECT SUM(quantity) as total FROM press_die_cut_blanks WHERE shift_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $dieCutTotal = $row['total'] ?? 0;
    $stmt->close();
    
    $stmt = $mysqli->prepare("SELECT SUM(quantity) as total FROM press_glued_boxes WHERE shift_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $gluedTotal = $row['total'] ?? 0;
    $stmt->close();
    
    // Группировка по коробкам
    $stmt = $mysqli->prepare("
        SELECT 
            box_name,
            SUM(quantity) as total_quantity
        FROM press_die_cut_blanks 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY box_name
        ORDER BY total_quantity DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $dieCutByBox = [];
    while ($row = $result->fetch_assoc()) {
        $dieCutByBox[] = $row;
    }
    $stmt->close();
    
    $stmt = $mysqli->prepare("
        SELECT 
            box_name,
            SUM(quantity) as total_quantity
        FROM press_glued_boxes 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY box_name
        ORDER BY total_quantity DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $gluedByBox = [];
    while ($row = $result->fetch_assoc()) {
        $gluedByBox[] = $row;
    }
    $stmt->close();
    
    // Группировка по брендам
    $stmt = $mysqli->prepare("
        SELECT 
            COALESCE(brand_name, 'Без бренда') as brand_name,
            SUM(quantity) as total_quantity
        FROM press_die_cut_blanks 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY brand_name
        ORDER BY total_quantity DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $dieCutByBrand = [];
    while ($row = $result->fetch_assoc()) {
        $dieCutByBrand[] = $row;
    }
    $stmt->close();
    
    $stmt = $mysqli->prepare("
        SELECT 
            COALESCE(brand_name, 'Без бренда') as brand_name,
            SUM(quantity) as total_quantity
        FROM press_glued_boxes 
        WHERE shift_date BETWEEN ? AND ?
        GROUP BY brand_name
        ORDER BY total_quantity DESC
    ");
    $stmt->bind_param("ss", $dateFrom, $dateTo);
    $stmt->execute();
    $result = $stmt->get_result();
    $gluedByBrand = [];
    while ($row = $result->fetch_assoc()) {
        $gluedByBrand[] = $row;
    }
    $stmt->close();
    
    $mysqli->close();
    
    return [
        'die_cut' => $dieCutData,
        'glued' => $gluedData,
        'die_cut_total' => $dieCutTotal,
        'glued_total' => $gluedTotal,
        'die_cut_by_box' => $dieCutByBox,
        'glued_by_box' => $gluedByBox,
        'die_cut_by_brand' => $dieCutByBrand,
        'glued_by_brand' => $gluedByBrand
    ];
}

$statistics = getStatistics($pressDbConfig, $dateFrom, $dateTo);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Статистика - Оператор тигельного пресса</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f5f5f5; padding: 20px 10px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 24px; font-weight: 700; color: #2c3e50; margin-bottom: 4px; }
        .header p { font-size: 14px; color: #7f8c8d; }
        .controls { display: flex; gap: 8px; justify-content: center; margin-bottom: 20px; flex-wrap: wrap; align-items: center; }
        .controls input[type="date"] { padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; background: white; font-size: 13px; }
        .controls input[type="date"]:focus { outline: none; border-color: #3498db; }
        .btn { padding: 6px 14px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        .summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
        .card { background: white; border-radius: 8px; padding: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .card-title { font-size: 12px; color: #7f8c8d; margin-bottom: 4px; font-weight: 600; }
        .card-value { font-size: 24px; font-weight: 700; color: #2c3e50; }
        .card-value.blue { color: #3498db; }
        .card-value.orange { color: #e67e22; }
        .section { background: white; border-radius: 8px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); }
        .section-title { font-size: 16px; font-weight: 700; color: #2c3e50; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #ecf0f1; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table th, table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #eee; }
        table th { background: #f8f9fa; font-weight: 600; color: #2c3e50; font-size: 12px; }
        table td { color: #555; }
        table tr:hover { background: #f8f9fa; }
        .text-right { text-align: right; }
        .subtitle { font-size: 13px; color: #3498db; margin-bottom: 8px; font-weight: 600; }
        .subtitle.orange { color: #e67e22; }
        .empty-state { text-align: center; padding: 20px; color: #7f8c8d; font-size: 13px; }
        @media (max-width: 768px) {
            body { padding: 10px 8px; }
            .controls { flex-direction: column; }
            .controls input[type="date"] { width: 100%; }
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
            table { font-size: 11px; }
            table th, table td { padding: 4px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Статистика</h1>
            <p>Период: <?= date('d.m.Y', strtotime($dateFrom)) ?> — <?= date('d.m.Y', strtotime($dateTo)) ?></p>
        </div>
        
        <div class="controls">
            <input type="date" id="date-from" value="<?= htmlspecialchars($dateFrom) ?>">
            <span>—</span>
            <input type="date" id="date-to" value="<?= htmlspecialchars($dateTo) ?>">
            <button class="btn btn-primary" onclick="updateStatistics()">Обновить</button>
            <a href="index.php" class="btn btn-secondary">← Назад</a>
        </div>
        
        <div class="summary-cards">
            <div class="card">
                <div class="card-title">Высечено</div>
                <div class="card-value blue"><?= number_format($statistics['die_cut_total'], 0, ',', ' ') ?></div>
            </div>
            <div class="card">
                <div class="card-title">Склеено</div>
                <div class="card-value orange"><?= number_format($statistics['glued_total'], 0, ',', ' ') ?></div>
            </div>
            <div class="card">
                <div class="card-title">Дней</div>
                <div class="card-value"><?= max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1) ?></div>
            </div>
            <div class="card">
                <div class="card-title">Среднее/день</div>
                <div class="card-value"><?= $statistics['die_cut_total'] > 0 || $statistics['glued_total'] > 0 ? number_format(($statistics['die_cut_total'] + $statistics['glued_total']) / max(1, (strtotime($dateTo) - strtotime($dateFrom)) / 86400 + 1), 0, ',', ' ') : '0' ?></div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">По типам коробок</div>
            <div class="grid-2">
                <div>
                    <div class="subtitle">Высеченные заготовки</div>
                    <?php if (!empty($statistics['die_cut_by_box'])): ?>
                        <table>
                            <thead><tr><th>Коробка</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['die_cut_by_box'] as $item): ?>
                                    <tr><td><?= htmlspecialchars($item['box_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
                <div>
                    <div class="subtitle orange">Склеенные коробки</div>
                    <?php if (!empty($statistics['glued_by_box'])): ?>
                        <table>
                            <thead><tr><th>Коробка</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['glued_by_box'] as $item): ?>
                                    <tr><td><?= htmlspecialchars($item['box_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">По брендам</div>
            <div class="grid-2">
                <div>
                    <div class="subtitle">Высеченные заготовки</div>
                    <?php if (!empty($statistics['die_cut_by_brand'])): ?>
                        <table>
                            <thead><tr><th>Бренд</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['die_cut_by_brand'] as $item): ?>
                                    <tr><td><?= htmlspecialchars($item['brand_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
                <div>
                    <div class="subtitle orange">Склеенные коробки</div>
                    <?php if (!empty($statistics['glued_by_brand'])): ?>
                        <table>
                            <thead><tr><th>Бренд</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['glued_by_brand'] as $item): ?>
                                    <tr><td><?= htmlspecialchars($item['brand_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">По дням</div>
            <div class="grid-2">
                <div>
                    <div class="subtitle">Высеченные заготовки</div>
                    <?php if (!empty($statistics['die_cut'])): ?>
                        <table>
                            <thead><tr><th>Дата</th><th>Бренд</th><th>Коробка</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['die_cut'] as $item): ?>
                                    <tr><td><?= date('d.m.Y', strtotime($item['shift_date'])) ?></td><td><?= htmlspecialchars($item['brand_name'] ?? '—') ?></td><td><?= htmlspecialchars($item['box_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
                <div>
                    <div class="subtitle orange">Склеенные коробки</div>
                    <?php if (!empty($statistics['glued'])): ?>
                        <table>
                            <thead><tr><th>Дата</th><th>Бренд</th><th>Коробка</th><th class="text-right">Кол-во</th></tr></thead>
                            <tbody>
                                <?php foreach ($statistics['glued'] as $item): ?>
                                    <tr><td><?= date('d.m.Y', strtotime($item['shift_date'])) ?></td><td><?= htmlspecialchars($item['brand_name'] ?? '—') ?></td><td><?= htmlspecialchars($item['box_name']) ?></td><td class="text-right"><strong><?= number_format($item['total_quantity'], 0, ',', ' ') ?></strong></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?><div class="empty-state">Нет данных</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function updateStatistics() {
            const dateFrom = document.getElementById('date-from').value;
            const dateTo = document.getElementById('date-to').value;
            if (!dateFrom || !dateTo) { alert('Выберите период'); return; }
            if (dateFrom > dateTo) { alert('Дата начала не может быть позже даты окончания'); return; }
            window.location.href = `?date_from=${dateFrom}&date_to=${dateTo}`;
        }
    </script>
</body>
</html>

