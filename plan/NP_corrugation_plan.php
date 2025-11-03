<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$order = $_GET['order'] ?? '';
$days = intval($_GET['days'] ?? 9);
$start = $_GET['start'] ?? date('Y-m-d');

$start_date = new DateTime($start);
$dates = [];
for ($i = 0; $i < $days; $i++) {
    $dates[] = $start_date->format('Y-m-d');
    $start_date->modify('+1 day');
}

// Проверяем, что order не пустой
if (empty($order)) {
    die("Ошибка: не указан номер заявки");
}

// Получение позиций из раскроя - разбиваем сложный запрос на несколько простых

try {
    // Сначала получаем основные данные из cut_plans и roll_plan
    $stmt1 = $pdo->prepare("
        SELECT c.filter, c.height, c.width, c.length, rp.plan_date
    FROM cut_plans c
        INNER JOIN roll_plan rp ON c.bale_id = rp.bale_id AND rp.order_number = c.order_number
    WHERE c.order_number = ?
        ORDER BY rp.plan_date, c.filter
        LIMIT 500
    ");
    $stmt1->execute([$order]);
    $basic_positions = $stmt1->fetchAll();
    
    if (empty($basic_positions)) {
        throw new Exception("Нет данных для заявки");
    }
    
    // Получаем уникальные фильтры для дальнейших запросов
    $filters = array_unique(array_column($basic_positions, 'filter'));
    $filter_data = [];
    
    // Получаем данные по фильтрам одним запросом
    if (!empty($filters)) {
        $filters_array = array_values($filters);
        $placeholders = implode(',', array_fill(0, count($filters_array), '?'));
        $stmt2 = $pdo->prepare("
            SELECT filter, paper_package, glueing, prefilter, form_factor_id
            FROM panel_filter_structure 
            WHERE filter IN ($placeholders)
        ");
        $stmt2->execute($filters_array);
        while ($row = $stmt2->fetch()) {
            // Сохраняем с trim для корректного поиска
            $filter_data[trim($row['filter'])] = $row;
        }
    }
    
    // Получаем данные по бумаге из panel_filter_structure
    $paper_names = array_unique(array_column($filter_data, 'paper_package'));
    $paper_names = array_filter($paper_names, function($name) { return !empty($name); });
    $paper_data = [];
    
    if (!empty($paper_names)) {
        $paper_names_array = array_values($paper_names);
        $placeholders_paper = implode(',', array_fill(0, count($paper_names_array), '?'));
        $stmt3 = $pdo->prepare("
            SELECT p_p_name, p_p_height, p_p_pleats_count
            FROM paper_package_panel 
            WHERE p_p_name IN ($placeholders_paper)
        ");
        $stmt3->execute($paper_names_array);
        while ($row = $stmt3->fetch()) {
            $paper_data[$row['p_p_name']] = $row;
        }
    }
    
    // Получаем form_factors
    $form_factor_ids = array_unique(array_column($filter_data, 'form_factor_id'));
    $form_factor_ids = array_filter($form_factor_ids, function($id) { return !empty($id); });
    $form_factors = [];
    
    if (!empty($form_factor_ids)) {
        $form_factor_ids_array = array_values($form_factor_ids);
        $placeholders_form = implode(',', array_fill(0, count($form_factor_ids_array), '?'));
        $stmt4 = $pdo->prepare("
            SELECT id, name
            FROM form_factors 
            WHERE id IN ($placeholders_form)
        ");
        $stmt4->execute($form_factor_ids_array);
        while ($row = $stmt4->fetch()) {
            $form_factors[$row['id']] = $row['name'];
        }
    }
    
    // Объединяем данные
    $positions = [];
    foreach ($basic_positions as $pos) {
        // Используем trim для корректного поиска
        $filter_key = trim($pos['filter']);
        $filter_info = $filter_data[$filter_key] ?? [];
        
        // Если не найдено точное совпадение, попробуем варианты с заменой символов
        if (empty($filter_info)) {
            // Нормализуем для поиска: заменяем специальные символы на обычные
            $normalized_filter = $filter_key;
            $normalized_filter = str_replace(['Ö', 'ö', 'Ü', 'ü', 'Ä', 'ä'], ['O', 'o', 'U', 'u', 'A', 'a'], $normalized_filter);
            
            // Ищем по всем ключам
            foreach ($filter_data as $key => $value) {
                $normalized_key = str_replace(['Ö', 'ö', 'Ü', 'ü', 'Ä', 'ä'], ['O', 'o', 'U', 'u', 'A', 'a'], $key);
                
                if ($normalized_key === $normalized_filter) {
                    $filter_info = $value;
                    error_log("Найдено совпадение с нормализацией: '$filter_key' -> '$key'");
                    break;
                }
            }
        }
        
        $paper_info = $paper_data[$filter_info['paper_package'] ?? ''] ?? [];
        $form_factor_name = $form_factors[$filter_info['form_factor_id'] ?? ''] ?? '';
        
        // Отладка для MRA-076 и AF1601s
        if (strpos($pos['filter'], 'MRA-076') !== false || strpos($pos['filter'], 'AF1601s') !== false) {
            error_log("=== DEBUG for " . $pos['filter'] . " ===");
            error_log("- pos[filter]: " . $pos['filter']);
            error_log("- filter_info: " . json_encode($filter_info));
            error_log("- paper_package: " . ($filter_info['paper_package'] ?? 'NULL'));
            error_log("- paper_info: " . json_encode($paper_info));
            error_log("- p_p_pleats_count: " . ($paper_info['p_p_pleats_count'] ?? 'NULL'));
            error_log("- p_p_height: " . ($paper_info['p_p_height'] ?? 'NULL'));
        }
        
        $positions[] = [
            'plan_date' => $pos['plan_date'],
            'filter' => $pos['filter'],
            'height' => $pos['height'],
            'width' => $pos['width'],
            'length' => $pos['length'],
            'paper_package' => $filter_info['paper_package'] ?? '',
            'p_p_height' => $paper_info['p_p_height'] ?? 0,
            'p_p_pleats_count' => $paper_info['p_p_pleats_count'] ?? 0,
            'glueing' => $filter_info['glueing'] ?? '',
            'prefilter' => $filter_info['prefilter'] ?? '',
            'form_factor' => $form_factor_name
        ];
    }
    
} catch (Exception $e) {
    die("Ошибка выполнения запроса: " . $e->getMessage());
}

// Проверяем, есть ли данные
if (empty($positions)) {
    die("Нет данных для заявки: " . htmlspecialchars($order));
}

$by_date = [];
foreach ($positions as $p) {
    // Проверяем наличие обязательных полей
    if (empty($p['plan_date']) || empty($p['filter'])) {
        continue;
    }
    
    $icons = '';
    if (!empty($p['glueing'])) $icons .= ' <span class="icon" title="Проливка">●</span>';
    if (!empty($p['prefilter'])) $icons .= ' <span class="icon" title="Предфильтр">◩</span>';
    
    $form_factor_value = $p['form_factor'] ?? '';
    $form_factor = is_string($form_factor_value) ? trim($form_factor_value) : '';
    if ($form_factor === 'трапеция') {
        $icons .= ' <span class="icon" title="Трапеция">⏃</span>';
    } elseif ($form_factor === 'трапеция с обечайкой') {
        $icons .= ' <span class="icon" title="Трапеция с обечайкой">⏃◯</span>';
    }

    $height = floatval($p['height'] ?? 0);
    $width = floatval($p['width'] ?? 0);
    $label = htmlspecialchars($p['filter']) . " [{$height}] {$width}{$icons}";
    
    $pleats = intval($p['p_p_pleats_count'] ?? 0);
    $pleat_height = floatval($p['p_p_height'] ?? 0);
    
    // Отладка для MRA-076 и AF1601s
    if (strpos($p['filter'], 'MRA-076') !== false || strpos($p['filter'], 'AF1601s') !== false) {
        error_log("=== DEBUG for " . $p['filter'] . " ===");
        error_log("- filter: " . $p['filter']);
        error_log("- height (cut_plans): " . ($p['height'] ?? 'NULL'));
        error_log("- p_p_height (paper_package_panel): " . ($p['p_p_height'] ?? 'NULL'));
        error_log("- pleats (p_p_pleats_count): " . ($p['p_p_pleats_count'] ?? 'NULL'));
        error_log("- paper_package: " . ($p['paper_package'] ?? 'NULL'));
        error_log("- final pleats: " . $pleats . ", pleat_height: " . $pleat_height);
    }
    
    $by_date[$p['plan_date']][] = [
        'label' => $label,
        'cut_date' => $p['plan_date'],
        'filter' => $p['filter'],
        'length' => floatval($p['length'] ?? 0),
        'pleats' => $pleats,
        'pleat_height' => $pleat_height
    ];
}

// Загрузка существующего плана гофрирования
$existing_plan = [];
try {
    $stmt = $pdo->prepare("SELECT plan_date, filter_label, count FROM corrugation_plan WHERE order_number = ? ORDER BY plan_date, filter_label");
    $stmt->execute([$order]);
    $existing_plan = $stmt->fetchAll();
    
    // Отладка загруженного плана
    error_log("Loaded existing plan for order $order: " . json_encode($existing_plan));
} catch (Exception $e) {
    error_log("Error loading plan: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Планирование гофрирования</title>
    <style>
        * { user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; }
        input, textarea { user-select: text; -webkit-user-select: text; -moz-user-select: text; -ms-user-select: text; }
        body { font-family: sans-serif; padding: 20px; background: #f0f0f0; font-size: 11px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 5px; vertical-align: top; white-space: nowrap; }
        th { font-size: 11px; }
        #top-table td { 
            white-space: normal !important; 
            display: table-cell !important;
            vertical-align: top;
        }
        #top-table .position-cell {
            display: block !important;
            white-space: normal !important;
        }
        
        /* Подсветка одинаковых позиций при наведении */
        .position-cell.highlighted {
            border: 2px solid #f44336 !important;
            box-shadow: 0 0 5px rgba(244, 67, 54, 0.8) !important;
        }
        .position-cell {
            cursor: pointer;
            padding: 3px;
            border-bottom: 1px dotted #ccc;
            border: 2px solid transparent; /* Прозрачная граница по умолчанию */
            display: block;
            margin-bottom: 2px;
            position: relative;
        }
        .used {
            background-color: #8996d7;
            color: #333;
            border-radius: 4px;
            padding: 2px 4px;
            display: inline-block;
            margin-bottom: 2px;
            font-size: 10px;
            cursor: pointer;
        }
        .used:hover {
            background-color: #7a88d1;
        }
        .assigned-item { background: #d2f5a3; margin-bottom: 2px; padding: 2px 4px; cursor: pointer; border-radius: 4px; }
        .drop-target { min-height: 50px; }
        .active-day { background-color: #fff3cd !important; border: 2px solid #ffc107 !important; }
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.4); justify-content: center; align-items: center;
        }
        .modal-content {
            background: white; padding: 20px; border-radius: 5px; width: 400px;
        }
        .modal h3 { margin-top: 0; }
        .modal button { margin-top: 10px; }
        .summary { font-weight: bold; padding-top: 5px; }
        .icon { font-size: 12px; margin-left: 4px; }
        .legend { margin-bottom: 10px; font-size: 11px; }
        .active-day-info {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fffacd; /* Пастельный желтый */
            border: 2px solid #333;
            border-radius: 8px;
            padding: 10px;
            font-size: 12px;
            z-index: 1000;
            cursor: move;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            min-width: 200px;
            max-width: 300px;
            max-height: 80vh;
            overflow-y: auto;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }
        
        .panel-header {
            border-bottom: 1px solid #e0e0e0;
            padding: 15px;
            margin-bottom: 15px;
            position: sticky;
            top: 0;
            background: linear-gradient(135deg, #fff8e1, #fffacd);
            z-index: 10;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            min-height: 150px; /* Увеличиваем высоту панели */
        }
        
        .info-item {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 8px;
            padding: 8px 10px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 6px;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
            font-size: 11px;
        }
        
        .info-value {
            font-weight: normal;
            color: #333;
            font-size: 12px;
        }
        
        .info-separator {
            color: #666;
            font-size: 12px;
            margin: 0 5px;
        }
        
        .legend-section {
            margin-top: 15px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        
        .legend-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 11px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
            font-size: 10px;
            color: #555;
        }
        
        .legend-icon {
            margin-right: 6px;
            font-weight: bold;
        }
        
        .planning-list {
            margin-top: 15px;
            padding-top: 15px;
        }
        
        
        .list-header {
            font-weight: bold;
            margin-bottom: 8px;
            color: #333;
        }
        
        .planning-day {
            margin-bottom: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f9f9f9;
        }
        
        .day-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 8px;
            background: #e9e9e9;
            border-radius: 3px 3px 0 0;
            font-size: 11px;
        }
        
        .day-date {
            font-weight: bold;
        }
        
        .day-summary {
            color: #666;
            font-size: 10px;
        }
        
        .planning-day .drop-target {
            min-height: 30px;
            padding: 4px;
            background: white;
            border-radius: 0 0 3px 3px;
        }
        
        .planning-day .assigned-item {
            background-color: #8996d7;
            color: #333;
            border-radius: 4px;
            padding: 2px 4px;
            margin: 1px 0;
            font-size: 10px;
            cursor: pointer;
        }
        .active-day-info.dragging {
            opacity: 0.8;
        }
    </style>
</head>
<body>
<!-- Перетаскиваемая плашка с информацией об активном дне -->
<div id="active-day-info" class="active-day-info">

    <div class="panel-header">
        <div class="info-item">
            <span class="info-value" id="active-day-date">Не выбран</span>
            <span class="info-separator"> | </span>
            <span class="info-value" id="active-day-count">0 шт</span>
        </div>
        
        <div class="legend-section">
            <div class="legend-title">Легенда:</div>
            <div class="legend-item">
                <span class="legend-icon">●</span>
                <span>Проливка</span>
            </div>
            <div class="legend-item">
                <span class="legend-icon">◩</span>
                <span>Предфильтр</span>
            </div>
            <div class="legend-item">
                <span class="legend-icon">⏃</span>
                <span>Трапеция</span>
            </div>
            <div class="legend-item">
                <span class="legend-icon">⏃◯</span>
                <span>Трапеция с обечайкой</span>
            </div>
        </div>
        
        <div style="margin-top: 15px; text-align: center;">
            <strong style="color: #ff9800; font-size: 12px;">План гофрирования</strong>
        </div>
    </div>
    
    <div class="planning-list">
        <div id="planning-days-list">
            <?php foreach ($dates as $d): ?>
                <div class="planning-day" data-date="<?= $d ?>">
                    <div class="day-header">
                        <span class="day-date"><?= $d ?></span>
                        <span class="day-summary" id="summary-<?= $d ?>">0 шт</span>
                    </div>
                    <div class="drop-target" data-date="<?= $d ?>"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h2 style="font-size: 18px; font-weight: normal; margin: 0;">Планирование гофрирования для заявки <?= htmlspecialchars($order) ?></h2>
    <div style="display: flex; align-items: center; gap: 15px;">
        <form method="get" style="display:flex; align-items:center; gap:10px; margin:0;">
    Дата начала: <input type="date" name="start" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d')) ?>">
    Дней: <input type="number" name="days" value="<?= $days ?>" min="1" max="90">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
            <button type="submit">Применить дни</button>
            <button type="button" onclick="addDay()" style="display: none;">Добавить день</button>
</form>

        <div style="display: flex; gap: 10px;">
            <button type="button" onclick="loadExistingPlan()">Загрузить план</button>
            <button type="button" onclick="savePlan(false)">Сохранить</button>
            <button type="button" onclick="preparePlan()">Завершить</button>
        </div>
    </div>
</div>
<table id="top-table">
    <tr>
        <?php foreach ($dates as $d): ?>
            <th><?= $d ?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($dates as $d): ?>
            <td>
                <?php foreach ($by_date[$d] ?? [] as $item): ?>
                    <?php $uniqueId = uniqid('pos_'); ?>
                    <div class="position-cell"
                         data-id="<?= $uniqueId ?>"
                         data-filter="<?= htmlspecialchars(strip_tags($item['label'])) ?>"
                         data-cut-date="<?= $item['cut_date'] ?>"
                         data-length="<?= $item['length'] ?>"
                         data-pleats="<?= $item['pleats'] ?>"
                         data-pleat-height="<?= $item['pleat_height'] ?>">
                        <?= $item['label'] ?>
                    </div>
                <?php endforeach; ?>
            </td>
        <?php endforeach; ?>
    </tr>
</table>

<form method="post" action="NP/save_corrugation_plan.php" style="display: none;">
    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
    <input type="hidden" name="plan_data" id="plan_data">
</form>

<div class="modal" id="modal">
    <div class="modal-content">
        <h3>Выберите дату</h3>
        <div id="modal-dates"></div>
        <div style="margin: 10px 0; padding: 8px; background: #f8f9fa; border-radius: 4px; font-size: 12px;">
            💡 <strong>Shift+клик</strong> на позицию добавит её в активный день (последний использованный день)
        </div>
        <button onclick="closeModal()">Отмена</button>
    </div>
</div>

<script>
    let selectedData = {};
    let activeDay = null; // Активный день - день последнего добавления
    
    // Данные существующего плана
    const existingPlanData = <?= json_encode($existing_plan) ?>;

    function closeModal() {
        document.getElementById("modal").style.display = "none";
        selectedData = {};
    }

    // Функция для обновления плашки активного дня
    function updateActiveDayInfo(forceScroll = false) {
        const infoDiv = document.getElementById('active-day-info');
        const dateDiv = document.getElementById('active-day-date');
        const countDiv = document.getElementById('active-day-count');
        
        // Проверяем, изменился ли активный день
        const previousActiveDay = dateDiv.textContent;
        const activeDayChanged = previousActiveDay !== activeDay;
        
        if (activeDay) {
            dateDiv.textContent = activeDay;
            
            // Считаем количество гофропакетов в активном дне
            const activeTd = document.querySelector('.drop-target[data-date="' + activeDay + '"]');
            if (activeTd) {
                const items = activeTd.querySelectorAll('.assigned-item');
                let totalCount = 0;
                for (let i = 0; i < items.length; i++) {
                    const qty = parseInt(items[i].getAttribute('data-qty') || '0');
                    // Проверяем на корректность значения
                    if (isFinite(qty) && !isNaN(qty)) {
                        totalCount += qty;
                    } else {
                        console.warn('Некорректное количество в плашке активного дня:', qty);
                    }
                }
                countDiv.textContent = totalCount + ' шт';
            } else {
                countDiv.textContent = '0 шт';
            }
            
            // Прокручиваем только если активный день изменился или принудительно
            if (activeDayChanged || forceScroll) {
                scrollToActiveDay();
            }
        } else {
            dateDiv.textContent = 'Не выбран';
            countDiv.textContent = '0 шт';
        }
    }
    
    function scrollToActiveDay() {
        if (!activeDay) return;
        
        console.log('=== SCROLL DEBUG ===');
        console.log('activeDay:', activeDay);
        
        // Пробуем прокручивать основной контейнер плавающей панели
        const activeDayInfo = document.getElementById('active-day-info');
        const activeDayElement = document.querySelector('.planning-day[data-date="' + activeDay + '"]');
        
        console.log('activeDayInfo:', activeDayInfo);
        console.log('activeDayElement:', activeDayElement);
        
        if (activeDayInfo && activeDayElement) {
            console.log('Found both elements, scrolling...');
            
            // Вычисляем позицию элемента относительно прокручиваемого контейнера
            const containerRect = activeDayInfo.getBoundingClientRect();
            const elementRect = activeDayElement.getBoundingClientRect();
            
            console.log('containerRect:', containerRect);
            console.log('elementRect:', elementRect);
            
            // Вычисляем смещение элемента внутри контейнера
            const elementTop = elementRect.top - containerRect.top + activeDayInfo.scrollTop;
            
            console.log('elementTop:', elementTop);
            console.log('current scrollTop:', activeDayInfo.scrollTop);
            
            // Прокручиваем к элементу с учетом отступа от заголовка
            activeDayInfo.scrollTo({
                top: elementTop - 40,
                behavior: 'smooth'
            });
            
            // Альтернативный метод если scrollTo не работает
            setTimeout(() => {
                activeDayElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }, 100);
            
            console.log('Scroll command executed');
        } else {
            console.log('Elements not found!');
        }
    }

    // Функция для обновления визуального отображения активного дня
    function updateActiveDayVisual() {
        // Убираем выделение со всех дней
        document.querySelectorAll('.drop-target').forEach(td => {
            td.classList.remove('active-day');
        });
        
        // Выделяем активный день
        if (activeDay) {
            const activeTd = document.querySelector('.drop-target[data-date="' + activeDay + '"]');
            if (activeTd) {
                activeTd.classList.add('active-day');
            }
        }
        
        // Обновляем плашку с информацией
        updateActiveDayInfo();
    }

    // Функция для добавления позиции в указанный день
    function addPositionToDay(cell, targetDate) {
        if (!targetDate) return;

        const td = document.querySelector('.drop-target[data-date="' + targetDate + '"]');
        if (!td) return;

        // Проверяем, что дата подходит по условию (дата назначения >= дата раскроя)
        if (targetDate < selectedData.cutDate) return;

        const rollLengthMm = selectedData.length * 1000;
        const blankLength = selectedData.pleats * selectedData.height * 2;
        
        
        // Проверяем деление на ноль
        let qty = 0;
        if (blankLength > 0) {
            qty = Math.floor(rollLengthMm / blankLength);
        } else {
            console.warn('Неверные данные для расчета количества:', selectedData);
            console.warn('rollLengthMm:', rollLengthMm, 'blankLength:', blankLength);
            
            // Специальное сообщение для MRA-076
            if (selectedData.label && selectedData.label.includes('MRA-076')) {
                alert('Ошибка для позиции MRA-076: отсутствуют данные о количестве складок или высоте складки в базе данных. Обратитесь к администратору для заполнения данных в таблице paper_package_panel.');
            }
            return; // Прекращаем добавление если данные некорректны
        }

        // Проверяем что qty корректное число
        if (!isFinite(qty) || isNaN(qty) || qty <= 0) {
            console.warn('Некорректное количество для добавления:', qty);
            return;
        }

        const div = document.createElement('div');
        div.innerText = selectedData.label + " (" + qty + " шт)";
        div.classList.add('assigned-item');
        div.setAttribute("data-qty", qty);
        div.setAttribute("data-label", selectedData.label);
        div.setAttribute("data-id", selectedData.id);
        td.appendChild(div);

        cell.classList.add('used');
        updateSummary(targetDate);
        attachRemoveHandlers();
        
        // Обновляем активный день при добавлении через Shift+клик
        activeDay = targetDate;
        updateActiveDayVisual();
    }

    function updateSummary(date) {
        const td = document.querySelector('.drop-target[data-date="' + date + '"]');
        if (!td) return;
        
        let total = 0;
        const items = td.querySelectorAll('.assigned-item');
        for (let i = 0; i < items.length; i++) {
            const qty = parseInt(items[i].getAttribute('data-qty') || '0');
            // Проверяем на корректность значения
            if (isFinite(qty) && !isNaN(qty)) {
            total += qty;
            } else {
                console.warn('Некорректное количество при подсчете:', qty);
            }
        }
        const summary = document.getElementById("summary-" + date);
        if (summary) {
            summary.innerText = total + " шт";
        }
    }

    function attachRemoveHandlers() {
        document.querySelectorAll('.assigned-item').forEach(div => {
            div.onclick = () => {
                const posId = div.getAttribute('data-id');
                const upperCell = document.querySelector('.position-cell.used[data-id="' + posId + '"]');
                if (upperCell) {
                    upperCell.classList.remove('used');
                }
                const parentDate = div.closest('.drop-target').dataset.date;
                div.remove();
                updateSummary(parentDate);
                updateActiveDayInfo(); // Обновляем плашку после удаления
            };
        });
    }

    // Используем делегирование событий для лучшей производительности
    document.addEventListener('DOMContentLoaded', function() {
        // Функция для подсветки одинаковых позиций
        function highlightSimilarPositions(filterName) {
            // Убираем предыдущую подсветку
            document.querySelectorAll('.position-cell.highlighted').forEach(cell => {
                cell.classList.remove('highlighted');
            });
            
            // Подсвечиваем все позиции с таким же названием
    document.querySelectorAll('.position-cell').forEach(cell => {
                const cellFilter = cell.dataset.filter || '';
                if (cellFilter === filterName) {
                    cell.classList.add('highlighted');
                }
            });
        }

        // Делегирование для кликов по position-cell
        document.getElementById('top-table').addEventListener('click', function(e) {
            const cell = e.target.closest('.position-cell');
            if (!cell) return;

            // Если позиция уже использована, возвращаем её обратно
            if (cell.classList.contains('used')) {
                const posId = cell.dataset.id;
                const assignedItem = document.querySelector('.assigned-item[data-id="' + posId + '"]');
                if (assignedItem) {
                    const parentDate = assignedItem.closest('.drop-target').dataset.date;
                    assignedItem.remove();
                    cell.classList.remove('used');
                    updateSummary(parentDate);
                    updateActiveDayInfo(); // Обновляем плашку после возврата
                }
                return;
            }

            selectedData = {
                id: cell.dataset.id,
                label: cell.dataset.filter,
                cutDate: cell.dataset.cutDate,
                length: parseFloat(cell.dataset.length) || 0,
                pleats: parseInt(cell.dataset.pleats) || 0,
                height: parseFloat(cell.dataset.pleatHeight) || 0
            };
            

            // Если зажат Shift, добавляем сразу в активный день или первый подходящий день
            if (e.shiftKey) {
                let targetDay = activeDay;
                
                // Если активного дня нет, найдем первый подходящий день
                if (!targetDay) {
                    const dropTargets = document.querySelectorAll('.drop-target');
                    for (let i = 0; i < dropTargets.length; i++) {
                        const td = dropTargets[i];
                        const date = td.getAttribute('data-date');
                        if (date >= selectedData.cutDate) {
                            targetDay = date;
                            break;
                        }
                    }
                }
                
                if (targetDay) {
                    addPositionToDay(cell, targetDay);
                    return;
                }
            }

            // Иначе показываем модальное окно
            document.getElementById("modal").style.display = "flex";
            const modalDates = document.getElementById("modal-dates");
            modalDates.innerHTML = '';

            const dropTargets = document.querySelectorAll('.drop-target');
            for (let i = 0; i < dropTargets.length; i++) {
                const td = dropTargets[i];
                const date = td.getAttribute('data-date');
                if (date >= selectedData.cutDate) {
                    const btn = document.createElement('button');
                    btn.textContent = date;
                    btn.onclick = () => {
                        const rollLengthMm = selectedData.length * 1000; // length в метрах, конвертируем в мм
                        const blankLength = selectedData.pleats * selectedData.height * 2;
                        
                        // Отладка для AF1601s
                        if (selectedData.label && selectedData.label.includes('AF1601s')) {
                            console.log('=== AF1601s DEBUG ===');
                            console.log('selectedData:', selectedData);
                            console.log('rollLengthMm:', rollLengthMm);
                            console.log('blankLength:', blankLength);
                            console.log('pleats:', selectedData.pleats);
                            console.log('height:', selectedData.height);
                        }
                        
                        // Проверяем деление на ноль
                        let qty = 0;
                        if (blankLength > 0) {
                            qty = Math.floor(rollLengthMm / blankLength);
                        } else {
                            console.warn('Неверные данные для расчета количества:', selectedData);
                            console.warn('rollLengthMm:', rollLengthMm, 'blankLength:', blankLength);
                            alert('Ошибка: невозможно рассчитать количество для позиции ' + selectedData.label + '. Проверьте данные о количестве складок и высоте. Pleats: ' + selectedData.pleats + ', Height: ' + selectedData.height);
                            return;
                        }

                        // Отладка для AF1601s
                        if (selectedData.label && selectedData.label.includes('AF1601s')) {
                            console.log('=== AF1601s MODAL DEBUG ===');
                            console.log('qty before check:', qty);
                            console.log('isFinite(qty):', isFinite(qty));
                            console.log('isNaN(qty):', isNaN(qty));
                            console.log('qty <= 0:', qty <= 0);
                        }
                        
                        // Проверяем что qty корректное число
                        if (!isFinite(qty) || isNaN(qty) || qty <= 0) {
                            console.warn('Некорректное количество для добавления (модалка):', qty);
                            return;
                        }

                        const div = document.createElement('div');
                        div.innerText = selectedData.label + " (" + qty + " шт)";
                        div.classList.add('assigned-item');
                        div.setAttribute("data-qty", qty);
                        div.setAttribute("data-label", selectedData.label);
                        div.setAttribute("data-id", selectedData.id);
                        td.appendChild(div);

                        cell.classList.add('used');
                        updateSummary(date);
                        attachRemoveHandlers();
                        
                        // Устанавливаем активный день при добавлении через модальное окно
                        activeDay = date;
                        updateActiveDayVisual();
                        
                        closeModal();
                    };
                    modalDates.appendChild(btn);
                }
            }
        });

        // Обработчики наведения мыши для подсветки одинаковых позиций
        document.getElementById('top-table').addEventListener('mouseover', function(e) {
            const cell = e.target.closest('.position-cell');
            if (cell) {
                const filterName = cell.dataset.filter || '';
                highlightSimilarPositions(filterName);
            }
        });

        document.getElementById('top-table').addEventListener('mouseout', function(e) {
            const cell = e.target.closest('.position-cell');
            if (cell) {
                // Небольшая задержка, чтобы подсветка не мигала при быстром движении мыши
                setTimeout(() => {
                    const hoveredCell = document.querySelector('.position-cell:hover');
                    if (!hoveredCell) {
                        document.querySelectorAll('.position-cell.highlighted').forEach(cell => {
                            cell.classList.remove('highlighted');
                        });
                    }
                }, 100);
            }
        });
        
        // Инициализация: находим последний день с добавленными позициями как активный день
        const dropTargets = document.querySelectorAll('.drop-target');
        let lastUsedDate = null;
        for (let i = 0; i < dropTargets.length; i++) {
            const td = dropTargets[i];
            const items = td.querySelectorAll('.assigned-item');
            if (items.length > 0) {
                lastUsedDate = td.getAttribute('data-date');
            }
        }
        if (lastUsedDate) {
            activeDay = lastUsedDate;
            updateActiveDayVisual();
        }

        // Функционал перетаскивания плашки активного дня
        const activeDayInfo = document.getElementById('active-day-info');
        let isDragging = false;
        let currentX;
        let currentY;
        let initialX;
        let initialY;
        let xOffset = 0;
        let yOffset = 0;

        activeDayInfo.addEventListener('mousedown', dragStart);
        document.addEventListener('mousemove', drag);
        document.addEventListener('mouseup', dragEnd);

        function dragStart(e) {
            // Перетаскивание начинается только если клик на саму плашку или её содержимое
            if (e.target.closest('#active-day-info')) {
                initialX = e.clientX - xOffset;
                initialY = e.clientY - yOffset;
                isDragging = true;
                activeDayInfo.classList.add('dragging');
            }
        }

        function drag(e) {
            if (isDragging) {
                e.preventDefault();
                currentX = e.clientX - initialX;
                currentY = e.clientY - initialY;

                xOffset = currentX;
                yOffset = currentY;

                activeDayInfo.style.transform = `translate(${currentX}px, ${currentY}px)`;
            }
        }

        function dragEnd(e) {
            initialX = currentX;
            initialY = currentY;
            isDragging = false;
            activeDayInfo.classList.remove('dragging');
        }
        
        // Автоматическая загрузка существующего плана при открытии страницы
        if (existingPlanData.length > 0) {
            // Небольшая задержка для корректной инициализации
            setTimeout(() => {
                loadExistingPlan(false); // Без alert при автоматической загрузке
            }, 100);
        }
    });

    function addDay() {
        const topTable = document.getElementById('top-table');
        const bottomTable = document.getElementById('bottom-table');

        const lastDateCell = topTable.querySelector('tr th:last-child');
        const lastDate = new Date(lastDateCell.innerText);
        lastDate.setDate(lastDate.getDate() + 1);
        const newDateStr = lastDate.toISOString().slice(0, 10);

        const topHead = topTable.querySelector('tr');
        const newTopTh = document.createElement('th');
        newTopTh.innerText = newDateStr;
        topHead.appendChild(newTopTh);

        const topRow = topTable.querySelector('tr:nth-of-type(2)');
        const newTopTd = document.createElement('td');
        topRow.appendChild(newTopTd);

        const bottomHead = bottomTable.querySelector('tr');
        const newBottomTh = document.createElement('th');
        newBottomTh.innerText = newDateStr;
        bottomHead.appendChild(newBottomTh);

        const bottomRow = bottomTable.querySelector('tr:nth-of-type(2)');
        const newBottomTd = document.createElement('td');
        newBottomTd.classList.add('drop-target');
        newBottomTd.setAttribute('data-date', newDateStr);
        bottomRow.appendChild(newBottomTd);

        const summaryRow = bottomTable.querySelector('.summary-row');
        const newSummaryTd = document.createElement('td');
        newSummaryTd.classList.add('summary');
        newSummaryTd.id = "summary-" + newDateStr;
        newSummaryTd.innerText = "0 шт";
        summaryRow.appendChild(newSummaryTd);
        
        // Добавляем день в плавающую панель
        addDayToFloatingPanel(newDateStr);
        
        // Обновляем визуализацию активного дня после добавления нового дня
        updateActiveDayVisual();
    }
    
    function addDayToFloatingPanel(newDateStr) {
        const planningDaysList = document.getElementById('planning-days-list');
        
        // Создаем новый элемент дня для плавающей панели
        const newPlanningDay = document.createElement('div');
        newPlanningDay.className = 'planning-day';
        newPlanningDay.setAttribute('data-date', newDateStr);
        
        newPlanningDay.innerHTML = `
            <div class="day-header">
                <span class="day-date">${newDateStr}</span>
                <span class="day-summary" id="summary-${newDateStr}">0 шт</span>
            </div>
            <div class="drop-target" data-date="${newDateStr}"></div>
        `;
        
        // Добавляем новый день в конец списка
        planningDaysList.appendChild(newPlanningDay);
    }

    function loadExistingPlan(showAlert = true) {
        if (existingPlanData.length === 0) {
            if (showAlert) {
                alert('Нет сохраненного плана для загрузки');
            }
            return;
        }
        
        // Очищаем текущий план
        document.querySelectorAll('.drop-target').forEach(td => {
            td.innerHTML = '';
        });
        
        // Сбрасываем все использованные позиции
        document.querySelectorAll('.position-cell.used').forEach(cell => {
            cell.classList.remove('used');
        });
        
        // Загружаем существующий план
        existingPlanData.forEach(item => {
            const targetTd = document.querySelector('.drop-target[data-date="' + item.plan_date + '"]');
            if (targetTd) {
                // Находим соответствующую позицию в верхней таблице
                const positionCell = Array.from(document.querySelectorAll('.position-cell')).find(cell => {
                    // Проверяем точное совпадение
                    const cellFilter = cell.dataset.filter || '';
                    const savedFilter = item.filter_label || '';
                    
                    // Сначала проверяем точное совпадение
                    if (cellFilter === savedFilter) {
                        return !cell.classList.contains('used');
                    }
                    
                    // Затем проверяем частичное совпадение только если это не точное совпадение
                    const cellBaseName = cellFilter.replace(/ \[.*?\].*/, '');
                    const savedBaseName = savedFilter.replace(/ \[.*?\].*/, '');
                    
                    // Проверяем, что базовые имена совпадают точно (не частично)
                    if (cellBaseName === savedBaseName) {
                        return !cell.classList.contains('used');
                    }
                    
                    return false;
                });
                
                console.log('Loading plan item:', {
                    filter_label: item.filter_label,
                    found_cell: positionCell ? positionCell.dataset.filter : 'NOT_FOUND'
                });
                
                // Отладка для AF1601s
                if (item.filter_label && item.filter_label.includes('AF1601s')) {
                    console.log('=== AF1601s LOAD DEBUG ===');
                    console.log('savedFilter:', item.filter_label);
                    
                    // Подсчитаем точное количество ячеек
                    const allCells = document.querySelectorAll('.position-cell');
                    const af1601Cells = Array.from(allCells).filter(cell => cell.dataset.filter === 'AF1601 [48] 199');
                    const af1601sCells = Array.from(allCells).filter(cell => cell.dataset.filter === 'AF1601s [48] 199');
                    
                    console.log('=== COUNTING DEBUG ===');
                    console.log('Total position cells:', allCells.length);
                    console.log('AF1601 [48] 199 cells:', af1601Cells.length);
                    console.log('AF1601s [48] 199 cells:', af1601sCells.length);
                    
                    // Подсчитаем used/unused
                    const af1601Used = af1601Cells.filter(cell => cell.classList.contains('used')).length;
                    const af1601Unused = af1601Cells.filter(cell => !cell.classList.contains('used')).length;
                    const af1601sUsed = af1601sCells.filter(cell => cell.classList.contains('used')).length;
                    const af1601sUnused = af1601sCells.filter(cell => !cell.classList.contains('used')).length;
                    
                    console.log('AF1601 used:', af1601Used, 'unused:', af1601Unused);
                    console.log('AF1601s used:', af1601sUsed, 'unused:', af1601sUnused);
                    
                    // Проверим логику сопоставления
                    const testCell = Array.from(document.querySelectorAll('.position-cell')).find(cell => {
                        const cellFilter = cell.dataset.filter || '';
                        const savedFilter = item.filter_label || '';
                        
                        return (cellFilter === savedFilter || 
                                cellFilter.includes(savedFilter) || 
                                savedFilter.includes(cellFilter.replace(/ \[.*?\].*/, ''))) 
                               && !cell.classList.contains('used');
                    });
                    
                    console.log('Found cell for AF1601s:', testCell);
                }
                
                if (positionCell) {
                    // Проверяем корректность количества
                    const count = parseInt(item.count) || 0;
                    if (count <= 0 || !isFinite(count)) {
                        console.warn('Некорректное количество для позиции:', item.filter_label, item.count);
                        return; // Пропускаем эту позицию
                    }
                    
                    // Создаем элемент в нижней таблице
                    const div = document.createElement('div');
                    div.innerText = item.filter_label + " (" + count + " шт)";
                    div.classList.add('assigned-item');
                    div.setAttribute("data-qty", count);
                    div.setAttribute("data-label", item.filter_label);
                    div.setAttribute("data-id", positionCell.dataset.id);
                    targetTd.appendChild(div);
                    
                    // Отмечаем позицию как использованную
                    positionCell.classList.add('used');
                    
                    // Обновляем счетчик
                    updateSummary(item.plan_date);
                    attachRemoveHandlers();
                    
                    // Устанавливаем активный день
                    activeDay = item.plan_date;
                }
            }
        });
        
        updateActiveDayVisual();
        
        if (showAlert && existingPlanData.length > 0) {
            alert('План загружен! Загружено ' + existingPlanData.length + ' позиций.');
        }
    }

    function savePlan(shouldRedirect = false) {
        const data = {};
        document.querySelectorAll('.drop-target').forEach(td => {
            const date = td.getAttribute('data-date');
            const items = Array.from(td.querySelectorAll('div')).map(d => d.innerText);
            if (items.length > 0) data[date] = items;
        });

        if (shouldRedirect) {
            // Для кнопки "Завершить" - используем обычную отправку формы
        document.getElementById('plan_data').value = JSON.stringify(data);
            return;
        }

        // Для кнопки "Сохранить" - AJAX запрос
        const order = '<?= htmlspecialchars($order) ?>';
        const formData = new FormData();
        formData.append('order', order);
        formData.append('plan_data', JSON.stringify(data));

        fetch('NP/save_corrugation_plan_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                alert(result.message);
            } else {
                alert('Ошибка: ' + result.message);
            }
        })
        .catch(error => {
            alert('Ошибка при сохранении: ' + error.message);
        });
    }

    function preparePlan() {
        savePlan(true); // true означает что это завершение с перенаправлением
    }
</script>
</body>
</html>