<?php
session_start(); // Запускаем сессию в начале файла

require_once('NP/cut.php');

// Подключение к базе данных
$pdo1 = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$pdo2 = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");


$order = $_GET['order'] ?? '';

// Проверяем все фильтры в заявке на наличие в БД
$stmt = $pdo1->prepare("SELECT filter, count FROM orders WHERE order_number = ? AND (hide IS NULL OR hide != 1)");
$stmt->execute([$order]);
$filters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Проверка наличия фильтров в БД при загрузке страницы
$missing_filters = [];
$existing_filters = [];

foreach ($filters as $filter_row) {
    $filter_name = $filter_row['filter'];
    
    // Проверяем наличие фильтра в panel_filter_structure
    $check_stmt = $pdo2->prepare("SELECT COUNT(*) FROM panel_filter_structure WHERE filter = ?");
    $check_stmt->execute([$filter_name]);
    $exists = $check_stmt->fetchColumn();
    
    if ($exists > 0) {
        $existing_filters[] = $filter_name;
    } else {
        $missing_filters[] = $filter_name;
    }
}

// Получаем все существующие фильтры из БД для выпадающего списка аналогов
$all_filters_stmt = $pdo2->query("SELECT filter FROM panel_filter_structure ORDER BY filter");
$all_existing_filters = [];
while ($row = $all_filters_stmt->fetch(PDO::FETCH_ASSOC)) {
    $all_existing_filters[] = $row['filter'];
}

// ===== ФОРМАТ 199: Проверка и распределение =====
$format_199_filters = [];
$format_199_assigned = [];

// Проверяем только если нет missing_filters
if (empty($missing_filters)) {
    foreach ($filters as $filter_row) {
        $filter_name = $filter_row['filter'];
        $filter_count = (int)$filter_row['count'];
        
        // Получаем информацию о бумаге
        $paper_info = getPaperInfo($pdo2, $filter_name);
        if (!$paper_info) continue;
        
        $width = (float)$paper_info['p_p_width'];
        
        // Проверяем ширину: 199 или диапазон 175-190
        if ($width == 199 || ($width >= 175 && $width <= 190)) {
            $format_199_filters[] = [
                'filter' => $filter_name,
                'count' => $filter_count,
                'width' => $width,
                'paper' => $paper_info['p_p_name'],
                'height' => (float)$paper_info['p_p_height'],
                'pleats' => (int)$paper_info['p_p_pleats_count']
            ];
        }
    }
}

// Обработка сброса форматов 199
if (isset($_GET['reset_format_199'])) {
    unset($_SESSION['format_199_assigned']);
    unset($_SESSION['format_199_stock']);
    header("Location: ?order=" . urlencode($order));
    exit;
}

// Обработка сброса ручных бухт
if (isset($_GET['reset_manual'])) {
    unset($_SESSION['manual_bales']);
    header("Location: ?order=" . urlencode($order));
    exit;
}

// Обработка AJAX запроса на сохранение ручных бухт
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);
    
    if (isset($data['action']) && $data['action'] === 'save_manual_bales') {
        $manual_bales = $data['bales'] ?? [];
        
        if (empty($manual_bales)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Нет бухт для сохранения']);
            exit;
        }
        
        // Сохраняем в сессию
        $_SESSION['manual_bales'] = $manual_bales;
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => count($manual_bales)]);
        exit;
    }
}

