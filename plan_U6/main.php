<?php
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');
require_once('settings.php');
require_once('tools/tools.php');

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
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

if (!$user) {
    $user = [
        'full_name' => $session['full_name'] ?? 'Пользователь',
        'phone' => $session['phone'] ?? ''
    ];
}

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

$currentDepartment = 'U6';
$hasAccessToU6 = false;
$userRole = null;

foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U6') {
        $hasAccessToU6 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

if (!$hasAccessToU6) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U6</h3>";
    echo "<p>Ваши доступные цеха: ";
    $deptNames = [];
    foreach ($userDepartments as $dept) {
        $deptNames[] = $dept['department_code'] . " (" . $dept['role_name'] . ")";
    }
    echo implode(", ", $deptNames);
    echo "</p>";
    echo "<p><a href='../index.php'>← Вернуться на главную страницу</a></p>";
    echo "</div>";
    $userRole = 'guest';
}

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">';
echo '<title>U6 — Заявки</title>';
echo '<link rel="stylesheet" href="sheets.css">';
echo '</head><body>';

echo "<table width='100%' height='100%' style='background-color: #6495ed'>";
echo "<tr height='10%' align='center' style='background-color: #dedede'><td width='20%'>Подразделение: U6";
edit_access_button_draw();
if (is_edit_access_granted()) {
    echo '<div id="alert_div_1" style="width: 220px; height: 5px; background-color: lightgreen;"></div>';
} else {
    echo '<div id="alert_div_2" style="width: 220px; height: 5px; background-color: gray;"></div>';
}
echo "</td><td width='80%'><br>$application_name<br></td><td></td></tr>";

echo "<div style='position: fixed; top: 10px; right: 10px; background: white; padding: 12px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e5e7eb;'>";
echo "<div style='display: flex; align-items: center; gap: 12px;'>";
echo "<div style='width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px;'>";
echo mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8');
echo "</div><div>";
echo "<div style='font-weight: 600; font-size: 14px; color: #1f2937;'>" . htmlspecialchars($user['full_name'] ?? 'Пользователь') . "</div>";
echo "<div style='font-size: 12px; color: #6b7280;'>" . htmlspecialchars($user['phone'] ?? '') . "</div>";
echo "<div style='font-size: 11px; color: #9ca3af;'>" . $currentDepartment . " • " . ucfirst($userRole ?? 'guest') . "</div>";
echo "</div>";
echo "<a href='../index.php' style='padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px;'>На главную</a>";
echo "<a href='../auth/logout.php' style='padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px;'>Выход</a>";
echo "</div></div>";

echo "<tr><td colspan='3' style='padding: 20px; vertical-align: top;'>";
echo "<div style='display:flex; gap:20px; justify-content:center; align-items:flex-start; flex-wrap:wrap;'>";
echo "<table width='420' bgcolor='white' style='border-collapse: collapse; border-radius: 8px; flex: 0 0 420px;'>";
echo "<tr><td style='padding: 20px;'>";

echo '<div style="padding: 12px; background: #ffffff; border: 2px solid #6495ed; border-radius: 6px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Управление заявками — U6</div>';

echo '<form action="new_order.php" method="post" target="_blank" style="text-align: center; margin-bottom: 10px;">';
echo '<input type="submit" value="Создать заявку вручную" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
echo '</form>';

echo '<form action="archived_orders.php" method="get" target="_blank" style="text-align: center; margin-bottom: 10px;">';
echo '<input type="submit" value="Архив заявок" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
echo '</form>';

echo '<form action="combine_orders.php" method="post" target="_blank" style="text-align: center; margin-bottom: 15px;">';
echo '<input type="submit" value="Объединить заявки" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
echo '</form>';

echo '<div style="border-top: 1px solid #e0e0e0; padding-top: 15px; margin-bottom: 15px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 14px; margin-bottom: 10px; text-align: center;">Операции</div>';
echo '<div style="text-align: center; margin-bottom: 8px;"><a href="product_output.php" target="_blank" style="display:inline-block;height:25px;line-height:25px;width:220px;background:#6495ed;color:white;border-radius:4px;font-weight:bold;text-decoration:none;">Выпуск продукции</a></div>';
echo '<form action="product_output_view.php" method="post" target="_blank" style="text-align: center; margin-bottom: 8px;">';
echo '<input type="submit" value="Обзор выпуска продукции" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
echo '</form>';
echo '<div style="text-align: center; margin-bottom: 8px;"><a href="laser_request.php" target="_blank" style="display:inline-block;height:25px;line-height:25px;width:220px;background:#6495ed;color:white;border-radius:4px;font-weight:bold;text-decoration:none;">Заявка на лазер</a></div>';
echo '<div style="text-align: center; margin-bottom: 8px;"><a href="bobbin_cut_request.php" target="_blank" style="display:inline-block;height:25px;line-height:25px;width:220px;background:#6495ed;color:white;border-radius:4px;font-weight:bold;text-decoration:none;">Заявка на бобинорезку</a></div>';
echo '</div>';

