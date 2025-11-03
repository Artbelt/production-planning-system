<?php
// Проверяем авторизацию через новую систему
require_once('../auth/includes/config.php');
require_once('../auth/includes/auth-functions.php');

// Подключаем настройки базы данных
require_once('settings.php');
require_once('tools/tools.php');

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

// Получаем информацию о пользователе
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

// Если пользователь не найден, используем данные из сессии
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

// Определяем текущий цех
$currentDepartment = $_SESSION['auth_department'] ?? 'U4';

// Проверяем, есть ли у пользователя доступ к цеху U4
$hasAccessToU4 = false;
$userRole = null;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U4') {
        $hasAccessToU4 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

// Если нет доступа к U4, показываем предупреждение, но не блокируем
if (!$hasAccessToU4) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U4</h3>";
    echo "<p>Ваши доступные цеха: ";
    $deptNames = [];
    foreach ($userDepartments as $dept) {
        $deptNames[] = $dept['department_code'] . " (" . $dept['role_name'] . ")";
    }
    echo implode(", ", $deptNames);
    echo "</p>";
    echo "<p><a href='../index.php'>← Вернуться на главную страницу</a></p>";
    echo "</div>";
    
    // Устанавливаем роль по умолчанию для отображения
    $userRole = 'guest';
}

// Функция проверки доступа к заявкам на лазер
function canAccessLaserRequests($userDepartments, $currentDepartment) {
    // Проверяем доступ для текущего цеха
    foreach ($userDepartments as $dept) {
        if ($dept['department_code'] === $currentDepartment) {
            $role = $dept['role_name'];
            // Доступ имеют: сборщики, мастера, директора (но не менеджеры)
            return in_array($role, ['assembler', 'supervisor', 'director']);
        }
    }
    return false;
}

// Для main.php всегда проверяем доступ к цеху U4
$canAccessLaser = canAccessLaserRequests($userDepartments, 'U4');

echo "<link rel=\"stylesheet\" href=\"sheets.css\">";
/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                  Блок авторизации                                                */
/** ---------------------------------------------------------------------------------------------------------------- */

// Устанавливаем переменные для совместимости со старым кодом
$workshop = $currentDepartment;
$advertisement = 'Информация';


//echo '<title>'.$workshop.'</title>';
echo '<title>U4</title>';
echo '<head>';
//echo '<script> setInterval(() => window.location.reload(), 15000);</script>';//автообновление страницы каждые 15 сек
echo '</head>';


/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 конец авторизации                                                */
/** ---------------------------------------------------------------------------------------------------------------- */

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                              Шапка главного окна                                                 */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "<table  width=100% height=100% style='background-color: #6495ed' >"
    ."<tr height='10%' align='center' style='background-color: #dedede'><td width='20%' >Подразделение: U4";

    edit_access_button_draw();

if (is_edit_access_granted()){
    echo '<div id = "alert_div_1" style="width: 220; height: 5; background-color: lightgreen;"></div><p>';
}else{
    echo '<div id = "alert_div_2" style="width: 220; height: 5; background-color: gray;"></div><p>';
}

echo "</td><td width='80%'><!--#application_name=--><br>$application_name<br></td>"
    ."<td ><!-- Панель авторизации перенесена вверх --></td></tr>";
    
// Добавляем аккуратную панель авторизации
echo "<!-- Аккуратная панель авторизации -->
<div style='position: fixed; top: 10px; right: 10px; background: white; padding: 12px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e5e7eb;'>
    <div style='display: flex; align-items: center; gap: 12px;'>
        <div style='width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px;'>
            " . mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8') . "
        </div>
        <div>
            <div style='font-weight: 600; font-size: 14px; color: #1f2937;'>" . htmlspecialchars($user['full_name'] ?? 'Пользователь') . "</div>
            <div style='font-size: 12px; color: #6b7280;'>" . htmlspecialchars($user['phone'] ?? '') . "</div>
            <div style='font-size: 11px; color: #9ca3af;'>" . $currentDepartment . " • " . ucfirst($userRole ?? 'guest') . "</div>
        </div>
        <a href='../auth/change-password.php' style='padding: 4px 8px; background: transparent; color: #9ca3af; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: 400; transition: all 0.2s; border: 1px solid #e5e7eb;' onmouseover='this.style.background=\"#f9fafb\"; this.style.color=\"#6b7280\"; this.style.borderColor=\"#d1d5db\"' onmouseout='this.style.background=\"transparent\"; this.style.color=\"#9ca3af\"; this.style.borderColor=\"#e5e7eb\"'>Пароль</a>
        <a href='../auth/logout.php' style='padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500; transition: background-color 0.2s;' onmouseover='this.style.background=\"#e5e7eb\"' onmouseout='this.style.background=\"#f3f4f6\"'>Выход</a>
    </div>
