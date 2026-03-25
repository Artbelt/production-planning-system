<?php /** show_order.php  файл отображает выбранную заявку в режиме просмотра*/

//require_once('tools/tools.php');
//require_once('settings.php');
//require_once ('style/table.txt');

require('tools/tools.php');
require('settings.php');
// style/table.txt здесь не подключаем: стили рамок/hover конфликтуют с модальными окнами


/** Номер заявки которую надо нарисовать (POST — при переходе с главной, иначе пусто при прямой ссылке) */
$order_number = isset($_POST['order_number']) ? trim((string)$_POST['order_number']) : '';

/** При отсутствии номера заявки — сообщение и выход (таблица не заполняется при прямой загрузке страницы) */
if ($order_number === '') {
    echo '<h3>Заявка</h3>';
    echo '<p style="color:#c00;">Номер заявки не передан. Выберите заявку на <a href="main.php">главной странице</a> (кнопка с номером заявки).</p>';
    echo '<p>Страница show_order.php открывается с данными только при переходе из списка заявок.</p>';
    return;
}

/** Заголовок страницы с номером заявки */
echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Заявка '.htmlspecialchars($order_number).'</title></head><body>';

/** Показываем номер заявки */
echo '<h3>Заявка: '.htmlspecialchars($order_number).'</h3>';

/** Стили (в стиле план/show_order.php) */
echo <<<STYLE
<style>
:root{
    --bg:#f6f7f9;
    --panel:#ffffff;
    --ink:#1e293b;
    --muted:#64748b;
    --border:#e2e8f0;
    --accent:#667eea;
    --radius:14px;
    --shadow-soft:0 2px 8px rgba(0,0,0,0.08);
}
html,body{height:100%}
body{
    margin:0; background:var(--bg); color:var(--ink);
    font: 16px/1.6 "Inter","Segoe UI", Arial, sans-serif;
    -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
}

.container{ max-width:1600px; margin:0 auto; padding:16px; }

#loading{
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15,23,42,0.25);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    font-size: 24px;
    color: #fff;
    font-weight: bold;
}
.spinner{
    border: 8px solid rgba(255,255,255,0.3);
    border-top: 8px solid #fff;
    border-radius: 50%;
    width: 80px;
    height: 80px;
    animation: spin 1s linear infinite;
    margin-bottom: 15px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.loading-text{ font-size: 20px; color:#fff; }

.tooltip{
    position: relative;
    display: inline-block;
    cursor: help;
}
.tooltip .tooltiptext{
    visibility: hidden;
    width: max-content;
    max-width: 400px;
    background-color: #333;
    color: #fff;
    text-align: left;
    padding: 5px 10px;
    border-radius: 6px;
    position: absolute;
    z-index: 10;
    bottom: 125%;
    left: 50%;
    transform: translateX(-50%);
    opacity: 0;
    transition: opacity 0.3s;
    white-space: pre-line;
}
.tooltip:hover .tooltiptext{ visibility: visible; opacity: 1; }

table{
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    margin-top: 0;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}
th, td{
    border-bottom: 1px solid var(--border);
    padding: 10px 12px;
    text-align: center;
    color: var(--ink);
}
tr:last-child td{ border-bottom: 0; }
th{ background:#f8fafc; font-weight:600; }

h3{ margin:0; font-size:18px; font-weight:700; }

.table-wrap{ overflow-y:auto; overflow-x:auto; -webkit-overflow-scrolling: touch; border-radius: var(--radius); box-shadow: var(--shadow-soft); }

/* Закрепляем слева 2-й и 3-й столбцы при горизонтальной прокрутке */
#order_table_container{ --sticky-filter-width: 170px; }
#order_table_container th.sticky-filter{
    position: sticky;
    left: 0;
    z-index: 6;
    background: #f8fafc;
    box-shadow: 2px 0 0 rgba(0,0,0,0.04);
}
#order_table_container td.sticky-filter{
    position: sticky;
    left: 0;
    z-index: 5;
    background: var(--panel);
    box-shadow: 2px 0 0 rgba(0,0,0,0.04);
}
#order_table_container th.sticky-qty{
    position: sticky;
    left: var(--sticky-filter-width);
    z-index: 6;
    background: #f8fafc;
    box-shadow: 2px 0 0 rgba(0,0,0,0.04);
}
#order_table_container td.sticky-qty{
    position: sticky;
    left: var(--sticky-filter-width);
    z-index: 5;
    background: var(--panel);
}

