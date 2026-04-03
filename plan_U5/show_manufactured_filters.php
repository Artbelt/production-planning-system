<?php

require_once('tools/tools.php');
require_once('style/table.txt');

$production_date = reverse_date($_POST['production_date']);
?>
<input type="text"
       id="calendar_input"
       name="selected_date"
       value="<?php echo isset($production_date) ? htmlspecialchars($production_date) : ''; ?>"
       readonly

       style="width: 120px;">
<?php
// === Загружаем доплаты из БД в массив ===
$addition_rows = mysql_execute("SELECT code, amount FROM salary_additions");
$additions = [];
foreach ($addition_rows as $a) {
    $additions[$a['code']] = (float)$a['amount'];
}

// === Загружаем ранее сохраненные часы ===
//$hours_raw = mysql_execute("SELECT filter, order_number, hours FROM hourly_work_log");
$hours_raw = mysql_execute("SELECT filter, order_number, hours FROM hourly_work_log WHERE date_of_work = '$production_date'");

$hours_map = [];
foreach ($hours_raw as $h) {
    $key = $h['filter'] . '_' . $h['order_number'];
    $hours_map[$key] = $h['hours'];
}

$sql = "

        SELECT 
            mp.date_of_production,
            mp.name_of_filter,
            mp.count_of_filters,
            mp.name_of_order,
            mp.team,
        
            sfs.insertion_count,
            sfs.foam_rubber,
            sfs.form_factor,
            sfs.tail,
            sfs.has_edge_cuts,
            pps.p_p_material,
            st.rate_per_unit,
            st.type,
            st.tariff_name
        
        FROM manufactured_production mp
        
        /* --- salon_filter_structure: берем по 1 строке на каждый filter --- */
        LEFT JOIN (
            SELECT 
                filter,
                MAX(insertion_count) AS insertion_count,
                MAX(foam_rubber)     AS foam_rubber,
                MAX(form_factor)     AS form_factor,
                MAX(tail)            AS tail,
                MAX(has_edge_cuts)   AS has_edge_cuts,
                MAX(paper_package)   AS paper_package,
                MAX(tariff_id)       AS tariff_id
            FROM salon_filter_structure
            GROUP BY filter
        ) sfs ON sfs.filter = mp.name_of_filter
        
        /* --- paper_package_salon: по 1 строке на каждый p_p_name --- */
        LEFT JOIN (
            SELECT 
                p_p_name,
                MAX(p_p_material) AS p_p_material
            FROM paper_package_salon
            GROUP BY p_p_name
        ) pps ON pps.p_p_name = sfs.paper_package
        
        LEFT JOIN salary_tariffs st ON st.id = sfs.tariff_id
        WHERE mp.date_of_production = '$production_date';
        ";




$result = mysql_execute($sql);

// Группировка по бригадам
$teams = [];
$sums = [];
$wages = [];
$bonus_breakdown = [];