</div>";

echo "<tr height='10%' align='center' ><td colspan='3'>#attention block<br>";

/** Раздел объявлений */
echo $advertisement."</td></tr>";


/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ОПЕРАЦИИ                                                  */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "<tr align='center'><td>"
    ."<table  height='100%' width='100%' bgcolor='white' style='border-collapse: collapse'>"
    ."<tr height='80%'><td>";
    
    // Блок Операции
    echo '<div style="margin-bottom: 15px; padding: 12px; background: #ffffff; border: 2px solid #6495ed; border-radius: 6px;">';
    echo '<div style="color: #6495ed; font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Операции</div>';
    echo '<form action="product_output.php" method="post" target="_blank" style="text-align: center;">';
    echo '<input type="submit" value="Выпуск продукции" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
    echo '</form>';
    if ($canAccessLaser) {
        echo '<div style="text-align: center; margin-top: 10px;">';
        echo '<a href="laser_request.php" target="_blank" rel="noopener" style="display: inline-block;">';
        echo '<button type="button" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Заявка на лазер</button>';
        echo '</a>';
        echo '</div>';
    }
    echo '</div>';
    
    // Блок Информация
    echo '<div style="margin-bottom: 15px; padding: 12px; background: #ffffff; border: 2px solid #6495ed; border-radius: 6px;">';
    echo '<div style="color: #6495ed; font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Информация</div>';
    echo '<form action="product_output_view.php" method="post" target="_blank" style="text-align: center;">';
    echo '<input type="submit" value="Обзор выпуска продукции" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
    echo '</form>';
    echo '</div>';
    
    echo "</td></tr>"
    ."<tr bgcolor='#6495ed'><td>"

    /** ---------------------------------------------------------------------------------------------------------------- */
    /**                                                 Раздел ПРИЛОЖЕНИЯ                                                */
    /** ---------------------------------------------------------------------------------------------------------------- */
    ."Управление данными <p>"
    /**
    ."<form action='add_filter_into_db.php' method='post'>"
    ."<input type='hidden' name='workshop' value='$workshop'>"
    ."<input type='submit'  value='добавить фильтр в БД-----ххх'  style=\"height: 20px; width: 220px\">"
    ."</form>"
     */
    /** Добавление полной информации по фильтру  */
    . "<form action='add_round_filter_into_db.php' method='post' target='_blank' >"
    ."<input type='hidden' name='workshop' value='$workshop'>"
    ."<input type='submit'  value='добавить фильтр в БД(full)'  style=\"height: 20px; width: 220px\">"
    ."</form>"


    ."<form action='manufactured_production_editor.php' method='post' target='_blank'>"
    ."<input type='hidden' name='workshop' value='U3'>"
    ."<input type='submit'  value='Редактор внесенной продукции'  style=\"height: 20px; width: 220px\">"
    ."</form>"

    ."</td></tr>"
    ."</table>";

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ЗАДАЧИ                                                    */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "</td><td>"
    ."<table height='100%' width='100%' bgcolor='white' style='border-collapse: collapse'>"
    //."<tr><td>1</td><td>2</td></tr>"
    ."<tr><td style='color: cornflowerblue'>Изготовленая продукция за последние 10 дней: <p>";

show_weekly_production();
echo "<p>Изготовленные гофропакеты за последние 10 дней<p>";



echo "</td></tr><tr><td></td></tr>"
    ."</table>"
    ."</td><td>";

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ЗАЯВКИ                                                    */
/** ---------------------------------------------------------------------------------------------------------------- */

/** Форма загрузки файла с заявкой в БД */
echo '<table height="100%" ><tr><td bgcolor="white" style="border-collapse: collapse">';

/** Раздел Управление заявками */
echo '<div style="margin-bottom: 15px; padding: 12px; background: #ffffff; border: 2px solid #6495ed; border-radius: 6px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center; border-bottom: 1px solid #e0e0e0; padding-bottom: 8px;">Управление заявками</div>';
echo '<form action="new_order.php" method="post" target="_blank" style="text-align: center; margin-bottom: 15px;">';
echo '<input type="submit" value="Создать заявку вручную" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">';
echo '</form>';