// Обработка POST запроса от модального окна формата 199
if (isset($_POST['format_199_submit'])) {
    $format_199_stock = (int)($_POST['format_199_stock'] ?? 0);
    $assigned_filters_raw = $_POST['assigned_filters'] ?? [];
    
    // Конвертируем рулоны в штуки фильтров
    $assigned_filters = [];
    foreach ($assigned_filters_raw as $filter_name => $assigned_reels) {
        if ($assigned_reels > 0) {
            // Находим информацию о фильтре
            foreach ($format_199_filters as $f) {
                if ($f['filter'] === $filter_name) {
                    // Рассчитываем сколько штук фильтров соответствует назначенным рулонам
                    $pleats = $f['pleats'];
                    $height = $f['height'];
                    $length_per_filter = $pleats * 2 * $height;
                    $meters_per_reel = 1000; // 1000 метров в рулоне
                    $filters_per_reel = $meters_per_reel / ($length_per_filter / 1000);
                    $assigned_count = round($assigned_reels * $filters_per_reel);
                    
                    // Ограничиваем максимальным количеством в заявке
                    $assigned_count = min($assigned_count, $f['count']);
                    
                    if ($assigned_count > 0) {
                        $assigned_filters[$filter_name] = $assigned_count;
                    }
                    break;
                }
            }
        }
    }
    
    // Сохраняем назначенные фильтры в сессии
    $_SESSION['format_199_assigned'] = $assigned_filters;
    $_SESSION['format_199_stock'] = $format_199_stock;
    
    error_log("Format 199 POST: Saved to session: " . json_encode($assigned_filters));
    error_log("Format 199 POST: Stock: $format_199_stock");
    
    // Перезагружаем страницу для продолжения расчета
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// Загружаем назначенные фильтры из сессии, если они есть
if (isset($_SESSION['format_199_assigned'])) {
    $format_199_assigned = $_SESSION['format_199_assigned'];
    error_log("Format 199: Loaded from session: " . json_encode($format_199_assigned));
} else {
    error_log("Format 199: No data in session");
}

$rolls_1000 = [];
$rolls_500 = [];

// Загружаем ручные бухты из сессии
$manual_bales = $_SESSION['manual_bales'] ?? [];

// Подсчитываем использованные рулоны вручную
$manual_rolls_used = [];
foreach ($manual_bales as $bale) {
    foreach ($bale as $roll) {
        $key = $roll['filter'] . '_' . $roll['width'] . '_' . $roll['height'] . '_' . $roll['length'];
        $manual_rolls_used[$key] = ($manual_rolls_used[$key] ?? 0) + 1;
    }
}

function getPaperInfo($pdo, $filter) {
    $stmt = $pdo->prepare("SELECT paper_package FROM panel_filter_structure WHERE filter = ?");
    $stmt->execute([$filter]);
    $paper = $stmt->fetchColumn();
    if (!$paper) return null;

    $stmt = $pdo->prepare("SELECT * FROM paper_package_panel WHERE p_p_name = ?");
    $stmt->execute([$paper]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function ceilToHalf($number) {
    return ceil($number * 2) / 2;
}
function shuffleGroupedByHeight(array $arr): array {
    $grouped = [];
    foreach ($arr as $item) {
        $grouped[$item[1]][] = $item; // группируем по высоте
    }
    $result = [];
    foreach ($grouped as $group) {
        shuffle($group);
        $result = array_merge($result, $group);
    }
    return $result;
}


// Генератор всех сочетаний элементов массива по n
function getCombinations($elements, $length) {
    if ($length == 0) return [[]];
    if (count($elements) == 0) return [];

    $result = [];
    $head = $elements[0];
    $tail = array_slice($elements, 1);

    foreach (getCombinations($tail, $length - 1) as $combination) {
        array_unshift($combination, $head);
        $result[] = $combination;
    }

    foreach (getCombinations($tail, $length) as $combination) {
        $result[] = $combination;
    }

    return $result;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>План раскроя</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            margin: 10px;
            background: #fff;
        }

        table {
            border-collapse: collapse;
            margin: 10px auto;
            font-size: 11px;
            width: auto;
            max-width: 900px;
            min-width: 500px;
        }

        th, td {
            border: 1px solid #999;
            padding: 3px 6px;
            text-align: center;
        }

        th {
            background-color: #f0f0f0;
        }

        h2, h3 {
            text-align: center;
            margin: 20px 0 10px;
            font-size: 16px;
        }

        /* Modal styles */
        #manualModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10;
        }

        #manualModal .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            width: 95%;
            max-width: 1400px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .modal-row {
            display: flex;
            gap: 20px;
        }

        .modal-column {
            flex: 1;
            max-width: 50%;
        }

        .scroll-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ccc;
            min-height: 100px; /* Ensure minimum height for visibility */
        }
    </style>
</head>
<body>

<h2>Раскрой для заявки: <b><?= htmlspecialchars($order) ?></b></h2>

<?php if (!empty($missing_filters)): ?>
    <div style="margin: 10px auto; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9; border-radius: 8px; max-width: 800px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">Проверка фильтров в базе данных:</h3>
        
        <div style="color: #333; margin-bottom: 15px; padding: 12px; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
            <h4 style="margin: 0 0 8px 0; color: #856404; font-size: 14px;">⚠️ Расчёт раскроя остановлен</h4>
            <strong style="color: #856404;">НЕ найдено в БД (<?= count($missing_filters) ?>):</strong><br><br>
            <?php foreach ($missing_filters as $missing_filter): ?>
                <div style="margin: 8px 0; padding: 10px; background-color: #fff; border: 1px solid #e0e0e0; border-radius: 4px; display: flex; align-items: center; gap: 10px;">
                    <strong style="min-width: 120px; flex-shrink: 0;"><?= htmlspecialchars($missing_filter) ?></strong> 
                    <select class="analog-filter-select" 
                            data-missing-filter="<?= htmlspecialchars($missing_filter, ENT_QUOTES, 'UTF-8') ?>" 
                            style="flex: 1; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px; max-width: 200px;">
                        <option value="">-- Выбрать аналог --</option>
                        <?php foreach ($all_existing_filters as $existing_filter): ?>
                            <option value="<?= htmlspecialchars($existing_filter) ?>"><?= htmlspecialchars($existing_filter) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <a href="add_panel_filter_into_db.php?filter_name=<?= urlencode($missing_filter) ?>" 
                       target="_blank" 
                       class="add-filter-link"
                       data-missing-filter="<?= htmlspecialchars($missing_filter, ENT_QUOTES, 'UTF-8') ?>"
                       style="padding: 6px 12px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; font-size: 12px; white-space: nowrap;">
                       ➕ Добавить фильтр
                    </a>
                </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 15px; padding: 12px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">
                <strong style="color: #495057;">Действия:</strong><br>
                <ol style="margin: 8px 0; padding-left: 20px; color: #6c757d; font-size: 13px; line-height: 1.4;">
                    <li>Выберите аналог из выпадающего списка, если фильтр уже существует под другим названием</li>
                    <li>Нажмите "Добавить фильтр" для каждого отсутствующего фильтра</li>
                    <li>Заполните необходимые данные (данные аналога будут заполнены автоматически)</li>
                    <li>Обновите эту страницу для продолжения расчёта</li>
                </ol>
            </div>
        </div>
        
        
        <div style="margin-top: 15px; padding: 8px 12px; font-size: 13px; color: #6c757d; background-color: #f8f9fa; border-radius: 4px; text-align: center;">
            Всего фильтров в заявке: <strong style="color: #495057;"><?= count($filters) ?></strong> • 
            Найдено в БД: <strong style="color: #28a745;"><?= count($existing_filters) ?></strong> • 
            Не найдено: <strong style="color: #dc3545;"><?= count($missing_filters) ?></strong>
        </div>
    </div>

    <script>
    // Обработка изменения выбора аналога фильтра - только когда есть отсутствующие фильтры
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, looking for analog filter selects...');
        console.log('Document ready state:', document.readyState);
        
        // Добавляем обработчики событий для всех выпадающих списков аналогов
        const analogSelects = document.querySelectorAll('.analog-filter-select');
        const addFilterLinks = document.querySelectorAll('.add-filter-link');
        console.log('Found analog selects:', analogSelects.length);
        console.log('Found add filter links:', addFilterLinks.length);
        
        if (analogSelects.length === 0) {
            console.log('No analog selects found, checking again in 500ms...');
            setTimeout(function() {
                const retrySelects = document.querySelectorAll('.analog-filter-select');
                console.log('Retry found analog selects:', retrySelects.length);
                if (retrySelects.length > 0) {
                    setupAnalogHandlers(retrySelects);
                }
            }, 500);
        } else {
            setupAnalogHandlers(analogSelects);
        }
        
        function setupAnalogHandlers(selects) {
            console.log('Setting up handlers for', selects.length, 'selects');
            
            selects.forEach(function(select, index) {
                console.log('Adding listener to select', index, select);
                
                select.addEventListener('change', function() {
                    const missingFilter = this.getAttribute('data-missing-filter');
                    const selectedAnalog = this.value;
                    
                    console.log('Analog changed:', {
                        missingFilter: missingFilter,
                        selectedAnalog: selectedAnalog,
                        selectElement: this
                    });
                    
                    // Находим соответствующую ссылку "Добавить фильтр"
                    const addLinks = document.querySelectorAll('.add-filter-link');
                    let addLink = null;
                    for (let link of addLinks) {
                        if (link.getAttribute('data-missing-filter') === missingFilter) {
                            addLink = link;
                            break;
                        }
                    }
                    console.log('Found add link:', addLink);
                    
                    if (addLink) {
                        if (selectedAnalog) {
                            const newHref = `add_panel_filter_into_db.php?filter_name=${encodeURIComponent(missingFilter)}&analog_filter=${encodeURIComponent(selectedAnalog)}`;
                            console.log('New href:', newHref);
                            addLink.href = newHref;
                            addLink.style.backgroundColor = '#28a745'; // Зеленый цвет при выборе аналога
                            addLink.title = `Добавить фильтр "${missingFilter}" с данными аналога "${selectedAnalog}"`;
                        } else {
                            const newHref = `add_panel_filter_into_db.php?filter_name=${encodeURIComponent(missingFilter)}`;
                            console.log('New href (no analog):', newHref);
                            addLink.href = newHref;
                            addLink.style.backgroundColor = '#007bff'; // Исходный синий цвет
                            addLink.title = `Добавить фильтр "${missingFilter}" без аналога`;
                        }
                    } else {
                        console.error('Could not find add link for filter:', missingFilter);
                    }
                });
            });
        }
    });
    </script>

<?php endif; ?>