/* Buttons / action panel */
button, .btn-secondary{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
    font-weight: 500;
    border-radius: calc(var(--radius) - 2px);
    border: none;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
    text-decoration: none;
}
.btn-secondary{
    background: #f1f5f9;
    color: var(--ink);
    border: 1px solid var(--border);
    box-shadow: none;
}
.btn-secondary:hover{ background: hsl(220, 14%, 92%); }
.btn-sm{ padding: 0.3rem 0.625rem; font-size: 0.75rem; }

.button-group{ display: flex; gap: 0.5rem; flex-wrap: wrap; }
.action-panel{
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1rem;
    box-shadow: var(--shadow-soft);
}
.action-panel-title{
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--muted);
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Modals */
.modal{
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.modal-content{
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    max-width: 900px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes slideIn{
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header{
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border);
    background: var(--panel);
}
.modal-header h2, .modal-header h3{
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--ink);
}
.modal-close{
    background: transparent;
    border: 1px solid var(--border);
    border-radius: calc(var(--radius) - 2px);
    font-size: 1.25rem;
    cursor: pointer;
    color: var(--muted);
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.modal-close:hover{ background: #f1f5f9; color: var(--ink); }
.modal-body{ padding: 1.5rem; background: var(--panel); }
.modal-content.modal-compact{ max-width: 520px; }
.modal-content.modal-compact .modal-header{ padding: 0.5rem 0.75rem; }
.modal-content.modal-compact .modal-header h3{ font-size: 0.8125rem; }
.modal-content.modal-compact .modal-body{ padding: 0.5rem 0.75rem; }
.modal-content.modal-compact table{ font-size: 0.6875rem; }
.modal-content.modal-compact th,
.modal-content.modal-compact td{ padding: 0.2rem 0.4rem; }
.modal-content.modal-compact .no-zero-positions{ padding: 1rem; font-size: 0.75rem; }

.no-zero-positions{
    text-align: center;
    padding: 2.5rem;
    color: var(--muted);
    font-size: 1rem;
}
.zero-positions-header{
    margin: 0 0 1rem 0;
    font-size: 0.75rem;
    color: var(--ink);
    font-weight: 600;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid var(--border);
}

@media (max-width:900px){
    .container{ padding:16px; }
    /* На мобильных ширины часто не хватает: включаем горизонтальную прокрутку */
    table{ font-size:13px; width: max-content; }
    th, td{ padding: 8px 10px; }
}
</style>
STYLE;

echo "<div id='loading'><div class='spinner'></div><div class='loading-text'>Загрузка...</div></div>";
echo "<div class='container'>";

echo "<div class='action-panel'>";
echo "<div class='action-panel-title'>Действия</div>";
echo "<div class='button-group'>";
echo "<button onclick='showZeroProductionPositions()' class='btn-secondary btn-sm'>Позиции выпуск которых = 0</button>";
echo "<button onclick='showLaggingPositions()' class='btn-secondary btn-sm'>Позиции отстающие &gt; 20%</button>";
echo "<button onclick='checkGofraPackages()' class='btn-secondary btn-sm'>Проверка гофропакетов</button>";
echo "<button onclick='confirmArchiveOrder()' class='btn-secondary btn-sm'>Отправить заявку в архив</button>";
echo "</div></div>";

echo "<form id='archiveForm' action='hiding_order.php' method='post' style='display: none;'>";
echo "<input type='hidden' name='order_number' value='".htmlspecialchars($order_number)."'>";
echo "</form>";

/** Кнопка перехода в режим спецификации заявки*/
echo "<form action='show_order_for_workers.php' method='post' style='margin: 0 0 1rem 0;'>";
echo "<input type='hidden' name='order_number' value='".htmlspecialchars($order_number)."'>";
echo "<input type='submit' class='btn-secondary btn-sm' value='Подготовить спецификацию заявки для заготовительного участка'>";
echo "</form>";

/** Формируем шапку таблицы для вывода заявки */
echo "<div class='table-wrap' id='order_table_container'><table id='order_table'>
        <tr>
            <th> №п/п</th>                       
            <th class='sticky-filter'> Фильтр</th>
            <th class='sticky-qty'> Количество, шт</th>
            <th> Маркировка</th>
            <th> Упаковка инд.</th>  
            <th> Этикетка инд.</th>
            <th> Упаковка групп.</th>
            <th> Норма упаковки</th>
            <th> Этикетка групп.</th>    
            <th> Примечание</th>     
            <th> Изготовлено, шт</th>  
            <th> Остаток, шт</th>
            <th> Изготовленные крышки, шт</th>                                                       
            <th> Изготовленные гофропакеты, шт</th>                                                       
        </tr>";

/**
 * Рендер ячейки с тултипом по датам (когда делалось).
 * $dateList — массив вида [дата1, кол-во1, дата2, кол-во2, ...]
 * $totalQty — итоговое число в ячейке
 */
function renderTooltipCell($dateList, $totalQty) {
    if (empty($dateList)) {
        return "<td>" . (int)$totalQty . "</td>";
    }
    $tooltip = '';
    for ($i = 0; $i < count($dateList); $i += 2) {
        $tooltip .= $dateList[$i] . ' — ' . $dateList[$i + 1] . " шт\n";
    }
    return "<td><div class='tooltip'>" . (int)$totalQty . "<span class='tooltiptext'>" . htmlspecialchars(trim($tooltip)) . "</span></div></td>";
}

/** Загружаем из БД заявку */
$result = show_order($order_number);

/** Переменная для подсчета суммы фильтров в заявке */
$filter_count_in_order = 0;



/** Переменная для подсчета количества сделанных фильтров */
$filter_count_produced = 0;

/** Переменная для подсчета количества изготовленных гофропакетов */
$gofro_packages_produced = 0;

/** Переменная для подсчета количества изготовленных крышек по заявке */
$caps_produced = 0;

/** strings counter */
$count =0;

//echo '<form action="filter_parameters.php" method="post">';

/** Подключение к БД для получения гофропакетов */
require_once __DIR__ . '/../auth/includes/db.php';
$pdo_gofro = getPdo('plan_u3');

/** Разбор массива значений по подключению */
while ($row = $result->fetch(PDO::FETCH_ASSOC)){
    $prod_info = select_produced_filters_by_order($row['filter'], $order_number);
    $date_list_filters = $prod_info[0];
    $total_qty_filters = $prod_info[1];
    $difference = (int)$row['count'] - $total_qty_filters;
    $filter_count_in_order = $filter_count_in_order + (int)$row['count'];
    $filter_count_produced = $filter_count_produced + $total_qty_filters;

    // Получаем гофропакет для фильтра из round_filter_structure
    $gofro_package = '';
    $gofro_date_list = [];
    $gofro_package_count = 0;
    $st_gofro = $pdo_gofro->prepare("SELECT filter_package FROM round_filter_structure WHERE filter = ?");
    $st_gofro->execute([$row['filter']]);
    $row_gofro = $st_gofro->fetch(PDO::FETCH_ASSOC);
    if ($row_gofro && !empty($row_gofro['filter_package'])) {
        $gofro_package = $row_gofro['filter_package'];
        list($gofro_date_list, $gofro_package_count) = get_parts_fact_dates($gofro_package, $order_number);
        $gofro_packages_produced += (int)$gofro_package_count;
    }

    $count += 1;
    echo "<tr style='hov'>"
        ."<td>".$count."</td>"
        ."<td class='sticky-filter'>".htmlspecialchars($row['filter'] ?? '')."</td>"
        ."<td class='sticky-qty'>".$row['count']."</td>"
        ."<td>".htmlspecialchars($row['marking'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['personal_packaging'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['personal_label'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['group_packaging'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['packaging_rate'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['group_label'] ?? '')."</td>"
        ."<td>".htmlspecialchars($row['remark'] ?? '')."</td>";
    // Изготовлено фильтров — с тултипом по датам
    echo renderTooltipCell($date_list_filters, $total_qty_filters);
    echo "<td>".$difference."</td>";
    // Изготовленные крышки — с тултипом по датам
    $caps_info = get_caps_fact_dates_by_filter($order_number, $row['filter']);
    $caps_produced += $caps_info[1];
    if ($caps_info[1] > 0) {
        echo renderTooltipCell($caps_info[0], $caps_info[1]);
    } else {
        echo "<td>-</td>";
    }
    // Изготовленные гофропакеты — с тултипом по датам
    if ($gofro_package_count > 0) {
        echo renderTooltipCell($gofro_date_list, $gofro_package_count);
    } else {
        echo "<td>-</td>";
    }
    echo "</tr>";
}

/** Если по заявке не найдено ни одной позиции — выводим подсказку */
if ($count === 0) {
    echo "<tr><td colspan='14' style='padding:10px; color:#666;'>По заявке «".htmlspecialchars($order_number)."» в базе не найдено ни одной позиции. Проверьте номер заявки или создайте заявку.</td></tr>";
}

/** @var расчет оставшегося количества продукции для производства $summ_difference */
$summ_difference = $filter_count_in_order - $filter_count_produced;
echo "<tr style='hov'>"
    ."<td>Итого:</td>"
    ."<td class='sticky-filter'></td>"
    ."<td class='sticky-qty'>".$filter_count_in_order."</td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td></td>"
    ."<td>".$filter_count_produced."</td>"
    ."<td>".$summ_difference.'*'."</td>"
    ."<td>".$caps_produced."</td>"
    ."<td>".$gofro_packages_produced."</td>"
    ."</tr>";

echo "</table>";
echo "</div>";
echo "<p style='margin-top:10px;'>* - без учета перевыполнения</p>";

?>
<!-- Модальные окна (как в plan/show_order.php) -->
<div id="laggingPositionsModal" class="modal">
    <div class="modal-content modal-compact">
        <div class="modal-header">
            <h3>Отставание &gt; 20%</h3>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button onclick="printLaggingPositions()" class="btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">Печать</button>
                <button class="modal-close" onclick="closeLaggingPositionsModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="laggingPositionsContent">
                <p style="text-align:center;padding:20px;color:var(--muted);font-size:0.75rem;">Загрузка...</p>
            </div>
        </div>
    </div>
</div>

<div id="zeroProductionModal" class="modal">
    <div class="modal-content" style="max-width: 585px;">
        <div class="modal-header">
            <h3>Позиции с нулевым выпуском</h3>
            <button class="modal-close" onclick="closeZeroProductionModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="zeroProductionContent">
                <p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>
            </div>
        </div>
    </div>
</div>

<div id="gofraCheckModal" class="modal">
    <div class="modal-content" style="max-width: 585px;">
        <div class="modal-header">
            <h3>Проверка гофропакетов</h3>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <button onclick="printGofraCheck()" class="btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">Печать</button>
                <button class="modal-close" onclick="closeGofraCheckModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="gofraFilters" style="margin-bottom: 1rem; padding: 0.75rem; background: #f1f5f9; border-radius: calc(var(--radius) - 2px); border: 1px solid var(--border);">
                <div style="font-weight: 600; margin-bottom: 0.5rem; color: var(--ink); font-size: 0.75rem;">Фильтр по типу проблемы:</div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.75rem;">
                        <input type="checkbox" id="filterNoGofra" checked style="margin: 0;">
                        <span style="color: #dc2626; font-weight: 600;">Нет гофропакетов</span>
                        <span style="color: var(--muted); font-size: 0.6875rem;">(0 гофропакетов, но есть выпуск)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.75rem;">
                        <input type="checkbox" id="filterShortage" checked style="margin: 0;">
                        <span style="color: #f59e0b; font-weight: 600;">Недостаток</span>
                        <span style="color: var(--muted); font-size: 0.6875rem;">(недостаток ≥ 20 штук)</span>
                    </label>
                </div>
            </div>
            <div id="gofraCheckContent">
                <p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>
            </div>
        </div>
    </div>
</div>

<script>
    function updateStickyColumnOffsets() {
        const container = document.getElementById('order_table_container');
        const table = document.getElementById('order_table');
        if (!container || !table) return;

        const filterTh = table.querySelector('thead th.sticky-filter');
        if (!filterTh) return;

        const w = Math.ceil(filterTh.getBoundingClientRect().width);
        container.style.setProperty('--sticky-filter-width', w + 'px');
    }

    window.addEventListener('load', function () {
        const el = document.getElementById('loading');
        if (el) el.style.display = 'none';
        updateStickyColumnOffsets();
    });

    window.addEventListener('resize', updateStickyColumnOffsets);

    const ORDER_NUMBER = <?php echo json_encode($order_number, JSON_UNESCAPED_UNICODE); ?>;

    function confirmArchiveOrder() {
        const confirmed = confirm('Вы уверены, что хотите отправить заявку "' + ORDER_NUMBER + '" в архив?\\n\\nЭто действие можно отменить только администратором базы данных.');
        if (confirmed) document.getElementById('archiveForm').submit();
    }

    // === Позиции с нулевым выпуском ===
    function showZeroProductionPositions() {
        document.getElementById('zeroProductionModal').style.display = 'flex';
        loadZeroProductionData();
    }
    function closeZeroProductionModal() {
        document.getElementById('zeroProductionModal').style.display = 'none';
    }
    function loadZeroProductionData() {
        const content = document.getElementById('zeroProductionContent');
        content.innerHTML = '<p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>';

        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const zeroPositions = [];

        // В plan_U3 таблица: cells[10] = Изготовлено (выпуск фильтров), cells[13] = Изготовленные гофропакеты
        for (let i = 1; i < rows.length - 1; i++) {
            const cells = rows[i].querySelectorAll('td');
            if (cells.length >= 14) {
                const filter = cells[1].textContent.trim();
                const plannedCount = parseInt(cells[2].textContent) || 0;
                const producedCount = parseInt(cells[10].textContent) || 0;
                const remark = cells[9].textContent.trim();

                const gofraElement = cells[13].querySelector('.tooltip') || cells[13];
                const gofraText = gofraElement.firstChild ? gofraElement.firstChild.textContent.trim() : cells[13].textContent.trim();
                const gofraCount = parseInt(gofraText) || 0;

                if (producedCount === 0 && plannedCount > 0) {
                    zeroPositions.push({ filter, plannedCount, remark, gofraCount });
                }
            }
        }

        if (zeroPositions.length === 0) {
            content.innerHTML = '<div class="no-zero-positions"><p style="color:#22c55e;font-weight:600;">Отлично! Все позиции имеют выпуск больше 0</p></div>';
            return;
        }

        let html = `<div class="zero-positions-header">Найдено позиций с нулевым выпуском: ${zeroPositions.length}</div>`;
        html += `<table><thead><tr><th>Фильтр</th><th style="text-align:center;">План, шт</th><th style="text-align:center;">Гофропакеты, шт</th><th style="text-align:center;">Выпуск, шт</th>${zeroPositions.some(p => p.remark) ? '<th>Примечание</th>' : ''}</tr></thead><tbody>`;
        zeroPositions.forEach(p => {
            html += `<tr><td>${p.filter}</td><td style="text-align:center;">${p.plannedCount}</td><td style="text-align:center;color:var(--accent);font-weight:500;">${p.gofraCount}</td><td style="text-align:center;color:#dc2626;font-weight:600;">0</td>${zeroPositions.some(q => q.remark) ? `<td style="font-size:0.6875rem;color:var(--muted);">${p.remark || ''}</td>` : ''}</tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;
    }

    // === Позиции с отставанием > 20% ===
    function showLaggingPositions() {
        document.getElementById('laggingPositionsModal').style.display = 'flex';
        loadLaggingPositionsData();
    }
    function closeLaggingPositionsModal() {
        document.getElementById('laggingPositionsModal').style.display = 'none';
    }
    function loadLaggingPositionsData() {
        const content = document.getElementById('laggingPositionsContent');
        content.innerHTML = '<p style="text-align:center;padding:20px;color:var(--muted);font-size:0.75rem;">Загрузка...</p>';

        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const laggingPositions = [];

        // В plan_U3 таблица: cells[10] = Изготовлено (выпуск фильтров), cells[13] = Изготовленные гофропакеты
        for (let i = 1; i < rows.length - 1; i++) {
            const cells = rows[i].querySelectorAll('td');
            if (cells.length >= 14) {
                const filter = cells[1].textContent.trim();
                const plannedCount = parseInt(cells[2].textContent) || 0;

                const producedEl = cells[10].querySelector('.tooltip') || cells[10];
                const producedText = producedEl.firstChild ? producedEl.firstChild.textContent.trim() : cells[10].textContent.trim();
                const producedCount = parseInt(producedText) || 0;

                const gofraEl = cells[13].querySelector('.tooltip') || cells[13];
                const gofraText = gofraEl.firstChild ? gofraEl.firstChild.textContent.trim() : cells[13].textContent.trim();
                const gofraCount = parseInt(gofraText) || 0;

                const remark = cells[9].textContent.trim();

                if (plannedCount > 0 && producedCount < plannedCount * 0.8) {
                    const lagPercent = Math.round((1 - producedCount / plannedCount) * 100);
                    laggingPositions.push({ filter, plannedCount, producedCount, gofraCount, remark, lagPercent });
                }
            }
        }

        if (laggingPositions.length === 0) {
            content.innerHTML = '<div class="no-zero-positions"><p style="color:#22c55e;font-weight:600;font-size:0.75rem;">Нет позиций с отставанием &gt; 20%</p></div>';
            return;
        }

        let html = `<div class="zero-positions-header">Найдено: ${laggingPositions.length}</div>`;
        html += `<table><thead><tr><th>Фильтр</th><th style="text-align:center;">План</th><th style="text-align:center;">Изгот.</th><th style="text-align:center;">Гофра</th><th style="text-align:center;">−%</th>${laggingPositions.some(p => p.remark) ? '<th>Прим.</th>' : ''}</tr></thead><tbody>`;
        laggingPositions.forEach(p => {
            html += `<tr><td>${p.filter}</td><td style="text-align:center;">${p.plannedCount}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${p.producedCount}</td><td style="text-align:center;color:var(--accent);">${p.gofraCount}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${p.lagPercent}%</td>${laggingPositions.some(q => q.remark) ? `<td style="font-size:0.65rem;color:var(--muted);">${p.remark || ''}</td>` : ''}</tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;
    }

    function printLaggingPositions() {
        const content = document.getElementById('laggingPositionsContent');
        const printWindow = window.open('', '_blank', 'width=700,height=500');
        const printHTML = `<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Отставание &gt; 20% - ${ORDER_NUMBER}</title><style>body{font-family:Arial,sans-serif;margin:20px;font-size:11px}h1{font-size:14px;margin:0 0 10px}h2{font-size:12px;margin:0 0 15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #374151;padding:4px;text-align:left}th{background:#f3f4f6}</style></head><body><h1>Позиции с отставанием более 20%</h1><h2>Заявка: ${ORDER_NUMBER} | ${new Date().toLocaleDateString('ru-RU')}</h2>${content.innerHTML}</body></html>`;
        printWindow.document.write(printHTML);
        printWindow.document.close();
        printWindow.onload = function() { printWindow.focus(); printWindow.print(); };
    }

    // === Проверка гофропакетов ===
    function checkGofraPackages() {
        document.getElementById('gofraCheckModal').style.display = 'flex';
        loadGofraCheckData();

        const filterNoGofra = document.getElementById('filterNoGofra');
        const filterShortage = document.getElementById('filterShortage');
        filterNoGofra.removeEventListener('change', loadGofraCheckData);
        filterShortage.removeEventListener('change', loadGofraCheckData);
        filterNoGofra.addEventListener('change', loadGofraCheckData);
        filterShortage.addEventListener('change', loadGofraCheckData);
    }
    function closeGofraCheckModal() {
        document.getElementById('gofraCheckModal').style.display = 'none';
    }

    function loadGofraCheckData() {
        const content = document.getElementById('gofraCheckContent');
        content.innerHTML = '<p style="text-align:center;padding:40px;color:var(--muted);">Загрузка данных...</p>';

        const showNoGofra = document.getElementById('filterNoGofra').checked;
        const showShortage = document.getElementById('filterShortage').checked;

        const table = document.getElementById('order_table');
        const rows = table.querySelectorAll('tr');
        const problemPositions = [];

        // В plan_U3 таблица: cells[10] = Изготовлено (выпуск фильтров), cells[13] = Изготовленные гофропакеты
        for (let i = 1; i < rows.length - 1; i++) {
            const cells = rows[i].querySelectorAll('td');
            if (cells.length >= 14) {
                const filter = cells[1].textContent.trim();
                const plan = cells[2].textContent.trim();

                const producedEl = cells[10].querySelector('.tooltip') || cells[10];
                const producedText = producedEl.firstChild ? producedEl.firstChild.textContent.trim() : cells[10].textContent.trim();
                const produced = parseInt(producedText) || 0;

                const gofraEl = cells[13].querySelector('.tooltip') || cells[13];
                const gofraText = gofraEl.firstChild ? gofraEl.firstChild.textContent.trim() : cells[13].textContent.trim();
                const gofra = parseInt(gofraText) || 0;

                const shortage = Math.max(0, produced - gofra);

                let problemType = '', shouldShow = false;
                if (gofra === 0 && produced > 0) {
                    problemType = 'Нет гофропакетов';
                    shouldShow = showNoGofra;
                } else if (gofra < produced && produced > 0 && shortage >= 20) {
                    problemType = 'Недостаток';
                    shouldShow = showShortage;
                }

                if (shouldShow) {
                    problemPositions.push({ filter, plan, produced, gofra, problemType, shortage });
                }
            }
        }

        if (problemPositions.length === 0) {
            const msg = (!showNoGofra && !showShortage)
                ? 'Выберите хотя бы один тип проблемы для отображения.'
                : 'Для выбранных типов проблем ничего не найдено.';
            content.innerHTML = `<div style="text-align:center;padding:2.5rem;"><p style="color:#22c55e;font-size:0.875rem;font-weight:600;">${msg}</p></div>`;
            return;
        }

        let html = `<div class="zero-positions-header">Обнаружено проблемных позиций: ${problemPositions.length}</div>`;
        html += `<table><thead><tr><th>Фильтр</th><th style="text-align:center;">План, шт</th><th style="text-align:center;">Выпущено, шт</th><th style="text-align:center;">Гофропакетов, шт</th><th style="text-align:center;">Недостаток, шт</th><th style="text-align:center;">Тип проблемы</th></tr></thead><tbody>`;
        problemPositions.forEach(pos => {
            const typeColor = pos.problemType === 'Нет гофропакетов' ? '#dc2626' : '#f59e0b';
            html += `<tr><td>${pos.filter}</td><td style="text-align:center;">${pos.plan}</td><td style="text-align:center;color:#22c55e;font-weight:600;">${pos.produced}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${pos.gofra}</td><td style="text-align:center;color:#dc2626;font-weight:600;">${pos.shortage}</td><td style="text-align:center;color:${typeColor};font-weight:600;font-size:0.6875rem;">${pos.problemType}</td></tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;
    }

    function printGofraCheck() {
        const content = document.getElementById('gofraCheckContent');
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        const printHTML = `<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Проверка гофропакетов - ${ORDER_NUMBER}</title><style>body{font-family:Arial,sans-serif;margin:20px;font-size:12px}h1{color:#dc2626;text-align:center;font-size:18px;margin-bottom:20px}table{width:100%;border-collapse:collapse;font-size:11px}th,td{border:1px solid #374151;padding:6px;text-align:center}th{background:#f3f4f6}</style></head><body><h1>Проверка гофропакетов</h1><h2 style="font-size:14px;">Заявка: ${ORDER_NUMBER}</h2><p style="color:#6b7280;font-size:11px;">Дата: ${new Date().toLocaleDateString('ru-RU')}</p>${content.innerHTML}</body></html>`;
        printWindow.document.write(printHTML);
        printWindow.document.close();
        printWindow.onload = function() { printWindow.focus(); printWindow.print(); };
    }

    // Закрытие модальных окон при клике вне контента
    window.onclick = function(event) {
        if (event.target === document.getElementById('zeroProductionModal')) closeZeroProductionModal();
        if (event.target === document.getElementById('laggingPositionsModal')) closeLaggingPositionsModal();
        if (event.target === document.getElementById('gofraCheckModal')) closeGofraCheckModal();
    };
</script>

<script>
    document.querySelectorAll('td').forEach(cell => {
        cell.innerHTML = cell.innerHTML.replace(/\[!(.*?)!\]/g, '<span style="background-color: yellow;">$1</span>');
    });
</script>
 </div>
</body></html>