echo '<div style="border-top: 1px solid #e0e0e0; padding-top: 15px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 14px; margin-bottom: 10px; text-align: center;">Сохраненные заявки</div>';

$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    echo 'Ошибка подключения к БД: ' . htmlspecialchars($mysqli->connect_error);
    exit;
}

$sql = "SELECT DISTINCT order_number, workshop, hide FROM orders";
if (!$result = $mysqli->query($sql)) {
    echo 'Ошибка загрузки заявок: ' . htmlspecialchars($mysqli->error);
    exit;
}

if ($result->num_rows === 0) {
    echo "<div style='text-align: center; color: #666; font-style: italic;'>В базе нет ни одной заявки</div>";
}

echo '<form action="show_order.php" method="post" target="_blank" style="text-align: center;">';

$orders_list = [];
while ($orders_data = $result->fetch_assoc()) {
    if ((int)$orders_data['hide'] !== 1) {
        $order_num = $orders_data['order_number'];
        if (!isset($orders_list[$order_num])) {
            $orders_list[$order_num] = $orders_data;
        }
    }
}

foreach ($orders_list as $order_num => $orders_data) {
    $total_planned = 0;
    $total_produced = 0;

    $stmt_total = $mysqli->prepare("SELECT SUM(`count`) as total FROM orders WHERE order_number = ?");
    if ($stmt_total) {
        $stmt_total->bind_param('s', $order_num);
        $stmt_total->execute();
        $res_total = $stmt_total->get_result();
        if ($row_total = $res_total->fetch_assoc()) {
            $total_planned = (int)$row_total['total'];
        }
        $stmt_total->close();
    }

    static $hasManufacturedProduction = null;
    if ($hasManufacturedProduction === null) {
        $check = $mysqli->query("SHOW TABLES LIKE 'manufactured_production'");
        $hasManufacturedProduction = $check && $check->num_rows > 0;
    }
    if ($hasManufacturedProduction) {
        $stmt_produced = $mysqli->prepare("SELECT SUM(count_of_filters) as produced FROM manufactured_production WHERE name_of_order = ?");
        if ($stmt_produced) {
            $stmt_produced->bind_param('s', $order_num);
            $stmt_produced->execute();
            $res_produced = $stmt_produced->get_result();
            if ($row_produced = $res_produced->fetch_assoc()) {
                $total_produced = (int)$row_produced['produced'];
            }
            $stmt_produced->close();
        }
    }

    $progress = $total_planned > 0 ? round(($total_produced / $total_planned) * 100) : 0;
    $safe_order = htmlspecialchars($order_num, ENT_QUOTES, 'UTF-8');

    echo "<button type='submit' name='order_number' value='{$safe_order}' style='height: 35px; width: 215px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; padding: 0 12px; margin-bottom: 8px; margin-left: auto; margin-right: auto;' title='Прогресс выполнения: {$progress}%'>";
    echo "<span style='font-size: 13px; flex: 1; text-align: center;'>{$safe_order}</span>";
    echo "<span style='font-size: 10px; opacity: 0.8; margin-left: 8px;'>[{$progress}%]</span>";
    echo "</button>";
}

echo '</form>';
echo '</div>';

echo '<div style="border-top: 1px solid #e0e0e0; padding-top: 15px; margin-top: 15px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 14px; margin-bottom: 10px; text-align: center;">Загрузка заявки из Excel</div>';
echo '<form enctype="multipart/form-data" action="load_file.php" method="POST" target="_blank" style="text-align: center;">';
echo '<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />';
echo '<div style="margin-bottom: 10px; color: #666; font-size: 12px;">Добавить заявку в систему:</div>';
echo '<div style="margin-bottom: 10px;"><input name="userfile" type="file" /></div>';
echo '<input type="submit" value="Загрузить файл" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;" />';
echo '</form>';
echo '</div>';

echo '</div>';
echo "</td></tr></table>";
echo "<div style='width: 420px; max-width: 100%; flex: 0 0 420px;'>";
show_weekly_production();
echo "</div>";
echo "</div>";
echo "</td></tr></table>";
echo '</body></html>';

$result->close();
$mysqli->close();