<?php 
// ===== МОДАЛЬНОЕ ОКНО ДЛЯ ФОРМАТА 199 =====
// Показываем модальное окно только если:
// 1. Нет missing_filters
// 2. Есть фильтры для формата 199
// 3. Еще не назначены фильтры (нет данных в сессии)
if (empty($missing_filters) && !empty($format_199_filters) && empty($format_199_assigned)):
?>
<div id="format199Modal" style="display: block; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #fff; margin: 5% auto; padding: 0; border: 1px solid #999; width: 95%; max-width: 1000px;">
        <div style="padding: 15px 20px; background-color: #f0f0f0; border-bottom: 1px solid #999;">
            <h2 style="margin: 0; font-size: 16px; text-align: center; color: #333;">Распределение фильтров для формата 199</h2>
            <p style="margin: 5px 0 0 0; font-size: 12px; text-align: center; color: #666;">Укажите количество форматов 199 на складе и выберите позиции для них</p>
        </div>
        
        <form method="POST" id="format199Form">
            <div style="padding: 20px;">
                <!-- Количество форматов на складе -->
                <div style="margin-bottom: 20px; padding: 10px; background-color: #f9f9f9; border: 1px solid #999;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #333; font-size: 12px;">
                        Количество форматов 199 на складе:
                    </label>
                    <input type="number" 
                           name="format_199_stock" 
                           id="format_199_stock" 
                           min="0" 
                           value="0" 
                           required
                           style="width: 100px; padding: 3px 6px; border: 1px solid #999; font-size: 12px;"
                           onchange="updateFormat199Calc()">
                    <span style="margin-left: 5px; color: #666; font-size: 12px;">шт.</span>
                </div>
                
                <!-- Список доступных фильтров -->
                <div style="margin-bottom: 20px;">
                    <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #333; text-align: center;">
                        Доступные позиции (ширина 199 мм или 175-190 мм):
                    </h3>
                    
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #999;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead style="position: sticky; top: 0; background: #f0f0f0; z-index: 10;">
                                <tr>
                                    <th style="padding: 3px 6px; text-align: center; border: 1px solid #999; width: 60px;">Выбрать</th>
                                    <th style="padding: 3px 6px; text-align: left; border: 1px solid #999;">Фильтр</th>
                                    <th style="padding: 3px 6px; text-align: center; border: 1px solid #999; width: 80px;">Ширина</th>
                                    <th style="padding: 3px 6px; text-align: center; border: 1px solid #999; width: 100px;">Рулонов в заявке</th>
                                    <th style="padding: 3px 6px; text-align: center; border: 1px solid #999; width: 120px;">Назначить рулонов</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($format_199_filters as $idx => $f): ?>
                                <tr>
                                    <td style="padding: 3px 6px; text-align: center; border: 1px solid #999;">
                                        <input type="checkbox" 
                                               class="filter-checkbox" 
                                               data-filter-index="<?= $idx ?>"
                                               data-filter-name="<?= htmlspecialchars($f['filter']) ?>"
                                               data-max-count="<?= $f['count'] ?>"
                                               onchange="toggleFilterInput(this)">
                                    </td>
                                    <td style="padding: 3px 6px; border: 1px solid #999; font-weight: bold;">
                                        <?= htmlspecialchars($f['filter']) ?>
                                    </td>
                                    <td style="padding: 3px 6px; text-align: center; border: 1px solid #999;">
                                        <?= number_format($f['width'], 0) ?> мм
                                    </td>
                                    <td style="padding: 3px 6px; text-align: center; border: 1px solid #999; font-weight: bold;">
                                        <?php 
                                        // Рассчитываем количество рулонов для этого фильтра
                                        $pleats = $f['pleats'];
                                        $height = $f['height'];
                                        $length_per_filter = $pleats * 2 * $height;
                                        $total_length_m = ($length_per_filter * $f['count']) / 1000;
                                        $reels = ceilToHalf($total_length_m / 1000);
                                        echo number_format($reels, 1, ',', ' ') . ' рул';
                                        ?>
                                    </td>
                                    <td style="padding: 3px 6px; text-align: center; border: 1px solid #999;">
                                        <input type="number" 
                                               name="assigned_filters[<?= htmlspecialchars($f['filter']) ?>]" 
                                               class="filter-count-input"
                                               data-filter-index="<?= $idx ?>"
                                               data-max-reels="<?= $reels ?>"
                                               min="0" 
                                               max="<?= $reels ?>" 
                                               step="0.5"
                                               value="0"
                                               disabled
                                               style="width: 60px; padding: 2px 4px; border: 1px solid #999; text-align: center; font-size: 11px;"
                                               onchange="updateFormat199Calc()">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Итоговая информация -->
                <div style="margin-top: 15px; padding: 10px; background-color: #f9f9f9; border: 1px solid #999;">
                    <div style="display: table; width: 100%; font-size: 12px;">
                        <div style="display: table-row;">
                            <div style="display: table-cell; padding: 5px; text-align: center; width: 33%;">
                                <span style="color: #333;">Форматов на складе:</span><br>
                                <strong id="total_stock" style="font-size: 14px; color: #333;">0 шт</strong>
                            </div>
                            <div style="display: table-cell; padding: 5px; text-align: center; width: 33%;">
                                <span style="color: #333;">Назначено рулонов:</span><br>
                                <strong id="total_assigned" style="font-size: 14px; color: #333;">0 рул</strong>
                            </div>
                            <div style="display: table-cell; padding: 5px; text-align: center; width: 33%;">
                                <span style="color: #333;">Остаток форматов:</span><br>
                                <strong id="remaining_stock" style="font-size: 14px; color: #333;">0 шт</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="padding: 15px 20px; background-color: #f0f0f0; border-top: 1px solid #999; display: flex; justify-content: space-between; align-items: center;">
                <div style="color: #666; font-size: 11px;">
                    <strong>Примечание:</strong> Выбранные позиции будут вычтены из общего расчета
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" 
                            onclick="skipFormat199()" 
                            style="padding: 5px 15px; background: #999; color: white; border: 1px solid #666; cursor: pointer; font-size: 12px;">
                        Пропустить
                    </button>
                    <button type="submit" 
                            name="format_199_submit"
                            value="1"
                            style="padding: 5px 20px; background: #333; color: white; border: 1px solid #000; cursor: pointer; font-size: 12px; font-weight: bold;">
                        Применить и продолжить расчет
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleFilterInput(checkbox) {
    const index = checkbox.dataset.filterIndex;
    const input = document.querySelector(`.filter-count-input[data-filter-index="${index}"]`);
    
    if (checkbox.checked) {
        input.disabled = false;
        input.value = input.dataset.maxReels; // По умолчанию все количество рулонов
    } else {
        input.disabled = true;
        input.value = 0;
    }
    
    updateFormat199Calc();
}

function updateFormat199Calc() {
    const stock = parseInt(document.getElementById('format_199_stock').value) || 0;
    let totalAssigned = 0;
    
    document.querySelectorAll('.filter-count-input:not([disabled])').forEach(input => {
        totalAssigned += parseFloat(input.value) || 0;
    });
    
    const remaining = stock - totalAssigned;
    
    document.getElementById('total_stock').textContent = stock + ' шт';
    document.getElementById('total_assigned').textContent = totalAssigned.toFixed(1) + ' рул';
    document.getElementById('remaining_stock').textContent = remaining.toFixed(1) + ' шт';
    document.getElementById('remaining_stock').style.color = remaining < 0 ? '#dc3545' : '#28a745';
}

function skipFormat199() {
    // Пропускаем распределение - просто отправляем пустую форму
    document.getElementById('format_199_stock').value = 0;
    document.querySelectorAll('.filter-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.filter-count-input').forEach(input => {
        input.value = 0;
        input.disabled = true;
    });
    document.getElementById('format199Form').submit();
}

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
    updateFormat199Calc();
});
</script>

<?php 
endif; // Конец модального окна формата 199
?>

<?php 
// Если есть отсутствующие фильтры, останавливаем выполнение основного кода
if (!empty($missing_filters)): 
    // Показываем только информацию об ошибке, основной расчет не выполняем
?>
    <div style="margin: 20px auto; padding: 15px; text-align: center; background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; max-width: 500px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h3 style="color: #856404; margin: 0 0 8px 0; font-size: 16px;">⚠️ Невозможно выполнить расчёт раскроя</h3>
        <p style="color: #856404; margin: 0; font-size: 14px;">Добавьте отсутствующие фильтры в базу данных и обновите страницу.</p>
    </div>
<?php 
else:
    // Основной код расчета выполняем только если все фильтры есть в БД
?>

<?php if (!empty($format_199_assigned)): ?>
    <div style="margin: 20px auto; padding: 15px; background-color: #f9f9f9; border: 1px solid #999; max-width: 800px;">
        <h3 style="margin: 0 0 10px 0; color: #333; font-size: 14px; text-align: center;">
            Назначено на формат 199
        </h3>
        <div style="background: white; padding: 10px; border: 1px solid #999;">
            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                <thead>
                    <tr style="background: #f0f0f0;">
                        <th style="padding: 3px 6px; text-align: left; border: 1px solid #999; color: #333; font-weight: bold;">Фильтр</th>
                        <th style="padding: 3px 6px; text-align: center; border: 1px solid #999; color: #333; font-weight: bold;">Назначено рулонов</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_assigned_reels = 0;
                    foreach ($format_199_assigned as $filter_name => $assigned_count): 
                        if ($assigned_count > 0):
                            // Находим информацию о фильтре для расчета рулонов
                            $assigned_reels = 0;
                            foreach ($format_199_filters as $f) {
                                if ($f['filter'] === $filter_name) {
                                    $pleats = $f['pleats'];
                                    $height = $f['height'];
                                    $length_per_filter = $pleats * 2 * $height;
                                    $meters_per_reel = 1000; // 1000 метров в рулоне
                                    $filters_per_reel = $meters_per_reel / ($length_per_filter / 1000);
                                    $assigned_reels = $assigned_count / $filters_per_reel;
                                    break;
                                }
                            }
                            $total_assigned_reels += $assigned_reels;
                    ?>
                        <tr>
                            <td style="padding: 3px 6px; border: 1px solid #999; color: #333; font-weight: bold;"><?= htmlspecialchars($filter_name) ?></td>
                            <td style="padding: 3px 6px; text-align: center; border: 1px solid #999; color: #333; font-weight: bold;"><?= number_format($assigned_reels, 1, ',', ' ') ?> рул</td>
                        </tr>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f0f0f0;">
                        <td style="padding: 5px 6px; font-weight: bold; color: #333; border: 1px solid #999;">Всего назначено рулонов:</td>
                        <td style="padding: 5px 6px; text-align: center; font-weight: bold; color: #333; border: 1px solid #999;"><?= number_format($total_assigned_reels, 1, ',', ' ') ?> рул</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="margin-top: 10px; padding: 8px; background: #fff3cd; border: 1px solid #ffeaa7; font-size: 11px; color: #856404;">
            <strong>Примечание:</strong> Эти позиции вычтены из общего расчета раскроя ниже.
            <?php if (isset($_SESSION['format_199_stock']) && $_SESSION['format_199_stock'] > 0): ?>
                Использовано форматов 199: <?= $_SESSION['format_199_stock'] ?> шт.
            <?php endif; ?>
            <a href="?order=<?= urlencode($order) ?>&reset_format_199=1" 
               style="margin-left: 10px; color: #d84315; text-decoration: underline; font-weight: bold;"
               onclick="return confirm('Сбросить назначение форматов 199 и пересчитать?')">
                Пересчитать
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Кнопка для ручной упаковки бухт -->
<div style="margin: 20px auto; text-align: center;">
    <button type="button" onclick="openManualPackingModal()" 
            style="padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: all 0.3s;">
        📦 Упаковать бухты вручную
    </button>
    <?php if (isset($_SESSION['manual_bales']) && !empty($_SESSION['manual_bales'])): ?>
        <span style="margin-left: 10px; padding: 6px 12px; background: #4caf50; color: white; border-radius: 4px; font-size: 12px;">
            ✓ Упаковано вручную: <?= count($_SESSION['manual_bales']) ?> бухт
        </span>
        <a href="?order=<?= urlencode($order) ?>&reset_manual=1" 
           onclick="return confirm('Удалить все ручные бухты?')"
           style="margin-left: 10px; padding: 6px 12px; background: #f44336; color: white; border-radius: 4px; font-size: 12px; text-decoration: none;">
            Сбросить
        </a>
    <?php endif; ?>
