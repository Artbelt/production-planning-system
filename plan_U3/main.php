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
$currentDepartment = $_SESSION['auth_department'] ?? 'U3';

// Проверяем, есть ли у пользователя доступ к цеху U3
$hasAccessToU3 = false;
$userRole = null;
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === 'U3') {
        $hasAccessToU3 = true;
        $userRole = $dept['role_name'];
        break;
    }
}

// Если нет доступа к U3, показываем предупреждение, но не блокируем
if (!$hasAccessToU3) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px; border-radius: 5px;'>";
    echo "<h3>⚠️ Внимание: Нет доступа к цеху U3</h3>";
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

// Для main.php всегда проверяем доступ к цеху U3
$canAccessLaser = canAccessLaserRequests($userDepartments, 'U3');

echo "<link rel=\"stylesheet\" href=\"sheets.css\">";
/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                  Блок авторизации                                                */
/** ---------------------------------------------------------------------------------------------------------------- */

// Устанавливаем переменные для совместимости со старым кодом
$workshop = $currentDepartment;
$advertisement = 'Информация';

$application_name = 'Система управления производством на участке U3';

//echo '<title>'.$workshop.'</title>';
echo '<title>U3</title>';
echo '<head>';
//echo '<script> setInterval(() => window.location.reload(), 15000);</script>';//автообновление страницы каждые 15 сек
?>
<style>
    /* Стиль для кнопки */
    .alert-button {
        background-color: yellow; /* Зеленый фон */
    }

    /* Дополнительный стиль при наведении */
    .alert-button:hover {
        background-color: skyblue; /* Темно-зеленый фон при наведении */
    }
</style>


<?php
echo '</head>';



/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 конец авторизации                                                */
/** ---------------------------------------------------------------------------------------------------------------- */

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                              Шапка главного окна                                                 */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "<table  width=100% height=100% style='background-color: #6495ed' >"
    ."<tr height='10%' align='center' style='background-color: #dedede'><td width='20%' >Подразделение: U3";

edit_access_button_draw();

if (is_edit_access_granted()){
    echo '<div id = "alert_div_1" style="width: 220; height: 5; background-color: lightgreen;"></div><p>';
}else{
    echo '<div id = "alert_div_2" style="width: 220; height: 5; background-color: gray;"></div><p>';
}

echo "</td><td width='80%'><!--#application_name=--><br>".$application_name."<br></td>"
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

echo "<tr height='10%' align='center' ><td colspan='3'><br>";

/** Раздел объявлений */
echo $advertisement."</td></tr>";


/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ОПЕРАЦИИ                                                  */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "<tr align='center'><td>"
    ."<table  height='100%' width='100%' bgcolor='white' style='border-collapse: collapse'>"
    ."<tr height='80%'><td>Операции: <p>";

?>
<a href="product_output.php" target="_blank" rel="noopener noreferrer">
    <button style="height: 20px; width: 220px">Выпуск продукции</button>
</a>
<?php
echo "<form action='cap_storage.php' method='post' target='_blank' ><input type='submit' value='Операции с крышками'  style=\"height: 20px; width: 220px\"></form>"
    ."<form action='parts_output_for_workers.php' method='post' target='_blank' ><input type='submit' value='Выпуск гофропакетов' style=\"height: 20px; width: 220px\"></form>";
    
if ($canAccessLaser) {
    echo "<a href='laser_request.php' target='_blank' rel='noopener noreferrer'>";
    echo "<button type='button' style='height: 20px; width: 220px'>Заявка на лазер</button>";
    echo "</a>";
    }
echo "<p>Информация: <p>";
    ?>
    <form action='dimensions_report.php' method='post' target='_blank' ><input type='submit' value='Таблица размеров для участка'  style=\"height: 20px; width: 220px\"></form>
    <form action='product_output_view.php' method='post' target='_blank' ><input type='submit' value='Обзор выпуска продукции'  style=\"height: 20px; width: 220px\"></form>
<form action="gofra_packages_table.php" method="post" target="_blank">
    <input type="hidden" name="workshop" value="<?= htmlspecialchars($workshop) ?>">
    <input type="submit" value="Кол-во гофропакетов из рулона">