// Раздел "Сохраненные заявки"
echo '<div style="border-top: 1px solid #e0e0e0; padding-top: 15px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 14px; margin-bottom: 10px; text-align: center;">Сохраненные заявки</div>';

/** Подключаемся к БД */
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    /** Если не получилось подключиться */
    echo 'Возникла проблема на сайте'
        . "Номер ошибки: " . $mysqli->connect_errno . "\n"
        . "Ошибка: " . $mysqli->connect_error . "\n";
    exit;
}

/** Выполняем запрос SQL для загрузки заявок*/
$sql = "SELECT DISTINCT order_number, workshop, hide FROM orders;";
if (!$result = $mysqli->query($sql)){
    echo "Ошибка: Наш запрос не удался и вот почему: \n Запрос: " . $sql . "\n"
        ."Номер ошибки: " . $mysqli->errno . "\n Ошибка: " . $mysqli->error . "\n";
    exit;
}
/** Разбираем результат запроса */
if ($result->num_rows === 0) { echo "<div style='text-align: center; color: #666; font-style: italic;'>В базе нет ни одной заявки</div>";}

/** Разбор массива значений  */
echo '<form action="show_order.php" method="post" target="_blank" style="text-align: center;">';

// Группируем заявки для отображения
$orders_list = [];
while ($orders_data = $result->fetch_assoc()){
    if ($orders_data['hide'] != 1){
        $order_num = $orders_data['order_number'];
        if (!isset($orders_list[$order_num])) {
            $orders_list[$order_num] = $orders_data;
        }
    }
}

// Выводим уникальные заявки с прогрессом
foreach ($orders_list as $order_num => $orders_data){
    // Расчет прогресса для заявки
    $total_planned = 0;
    $total_produced = 0;
    
    // Получаем общее количество по заявке
    $sql_total = "SELECT SUM(count) as total FROM orders WHERE order_number = '$order_num'";
    if ($res_total = $mysqli->query($sql_total)) {
        if ($row_total = $res_total->fetch_assoc()) {
            $total_planned = (int)$row_total['total'];
        }
    }
    
    // Получаем произведенное количество
    $sql_produced = "SELECT SUM(count_of_filters) as produced FROM manufactured_production WHERE name_of_order = '$order_num'";
    if ($res_produced = $mysqli->query($sql_produced)) {
        if ($row_produced = $res_produced->fetch_assoc()) {
            $total_produced = (int)$row_produced['produced'];
        }
    }
    
    // Вычисляем процент
    $progress = 0;
    if ($total_planned > 0) {
        $progress = round(($total_produced / $total_planned) * 100);
    }
    
    // Формируем кнопку с меньшим шрифтом для процента
    echo "<button type='submit' name='order_number' value='{$order_num}' style='height: 35px; width: 215px; font-size: 13px; display: flex; justify-content: space-between; align-items: center; padding: 0 12px; margin-bottom: 8px; margin-left: auto; margin-right: auto;' title='Прогресс выполнения: {$progress}%'>";
    echo "<span style='font-size: 13px; flex: 1; text-align: center;'>{$order_num}</span>";
    echo "<span style='font-size: 10px; opacity: 0.8; margin-left: 8px;'>[{$progress}%]</span>";
    echo "</button>";
}

echo '</form>';
echo '</div>'; // Закрываем подраздел "Сохраненные заявки"

// Добавляем блок загрузки файлов
echo '<div style="border-top: 1px solid #e0e0e0; padding-top: 15px;">';
echo '<div style="color: #6495ed; font-weight: bold; font-size: 14px; margin-bottom: 10px; text-align: center;">Загрузка заявки</div>';
echo '<form enctype="multipart/form-data" action="load_file.php" method="POST" target="_blank" style="text-align: center;">';
echo '<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />';
echo '<div style="margin-bottom: 10px; color: #666; font-size: 12px;">Добавить заявку в систему:</div>';
echo '<div style="margin-bottom: 10px;"><input name="userfile" type="file" /></div>';
echo '<input type="submit" value="Загрузить файл" style="height: 25px; width: 220px; background: #6495ed; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;" />';
echo '</form>';
echo '</div>'; // Закрываем подраздел "Загрузка заявки"

echo '</div>'; // Закрываем основной блок "Управление заявками"

// Закрываем соединение с БД
$result->close();
$mysqli->close();





echo "</td></tr></table>";
echo "</td></tr></table>";


?>