</div>

<table>
    <tr>
        <th>Фильтр</th>
        <th>Требуется, шт</th>
        <th>Бумага</th>
        <th>Ширина, мм</th>
        <th>Высота ребра, мм</th>
        <th>Рёбер</th>
        <th>Длина на фильтр, мм</th>
        <th>Итого м</th>
        <th>Рулонов (1000/500)</th>
    </tr>
    <?php
    foreach ($filters as $f) {
        $filter = $f['filter'];
        $count = (int)$f['count'];
        
        // Вычитаем назначенные на формат 199 фильтры
        if (!empty($format_199_assigned) && isset($format_199_assigned[$filter])) {
            $assigned_count = (int)$format_199_assigned[$filter];
            $count = max(0, $count - $assigned_count);
            
            // Если все количество назначено на формат 199, пропускаем этот фильтр
            if ($count == 0) {
                continue;
            }
        }
        
        $paper = getPaperInfo($pdo2, $filter);
        if (!$paper) continue;

        $pleats = (int)$paper['p_p_pleats_count'];
        $height = (float)$paper['p_p_height'];
        $width = (float)$paper['p_p_width'];
        $length_per_filter = $pleats * 2 * $height;
        $total_length_m = ($length_per_filter * $count) / 1000;
        $reels = ceilToHalf($total_length_m / 1000);

        // Распределяем по рулонам
        $full = floor($reels);
        $half = ($reels - $full) >= 0.49 ? 1 : 0;

        // Подсчитываем, сколько рулонов уже использовано вручную
        $key_1000 = $filter . '_' . $width . '_' . $height . '_1000';
        $key_500 = $filter . '_' . $width . '_' . $height . '_500';
        $manual_used_1000 = $manual_rolls_used[$key_1000] ?? 0;
        $manual_used_500 = $manual_rolls_used[$key_500] ?? 0;

        // Добавляем только те рулоны, которые не были использованы вручную
        $rolls_to_add_1000 = max(0, $full - $manual_used_1000);
        for ($i = 0; $i < $rolls_to_add_1000; $i++) {
            $rolls_1000[] = [
                'filter' => $filter,
                'paper' => $paper['p_p_name'],
                'width' => $width,
                'height' => $height,
                'length' => 1000,
                'len_per_filter' => $length_per_filter
            ];
        }

        $rolls_to_add_500 = max(0, $half - $manual_used_500);
        if ($rolls_to_add_500 > 0) {
            $rolls_500[] = [
                'filter' => $filter,
                'paper' => $paper['p_p_name'],
                'width' => $width,
                'height' => $height,
                'length' => 500,
                'len_per_filter' => $length_per_filter
            ];
        }

        echo "<tr>
        <td>" . htmlspecialchars($filter) . "</td>
        <td>$count</td>
        <td>" . htmlspecialchars($paper['p_p_name']) . "</td>
        <td>$width</td>
        <td>$height</td>
        <td>$pleats</td>
        <td>$length_per_filter</td>
        <td>" . number_format($total_length_m, 2, ',', ' ') . "</td>
        <td>$full ×1000 " . ($half ? '+ 1×500' : '') . "</td>
    </tr>";
    }

    // ===== ДОБАВЛЯЕМ СТРОКИ ДЛЯ РУЛОНОВ ФОРМАТА 199 =====
    if (!empty($format_199_assigned)) {
        foreach ($format_199_assigned as $filter_name => $assigned_count) {
            if ($assigned_count > 0) {
                $paper_info = getPaperInfo($pdo2, $filter_name);
                if (!$paper_info) continue;
                
                $pleats = (int)$paper_info['p_p_pleats_count'];
                $height = (float)$paper_info['p_p_height'];
                $width = (float)$paper_info['p_p_width'];
                $length_per_filter = $pleats * 2 * $height;
                $total_length_m = ($length_per_filter * $assigned_count) / 1000;
                $reels = ceilToHalf($total_length_m / 1000);
                
                $full = floor($reels);
                $half = ($reels - $full) >= 0.49 ? 1 : 0;
                
                echo "<tr style='background-color: #f0f8ff;'>
                <td>" . htmlspecialchars($filter_name) . " <span style='color: #666; font-size: 10px;'>(формат 199)</span></td>
                <td>$assigned_count</td>
                <td>" . htmlspecialchars($paper_info['p_p_name']) . "</td>
                <td>$width</td>
                <td>$height</td>
                <td>$pleats</td>
                <td>$length_per_filter</td>
                <td>" . number_format($total_length_m, 2, ',', ' ') . "</td>
                <td>$full ×1000 " . ($half ? '+ 1×500' : '') . "</td>
            </tr>";
            }
        }
    }

    // Отдельный раскрой для 1000 м рулонов
    //list($bales_1000, $left_1000) = packRollsByGroupedHeight($rolls_1000, 1200);
    list($bales_1000, $left_1000) = cut_execute($rolls_1000, 1200, 35, 5);

    // Отдельный раскрой для 500 м рулонов
    //list($bales_500, $left_500) = packRollsByGroupedHeight($rolls_500, 1200);
    list($bales_500, $left_500) = cut_execute($rolls_500, 1200, 35,5);

    // Объединяем результаты
    $bales = array_merge($bales_1000, $bales_500);

    // ===== ОТДЕЛЬНАЯ ОБРАБОТКА РУЛОНОВ ФОРМАТА 199 =====
    $rolls_1000_format199 = [];
    $rolls_500_format199 = [];
    $bales_format199 = [];
    $left_1000_format199 = [];
    $left_500_format199 = [];
    
    if (!empty($format_199_assigned)) {
        error_log("Format 199: Starting processing, assigned filters: " . json_encode($format_199_assigned));
        
        foreach ($format_199_assigned as $filter_name => $assigned_count) {
            if ($assigned_count > 0) {
                // Находим информацию о фильтре
                $paper_info = getPaperInfo($pdo2, $filter_name);
                if (!$paper_info) {
                    error_log("Format 199: No paper info found for filter: $filter_name");
                    continue;
                }
                
                $pleats = (int)$paper_info['p_p_pleats_count'];
                $height = (float)$paper_info['p_p_height'];
                $width = (float)$paper_info['p_p_width'];
                $length_per_filter = $pleats * 2 * $height;
                $total_length_m = ($length_per_filter * $assigned_count) / 1000;
                $reels = ceilToHalf($total_length_m / 1000);
                
                // Распределяем по рулонам формата 199
                $full = floor($reels);
                $half = ($reels - $full) >= 0.49 ? 1 : 0;
                
                // Добавляем рулоны 1000м в отдельный массив
                for ($i = 0; $i < $full; $i++) {
                    $rolls_1000_format199[] = [
                        'filter' => $filter_name,
                        'paper' => $paper_info['p_p_name'],
                        'width' => $width,
                        'height' => $height,
                        'length' => 1000,
                        'len_per_filter' => $length_per_filter
                    ];
                }
                
                // Добавляем рулон 500м в отдельный массив
                if ($half) {
                    $rolls_500_format199[] = [
                        'filter' => $filter_name,
                        'paper' => $paper_info['p_p_name'],
                        'width' => $width,
                        'height' => $height,
                        'length' => 500,
                        'len_per_filter' => $length_per_filter
                    ];
                }
            }
        }
        
        // Для формата 199 каждый рулон = отдельная бухта (не нужен раскрой)
        if (!empty($rolls_1000_format199) || !empty($rolls_500_format199)) {
            error_log("Format 199: Processing rolls - 1000m: " . count($rolls_1000_format199) . ", 500m: " . count($rolls_500_format199));
            
            // Каждый рулон формата 199 - это отдельная бухта
            foreach ($rolls_1000_format199 as $roll) {
                $bales_format199[] = [$roll]; // Бухта с одним рулоном
            }
            
            foreach ($rolls_500_format199 as $roll) {
                $bales_format199[] = [$roll]; // Бухта с одним рулоном
            }
            
            error_log("Format 199: Created bales (one roll per bale): " . count($bales_format199));
        }
    }

    // Удаляем старые данные перед сохранением новых
    $pdo1->prepare("DELETE FROM cut_plans WHERE order_number = ? AND manual = 0")->execute([$order]);
    error_log("Format 199: Deleted old cut_plans for order: $order");

    // Сохраняем раскроенные рулоны в базу данных -
    $bale_id_counter = 1;

    // Сохраняем основные бухты
    foreach ($bales as $bale) {
        foreach ($bale as $roll) {
            $stmt = $pdo1->prepare("INSERT INTO cut_plans (order_number, manual, filter, paper, width, height, length, format, waste, bale_id)
            VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $order,
                $roll['filter'],
                $roll['paper'],
                $roll['width'],
                $roll['height'],
                $roll['length'],
                '1000', // Формат 1000
                $roll['waste'] ?? null,
                $bale_id_counter
            ]);
        }
        $bale_id_counter++;
    }

    // Сохраняем бухты формата 199 отдельно
    if (!empty($bales_format199)) {
        error_log("Format 199: Saving " . count($bales_format199) . " bales to database, starting from bale_id: $bale_id_counter");
        
        foreach ($bales_format199 as $bale) {
            foreach ($bale as $roll) {
                error_log("Format 199: Saving roll - filter: " . $roll['filter'] . ", bale_id: $bale_id_counter");
                
                $stmt = $pdo1->prepare("INSERT INTO cut_plans (order_number, manual, filter, paper, width, height, length, format, waste, bale_id)
                VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $order,
                    $roll['filter'],
                    $roll['paper'],
                    $roll['width'],
                    $roll['height'],
                    $roll['length'],
                    '199', // Формат 199
                    $roll['waste'] ?? null,
                    $bale_id_counter
                ]);
            }
            $bale_id_counter++;
        }
        
        error_log("Format 199: Finished saving, final bale_id: $bale_id_counter");
    } else {
        error_log("Format 199: No bales to save (bales_format199 is empty)");
    }

    // 🆕 Обновляем orders
    $pdo1->prepare("UPDATE orders SET cut_ready = 1 WHERE order_number = ?")->execute([$order]);

    // Оставшиеся рулоны, которые не вошли в раскрой
    $remaining_rolls = array_merge($left_1000, $left_500);

    // Проверка количества полос
    $total_initial = count($rolls_1000) + count($rolls_500);
    $total_format199_initial = count($rolls_1000_format199) + count($rolls_500_format199);
    
    $total_used = 0;
    foreach ($bales as $bale) {
        $total_used += count($bale);
    }
    
    $total_format199_used = 0;
    foreach ($bales_format199 as $bale) {
        $total_format199_used += count($bale);
    }
    
    $total_left = count($remaining_rolls);
    $check = ($total_used + $total_left === $total_initial);
    $check_format199 = ($total_format199_used === $total_format199_initial);
    