</form>
</td></tr>
    <tr bgcolor='#6495ed'><td>


    <?php
    /** ---------------------------------------------------------------------------------------------------------------- */
    /**                                                 Раздел ПРИЛОЖЕНИЯ                                                */
    /** ---------------------------------------------------------------------------------------------------------------- */
    echo "Управление данными <p>"
    /**
    ."<form action='[DEL]add_filter_into_db.php' method='post'>"
    ."<input type='hidden' name='workshop' value='$workshop'>"
    ."<input type='submit'  value='добавить фильтр в БД-----ххх'  style=\"height: 20px; width: 220px\">"
    ."</form>"
     */
    /** Добавление полной информации по фильтру  */
    . "<form action='add_round_filter_into_db.php' method='post' target='_blank' >"
    ."<input type='hidden' name='workshop' value='$workshop'>"
    ."<input type='submit'  value='добавить фильтр в БД(full)'  style=\"height: 20px; width: 220px\">"
    ."</form>"

    ."<form action='edit_filter_properties.php' method='post' target='_blank' >"
    ."<input type='hidden' name='workshop' value='$workshop'>"
    ."<input type='submit'  value='изменить параметры фильтра'  style=\"height: 20px; width: 220px\">"
    ."</form>"

    ."<form action='manufactured_production_editor.php' method='post' target='_blank'>"
    ."<input type='hidden' name='workshop' value='U3'>"
    ."<input type='submit'  value='Редактор выпуска продукции'  style=\"height: 20px; width: 220px\">"
    ."</form>"

    ."<form action='manufactured_parts_editor.php' method='post' target='_blank'>"
    ."<input type='hidden' name='workshop' value='U3'>"
    ."<input type='submit'  value='Редактор выпуска комплектующих'  style=\"height: 20px; width: 220px\">"
    ."</form>";
?>
<form action="create_ad.php" method="post" style="display: flex; flex-direction: column; max-width: 400px; gap: 10px;">
    <label style="display: flex; flex-direction: column; font-weight: bold;">
        <span>Название объявления</span>
        <input type="text" name="title" placeholder="Введите название" required
               style="padding: 8px; font-size: 16px; border: 1px solid #ccc; border-radius: 5px;">
    </label>

    <label style="display: flex; flex-direction: column; font-weight: bold;">
        <span>Текст объявления</span>
        <textarea name="content" placeholder="Введите текст" required
                  style="padding: 8px; font-size: 16px; border: 1px solid #ccc; border-radius: 5px; resize: vertical; min-height: 100px;"></textarea>
    </label>

    <label style="display: flex; flex-direction: column; font-weight: bold;">
        <span>Дата окончания</span>
        <input type="date" name="expires_at" required
               style="padding: 8px; font-size: 16px; border: 1px solid #ccc; border-radius: 5px;">
    </label>

    <button type="submit"
            style="padding: 10px; font-size: 16px; background-color: #007bff; color: white; cursor: pointer; border: none; border-radius: 5px;"
            onmouseover="this.style.backgroundColor='#0056b3'"
            onmouseout="this.style.backgroundColor='#007bff'">
        Создать объявление
    </button>
</form>


<?php
echo"</td></tr>"
    ."</table>";

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ЗАДАЧИ                                                    */
/** ---------------------------------------------------------------------------------------------------------------- */
echo "</td><td>"
    ."<table height='100%' width='100%' bgcolor='white' style='border-collapse: collapse'>";


echo "<tr><td style='color: cornflowerblue'> <p>";
show_ads();
echo "Изготовленая продукция за последние 10 дней:";
show_weekly_production();
echo "<p>Изготовленные гофропакеты за последние 10 дней<p>";


show_weekly_parts();

?>
<div style="max-width:600px;padding:12px;border:1px solid #ddd;border-radius:10px;margin:12px 0;">
    <h4 style="margin:0 0 8px;">Поиск заявок по фильтру</h4>

    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <label for="filterSelect">Фильтр:</label>
        <?php
        load_filters_into_select(); // <select name="analog_filter">
        ?>
    </div>

    <div id="filterSearchResult" style="margin-top:12px;"></div>
</div>

