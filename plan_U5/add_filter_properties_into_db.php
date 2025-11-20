<?php
require_once('tools/tools.php');
require_once('settings.php');

$workshop = $_POST['workshop'] ?? $_GET['workshop'] ?? 'U5';
$selected_filter = $_POST['filter_name'] ?? '';

// Если фильтр выбран, загружаем его данные
$filter_data = null;
if ($selected_filter) {
    $filter_data = get_salon_filter_data($selected_filter);
}

// Загружаем список тарифов с build_complexity
$tariffs = [];
try {
    $tariff_result = mysql_execute("SELECT id, tariff_name, rate_per_unit, type, build_complexity FROM salary_tariffs ORDER BY tariff_name");
    while ($row = $tariff_result->fetch_assoc()) {
        $tariffs[] = $row;
    }
} catch (Exception $e) {
    // Игнорируем ошибки
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Редактирование параметров фильтра</title>
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
        .container{max-width:1100px; margin:24px auto 64px; padding:0 16px;}
        header.top{
            display:flex; align-items:center; justify-content:space-between;
            padding:18px 20px; background:#fff; border:1px solid var(--border);
            border-radius:var(--radius); box-shadow:var(--shadow);
        }
        .title{font-size:18px; font-weight:700; letter-spacing:.2px}
        .badge{
            display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border:1px solid var(--border);
            border-radius:999px; color:var(--muted); background:#f3f4f6;
        }
        .grid{display:grid; gap:16px}
        .grid.cols-2{grid-template-columns:1fr 1fr}
        .card{
            background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:var(--shadow); padding:18px;
        }
        .card h3{margin:0 0 12px; font-size:16px; font-weight:700}
        label{display:block; color:var(--muted); margin-bottom:6px; font-size:13px}
        .row-2{display:grid; gap:12px; grid-template-columns:1fr 1fr}
        .row-4{display:grid; gap:12px; grid-template-columns:repeat(4,1fr)}
        input[type="text"], input[type="number"], select{
            width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border);
            background:#fff; color:var(--text); outline:none;
            transition:border-color .15s, box-shadow .15s;
        }
        input[type="text"]:focus, input[type="number"]:focus, select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 2px rgba(37,99,235,.15);
        }
        .help{color:var(--muted); font-size:12px; margin-top:4px}
        .checks{display:flex; gap:14px; flex-wrap:wrap; margin-top:10px}
        .check{
            display:flex; align-items:center; gap:6px; padding:6px 8px; border:1px solid var(--border);
            border-radius:8px; background:#f9fafb;
        }
        .actions{
            position:sticky; bottom:0; margin-top:20px; padding:12px 16px; background:#fff;
            border:1px solid var(--border); border-radius:var(--radius);
            display:flex; justify-content:space-between; align-items:center; gap:12px;
            box-shadow:0 -2px 10px rgba(0,0,0,.05);
        }
        .btn{
            border:1px solid transparent; background:var(--accent);
            color:white; padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer;
            transition:background .15s;
        }
        .btn:hover{background:#1e4ed8}
        .btn.secondary{background:#f3f4f6; color:var(--text); border-color:var(--border)}
        .btn.secondary:hover{background:#e5e7eb}
        .muted{color:var(--muted)}
        .select-form{display:flex; gap:12px; align-items:end; flex-wrap:wrap}
        .select-form select{min-width:280px}
        @media(max-width:900px){
            .row-2,.row-4{grid-template-columns:1fr}
            .grid.cols-2{grid-template-columns:1fr}
            .actions{flex-direction:column; align-items:stretch}
        }
    </style>
</head>
<body>

<div class="container">

    <header class="top">
        <div class="title">Редактирование параметров фильтра</div>
        <div class="badge">
            <span class="muted">Цех:</span>
            <strong><?php echo htmlspecialchars($workshop); ?></strong>
        </div>
    </header>

    <?php if (!$selected_filter): ?>
        <!-- Форма выбора фильтра -->
        <section class="card">
            <h3>Выберите фильтр для редактирования</h3>
            <form method="post" class="select-form">
                <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($workshop); ?>">
                <div style="flex:1; min-width:280px">
                    <select name="filter_name" required>
                        <option value="">— Выберите фильтр —</option>
                        <?php
                        // Загружаем список фильтров
                        try {
                            $all_filters = mysql_execute("SELECT DISTINCT filter FROM salon_filter_structure WHERE filter IS NOT NULL AND filter != '' ORDER BY filter LIMIT 5000");
                            foreach ($all_filters as $row) {
                                $f = $row['filter'];
                                echo "<option value=\"" . htmlspecialchars($f) . "\">" . htmlspecialchars($f) . "</option>";
                            }
                        } catch (Exception $e) {
                            echo "<option disabled>Ошибка загрузки фильтров</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn">Загрузить параметры</button>
            </form>
        </section>
    <?php else: ?>
        <!-- Форма редактирования -->
        <?php if (!$filter_data): ?>
            <div class="card" style="background:#fff3cd; border-color:#ffc107; color:#856404;">
                <strong>Ошибка:</strong> Фильтр "<?php echo htmlspecialchars($selected_filter); ?>" не найден в базе данных.
            </div>
            <div class="actions">
                <a href="?workshop=<?php echo urlencode($workshop); ?>" class="btn secondary">Выбрать другой фильтр</a>
            </div>
        <?php else: ?>
            <form id="editForm" action="processing_edit_filter_properties.php" method="post">
                <input type="hidden" name="workshop" value="<?php echo htmlspecialchars($workshop); ?>">
                <input type="hidden" name="filter_name" value="<?php echo htmlspecialchars($selected_filter); ?>">

                <div class="grid cols-2" style="margin-top:16px">
                    <!-- Общая информация -->
                    <section class="card">
                        <h3>Общая информация</h3>
                        <div class="row-2">
                            <div>
                                <label><b>Наименование фильтра</b></label>
                                <input type="text" name="filter_name_display" value="<?php echo htmlspecialchars($selected_filter); ?>" readonly style="background:#f3f4f6;">
                            </div>
                            <div>
                                <label>Категория</label>
                                <select name="category">
                                    <option value="Салонный" <?php echo ($filter_data['category'] ?? '') === 'Салонный' ? 'selected' : ''; ?>>Салонный</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <!-- Тариф и сложность -->
                    <section class="card">
                        <h3>Тариф и сложность производства</h3>
                        <div class="row-2">
                            <div>
                                <label>Тариф</label>
                                <select name="tariff_id" id="tariffSelect" onchange="updateBuildComplexity()">
                                    <option value="">— Не выбран —</option>
                                    <?php foreach ($tariffs as $tariff): ?>
                                        <option value="<?php echo htmlspecialchars($tariff['id']); ?>" 
                                                data-complexity="<?php echo htmlspecialchars($tariff['build_complexity'] ?? ''); ?>"
                                                <?php echo (isset($filter_data['tariff_id']) && $filter_data['tariff_id'] != '' && $filter_data['tariff_id'] == $tariff['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tariff['tariff_name']); ?> 
                                            <?php if (!empty($tariff['rate_per_unit'])): ?>
                                                (<?php echo htmlspecialchars($tariff['rate_per_unit']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Сложность производства (шт/смену)</label>
                                <input type="number" name="build_complexity" id="buildComplexityInput" step="0.01" 
                                       value="" 
                                       placeholder="Автоматически из тарифа" readonly style="background:#f3f4f6; cursor:not-allowed;">
                                <small style="color:var(--muted); font-size:11px; margin-top:4px; display:block">Заполняется автоматически из выбранного тарифа</small>
                            </div>
                        </div>
                    </section>

                    <!-- Гофропакет -->
                    <section class="card">
                        <h3>Гофропакет</h3>
                        <div class="row-4">
                            <div>
                                <label>Ширина шторы</label>
                                <input type="text" name="p_p_width" value="<?php echo htmlspecialchars($filter_data['paper_package_width'] ?? ''); ?>" placeholder="мм">
                            </div>
                            <div>
                                <label>Высота шторы</label>
                                <input type="text" name="p_p_height" value="<?php echo htmlspecialchars($filter_data['paper_package_height'] ?? ''); ?>" placeholder="мм">
                            </div>
                            <div>
                                <label>Кол-во ребер</label>
                                <input type="text" name="p_p_pleats_count" value="<?php echo htmlspecialchars($filter_data['paper_package_pleats_count'] ?? ''); ?>">
                            </div>
                            <div>
                                <label>Поставщик</label>
                                <select name="p_p_supplier">
                                    <option value=""></option>
                                    <option value="У5" <?php echo ($filter_data['paper_package_supplier'] ?? '') === 'У5' ? 'selected' : ''; ?>>У5</option>
                                </select>
                            </div>
                        </div>
                        <div class="row-2" style="margin-top:12px">
                            <div>
                                <label>Материал</label>
                                <select name="p_p_material">
                                    <option value=""></option>
                                    <option value="Carbon" <?php echo ($filter_data['paper_package_material'] ?? '') === 'Carbon' ? 'selected' : ''; ?>>Carbon</option>
                                </select>
                            </div>
                            <div>
                                <label>Комментарий</label>
                                <input type="text" name="p_p_remark" value="<?php echo htmlspecialchars($filter_data['paper_package_remark'] ?? ''); ?>" placeholder="Примечание по гофропакету">
                            </div>
                        </div>
                    </section>

                    <!-- Вставка -->
                    <section class="card">
                        <h3>Вставка</h3>
                        <div class="row-2">
                            <div>
                                <label>Количество в фильтре</label>
                                <input type="text" name="insertions_count" value="<?php echo htmlspecialchars($filter_data['insertion_count'] ?? ''); ?>">
                            </div>
                            <div>
                                <label>Поставщик</label>
                                <select name="insertions_supplier">
                                    <option value=""></option>
                                    <option value="УУ" <?php echo !empty($filter_data['insertion_count']) ? 'selected' : ''; ?>>УУ</option>
                                </select>
                            </div>
                        </div>
                    </section>

                    <!-- Лента / опции -->
                    <section class="card">
                        <h3>Лента и опции</h3>
                        <div class="row-2">
                            <div>
                                <label>Высота боковой ленты</label>
                                <input type="text" name="side_type" value="<?php echo htmlspecialchars($filter_data['side_type'] ?? ''); ?>" placeholder="мм">
                            </div>
                        </div>
                        <div class="checks">
                            <label class="check">
                                <input type="checkbox" name="foam_rubber" <?php echo ($filter_data['foam_rubber_checkbox_state'] ?? '') === 'checked' ? 'checked' : ''; ?>> 
                                Поролон
                            </label>
                            <label class="check">
                                <input type="checkbox" name="tail" <?php echo ($filter_data['tail_checkbox_state'] ?? '') === 'checked' ? 'checked' : ''; ?>> 
                                Язычок
                            </label>
                            <label class="check">
                                <input type="checkbox" name="form_factor" <?php echo ($filter_data['form_factor_checkbox_state'] ?? '') === 'checked' ? 'checked' : ''; ?>> 
                                Трапеция
                            </label>
                            <label class="check">
                                <input type="checkbox" name="has_edge_cuts" value="1" <?php echo (isset($filter_data['has_edge_cuts']) && $filter_data['has_edge_cuts'] == 1) ? 'checked' : ''; ?>> 
                                Надрезы
                            </label>
                        </div>
                    </section>

                    <!-- Упаковка: индивидуальная -->
                    <section class="card">
                        <h3>Индивидуальная упаковка</h3>
                        <div class="row-2">
                            <div>
                                <label>Коробка №</label>
                                <select name="box"><?php select_boxes($filter_data['box'] ?? ''); ?></select>
                            </div>
                        </div>
                    </section>

                    <!-- Упаковка: групповая -->
                    <section class="card">
                        <h3>Групповая упаковка</h3>
                        <div class="row-2">
                            <div>
                                <label>Ящик №</label>
                                <select name="g_box"><?php select_g_boxes($filter_data['g_box'] ?? ''); ?></select>
                            </div>
                        </div>
                    </section>

                    <!-- Примечание -->
                    <section class="card" style="grid-column:1/-1">
                        <h3>Примечание</h3>
                        <input type="text" name="remark" value="<?php echo htmlspecialchars($filter_data['comment'] ?? ''); ?>" placeholder="Произвольный комментарий" />
                    </section>
                </div>

                <!-- Кнопки -->
                <div class="actions">
                    <div class="muted">Проверьте корректность параметров перед сохранением.</div>
                    <div style="display:flex; gap:10px">
                        <button type="submit" class="btn">Сохранить изменения</button>
                        <a href="?workshop=<?php echo urlencode($workshop); ?>" class="btn secondary">Отмена</a>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>

</div>

<script>
// Функция обновления сложности производства из выбранного тарифа
function updateBuildComplexity() {
    const tariffSelect = document.getElementById('tariffSelect');
    const complexityInput = document.getElementById('buildComplexityInput');
    
    if (!tariffSelect || !complexityInput) {
        return;
    }
    
    const selectedOption = tariffSelect.options[tariffSelect.selectedIndex];
    const complexity = selectedOption ? selectedOption.getAttribute('data-complexity') : '';
    
    if (complexity && complexity !== '') {
        complexityInput.value = complexity;
    } else {
        complexityInput.value = '';
    }
}

// Устанавливаем начальное значение при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    updateBuildComplexity();
});
</script>

</body>
</html>