?>
</table>

<!-- Блок проверки количества полос -->
    <div style="margin: 30px auto; max-width: 700px; background: #f9f9f9; border: 2px solid #ddd; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="text-align: center; margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
            📊 Проверка количества полос
        </h3>
        
        <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #4caf50;">
            <p style="margin: 0 0 10px 0;"><strong style="font-size: 14px;">Основные рулоны:</strong></p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
                <div>Всего в заявке:</div>
                <div><b><?= $total_initial ?></b></div>
                
                <div>Упаковано в бухты:</div>
                <div><b><?= $total_used ?></b></div>
                
                <div>Осталось неиспользованных:</div>
                <div><b><?= $total_left ?></b></div>
                
                <div style="padding-top: 8px; border-top: 1px solid #eee;">Сумма совпадает:</div>
                <div style="padding-top: 8px; border-top: 1px solid #eee;">
                    <b style="color: <?= $check ? 'green' : 'red' ?>; font-size: 14px;">
                        <?= $check ? 'ДА ✅' : 'НЕТ ❌' ?>
                    </b>
                </div>
            </div>
        </div>
        
        <?php if ($total_format199_initial > 0): ?>
        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid #2196f3;">
            <p style="margin: 0 0 10px 0;"><strong style="font-size: 14px; color: #0066cc;">Рулоны формата 199 (каждый рулон = отдельная бухта):</strong></p>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
                <div>Всего рулонов формата 199:</div>
                <div><b><?= $total_format199_initial ?></b></div>
                
                <div>Создано бухт формата 199:</div>
                <div><b><?= $total_format199_used ?></b></div>
                
                <div style="padding-top: 8px; border-top: 1px solid #eee;">Сумма совпадает:</div>
                <div style="padding-top: 8px; border-top: 1px solid #eee;">
                    <b style="color: <?= $check_format199 ? 'green' : 'red' ?>; font-size: 14px;">
                        <?= $check_format199 ? 'ДА ✅' : 'НЕТ ❌' ?>
                    </b>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