<script>
    (function(){
        const resultBox = document.getElementById('filterSearchResult');

        function getSelectEl(){
            return document.querySelector('select[name="analog_filter"]');
        }

        async function runSearch(){
            const sel = getSelectEl();
            if(!sel){ resultBox.innerHTML = '<div style="color:red">Не найден выпадающий список.</div>'; return; }
            const val = sel.value.trim();
            if(!val){ resultBox.innerHTML = '<div style="color:#666">Выберите фильтр…</div>'; return; }

            resultBox.innerHTML = 'Загрузка…';

            try{
                const formData = new FormData();
                formData.append('filter', val);

                const resp = await fetch('search_filter_in_the_orders.php', {
                    method: 'POST',
                    body: formData
                });

                if(!resp.ok){
                    resultBox.innerHTML = `<div style="color:red">Ошибка запроса: ${resp.status} ${resp.statusText}</div>`;
                    return;
                }

                const html = await resp.text();
                resultBox.innerHTML = html;
            }catch(e){
                resultBox.innerHTML = `<div style="color:red">Ошибка: ${e}</div>`;
            }
        }

        const sel = getSelectEl();
        if(sel){
            sel.id = 'filterSelect'; // для label for
            sel.addEventListener('change', runSearch);
        }
    })();
</script>
<?php


echo "</td></tr><tr><td></td></tr>"
    ."</table>"
    ."</td><td>";

/** ---------------------------------------------------------------------------------------------------------------- */
/**                                                 Раздел ЗАЯВКИ                                                    */
/** ---------------------------------------------------------------------------------------------------------------- */

/** Форма загрузки файла с заявкой в БД */
echo '<table height="100%" ><tr><td bgcolor="white" style="border-collapse: collapse">Сохраненные заявки<p>';


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
if ($result->num_rows === 0) { echo "В базе нет ни одной заявки";}

/** Разбор массива значений  */
echo '<form action="show_order.php" method="post" target="_blank" >';

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
    
    // Формируем значение с меньшим шрифтом для процента
    $btnStyle = "height: 20px; width: 215px; font-size: 13px;";
    $btnClass = str_contains($order_num, '[!]') ? "class='alert-button'" : "";
    
    echo "<button type='submit' name='order_number' value='{$order_num}' {$btnClass} style=\"{$btnStyle}\" title='Прогресс выполнения: {$progress}%'>";
    echo "<span style='font-size: 13px;'>{$order_num}</span> ";
    echo "<span style='font-size: 10px; opacity: 0.8;'>[{$progress}%]</span>";
    echo "</button>";
}
echo '</form>';

?>
<form action="archived_orders.php" target="_blank">
    <input type="submit" value="Архив заявок" style="height: 20px; width: 215px">
</form>

<?php

/** Блок распланированных заявок  */
echo "Операции над заявками<p>";
echo "<form action='new_order.php' method='post' target='_blank'>"
    ."<input type='submit' value='Создать заявку вручную' style='height: 20px; width: 220px'>"
    ."</form>";
echo "<form action='planning_manager.php' method='post'  target='_blank' >"
    ."<input type='submit' value='Менеджер планирования' style='height: 20px; width: 220px'>"
    ."</form>";
echo "<form action='combine_orders.php' method='post' target='_blank' >"
    ."<input type='submit' value='Объединение заявок' style='height: 20px; width: 220px'>"
    ."</form>";
echo "<form action='NP_cut_index.php' method='post' target='_blank'>"
    ."<input type='submit' value='Планирование работы (new)' style='height: 20px; width: 220px'>"
    ."</form>";

/** Блок анализа производства */
echo "Мониторинг выполнения плана<p>";
echo "<form action='plan_monitoring.php' method='post' target='_blank' >"
    ."<input type='submit' value='Просмотр плана ' style='height: 20px; width: 140px'>";
load_plans();
echo "</form>";



echo "<button onclick=\"window.open('http://localhost/plan_U3/json_editor.html', '_blank');\">Редактор плана</button>";




/** Блок загрузки заявок */
echo "</td></tr><tr><td height='20%'>"
    .'<form enctype="multipart/form-data" action="load_file.php" method="POST" target="_blank" >'
    .'<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />'
    .'Добавить заявку в систему: <input name="userfile" type="file" /><br>'
    .'<input type="submit" value="Загрузить файл"  style="height: 20px; width: 220px" />'
    .'</form>'
    .'</td></tr></table>';

/** конец формы загрузки */
echo "</td></tr></table>";
echo "</td></tr></table>";
$result->close();
$mysqli->close();


?>
