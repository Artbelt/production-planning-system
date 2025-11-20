<?php
require_once('tools/tools.php');
require_once('settings.php');

$action = $_GET['action'] ?? 'list';
$tariff_id = $_GET['id'] ?? null;
$addition_action = $_GET['addition_action'] ?? null;
$addition_code = $_GET['addition_code'] ?? null;

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add' || $action === 'edit') {
            $tariff_name = trim($_POST['tariff_name'] ?? '');
            $rate_per_unit = floatval($_POST['rate_per_unit'] ?? 0);
            $type = trim($_POST['type'] ?? 'normal');
            $build_complexity = isset($_POST['build_complexity']) && $_POST['build_complexity'] !== '' ? floatval($_POST['build_complexity']) : null;
            
            if (empty($tariff_name)) {
                $error = 'Название тарифа не может быть пустым';
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    if ($action === 'add') {
                        // Используем отдельные запросы для обработки NULL значений
                        if ($build_complexity !== null) {
                            $stmt = $mysqli->prepare("INSERT INTO salary_tariffs (tariff_name, rate_per_unit, type, build_complexity) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param('sdsd', $tariff_name, $rate_per_unit, $type, $build_complexity);
                        } else {
                            $stmt = $mysqli->prepare("INSERT INTO salary_tariffs (tariff_name, rate_per_unit, type) VALUES (?, ?, ?)");
                            $stmt->bind_param('sds', $tariff_name, $rate_per_unit, $type);
                        }
                    } else {
                        $tariff_id = intval($_POST['tariff_id']);
                        // Обновляем основные поля
                        $stmt = $mysqli->prepare("UPDATE salary_tariffs SET tariff_name = ?, rate_per_unit = ?, type = ? WHERE id = ?");
                        $stmt->bind_param('sdsi', $tariff_name, $rate_per_unit, $type, $tariff_id);
                    }
                    
                    if ($stmt->execute()) {
                        // Если редактирование, обновляем build_complexity отдельно
                        if ($action === 'edit') {
                            $tariff_id = intval($_POST['tariff_id']);
                            if ($build_complexity !== null) {
                                $stmt2 = $mysqli->prepare("UPDATE salary_tariffs SET build_complexity = ? WHERE id = ?");
                                $stmt2->bind_param('di', $build_complexity, $tariff_id);
                                $stmt2->execute();
                                $stmt2->close();
                            } else {
                                $stmt2 = $mysqli->prepare("UPDATE salary_tariffs SET build_complexity = NULL WHERE id = ?");
                                $stmt2->bind_param('i', $tariff_id);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                        }
                        
                        header('Location: manage_tariffs.php?success=' . ($action === 'add' ? 'added' : 'updated'));
                        exit;
                    } else {
                        $error = 'Ошибка сохранения: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        } elseif ($action === 'delete') {
            $tariff_id = intval($_POST['tariff_id']);
            
            // Проверяем, используется ли тариф
            $usage_result = mysql_execute("SELECT COUNT(*) as count FROM salon_filter_structure WHERE tariff_id = $tariff_id");
            $usage_row = $usage_result->fetch_assoc();
            $usage_count = $usage_row['count'] ?? 0;
            
            if ($usage_count > 0) {
                $error = "Невозможно удалить тариф: он используется в $usage_count фильтрах";
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $stmt = $mysqli->prepare("DELETE FROM salary_tariffs WHERE id = ?");
                    $stmt->bind_param('i', $tariff_id);
                    
                    if ($stmt->execute()) {
                        header('Location: manage_tariffs.php?success=deleted');
                        exit;
                    } else {
                        $error = 'Ошибка удаления: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        }
    }
    
    // Обработка действий с дополнениями
    if (isset($_POST['addition_action'])) {
        $addition_action = $_POST['addition_action'];
        
        if ($addition_action === 'add' || $addition_action === 'edit') {
            $code = trim($_POST['code'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            
            if (empty($code)) {
                $error = 'Код доплаты не может быть пустым';
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    if ($addition_action === 'add') {
                        $stmt = $mysqli->prepare("INSERT INTO salary_additions (code, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
                        $stmt->bind_param('sd', $code, $amount);
                    } else {
                        $old_code = trim($_POST['old_code'] ?? '');
                        $stmt = $mysqli->prepare("UPDATE salary_additions SET code = ?, amount = ? WHERE code = ?");
                        $stmt->bind_param('sds', $code, $amount, $old_code);
                    }
                    
                    if ($stmt->execute()) {
                        header('Location: manage_tariffs.php?success=addition_' . ($addition_action === 'add' ? 'added' : 'updated'));
                        exit;
                    } else {
                        $error = 'Ошибка сохранения доплаты: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        } elseif ($addition_action === 'delete') {
            $code = trim($_POST['code'] ?? '');
            
            global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
            $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
            
            if ($mysqli->connect_errno) {
                $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
            } else {
                $stmt = $mysqli->prepare("DELETE FROM salary_additions WHERE code = ?");
                $stmt->bind_param('s', $code);
                
                if ($stmt->execute()) {
                    header('Location: manage_tariffs.php?success=addition_deleted');
                    exit;
                } else {
                    $error = 'Ошибка удаления доплаты: ' . $stmt->error;
                }
                $stmt->close();
                $mysqli->close();
            }
        }
    }
}

// Загружаем данные тарифа для редактирования
$tariff_data = null;
if ($action === 'edit' && $tariff_id) {
    $result = mysql_execute("SELECT * FROM salary_tariffs WHERE id = " . intval($tariff_id));
    $tariffs = [];
    while ($row = $result->fetch_assoc()) {
        $tariffs[] = $row;
    }
    if (!empty($tariffs)) {
        $tariff_data = $tariffs[0];
    } else {
        $action = 'list';
    }
}

// Загружаем список тарифов
$tariffs_list = [];
if ($action === 'list' || $addition_action) {
    try {
        $result = mysql_execute("SELECT st.*, COUNT(sfs.filter) as usage_count 
                                 FROM salary_tariffs st 
                                 LEFT JOIN salon_filter_structure sfs ON sfs.tariff_id = st.id 
                                 GROUP BY st.id 
                                 ORDER BY st.tariff_name");
        while ($row = $result->fetch_assoc()) {
            $tariffs_list[] = $row;
        }
    } catch (Exception $e) {
        // Если поле build_complexity еще не добавлено, загружаем без него
        $result = mysql_execute("SELECT st.*, COUNT(sfs.filter) as usage_count 
                                 FROM salary_tariffs st 
                                 LEFT JOIN salon_filter_structure sfs ON sfs.tariff_id = st.id 
                                 GROUP BY st.id 
                                 ORDER BY st.tariff_name");
        while ($row = $result->fetch_assoc()) {
            if (!isset($row['build_complexity'])) {
                $row['build_complexity'] = null;
            }
            $tariffs_list[] = $row;
        }
    }
}

// Загружаем данные доплаты для редактирования
$addition_data = null;
if ($addition_action === 'edit' && $addition_code) {
    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
    $mysqli_temp = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    $escaped_code = $mysqli_temp->real_escape_string($addition_code);
    $mysqli_temp->close();
    
    $result = mysql_execute("SELECT * FROM salary_additions WHERE code = '" . $escaped_code . "'");
    $additions = [];
    while ($row = $result->fetch_assoc()) {
        $additions[] = $row;
    }
    if (!empty($additions)) {
        $addition_data = $additions[0];
    } else {
        $addition_action = null;
    }
}

// Загружаем список дополнений
$additions_list = [];
$result = mysql_execute("SELECT * FROM salary_additions ORDER BY code");
while ($row = $result->fetch_assoc()) {
    $additions_list[] = $row;
}

$success_message = '';
if (isset($_GET['success'])) {
    $messages = [
        'added' => 'Тариф успешно добавлен',
        'updated' => 'Тариф успешно обновлен',
        'deleted' => 'Тариф успешно удален',
        'addition_added' => 'Доплата успешно добавлена',
        'addition_updated' => 'Доплата успешно обновлена',
        'addition_deleted' => 'Доплата успешно удалена'
    ];
    $success_message = $messages[$_GET['success']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Управление тарифами</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root{
            --bg:#f9fafb;
            --card:#ffffff;
            --muted:#5f6368;
            --text:#1f2937;
            --accent:#2563eb;
            --accent-2:#059669;
            --border:#e5e7eb;
            --danger:#dc2626;
            --radius:12px;
            --shadow:0 4px 12px rgba(0,0,0,.08);
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; background:var(--bg);
            color:var(--text); font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
        }
        .container{max-width:1000px; margin:24px auto 64px; padding:0 16px;}
        header.top{
            display:flex; align-items:center; justify-content:space-between;
            padding:18px 20px; background:#fff; border:1px solid var(--border);
            border-radius:var(--radius); box-shadow:var(--shadow); margin-bottom:20px;
        }
        .title{font-size:18px; font-weight:700; letter-spacing:.2px}
        .card{
            background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:var(--shadow); padding:18px; margin-bottom:16px;
        }
        .card h3{margin:0 0 12px; font-size:16px; font-weight:700}
        label{display:block; color:var(--muted); margin-bottom:6px; font-size:13px}
        input[type="text"], input[type="number"], select{
            width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border);
            background:#fff; color:var(--text); outline:none;
            transition:border-color .15s, box-shadow .15s;
        }
        input[type="text"]:focus, input[type="number"]:focus, select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 2px rgba(37,99,235,.15);
        }
        .btn{
            border:1px solid transparent; background:var(--accent);
            color:white; padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer;
            transition:background .15s; text-decoration:none; display:inline-block;
        }
        .btn:hover{background:#1e4ed8}
        .btn.secondary{background:#f3f4f6; color:var(--text); border-color:var(--border)}
        .btn.secondary:hover{background:#e5e7eb}
        .btn.danger{background:var(--danger); color:white}
        .btn.danger:hover{background:#b91c1c}
        .btn.success{background:var(--accent-2); color:white}
        .btn.success:hover{background:#047857}
        .row-2{display:grid; gap:12px; grid-template-columns:1fr 1fr}
        .row-3{display:grid; gap:12px; grid-template-columns:repeat(3,1fr)}
        .actions{display:flex; gap:10px; margin-top:16px}
        .alert{
            padding:12px 16px; border-radius:8px; margin-bottom:16px;
        }
        .alert.success{background:#d1fae5; border:1px solid #10b981; color:#065f46}
        .alert.error{background:#fee2e2; border:1px solid #ef4444; color:#991b1b}
        table{
            width:100%; border-collapse:collapse; margin-top:12px;
        }
        table th, table td{
            padding:12px; text-align:left; border-bottom:1px solid var(--border);
        }
        table th{background:#f9fafb; font-weight:600; color:var(--muted); font-size:12px; text-transform:uppercase}
        table tr:hover{background:#f9fafb}
        .badge{
            display:inline-block; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:600;
        }
        .badge.normal{background:#dbeafe; color:#1e40af}
        .badge.fixed{background:#fef3c7; color:#92400e}
        .badge.hourly{background:#e0e7ff; color:#3730a3}
        @media(max-width:900px){
            .row-2,.row-3{grid-template-columns:1fr}
        }
    </style>
    <script>
        function toggleHelpInfo() {
            const helpInfo = document.getElementById('helpInfo');
            if (helpInfo.style.display === 'none' || helpInfo.style.display === '') {
                helpInfo.style.display = 'block';
            } else {
                helpInfo.style.display = 'none';
            }
        }
    </script>
</head>
<body>

<div class="container">
    <header class="top">
        <div class="title">Управление тарифами</div>
    </header>

    <?php if ($success_message): ?>
        <div class="alert success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($action === 'list' && !$addition_action): ?>
        <!-- Кнопка показа справочной информации -->
        <div class="card" style="margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Управление тарифами и дополнениями</h3>
                <button onclick="toggleHelpInfo()" class="btn" style="background: #6b7280; padding: 8px 16px; font-size: 18px; line-height: 1;">
                    ?
                </button>
            </div>
        </div>

        <!-- Справочная информация -->
        <div id="helpInfo" class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0; color: white;">📊 Справочная информация: Тарифы и расчет заработной платы</h3>
                <button onclick="toggleHelpInfo()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 20px; line-height: 1;">×</button>
            </div>
            
            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">🎯 Базовая ставка</strong>
                <ul style="margin: 8px 0; padding-left: 20px; line-height: 1.8;">
                    <li>Каждому фильтру присваивается <strong>тариф</strong> из таблицы salary_tariffs</li>
                    <li>Тариф определяет базовую ставку (rate_per_unit) за единицу продукции</li>
                    <li>Тарифы бывают трех типов:</li>
                    <ul style="margin: 4px 0; padding-left: 20px;">
                        <li><strong>Обычный</strong> — стандартный тариф, к которому применяются доплаты</li>
                        <li><strong>Фиксированный (fixed)</strong> — фиксированная ставка, доплаты НЕ применяются</li>
                        <li><strong>Почасовый</strong> — расчет по часам работы, доплаты НЕ применяются</li>
                    </ul>
                </ul>
            </div>

            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">💰 Доплаты (additions)</strong>
                <p style="margin: 8px 0;">К базовой ставке могут добавляться доплаты из таблицы salary_additions:</p>
                <ul style="margin: 8px 0; padding-left: 20px; line-height: 1.8;">
                    <li><strong>+Язычок</strong> (tongue_glue) — если у фильтра есть язычок (tail содержит 'языч')<br>
                    <em style="font-size:12px; opacity:0.9;">⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                    
                    <li><strong>+Трапеция</strong> (edge_trim_glue) — если форма фильтра 'трапеция'<br>
                    <em style="font-size:12px; opacity:0.9;">⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                    
                    <li><strong>+Надрезы</strong> (edge_cuts) — если у фильтра есть надрезы (has_edge_cuts)<br>
                    <em style="font-size:12px; opacity:0.9;">✅ Применяется для ВСЕХ тарифов кроме почасовых!</em></li>
                </ul>
            </div>

            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">📐 Формула расчета</strong>
                <p style="margin: 8px 0; font-family: monospace; background: rgba(0,0,0,0.2); padding: 8px; border-radius: 4px;">
                    Итоговая ставка = Базовая ставка + Доплаты (если применимо)
                </p>
                <p style="margin: 8px 0; font-size: 13px; opacity: 0.9;">
                    Заработная плата = Итоговая ставка × Количество фильтров (или часы для почасовых тарифов)
                </p>
            </div>
        </div>

        <!-- Список тарифов -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                <h3 style="margin:0">Список тарифов</h3>
                <a href="?action=add" class="btn success">+ Добавить тариф</a>
            </div>
            
            <?php if (empty($tariffs_list)): ?>
                <p style="color:var(--muted); text-align:center; padding:40px">Тарифы не найдены</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Ставка за единицу</th>
                            <th>Тип</th>
                            <th>Сложность сборки</th>
                            <th>Используется</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tariffs_list as $tariff): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tariff['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($tariff['tariff_name']); ?></strong></td>
                                <td><?php echo number_format($tariff['rate_per_unit'], 2, '.', ' '); ?></td>
                                <td>
                                    <?php
                                    $type_labels = [
                                        'normal' => ['text' => 'Обычный', 'class' => 'normal'],
                                        'fixed' => ['text' => 'Фиксированный', 'class' => 'fixed'],
                                        'hourly' => ['text' => 'Почасовый', 'class' => 'hourly']
                                    ];
                                    $type_info = $type_labels[$tariff['type']] ?? ['text' => $tariff['type'], 'class' => 'normal'];
                                    ?>
                                    <span class="badge <?php echo $type_info['class']; ?>"><?php echo htmlspecialchars($type_info['text']); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($tariff['build_complexity'])): ?>
                                        <?php echo number_format($tariff['build_complexity'], 2, '.', ' '); ?> шт/смену
                                    <?php else: ?>
                                        <span style="color:var(--muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($tariff['usage_count']); ?> фильтров</td>
                                <td>
                                    <div style="display:flex; gap:8px">
                                        <a href="?action=edit&id=<?php echo $tariff['id']; ?>" class="btn secondary" style="padding:6px 12px; font-size:12px">Редактировать</a>
                                        <?php if ($tariff['usage_count'] == 0): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Вы уверены, что хотите удалить этот тариф?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="tariff_id" value="<?php echo $tariff['id']; ?>">
                                                <button type="submit" class="btn danger" style="padding:6px 12px; font-size:12px">Удалить</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:var(--muted); font-size:12px" title="Тариф используется в фильтрах">Удалить нельзя</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Управление дополнениями -->
        <?php if (!$addition_action): ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                <h3 style="margin:0">Управление дополнениями</h3>
                <a href="?addition_action=add" class="btn success">+ Добавить доплату</a>
            </div>
            
            <div style="background:#f0f9ff; border-left:4px solid #2563eb; padding:12px; margin-bottom:16px; border-radius:4px; font-size:13px; color:#1e40af;">
                <strong>💡 Подсказка:</strong> Доплаты применяются автоматически при расчете заработной платы. Код доплаты должен соответствовать стандартным кодам: <code>tongue_glue</code>, <code>edge_trim_glue</code>, <code>edge_cuts</code>.
            </div>
            
            <?php if (empty($additions_list)): ?>
                <p style="color:var(--muted); text-align:center; padding:40px">Доплаты не найдены</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Название</th>
                            <th>Сумма доплаты</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $addition_names = [
                            'tongue_glue' => 'Язычок',
                            'edge_trim_glue' => 'Трапеция',
                            'edge_cuts' => 'Надрезы'
                        ];
                        foreach ($additions_list as $addition): 
                            $name = $addition_names[$addition['code']] ?? $addition['code'];
                        ?>
                            <tr>
                                <td><code style="background:#f3f4f6; padding:4px 8px; border-radius:4px; font-size:12px"><?php echo htmlspecialchars($addition['code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                <td><?php echo number_format($addition['amount'], 2, '.', ' '); ?></td>
                                <td>
                                    <div style="display:flex; gap:8px">
                                        <a href="?addition_action=edit&addition_code=<?php echo urlencode($addition['code']); ?>" class="btn secondary" style="padding:6px 12px; font-size:12px">Редактировать</a>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Вы уверены, что хотите удалить эту доплату?');">
                                            <input type="hidden" name="addition_action" value="delete">
                                            <input type="hidden" name="code" value="<?php echo htmlspecialchars($addition['code']); ?>">
                                            <button type="submit" class="btn danger" style="padding:6px 12px; font-size:12px">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php elseif ($action === 'add' || $action === 'edit'): ?>
        <!-- Форма добавления/редактирования -->
        <div class="card">
            <h3><?php echo $action === 'add' ? 'Добавить тариф' : 'Редактировать тариф'; ?></h3>
            
            <form method="post">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <?php if ($action === 'edit' && $tariff_data): ?>
                    <input type="hidden" name="tariff_id" value="<?php echo htmlspecialchars($tariff_data['id']); ?>">
                <?php endif; ?>
                
                <div class="row-3">
                    <div>
                        <label>Название тарифа *</label>
                        <input type="text" name="tariff_name" required 
                               value="<?php echo htmlspecialchars($tariff_data['tariff_name'] ?? ''); ?>" 
                               placeholder="Например: Стандартный">
                    </div>
                    <div>
                        <label>Ставка за единицу *</label>
                        <input type="number" name="rate_per_unit" step="0.01" required 
                               value="<?php echo htmlspecialchars($tariff_data['rate_per_unit'] ?? '0'); ?>" 
                               placeholder="0.00">
                    </div>
                    <div>
                        <label>Тип тарифа *</label>
                        <select name="type" required>
                            <option value="normal" <?php echo ($tariff_data['type'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Обычный</option>
                            <option value="fixed" <?php echo ($tariff_data['type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Фиксированный</option>
                            <option value="hourly" <?php echo ($tariff_data['type'] ?? '') === 'hourly' ? 'selected' : ''; ?>>Почасовый</option>
                        </select>
                    </div>
                </div>
                
                <div class="row-2" style="margin-top:12px">
                    <div>
                        <label>Сложность сборки (шт/смену)</label>
                        <input type="number" name="build_complexity" step="0.01" 
                               value="<?php echo htmlspecialchars($tariff_data['build_complexity'] ?? ''); ?>" 
                               placeholder="Например: 600">
                        <small style="color:var(--muted); font-size:11px; margin-top:4px; display:block">Количество фильтров, которое можно собрать за смену</small>
                    </div>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn success">Сохранить</button>
                    <a href="manage_tariffs.php" class="btn secondary">Отмена</a>
                </div>
            </form>
        </div>
    <?php elseif ($addition_action === 'add' || $addition_action === 'edit'): ?>
        <!-- Форма добавления/редактирования доплаты -->
        <div class="card">
            <h3><?php echo $addition_action === 'add' ? 'Добавить доплату' : 'Редактировать доплату'; ?></h3>
            
            <form method="post">
                <input type="hidden" name="addition_action" value="<?php echo $addition_action; ?>">
                <?php if ($addition_action === 'edit' && $addition_data): ?>
                    <input type="hidden" name="old_code" value="<?php echo htmlspecialchars($addition_data['code']); ?>">
                <?php endif; ?>
                
                <div class="row-2">
                    <div>
                        <label>Код доплаты *</label>
                        <select name="code" required <?php echo ($addition_action === 'edit') ? 'disabled' : ''; ?> style="<?php echo ($addition_action === 'edit') ? 'background:#f3f4f6;' : ''; ?>">
                            <option value="">— Выберите код —</option>
                            <option value="tongue_glue" <?php echo ($addition_data['code'] ?? '') === 'tongue_glue' ? 'selected' : ''; ?>>tongue_glue (Язычок)</option>
                            <option value="edge_trim_glue" <?php echo ($addition_data['code'] ?? '') === 'edge_trim_glue' ? 'selected' : ''; ?>>edge_trim_glue (Трапеция)</option>
                            <option value="edge_cuts" <?php echo ($addition_data['code'] ?? '') === 'edge_cuts' ? 'selected' : ''; ?>>edge_cuts (Надрезы)</option>
                        </select>
                        <?php if ($addition_action === 'edit'): ?>
                            <input type="hidden" name="code" value="<?php echo htmlspecialchars($addition_data['code']); ?>">
                            <small style="color:var(--muted); font-size:11px; margin-top:4px; display:block">Код нельзя изменить при редактировании</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label>Сумма доплаты *</label>
                        <input type="number" name="amount" step="0.01" required 
                               value="<?php echo htmlspecialchars($addition_data['amount'] ?? '0'); ?>" 
                               placeholder="0.00">
                    </div>
                </div>
                
                <div style="background:#f9fafb; padding:12px; border-radius:8px; margin-top:16px; font-size:12px; color:var(--muted);">
                    <strong>Примечание:</strong> Доплаты применяются автоматически при расчете заработной платы в зависимости от характеристик фильтра и типа тарифа.
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn success">Сохранить</button>
                    <a href="manage_tariffs.php" class="btn secondary">Отмена</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

</body>
</html>