<h3>Рулоны 1000 м</h3>
<table>
    <tr><th>Фильтр</th><th>Бумага</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>
    <?php foreach ($rolls_1000 as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['filter']) ?></td>
            <td><?= htmlspecialchars($r['paper']) ?></td>
            <td><?= $r['width'] ?></td>
            <td><?= $r['height'] ?></td>
            <td><?= $r['length'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h3>Рулоны 500 м</h3>
<table>
    <tr><th>Фильтр</th><th>Бумага</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>
    <?php foreach ($rolls_500 as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['filter']) ?></td>
            <td><?= htmlspecialchars($r['paper']) ?></td>
            <td><?= $r['width'] ?></td>
            <td><?= $r['height'] ?></td>
            <td><?= $r['length'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if (!empty($rolls_1000_format199) || !empty($rolls_500_format199)): ?>
<h3 style="color: #0066cc; border-left: 4px solid #0066cc; padding-left: 10px;">📦 Рулоны формата 199 (1000 м)</h3>
<table style="border: 2px solid #0066cc;">
    <tr style="background-color: #e6f3ff;"><th>Фильтр</th><th>Бумага</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>
    <?php foreach ($rolls_1000_format199 as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['filter']) ?></td>
            <td><?= htmlspecialchars($r['paper']) ?></td>
            <td><?= $r['width'] ?></td>
            <td><?= $r['height'] ?></td>
            <td><?= $r['length'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h3 style="color: #0066cc; border-left: 4px solid #0066cc; padding-left: 10px;">📦 Рулоны формата 199 (500 м)</h3>
<table style="border: 2px solid #0066cc;">
    <tr style="background-color: #e6f3ff;"><th>Фильтр</th><th>Бумага</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>
    <?php foreach ($rolls_500_format199 as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['filter']) ?></td>
            <td><?= htmlspecialchars($r['paper']) ?></td>
            <td><?= $r['width'] ?></td>
            <td><?= $r['height'] ?></td>
            <td><?= $r['length'] ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h3 style="color: #0066cc; border-left: 4px solid #0066cc; padding-left: 10px;">📦 Бухты формата 199 (1 рулон = 1 бухта)</h3>
<table style="border: 2px solid #0066cc;">
    <tr style="background-color: #e6f3ff;">
        <th>Бухта №</th>
        <th>Фильтр</th>
        <th>Ширина</th>
        <th>Высота</th>
        <th>Длина</th>
        <th>Отход</th>
    </tr>
    <?php foreach ($bales_format199 as $i => $bale): ?>
        <?php foreach ($bale as $roll): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($roll['filter']) ?></td>
                <td><?= $roll['width'] ?></td>
                <td><?= $roll['height'] ?></td>
                <td><?= $roll['length'] ?></td>
                <td><?= $roll['waste'] ?? '' ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h3>Упакованные бухты</h3>
<table>
    <tr>
        <th>Бухта №</th>
        <th>Фильтр</th>
        <th>Ширина</th>
        <th>Высота</th>
        <th>Длина</th>
        <th>Отход</th>
    </tr>
    <?php foreach ($bales as $i => $bale): ?>
        <?php foreach ($bale as $roll): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($roll['filter']) ?></td>
                <td><?= $roll['width'] ?></td>
                <td><?= $roll['height'] ?></td>
                <td><?= $roll['length'] ?></td>
                <td><?= $roll['waste'] ?? '' ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
</table>
<h3>Не вошедшие в раскрой рулоны</h3>
<?php if (count($remaining_rolls) === 0): ?>
    <p style="text-align:center; color: red;">Нет рулонов, не вошедших в раскрой</p>
<?php else: ?>
    <table>
        <tr>
            <th>Фильтр</th>
            <th>Бумага</th>
            <th>Ширина</th>
            <th>Высота</th>
            <th>Длина</th>
        </tr>
        <?php foreach ($remaining_rolls as $roll): ?>
            <tr>
                <td><?= htmlspecialchars($roll['filter']) ?></td>
                <td><?= htmlspecialchars($roll['paper']) ?></td>
                <td><?= $roll['width'] ?></td>
                <td><?= $roll['height'] ?></td>
                <td><?= $roll['length'] ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<!-- МОДАЛЬНОЕ ОКНО -->

<div style="text-align: center;">
    <button onclick="openManualPacking()">Упаковать остатки</button>

</div>

<div id="manualModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10;">
    <div class="modal-content">
        <!-- Top row: Остатки and Собираемая бухта -->
        <div class="modal-row">
            <div class="modal-column">
                <h4>Не використані рулони</h4>
                <table id="leftoverTable" border="1" style="width:100%; font-size:11px;"></table>
            </div>
            <div class="modal-column">
                <h4>Собираемая бухта</h4>
                <table id="baleTable" border="1" style="width:100%; font-size:11px;"></table>
                <div style="text-align:right; margin-top:10px;">
                    <span>Суммарная ширина: <b><span id="totalWidth">0</span> мм</b></span><br>
                    <span>Остаток: <b><span id="remainingWidth">1200</span> мм</b></span>
                </div>
                <form method="POST" action="NP/manual_pack.php">
                    <input type="hidden" name="bale_data" id="baleDataInput">
                    <button type="submit" onclick="return saveManualBale()">Сохранить бухту</button>
                    <button type="button" id="closeAfterSaveBtn" onclick="closeManualPacking()" style="display:none; margin-left:10px;">Закрыть окно</button>
                </form>
            </div>
        </div>

        <!-- Bottom row: Всі фільтри and Сформированные вручную бухты -->
        <div class="modal-row">
            <div class="modal-column">
                <h4>Всі фільтри</h4>
                <div class="scroll-container">
                    <table id="catalogTable" border="1" style="width:100%; font-size:11px; border-collapse:collapse;"></table>
                </div>
            </div>
            <div class="modal-column">
                <h4>Сформированные вручную бухты</h4>
                <table id="manualBalesTable" border="1" style="width:100%; font-size:11px;">
                    <thead>
                    <tr><th>№</th><th>Фильтр</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
                <button id="saveAllBalesBtn" onclick="saveAllManualBales()" disabled>Сохранить раскрои</button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" name="order_number" value="<?= htmlspecialchars($order) ?>">

<?php endif; // Конец условия проверки отсутствующих фильтров ?>

<script>
    const remainingRolls = <?= json_encode($remaining_rolls) ?>;
    let allFilters = []; // загрузим через fetch ниже
    let bale = [];

    function openManualPacking() {
        fetch('NP/get_all_filters.php')
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok');
                return res.json();
            })
            .then(data => {
                console.log('Fetched filters:', data); // Для отладки
                if (Array.isArray(data) && data.length > 0) {
                    allFilters = data;
                } else {
                    allFilters = []; // Пустой массив, если данных нет
                    console.warn('No filter data received');
                }
                drawCatalogTable();
                drawInteractiveTables();
                updateTotalWidth();
            })
            .catch(error => {
                console.error('Error fetching filters:', error);
                allFilters = []; // Устанавливаем пустой массив при ошибке
                drawCatalogTable();
                drawInteractiveTables();
                updateTotalWidth();
            });
        document.getElementById('manualModal').style.display = 'block';
    }

    function drawCatalogTable() {
        const table = document.getElementById('catalogTable');
        table.innerHTML = '<tr><th>Фильтр</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>';
        if (allFilters.length > 0) {
            allFilters.forEach((r) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${r.filter || 'N/A'}</td><td>${r.width || 'N/A'}</td><td>${r.height || 'N/A'}</td><td>${r.length || 'N/A'}</td>`;
                tr.style.cursor = 'pointer';
                tr.onclick = () => {
                    const cloned = { ...r, source: 'catalog' };
                    bale.push(cloned);
                    drawInteractiveTables();
                    updateTotalWidth();
                };
                table.appendChild(tr);
            });
        } else {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="4">Нет данных</td>';
            table.appendChild(tr);
        }
    }

    function closeManualPacking() {
        document.getElementById('manualModal').style.display = 'none';
        bale = [];
        drawInteractiveTables();
    }

    function splitRoll(index) {
        const roll = remainingRolls[index];
        if (!roll || roll.length !== 1000) return;

        remainingRolls.splice(index, 1);
        const roll500a = { ...roll, length: 500 };
        const roll500b = { ...roll, length: 500 };
        remainingRolls.push(roll500a, roll500b);

        drawInteractiveTables();
        updateTotalWidth();
    }

    function drawInteractiveTables() {
        const leftTable = document.getElementById('leftoverTable');
        leftTable.innerHTML = '<tr><th>Фильтр</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>';
        remainingRolls.forEach((r, i) => {
            const tr = document.createElement('tr');
            let lengthCell = `${r.length}`;
            if (r.length === 1000) {
                lengthCell += ` <button onclick="splitRoll(${i})" title="Разделить на 2×500">✂️</button>`;
            }
            tr.innerHTML = `<td>${r.filter}</td><td>${r.width}</td><td>${r.height}</td><td>${lengthCell}</td>`;
            tr.style.cursor = 'pointer';
            tr.onclick = (e) => {
                if (e.target.tagName === 'BUTTON') return;
                bale.push(r);
                remainingRolls.splice(i, 1);
                drawInteractiveTables();
                updateTotalWidth();
            };
            leftTable.appendChild(tr);
        });

        const baleTable = document.getElementById('baleTable');
        baleTable.innerHTML = '<tr><th>Фильтр</th><th>Ширина</th><th>Высота</th><th>Длина</th></tr>';
        bale.forEach((r, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${r.filter}</td><td>${r.width}</td><td>${r.height}</td><td>${r.length}</td>`;
            if (r.source === 'catalog') {
                tr.style.backgroundColor = '#fffacc';
            }
            tr.style.cursor = 'pointer';
            tr.onclick = () => {
                if (r.source !== 'catalog') {
                    remainingRolls.push(r);
                }
                bale.splice(i, 1);
                drawInteractiveTables();
                updateTotalWidth();
            };
            baleTable.appendChild(tr);
        });
    }

    function updateTotalWidth() {
        const maxWidth = 1200;
        const total = bale.reduce((sum, r) => sum + parseFloat(r.width), 0);
        const remaining = Math.max(0, maxWidth - total);
        document.getElementById('totalWidth').innerText = total.toFixed(1);
        document.getElementById('remainingWidth').innerText = remaining.toFixed(1);
    }

    function saveManualBale() {
        if (bale.length === 0) return false;
        savedManualBales.push([...bale]);
        bale = [];
        drawInteractiveTables();
        updateTotalWidth();
        drawSavedManualBales();

        // Показываем кнопку "Закрыть окно"
        document.getElementById('closeAfterSaveBtn').style.display = 'inline-block';

        return false; // предотвращаем отправку формы
    }

    let savedManualBales = [];

    function saveManualBale() {
        if (bale.length === 0) return false;
        savedManualBales.push([...bale]);
        bale = [];
        drawInteractiveTables();
        updateTotalWidth();
        drawSavedManualBales();
        return false;
    }

    function drawSavedManualBales() {
        const tbody = document.querySelector("#manualBalesTable tbody");
        tbody.innerHTML = '';
        savedManualBales.forEach((baleGroup, index) => {
            baleGroup.forEach(r => {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${index + 1}</td><td>${r.filter}</td><td>${r.width}</td><td>${r.height}</td><td>${r.length}</td>`;
                tbody.appendChild(tr);
            });
        });
        document.getElementById('saveAllBalesBtn').disabled = savedManualBales.length === 0;
    }

    function saveAllManualBales() {
        if (savedManualBales.length === 0 && bales.length === 0) return;

        const order = <?= json_encode($order) ?>;
        
        // Добавляем ручные бухты из сессии к автоматическим
        const sessionManualBales = <?= json_encode($manual_bales) ?>;
        const allBales = [
            ...<?= json_encode(array_merge($bales, $bales_format199)) ?>,
            ...sessionManualBales
        ];
        
        const payload = {
            order: order,
            auto_bales: allBales,
            manual_bales: savedManualBales
        };

        fetch('NP/save_combined_bales.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
            .then(res => res.text())
            .then(res => {
                alert("Все бухты сохранены!");
                savedManualBales = [];
                drawSavedManualBales();
            })
            .catch(err => {
                console.error(err);
                alert("Ошибка при сохранении.");
            });
    }

</script>

<!-- Модальное окно для ручной упаковки бухт -->
<div id="manualPackingModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; overflow: auto;">
    <div style="background: white; margin: 20px auto; max-width: 1400px; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <!-- Заголовок -->
        <div style="padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 12px 12px 0 0; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin: 0; font-size: 20px;">📦 Ручная упаковка бухт</h2>
            <button onclick="closeManualPackingModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 24px; cursor: pointer; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;">×</button>
        </div>
        
        <!-- Основной контент -->
        <div style="display: flex; gap: 20px; padding: 20px;">
            <!-- Левая панель: Доступные рулоны -->
            <div style="flex: 1; border: 2px solid #e0e0e0; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">Доступные рулоны</h3>
                
                <!-- Фильтр по названию -->
                <div style="margin-bottom: 15px;">
                    <input type="text" 
                           id="filterRollsInput" 
                           placeholder="🔍 Поиск по названию фильтра..." 
                           oninput="filterAvailableRolls()"
                           style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; box-sizing: border-box;">
                </div>
                
                <div id="availableRolls" style="max-height: 450px; overflow-y: auto;">
                    <!-- Рулоны будут добавлены через JavaScript -->
                </div>
                
                <!-- Счётчик -->
                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
                    Показано: <span id="rollsShownCount">0</span> из <span id="rollsTotalCount">0</span>
                </div>
            </div>
            
            <!-- Центральная панель: Конструктор бухты -->
            <div style="flex: 1; border: 2px solid #667eea; border-radius: 8px; padding: 15px; background: #f0f4ff;">
                <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">Текущая бухта</h3>
                
                <!-- Визуализация бухты -->
                <div style="position: relative; height: 60px; background: linear-gradient(to right, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196f3; border-radius: 6px; margin-bottom: 15px;">
                    <div id="baleVisualization" style="display: flex; height: 100%; align-items: center; padding: 0 10px; gap: 2px;">
                        <!-- Рулоны в бухте -->
                    </div>
                    <div style="position: absolute; top: -20px; left: 0; font-size: 11px; color: #666;">0 мм</div>
                    <div style="position: absolute; top: -20px; right: 0; font-size: 11px; color: #666;">1200 мм</div>
                </div>
                
                <!-- Индикаторы -->
                <div style="margin-bottom: 15px;">
                    <div style="margin-bottom: 8px; font-size: 13px;">
                        <strong>Заполнено:</strong> <span id="currentWidth" style="color: #2196f3; font-weight: bold;">0</span> / 1200 мм
                    </div>
                    <div style="margin-bottom: 8px; font-size: 13px;">
                        <strong>Остаток:</strong> <span id="remainingWidth" style="font-weight: bold;">1200</span> мм
                        <span id="wasteIndicator" style="margin-left: 10px; padding: 2px 8px; border-radius: 4px; font-size: 11px;"></span>
                    </div>
                    <div style="font-size: 13px;">
                        <strong>Рулонов в бухте:</strong> <span id="rollCount" style="font-weight: bold;">0</span>
                    </div>
                </div>
                
                <!-- Список рулонов в текущей бухте -->
                <div id="currentBaleRolls" style="max-height: 300px; overflow-y: auto; background: white; border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 15px; min-height: 100px;">
                    <div style="text-align: center; color: #999; padding: 20px;">Выберите рулоны из списка слева</div>
                </div>
                
                <!-- Кнопки управления -->
                <div style="display: flex; gap: 10px;">
                    <button onclick="createBale()" id="createBaleBtn" disabled style="flex: 1; padding: 10px; background: #4caf50; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                        Создать бухту
                    </button>
                    <button onclick="clearCurrentBale()" style="padding: 10px 20px; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Очистить
                    </button>
                </div>
            </div>
            
            <!-- Правая панель: Созданные бухты -->
            <div style="flex: 1; border: 2px solid #e0e0e0; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #333;">
                    Созданные бухты вручную 
                    <span id="manualBalesCount" style="background: #4caf50; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px;">0</span>
                </h3>
                <div id="createdBales" style="max-height: 500px; overflow-y: auto;">
                    <div style="text-align: center; color: #999; padding: 40px 20px;">Пока нет созданных бухт</div>
                </div>
            </div>
        </div>
        
        <!-- Футер -->
        <div style="padding: 15px 20px; background: #f5f5f5; border-radius: 0 0 12px 12px; display: flex; justify-content: space-between; align-items: center;">
            <div style="font-size: 13px; color: #666;">
                💡 Совет: остаток < 35мм считается хорошим, зазоры должны быть ≥ 5мм
            </div>
            <button onclick="saveManualBalesAndClose()" style="padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px;">
                Сохранить и продолжить
            </button>
        </div>
    </div>
</div>

<script>
// Глобальные переменные для ручной упаковки
let availableRollsData = [];
let currentBale = [];
let manualBales = [];

// Открыть модальное окно
function openManualPackingModal() {
    // Инициализация данных рулонов
    initializeAvailableRolls();
    
    // Показываем модальное окно
    document.getElementById('manualPackingModal').style.display = 'block';
}

// Закрыть модальное окно
function closeManualPackingModal() {
    if (manualBales.length > 0) {
        if (!confirm('У вас есть несохраненные бухты. Закрыть окно без сохранения?')) {
            return;
        }
    }
    document.getElementById('manualPackingModal').style.display = 'none';
}

// Инициализация доступных рулонов
function initializeAvailableRolls() {
    // Получаем данные из PHP
    const rolls1000 = <?= json_encode($rolls_1000 ?? []) ?>;
    const rolls500 = <?= json_encode($rolls_500 ?? []) ?>;
    
    availableRollsData = [...rolls1000, ...rolls500].map((roll, idx) => ({
        id: 'roll_' + idx,
        ...roll,
        selected: false
    }));
    
    // Очищаем фильтр
    const filterInput = document.getElementById('filterRollsInput');
    if (filterInput) filterInput.value = '';
    
    renderAvailableRolls();
}

// Фильтрация рулонов по названию
function filterAvailableRolls() {
    renderAvailableRolls();
}

// Отрисовка доступных рулонов
function renderAvailableRolls() {
    const container = document.getElementById('availableRolls');
    const filterInput = document.getElementById('filterRollsInput');
    const filterText = filterInput ? filterInput.value.toLowerCase().trim() : '';
    
    if (availableRollsData.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #999; padding: 20px;">Нет доступных рулонов</div>';
        document.getElementById('rollsShownCount').textContent = '0';
        document.getElementById('rollsTotalCount').textContent = '0';
        return;
    }
    
    // Фильтруем рулоны
    const filteredRolls = availableRollsData.filter(roll => {
        if (filterText === '') return true;
        return roll.filter.toLowerCase().includes(filterText);
    });
    
    // Обновляем счётчики
    document.getElementById('rollsShownCount').textContent = filteredRolls.length;
    document.getElementById('rollsTotalCount').textContent = availableRollsData.length;
    
    if (filteredRolls.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #999; padding: 20px;">Ничего не найдено</div>';
        return;
    }
    
    container.innerHTML = filteredRolls.map(roll => `
        <div style="background: white; border: 1px solid #ddd; border-radius: 4px; padding: 8px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; ${roll.used ? 'opacity: 0.5;' : ''}">
            <input type="checkbox" 
                   id="${roll.id}" 
                   ${roll.used ? 'disabled' : ''}
                   ${currentBale.some(r => r.id === roll.id) ? 'checked' : ''}
                   onchange="toggleRollSelection('${roll.id}')"
                   style="cursor: pointer;">
            <label for="${roll.id}" style="flex: 1; cursor: pointer; font-size: 12px;">
                <strong>${roll.filter}</strong><br>
                <span style="color: #666;">${roll.width}×${roll.height}мм, ${roll.length}м</span>
            </label>
        </div>
    `).join('');
}

// Переключение выбора рулона
function toggleRollSelection(rollId) {
    const roll = availableRollsData.find(r => r.id === rollId);
    if (!roll || roll.used) return;
    
    const checkbox = document.getElementById(rollId);
    
    if (checkbox.checked) {
        // Добавляем в текущую бухту
        currentBale.push(roll);
    } else {
        // Убираем из текущей бухты
        const idx = currentBale.findIndex(r => r.id === rollId);
        if (idx > -1) currentBale.splice(idx, 1);
    }
    
    updateCurrentBale();
}

// Обновление отображения текущей бухты
function updateCurrentBale() {
    const totalWidth = currentBale.reduce((sum, r) => sum + parseFloat(r.width), 0);
    const remaining = 1200 - totalWidth;
    
    // Обновляем индикаторы
    document.getElementById('currentWidth').textContent = totalWidth.toFixed(1);
    document.getElementById('remainingWidth').textContent = remaining.toFixed(1);
    document.getElementById('rollCount').textContent = currentBale.length;
    
    // Индикатор отходов
    const wasteIndicator = document.getElementById('wasteIndicator');
    if (totalWidth > 1200) {
        wasteIndicator.textContent = '❌ Переполнение!';
        wasteIndicator.style.background = '#f44336';
        wasteIndicator.style.color = 'white';
        document.getElementById('createBaleBtn').disabled = true;
    } else if (remaining < 5 && currentBale.length > 0) {
        wasteIndicator.textContent = '⚠️ Зазор < 5мм';
        wasteIndicator.style.background = '#ff9800';
        wasteIndicator.style.color = 'white';
        document.getElementById('createBaleBtn').disabled = true;
    } else if (remaining <= 35 && currentBale.length > 0) {
        wasteIndicator.textContent = '✓ Хорошо';
        wasteIndicator.style.background = '#4caf50';
        wasteIndicator.style.color = 'white';
        document.getElementById('createBaleBtn').disabled = false;
    } else if (currentBale.length > 0) {
        wasteIndicator.textContent = '⚠️ Большой остаток';
        wasteIndicator.style.background = '#ff9800';
        wasteIndicator.style.color = 'white';
        document.getElementById('createBaleBtn').disabled = false;
    } else {
        wasteIndicator.textContent = '';
        document.getElementById('createBaleBtn').disabled = true;
    }
    
    // Визуализация
    const visualization = document.getElementById('baleVisualization');
    visualization.innerHTML = currentBale.map(roll => `
        <div style="height: 100%; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 4px; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px; font-weight: bold; flex: 0 0 ${(roll.width / 1200 * 100).toFixed(1)}%;" title="${roll.filter}: ${roll.width}мм">
            ${roll.width}
        </div>
    `).join('');
    
    // Список рулонов
    const rollsList = document.getElementById('currentBaleRolls');
    if (currentBale.length === 0) {
        rollsList.innerHTML = '<div style="text-align: center; color: #999; padding: 20px;">Выберите рулоны из списка слева</div>';
    } else {
        rollsList.innerHTML = currentBale.map((roll, idx) => `
            <div style="padding: 6px; border-bottom: 1px solid #eee; font-size: 12px;">
                ${idx + 1}. <strong>${roll.filter}</strong> - ${roll.width}×${roll.height}мм, ${roll.length}м
            </div>
        `).join('');
    }
}

// Создать бухту
function createBale() {
    if (currentBale.length === 0) return;
    
    const totalWidth = currentBale.reduce((sum, r) => sum + parseFloat(r.width), 0);
    if (totalWidth > 1200) {
        alert('Бухта переполнена! Уменьшите количество рулонов.');
        return;
    }
    
    // Добавляем в список созданных бухт
    manualBales.push([...currentBale]);
    
    // Отмечаем рулоны как использованные
    currentBale.forEach(roll => {
        roll.used = true;
        const checkbox = document.getElementById(roll.id);
        if (checkbox) checkbox.checked = false;
    });
    
    // Очищаем текущую бухту
    currentBale = [];
    
    // Обновляем интерфейс
    renderAvailableRolls();
    updateCurrentBale();
    renderCreatedBales();
}

// Очистить текущую бухту
function clearCurrentBale() {
    currentBale.forEach(roll => {
        const checkbox = document.getElementById(roll.id);
        if (checkbox) checkbox.checked = false;
    });
    currentBale = [];
    updateCurrentBale();
}

// Отрисовка созданных бухт
function renderCreatedBales() {
    const container = document.getElementById('createdBales');
    document.getElementById('manualBalesCount').textContent = manualBales.length;
    
    if (manualBales.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">Пока нет созданных бухт</div>';
        return;
    }
    
    container.innerHTML = manualBales.map((bale, baleIdx) => {
        const totalWidth = bale.reduce((sum, r) => sum + parseFloat(r.width), 0);
        const waste = 1200 - totalWidth;
        
        return `
            <div style="background: white; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="font-size: 13px;">Бухта #${baleIdx + 1}</strong>
                    <button onclick="deleteBale(${baleIdx})" style="background: #f44336; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;">Удалить</button>
                </div>
                <div style="font-size: 11px; color: #666; margin-bottom: 6px;">
                    Рулонов: ${bale.length} | Ширина: ${totalWidth.toFixed(1)}мм | Остаток: ${waste.toFixed(1)}мм
                </div>
                <div style="font-size: 11px;">
                    ${bale.map(r => r.filter + ' (' + r.width + 'мм)').join(', ')}
                </div>
            </div>
        `;
    }).join('');
}

// Удалить бухту
function deleteBale(baleIdx) {
    const bale = manualBales[baleIdx];
    
    // Освобождаем рулоны
    bale.forEach(roll => {
        roll.used = false;
    });
    
    // Удаляем бухту
    manualBales.splice(baleIdx, 1);
    
    // Обновляем интерфейс
    renderAvailableRolls();
    renderCreatedBales();
}

// Сохранить и закрыть
function saveManualBalesAndClose() {
    if (manualBales.length === 0) {
        alert('Вы не создали ни одной бухты.');
        return;
    }
    
    // Отправляем данные на сервер через AJAX
    fetch('?order=<?= urlencode($order) ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'save_manual_bales',
            bales: manualBales
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Ручные бухты сохранены! Страница будет перезагружена.');
            window.location.reload();
        } else {
            alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Ошибка при сохранении');
    });
}
</script>

</body>
</html>