<?php
require_once('tools/tools.php');
require_once('tools/backup_before_update.php');

$filter_name = $_POST['filter_name'] ?? '';
$category = $_POST['category'] ?? 'Салонный';

if (empty($filter_name)) {
    die("Ошибка: не указано имя фильтра");
}

/** ГОФРОПАКЕТ */
$p_p_name = "гофропакет " . $filter_name;
$p_p_width = $_POST['p_p_width'] ?? '';
$p_p_height = $_POST['p_p_height'] ?? '';
$p_p_pleats_count = $_POST['p_p_pleats_count'] ?? '';
$p_p_supplier = $_POST['p_p_supplier'] ?? '';
$p_p_remark = $_POST['p_p_remark'] ?? '';
$p_p_material = $_POST['p_p_material'] ?? '';

/** ВСТАВКА */
// ВАЖНО: Используем null вместо пустой строки, чтобы не очищать существующие данные
$insertion_count = !empty($_POST['insertions_count']) ? trim($_POST['insertions_count']) : null;

/** УПАКОВКА ИНД*/
$box = !empty($_POST['box']) ? trim($_POST['box']) : null;
/** УПАКОВКА ГР */
$g_box = !empty($_POST['g_box']) ? trim($_POST['g_box']) : null;
/** ПРИМЕЧАНИЕ */
$remark = !empty($_POST['remark']) ? trim($_POST['remark']) : null;
/** Высота ленты  */
$side_type = !empty($_POST['side_type']) ? trim($_POST['side_type']) : null;
/** Поролон */
$foam_rubber = isset($_POST['foam_rubber']) ? 'поролон' : '';
/** Язычек */
$tail = isset($_POST['tail']) ? 'язычек' : '';
/** Форм-фактор */
$form_factor = isset($_POST['form_factor']) ? 'трапеция' : '';

/** Надрезы */
$has_edge_cuts = isset($_POST['has_edge_cuts']) ? 1 : 0;

/** Тариф и сложность производства */
$tariff_id = $_POST['tariff_id'] ?? '';
$build_complexity = $_POST['build_complexity'] ?? '';

// Очищаем tariff_id если он пустой
if ($tariff_id === '' || $tariff_id === '0') {
    $tariff_id = null;
} else {
    $tariff_id = (int)$tariff_id;
}

// Очищаем build_complexity если он пустой
if ($build_complexity === '' || $build_complexity === '0') {
    $build_complexity = null;
} else {
    $build_complexity = (float)$build_complexity;
}

/** Проверяем, есть ли фильтр в БД */
$a = check_filter($filter_name);

/** Если фильтра нет в БД -> ошибка */
if (!$a) {
    die("Ошибка: Фильтр {$filter_name} не найден в БД. Редактирование возможно только для существующих фильтров.");
}

// Используем подготовленные запросы для безопасности
global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);

if ($mysqli->connect_errno) {
    die("Ошибка подключения к БД: " . $mysqli->connect_error);
}

// ЗАЩИТА: Создаем резервную копию перед обновлением
backup_filter_before_update($mysqli, $filter_name);

// ЗАЩИТА: Получаем текущие значения перед обновлением
$stmt_current = $mysqli->prepare("SELECT box, insertion_count, g_box, side_type FROM salon_filter_structure WHERE filter = ?");
$stmt_current->bind_param('s', $filter_name);
$stmt_current->execute();
$current_data = $stmt_current->get_result()->fetch_assoc();
$stmt_current->close();

// ЗАЩИТА: Используем COALESCE - обновляем только если новое значение не пустое, иначе оставляем старое
// Это предотвращает случайную очистку данных
$sql = "UPDATE salon_filter_structure SET 
        category = ?,
        insertion_count = COALESCE(NULLIF(?, ''), insertion_count),
        box = COALESCE(NULLIF(?, ''), box),
        g_box = COALESCE(NULLIF(?, ''), g_box),
        comment = COALESCE(NULLIF(?, ''), comment),
        foam_rubber = ?,
        form_factor = ?,
        tail = ?,
        side_type = COALESCE(NULLIF(?, ''), side_type),
        has_edge_cuts = ?
        WHERE filter = ?";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Ошибка подготовки запроса: " . $mysqli->error);
}