foreach ($result as $row) {
    $team = $row['team'];
    if (!isset($teams[$team])) {
        $teams[$team] = [];
        $sums[$team] = 0;
        $wages[$team] = 0;
        $bonus_breakdown[$team] = [];
    }

    $base_rate = (float)($row['rate_per_unit'] ?? 0);
    $rate = $base_rate;
    $description = [];

    $tail = mb_strtolower(trim($row['tail'] ?? ''));
    $form = mb_strtolower(trim($row['form_factor'] ?? ''));
    $has_edge_cuts = trim($row['has_edge_cuts'] ?? '');
    $count = (int)$row['count_of_filters'];
    $tariff_type = strtolower(trim($row['type'] ?? ''));
    $tariff_name = mb_strtolower(trim($row['tariff_name'] ?? ''));

    $is_hourly = $tariff_name === 'почасовый';
    $apply_additions = $tariff_type !== 'fixed' && !$is_hourly;
    $apply_edge_cuts = !$is_hourly; // надрезы применяются для всех тарифов кроме почасовых

    if ($apply_additions && strpos($tail, 'языч') !== false && isset($additions['tongue_glue'])) {
        $rate += $additions['tongue_glue'];
        $description[] = '+язычок';
        if (!isset($bonus_breakdown[$team]['язычок'])) {
            $bonus_breakdown[$team]['язычок'] = ['count' => 0, 'rate' => $additions['tongue_glue']];
        }
        $bonus_breakdown[$team]['язычок']['count'] += $count;
    }

    if ($apply_additions && $form === 'трапеция' && isset($additions['edge_trim_glue'])) {
        $rate += $additions['edge_trim_glue'];
        $description[] = '+трапеция';
        if (!isset($bonus_breakdown[$team]['трапеция'])) {
            $bonus_breakdown[$team]['трапеция'] = ['count' => 0, 'rate' => $additions['edge_trim_glue']];
        }
        $bonus_breakdown[$team]['трапеция']['count'] += $count;
    }

    if ($apply_edge_cuts && !empty($has_edge_cuts) && isset($additions['edge_cuts'])) {
        $rate += $additions['edge_cuts'];
        $description[] = '+надрезы';
        if (!isset($bonus_breakdown[$team]['надрезы'])) {
            $bonus_breakdown[$team]['надрезы'] = ['count' => 0, 'rate' => $additions['edge_cuts']];
        }
        $bonus_breakdown[$team]['надрезы']['count'] += $count;
    }

    $key = $row['name_of_filter'] . '_' . $row['name_of_order'];
    $hours = $is_hourly ? ($hours_map[$key] ?? 0) : 0;
    $amount = $is_hourly ? $rate * $hours : $rate * $count;

    $wages[$team] += $amount;
    $sums[$team] += $count;

    $row['final_rate'] = $rate;
    $row['final_amount'] = $amount;
    $row['addition_description'] = implode(' ', $description);
    $row['is_hourly'] = $is_hourly;
    $row['hours'] = $hours;
    $teams[$team][] = $row;
}

ksort($teams);

// Добавляем стили для тултипа
echo "<style>
.brigade-header {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 16px 0 8px 0;
}
.salary-info-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    background: #2563eb;
    color: white;
    border-radius: 50%;
    font-size: 12px;
    font-weight: bold;
    cursor: help;
    position: relative;
    vertical-align: super;
    margin-left: 4px;
}
.salary-info-icon:hover {
    background: #1e40af;
    transform: scale(1.1);
}
.salary-tooltip {
    visibility: hidden;
    opacity: 0;
    position: absolute;
    z-index: 1000;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 12px;
    width: 550px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    top: 30px;
    left: -20px;
    transition: all 0.3s ease;
    font-size: 13px;
    line-height: 1.6;
}
.salary-info-icon:hover .salary-tooltip {
    visibility: visible;
    opacity: 1;
    transform: translateY(5px);
}
.salary-tooltip h4 {
    margin: 0 0 12px 0;
    font-size: 16px;
    border-bottom: 2px solid rgba(255,255,255,0.3);
    padding-bottom: 8px;
}
.salary-tooltip ul {
    margin: 8px 0;
    padding-left: 20px;
}
.salary-tooltip li {
    margin: 6px 0;
}
.salary-tooltip strong {
    color: #fbbf24;
}
.salary-tooltip .highlight {
    background: rgba(251, 191, 36, 0.2);
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 600;
}
.salary-tooltip .section {
    margin: 12px 0;
    padding: 12px;
    background: rgba(255,255,255,0.1);
    border-radius: 8px;
    border-left: 3px solid #fbbf24;
}
/* Стили для таблицы с выпуском продукции */
.table-wrapper {
    overflow-x: auto;
    width: 100%;
    max-width: 100%;
    margin: 12px 0;
}
.produced-filters-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    table-layout: auto;
    line-height: 1.3;
}
.produced-filters-table td {
    padding: 4px 6px;
    border: 1px solid #e5e7eb;
    vertical-align: middle;
    white-space: nowrap;
}
.produced-filters-table td:last-child {
    min-width: 65px !important;
    max-width: 70px !important;
    width: 65px !important;
    overflow: hidden !important;
    white-space: nowrap !important;
    padding: 4px 6px !important;
    position: relative !important;
}
.produced-filters-table td:last-child input {
    width: 55px !important;
    max-width: 55px !important;
    min-width: 55px !important;
    box-sizing: border-box !important;
    padding: 2px 4px !important;
    font-size: 13px !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    border-radius: 0 !important;
}
/* Убираем стрелки у input number */
.produced-filters-table td:last-child input[type='number']::-webkit-inner-spin-button,
.produced-filters-table td:last-child input[type='number']::-webkit-outer-spin-button {
    -webkit-appearance: none !important;
    margin: 0 !important;
    display: none !important;
}
.produced-filters-table td:last-child input[type='number'] {
    -moz-appearance: textfield !important;
}
</style>";