// Преобразуем null в пустую строку для COALESCE
$insertion_count_safe = $insertion_count ?? '';
$box_safe = $box ?? '';
$g_box_safe = $g_box ?? '';
$remark_safe = $remark ?? '';
$side_type_safe = $side_type ?? '';

$stmt->bind_param('ssssssssssi', 
    $category,
    $insertion_count_safe,
    $box_safe,
    $g_box_safe,
    $remark_safe,
    $foam_rubber,
    $form_factor,
    $tail,
    $side_type_safe,
    $has_edge_cuts,
    $filter_name
);

if (!$stmt->execute()) {
    die("Ошибка обновления фильтра: " . $stmt->error);
}

$stmt->close();

/** Обновляем тариф и сложность отдельно */
if ($tariff_id !== null) {
    $sql_tariff = "UPDATE salon_filter_structure SET tariff_id = ? WHERE filter = ?";
    $stmt_tariff = $mysqli->prepare($sql_tariff);
    $stmt_tariff->bind_param('is', $tariff_id, $filter_name);
    $stmt_tariff->execute();
    $stmt_tariff->close();
} else {
    $sql_tariff = "UPDATE salon_filter_structure SET tariff_id = NULL WHERE filter = ?";
    $stmt_tariff = $mysqli->prepare($sql_tariff);
    $stmt_tariff->bind_param('s', $filter_name);
    $stmt_tariff->execute();
    $stmt_tariff->close();
}

if ($build_complexity !== null) {
    $sql_complexity = "UPDATE salon_filter_structure SET build_complexity = ? WHERE filter = ?";
    $stmt_complexity = $mysqli->prepare($sql_complexity);
    $stmt_complexity->bind_param('ds', $build_complexity, $filter_name);
    $stmt_complexity->execute();
    $stmt_complexity->close();
} else {
    $sql_complexity = "UPDATE salon_filter_structure SET build_complexity = NULL WHERE filter = ?";
    $stmt_complexity = $mysqli->prepare($sql_complexity);
    $stmt_complexity->bind_param('s', $filter_name);
    $stmt_complexity->execute();
    $stmt_complexity->close();
}

/** Обновляем информацию о гофропакете в БД */
$sql_paper = "INSERT INTO paper_package_salon(p_p_name, p_p_height, p_p_width, p_p_pleats_count, p_p_supplier, p_p_remark, p_p_material) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            p_p_height = VALUES(p_p_height),
            p_p_width = VALUES(p_p_width),
            p_p_pleats_count = VALUES(p_p_pleats_count),
            p_p_supplier = VALUES(p_p_supplier),
            p_p_remark = VALUES(p_p_remark),
            p_p_material = VALUES(p_p_material)";

$stmt_paper = $mysqli->prepare($sql_paper);
if (!$stmt_paper) {
    die("Ошибка подготовки запроса для гофропакета: " . $mysqli->error);
}

$stmt_paper->bind_param('sssssss',
    $p_p_name,
    $p_p_height,
    $p_p_width,
    $p_p_pleats_count,
    $p_p_supplier,
    $p_p_remark,
    $p_p_material
);

if (!$stmt_paper->execute()) {
    die("Ошибка обновления гофропакета: " . $stmt_paper->error);
}

$stmt_paper->close();
$mysqli->close();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Параметры фильтра обновлены</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f9fafb;
        }
        .container {
            background: white;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            text-align: center;
            max-width: 500px;
        }
        .success {
            color: #059669;
            font-size: 48px;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0 0 8px 0;
            font-size: 24px;
            color: #1f2937;
        }
        p {
            margin: 16px 0;
            color: #6b7280;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 16px;
            transition: background .15s;
        }
        .btn:hover {
            background: #1e4ed8;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓</div>
        <h1>Параметры фильтра успешно обновлены!</h1>
        <p>Фильтр <strong><?php echo htmlspecialchars($filter_name); ?></strong> был успешно обновлен в базе данных.</p>
        <a href="add_filter_properties_into_db.php?workshop=U5" class="btn">Редактировать другой фильтр</a>
        <br>
        <a href="main.php" class="btn" style="background: #6b7280; margin-top: 8px;">Вернуться на главную</a>
    </div>
</body>
</html>