foreach ($teams as $team => $rows) {
    echo "<h3 class='brigade-header'>Бригада $team
        <span class='salary-info-icon'>?
            <div class='salary-tooltip'>
                <h4>📊 Как рассчитывается заработная плата</h4>
                
                <div class='section'>
                    <strong>🎯 Базовая ставка</strong>
                    <ul>
                        <li>Каждому фильтру присваивается <span class='highlight'>тариф</span> из таблицы salary_tariffs</li>
                        <li>Тариф определяет базовую ставку (rate_per_unit) за единицу продукции</li>
                        <li>Тарифы бывают трех типов: <strong>обычный</strong>, <strong>фиксированный (fixed)</strong> и <strong>почасовый</strong></li>
                    </ul>
                </div>

                <div class='section'>
                    <strong>💰 Доплаты (additions)</strong>
                    <p style='margin: 8px 0;'>К базовой ставке могут добавляться доплаты из таблицы salary_additions:</p>
                    <ul>
                        <li><strong>+Язычок</strong> — если у фильтра есть язычок (tail содержит 'языч')<br>
                        <em style='font-size:11px;'>⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                        
                        <li><strong>+Трапеция</strong> — если форма фильтра 'трапеция'<br>
                        <em style='font-size:11px;'>⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                        
                        <li><strong>+Надрезы</strong> — если у фильтра есть надрезы (has_edge_cuts)<br>
                        <em style='font-size:11px;'>✅ Применяется для ВСЕХ тарифов кроме почасовых!</em></li>
                    </ul>
                </div>

                <div class='section'>
                    <strong>🔧 Типы тарифов</strong>
                    <ul>
                        <li><strong>Обычный тариф:</strong> Базовая ставка + ВСЕ доплаты (язычок, трапеция, надрезы)</li>
                        <li><strong>Фиксированный (fixed):</strong> Базовая ставка + только надрезы<br>
                        <em style='font-size:11px;'>Язычок и трапеция НЕ добавляются</em></li>
                        <li><strong>Почасовый:</strong> Ставка × количество часов, без доплат</li>
                    </ul>
                </div>

                <div class='section'>
                    <strong>🧮 Расчет итоговой зарплаты</strong>
                    <p style='margin: 8px 0;'><strong>Для обычных и fixed тарифов:</strong></p>
                    <code style='background:rgba(0,0,0,0.2); padding:8px; display:block; border-radius:6px;'>
                    Зарплата = (Базовая ставка + Доплаты) × Количество фильтров
                    </code>
                    <p style='margin: 8px 0;'><strong>Для почасовых тарифов:</strong></p>
                    <code style='background:rgba(0,0,0,0.2); padding:8px; display:block; border-radius:6px;'>
                    Зарплата = Ставка × Количество часов
                    </code>
                </div>

                <p style='margin-top: 12px; font-size: 11px; opacity: 0.8;'>
                    💡 Детализация по доплатам показывается внизу отчета для каждой бригады
                </p>
            </div>
        </span>
    </h3>";
    echo "<div class='table-wrapper'>";
    echo "<table class='produced-filters-table' style='border: 1px solid black; border-collapse: collapse; width: 100%; table-layout: auto;'>
        <tr>
            <td style='white-space: nowrap;'>Фильтр</td>
            <td style='white-space: nowrap;'>Количество</td>
            <td style='white-space: nowrap;'>Заявка</td>
            <td style='white-space: nowrap;'>Вставка</td>
            <td style='white-space: nowrap;'>Поролон</td>
            <td style='white-space: nowrap;'>Форма</td>
            <td style='white-space: nowrap;'>Хвосты</td>
            <td style='white-space: nowrap;'>Надрезы</td>
            <td style='white-space: nowrap;'>Доплаты</td>
            <td style='white-space: nowrap;'>Материал</td>
            <td style='white-space: nowrap;'>Бригада</td>
            <td style='white-space: nowrap;'>Название тарифа</td>
            <td style='white-space: nowrap;'>Тариф (грн)</td>
            <td style='white-space: nowrap;'>Сумма (грн)</td>
            <td>Часы</td>
        </tr>";

    foreach ($rows as $variant) {
        $rate = number_format($variant['final_rate'], 2, '.', ' ');
        $amount = number_format($variant['final_amount'], 2, '.', ' ');
        $edge_cuts = !empty($variant['has_edge_cuts']) ? 'Да' : '';
        $adds = $variant['addition_description'] ?? '';
        $input_name = "hours[{$variant['name_of_filter']}_{$variant['name_of_order']}]";
        $input_hours = $variant['is_hourly'] ? "<input type='number' step='0.1' min='0' max='999999' name='{$input_name}' value='{$variant['hours']}' maxlength='6' size='6' style='width:55px !important; max-width:55px !important; min-width:55px !important; box-sizing:border-box !important; padding:2px 4px !important; overflow:hidden !important; border-radius:0 !important;'>" : '';

        echo "<tr>
            <td>{$variant['name_of_filter']}</td>
            <td>{$variant['count_of_filters']}</td>
            <td>{$variant['name_of_order']}</td>
            <td>{$variant['insertion_count']}</td>
            <td>{$variant['foam_rubber']}</td>
            <td>{$variant['form_factor']}</td>
            <td>{$variant['tail']}</td>
            <td>{$edge_cuts}</td>
            <td>{$adds}</td>
            <td>{$variant['p_p_material']}</td>
            <td>{$variant['team']}</td>
            <td>{$variant['tariff_name']}</td>
            <td>{$rate}</td>
            <td>{$amount}</td>
            <td>{$input_hours}</td>
        </tr>";
    }
    echo "</table>";
    echo "</div>";
    echo "<p>Сумма выпущенной продукции бригады $team: {$sums[$team]} штук</p>";

    $bonus_total = 0;
    if (!empty($bonus_breakdown[$team])) {
        echo "<p><b>Применённые доплаты:</b></p><ul>";
        foreach ($bonus_breakdown[$team] as $type => $info) {
            $count = $info['count'];
            $rate = number_format($info['rate'], 2, '.', ' ');
            $sum = $info['count'] * $info['rate'];
            $sum_display = number_format($sum, 2, '.', ' ');
            $bonus_total += $sum;
            echo "<li>$type — $count шт. × $rate = $sum_display грн</li>";
        }
        echo "</ul>";
    }

    $total_salary = $wages[$team];
    echo "<p><b>Начисленная зарплата бригады $team: " . number_format($total_salary, 2, '.', ' ') . " грн</b></p><br>";
}

echo "<button onclick='saveHours()' style='margin-top: 10px;'>Сохранить часы</button>";

?>

<script>
    function saveHours() {
        const inputs = document.querySelectorAll("input[name^='hours']");
        const formData = new FormData();

        inputs.forEach(input => {
            if (input.value.trim() !== '') {
                formData.append(input.name, input.value);
            }
        });

        const dateInput = document.getElementById('calendar_input');
        if (dateInput && dateInput.value.trim() !== '') {
            formData.append('selected_date', dateInput.value.trim());
        }

        fetch('save_hours.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.text())
            .then(result => {
                alert("✅ Часы успешно сохранены!");
            })
            .catch(error => {
                console.error("Ошибка:", error);
                alert("❌ Не удалось сохранить часы.");
            });
    }
</script>
