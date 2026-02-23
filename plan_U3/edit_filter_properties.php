<?php
require_once('tools/tools.php');
require_once('settings.php');

// Определяем режим работы: 'edit' (редактирование) или 'add' (добавление)
$work_mode = isset($_POST['work_mode']) ? $_POST['work_mode'] : (isset($_GET['mode']) ? $_GET['mode'] : 'add');
if ($work_mode !== 'edit' && $work_mode !== 'add') {
    $work_mode = 'add';
}

// Если нажали "Загрузить", берём текущий filter_name как analog_filter и переключаемся в режим редактирования
if (isset($_POST['load_from_db']) && !empty($_POST['filter_name'])) {
    $_POST['analog_filter'] = $_POST['filter_name'];
    $work_mode = 'edit';
}

// Текущее имя фильтра
$filter_name = isset($_POST['filter_name']) ? $_POST['filter_name'] : '';

// Загрузка списка фильтров для прототипа
try {
    $all_filters = mysql_execute("SELECT filter FROM round_filter_structure ORDER BY filter");
} catch (Throwable $e) { 
    $all_filters = []; 
}

// Текущий выбранный прототип (или загруженный фильтр)
$analog_filter = (isset($_POST['analog_filter']) && $_POST['analog_filter'] !== '') ? $_POST['analog_filter'] : '';

// Получаем данные прототипа (если выбран)
// Если в режиме редактирования и есть аналог в данных, используем его как прототип
if ($work_mode === 'edit' && $filter_name !== '') {
    $temp_data = get_filter_data($filter_name);
    // Если у редактируемого фильтра есть аналог, используем его как прототип для загрузки данных
    if (!empty($temp_data['analog'])) {
        $analog_filter = $temp_data['analog'];
    }
}

if ($analog_filter !== '') {
    $analog_data = get_filter_data($analog_filter);
    // Если загружаем по имени фильтра, используем его как filter_name
    if (isset($_POST['load_from_db']) && !empty($_POST['filter_name'])) {
        $filter_name = $analog_filter;
    }
} else {
    $analog_data = array();
    $analog_data['paper_package_name'] ='';
    $analog_data['paper_package_height'] ='';
    $analog_data['paper_package_diameter'] ='';
    $analog_data['paper_package_ext_wireframe_name'] ='';
    $analog_data['paper_package_ext_wireframe_material'] ='';
    $analog_data['paper_package_int_wireframe_name'] ='';
    $analog_data['paper_package_int_wireframe_material'] ='';
    $analog_data['paper_package_paper_width'] ='';
    $analog_data['paper_package_fold_height'] ='';
    $analog_data['paper_package_fold_count'] ='';
    $analog_data['paper_package_remark'] ='';
    $analog_data['prefilter_name'] ='';
    $analog_data['up_cap'] ='';
    $analog_data['down_cap'] ='';
    $analog_data['pp_insertion'] ='';
    $analog_data['comment'] ='';
    $analog_data['analog'] ='';
    $analog_data['Diametr_outer']='';
    $analog_data['Diametr_inner_1']='';
    $analog_data['Diametr_inner_2']='';
    $analog_data['Height']='';
    $analog_data['packing']='';
    $analog_data['productivity']='';
    $analog_data['press']='';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Редактирование круглого фильтра</title>
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
        .row-4{display:grid; gap:12px; grid-template-columns:repeat(4,1fr); align-items:flex-end}
        .row-4 > div{display:flex; flex-direction:column; justify-content:flex-end}
        .row-4 label{min-height:2.5em; display:flex; align-items:flex-start; margin-bottom:6px}
        input[type="text"], select, input[type="number"]{
            width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border);
            background:#fff; color:var(--text); outline:none;
            transition:border-color .15s, box-shadow .15s;
        }
        input[type="text"]:focus, select:focus, input[type="number"]:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 2px rgba(37,99,235,.15);
        }
        input[readonly]{
            background:#f3f4f6; cursor:not-allowed;
        }
        .help{color:var(--muted); font-size:12px; margin-top:4px}
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
        .btn.load{background:var(--accent-2)}
        .btn.load:hover{background:#047857}
        .proto-form{display:flex; gap:12px; align-items:end; flex-wrap:wrap}
        .proto-form select{min-width:280px}
        .muted{color:var(--muted)}
        .cap-type-selector{display:flex; gap:16px; margin:12px 0}
        .cap-type-selector label{display:flex; align-items:center; gap:6px; margin:0; cursor:pointer}
        .cap-type-selector input[type="radio"]{width:auto; margin:0}
        .cap-options{margin-top:12px}
        .cap-options.hidden{display:none}
        .new-cap-input{margin-top:12px}
        .filter-image{text-align:center; margin:8px 0}
        .filter-image img{max-width:120px; height:auto; border-radius:var(--radius); border:1px solid var(--border); object-fit:contain}
        .load-form{display:flex; gap:8px; align-items:flex-end}
        .load-form input{flex:1}
        input[type="radio"]{
            width:auto; margin-right:4px; cursor:pointer;
        }
        label[style*="cursor:pointer"]{
            padding:8px 12px; border-radius:8px; transition:background .15s;
        }
        label[style*="cursor:pointer"]:hover{
            background:#f3f4f6;
        }
        @media(max-width:900px){
            .row-2,.row-4{grid-template-columns:1fr}
            .grid.cols-2{grid-template-columns:1fr}
            .actions{flex-direction:column; align-items:stretch}
            .load-form{flex-direction:column; align-items:stretch}
        }
    </style>
    <script>
        // Базовое обозначение из аналога (прототипа)
        window.analogFilterBase = <?= json_encode($analog_filter, JSON_UNESCAPED_UNICODE) ?>;
        
        function getBaseDesignation() {
            // Используем аналог (прототип), если он есть, иначе используем filter_name
            if (window.analogFilterBase && window.analogFilterBase !== '') {
                return window.analogFilterBase;
            }
            const filterName = document.querySelector('input[name="filter_name"]');
            return filterName ? filterName.value : '';
        }
        
        function toggleCapOptions(capPosition) {
            const metalRadio = document.getElementById('cap_type_' + capPosition + '_metal');
            const puRadio = document.getElementById('cap_type_' + capPosition + '_pu');
            const metalOptions = document.getElementById('metal_options_' + capPosition);
            const puOptions = document.getElementById('pu_options_' + capPosition);
            const puNameField = document.getElementById('pu_name_' + capPosition);
            
            if (metalRadio.checked) {
                metalOptions.classList.remove('hidden');
                puOptions.classList.add('hidden');
                if (puNameField) puNameField.value = '';
            } else if (puRadio.checked) {
                metalOptions.classList.add('hidden');
                puOptions.classList.remove('hidden');
                updatePUName(capPosition);
            }
        }
        
        function updatePUName(capPosition) {
            const baseDesignation = getBaseDesignation();
            const puNameField = document.getElementById('pu_name_' + capPosition);
            
            if (baseDesignation && puNameField) {
                let number = baseDesignation.replace(/^AF/i, '');
                puNameField.value = number + ' (скв/гл)';
            }
        }
        
        function toggleNewMetalCap(capPosition) {
            const select = document.getElementById('metal_cap_select_' + capPosition);
            const newCapDiv = document.getElementById('new_metal_cap_' + capPosition);
            const newCapInput = document.getElementById('new_metal_cap_input_' + capPosition);
            
            if (select && select.value === '__NEW__') {
                newCapDiv.classList.remove('hidden');
                if (newCapInput) newCapInput.focus();
            } else {
                if (newCapDiv) newCapDiv.classList.add('hidden');
                if (newCapInput) newCapInput.value = '';
            }
        }
        
        function generateNewMetalCapName(capPosition) {
            const baseDesignation = getBaseDesignation();
            const newCapInput = document.getElementById('new_metal_cap_input_' + capPosition);
            
            if (baseDesignation && newCapInput && !newCapInput.value) {
                let number = baseDesignation.replace(/^AF/i, '');
                newCapInput.value = 'AF' + number + ' (скв/гл)';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const filterNameInput = document.querySelector('input[name="filter_name"]');
            // Отслеживаем изменения в выборе прототипа (аналога)
            const analogSelect = document.querySelector('select[name="analog_filter"]');
            
            if (filterNameInput) {
                filterNameInput.addEventListener('input', function() {
                    ['up', 'down'].forEach(function(pos) {
                        const puRadio = document.getElementById('cap_type_' + pos + '_pu');
                        if (puRadio && puRadio.checked) {
                            updatePUName(pos);
                        }
                    });
                });
            }
            
            // Обновляем названия крышек при изменении прототипа
            if (analogSelect) {
                analogSelect.addEventListener('change', function() {
                    // Обновляем глобальную переменную базового обозначения
                    window.analogFilterBase = this.value || '';
                    ['up', 'down'].forEach(function(pos) {
                        const puRadio = document.getElementById('cap_type_' + pos + '_pu');
                        if (puRadio && puRadio.checked) {
                            updatePUName(pos);
                        }
                    });
                });
            }
            
            ['up', 'down'].forEach(function(pos) {
                const puRadio = document.getElementById('cap_type_' + pos + '_pu');
                const puNameField = document.getElementById('pu_name_' + pos);
                
                if (puRadio && puRadio.checked && puNameField && !puNameField.value) {
                    updatePUName(pos);
                }
            });
            
            toggleCapOptions('up');
            toggleCapOptions('down');
        });
    </script>
</head>
<body>

<div class="container">

    <header class="top">
        <div class="title">Редактирование круглого фильтра</div>
        <div class="badge">
            <span class="muted">Категория:</span>
            <strong>Круглый</strong>
        </div>
    </header>

    <!-- Переключатель режимов -->
    <section class="card" style="margin-bottom:16px">
        <h3>Режим работы</h3>
        <form action="" method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer">
                <input type="radio" name="work_mode" value="add" <?= $work_mode === 'add' ? 'checked' : '' ?> onchange="this.form.submit()">
                <span>Добавить новый фильтр в БД</span>
            </label>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer">
                <input type="radio" name="work_mode" value="edit" <?= $work_mode === 'edit' ? 'checked' : '' ?> onchange="this.form.submit()">
                <span>Редактировать существующий фильтр</span>
            </label>
        </form>
    </section>

    <?php if ($work_mode === 'edit'): ?>
        <!-- Режим редактирования -->
        <?php if ($analog_filter !== ''): ?>
            <p class='muted' style='margin:8px 2px 18px'>Загружен фильтр: <b><?= htmlspecialchars($analog_filter) ?></b></p>
        <?php else: ?>
            <p class='muted' style='margin:8px 2px 18px'>Выберите фильтр из списка и нажмите "Загрузить" для редактирования его параметров.</p>
        <?php endif; ?>

        <!-- Загрузка фильтра для редактирования -->
        <section class="card">
            <h3>Загрузка фильтра для редактирования</h3>
            <form action="" method="post" class="load-form">
                <input type="hidden" name="work_mode" value="edit">
                <div style="flex:1">
                    <label>Наименование фильтра</label>
                    <select name="filter_name">
                        <option value="">— Выберите фильтр —</option>
                        <?php foreach ($all_filters as $row):
                            $f = $row['filter'];
                            $sel = ($f === $filter_name) ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($f) ?>" <?= $sel ?>><?= htmlspecialchars($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="load_from_db" class="btn load">Загрузить</button>
            </form>
            <div class="help">Выберите фильтр из списка и нажмите "Загрузить" для редактирования его параметров.</div>
        </section>
    <?php else: ?>
        <!-- Режим добавления -->
        <!-- Прототип -->
        <section class="card">
            <h3>Прототип</h3>
            <form action="" method="post" class="proto-form">
                <input type="hidden" name="work_mode" value="add">
                <div style="flex:1; min-width:280px">
                    <label>Выберите существующий фильтр</label>
                    <select name="analog_filter" onchange="this.form.submit()">
                        <option value="">— без прототипа —</option>
                        <?php foreach ($all_filters as $row):
                            $f = $row['filter'];
                            $sel = ($f === $analog_filter) ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($f) ?>" <?= $sel ?>><?= htmlspecialchars($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">При выборе прототипа параметры ниже заполнятся автоматически — вы сможете их подправить.</div>
                </div>
                <input type="hidden" name="filter_name" value="<?= htmlspecialchars($filter_name) ?>">
            </form>
        </section>
    <?php endif; ?>

    <form id="saveForm" action="processing_add_round_filter_into_db.php" method="post">
        <input type="hidden" name="mode" value="<?= $work_mode === 'edit' ? 'update' : 'insert' ?>">
        <input type="hidden" name="work_mode" value="<?= htmlspecialchars($work_mode) ?>">
        <input type="hidden" name="analog" value="<?= htmlspecialchars($analog_filter) ?>">
        
        <div class="grid cols-2" style="margin-top:16px">
            
            <!-- Общая информация -->
            <section class="card">
                <h3>Общая информация</h3>
                <div class="row-2">
                    <div>
                        <label><b>Наименование фильтра</b></label>
                        <?php if ($work_mode === 'edit'): ?>
                            <input type="text" name="filter_name" value="<?= htmlspecialchars($filter_name) ?>" placeholder="Например, AF1600" readonly>
                        <?php else: ?>
                            <input type="text" name="filter_name" value="<?= htmlspecialchars($filter_name) ?>" placeholder="Например, AF1600">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label>Категория</label>
                        <select name="category">
                            <option>Круглый</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Размеры -->
            <section class="card">
                <h3>Размеры</h3>
                <div class="filter-image" style="margin-bottom:12px">
                    <img src="pictures/filter1.jpg" alt="Схема круглого фильтра">
                </div>
                <div class="row-4">
                    <div>
                        <label>D1 (наружный диаметр)</label>
                        <input type="text" name="Diametr_outer" value="<?= htmlspecialchars($analog_data['Diametr_outer']) ?>" placeholder="мм">
                    </div>
                    <div>
                        <label>d1 (внутренний верх)</label>
                        <input type="text" name="Diametr_inner_1" value="<?= htmlspecialchars($analog_data['Diametr_inner_1']) ?>" placeholder="мм">
                    </div>
                    <div>
                        <label>d2 (внутренний низ)</label>
                        <input type="text" name="Diametr_inner_2" value="<?= htmlspecialchars($analog_data['Diametr_inner_2']) ?>" placeholder="мм">
                    </div>
                    <div>
                        <label>H (высота)</label>
                        <input type="text" name="Height" value="<?= htmlspecialchars($analog_data['Height']) ?>" placeholder="мм">
                    </div>
                </div>
            </section>

            <!-- Гофропакет -->
            <section class="card">
                <h3>Гофропакет</h3>
                <div class="row-2">
                    <div>
                        <label>Наружный каркас</label>
                        <select name="p_p_ext_wireframe">
                            <option value=""></option>
                            <option value="ОЦ 0,45" <?= ($analog_data['paper_package_ext_wireframe_material'] ?? '') == 'ОЦ 0,45' ? 'selected' : '' ?>>ОЦ 0,45</option>
                            <option value="БЖ 0,22" <?= ($analog_data['paper_package_ext_wireframe_material'] ?? '') == 'БЖ 0,22' ? 'selected' : '' ?>>БЖ 0,22</option>
                        </select>
                    </div>
                    <div>
                        <label>Внутренний каркас</label>
                        <select name="p_p_int_wireframe">
                            <option value=""></option>
                            <option value="ОЦ 0,45" <?= ($analog_data['paper_package_int_wireframe_material'] ?? '') == 'ОЦ 0,45' ? 'selected' : '' ?>>ОЦ 0,45</option>
                            <option value="БЖ 0,22" <?= ($analog_data['paper_package_int_wireframe_material'] ?? '') == 'БЖ 0,22' ? 'selected' : '' ?>>БЖ 0,22</option>
                        </select>
                    </div>
                </div>
                <div class="row-4" style="margin-top:12px">
                    <div>
                        <label>Ширина бумаги</label>
                        <input type="text" name="p_p_paper_width" value="<?= htmlspecialchars($analog_data['paper_package_paper_width']) ?>" placeholder="мм">
                    </div>
                    <div>
                        <label>Высота ребра</label>
                        <input type="text" name="p_p_fold_height" value="<?= htmlspecialchars($analog_data['paper_package_fold_height']) ?>" placeholder="мм">
                    </div>
                    <div>
                        <label>Количество ребер</label>
                        <input type="text" name="p_p_fold_count" value="<?= htmlspecialchars($analog_data['paper_package_fold_count']) ?>" placeholder="шт">
                    </div>
                    <div>
                        <label>Комментарий</label>
                        <input type="text" name="p_p_remark" value="<?= htmlspecialchars($analog_data['paper_package_remark']) ?>" placeholder="Примечание">
                    </div>
                </div>
            </section>

            <!-- Крышки -->
            <section class="card">
                <h3>Крышки</h3>
                
                <!-- Верхняя крышка -->
                <div style="margin-bottom:20px; padding-bottom:20px; border-bottom:1px solid var(--border)">
                    <label style="font-weight:600; margin-bottom:8px">Верхняя крышка</label>
                    <div class="cap-type-selector">
                        <label>
                            <input type="radio" name="up_cap_type" id="cap_type_up_metal" value="metal" 
                                   <?= (empty($analog_data['up_cap_PU']) && !empty($analog_data['up_cap'])) || (empty($analog_data['up_cap_PU']) && empty($analog_data['up_cap'])) ? 'checked' : '' ?>
                                   onchange="toggleCapOptions('up')">
                            Металлическая
                        </label>
                        <label>
                            <input type="radio" name="up_cap_type" id="cap_type_up_pu" value="pu"
                                   <?= !empty($analog_data['up_cap_PU']) ? 'checked' : '' ?>
                                   onchange="toggleCapOptions('up')">
                            Полиуретановая
                        </label>
                    </div>
                    
                    <div id="metal_options_up" class="cap-options <?= !empty($analog_data['up_cap_PU']) ? 'hidden' : '' ?>">
                        <label>Выберите из ассортимента или добавьте новую</label>
                        <select id="metal_cap_select_up" name="up_cap_select" onchange="toggleNewMetalCap('up')">
                            <option value="">— Выберите —</option>
                            <option value="__NEW__">+ Добавить новую крышку</option>
                            <?php
                            $pdo = _planPdo();
                            $st = $pdo->query("SELECT DISTINCT cap_name FROM cap_stock ORDER BY cap_name");
                            if ($st) {
                                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = ($analog_data['up_cap'] ?? '') == $row['cap_name'] ? 'selected' : '';
                                    echo "<option value='".htmlspecialchars($row['cap_name'])."' $selected>".htmlspecialchars($row['cap_name'])."</option>";
                                }
                            }
                            ?>
                        </select>
                        <div id="new_metal_cap_up" class="new-cap-input hidden" style="margin-top:12px">
                            <label>Название новой крышки</label>
                            <input type="text" id="new_metal_cap_input_up" name="up_cap_new" 
                                   placeholder="AF1600 (скв/гл)" 
                                   onfocus="generateNewMetalCapName('up')">
                        </div>
                    </div>
                    
                    <div id="pu_options_up" class="cap-options <?= empty($analog_data['up_cap_PU']) ? 'hidden' : '' ?>">
                        <label>Название (формируется автоматически)</label>
                        <input type="text" id="pu_name_up" name="up_cap_PU" 
                               value="<?= htmlspecialchars($analog_data['up_cap_PU'] ?? '') ?>" 
                               readonly>
                    </div>
                </div>
                
                <!-- Нижняя крышка -->
                <div>
                    <label style="font-weight:600; margin-bottom:8px">Нижняя крышка</label>
                    <div class="cap-type-selector">
                        <label>
                            <input type="radio" name="down_cap_type" id="cap_type_down_metal" value="metal"
                                   <?= (empty($analog_data['down_cap_PU']) && !empty($analog_data['down_cap'])) || (empty($analog_data['down_cap_PU']) && empty($analog_data['down_cap'])) ? 'checked' : '' ?>
                                   onchange="toggleCapOptions('down')">
                            Металлическая
                        </label>
                        <label>
                            <input type="radio" name="down_cap_type" id="cap_type_down_pu" value="pu"
                                   <?= !empty($analog_data['down_cap_PU']) ? 'checked' : '' ?>
                                   onchange="toggleCapOptions('down')">
                            Полиуретановая
                        </label>
                    </div>
                    
                    <div id="metal_options_down" class="cap-options <?= !empty($analog_data['down_cap_PU']) ? 'hidden' : '' ?>">
                        <label>Выберите из ассортимента или добавьте новую</label>
                        <select id="metal_cap_select_down" name="down_cap_select" onchange="toggleNewMetalCap('down')">
                            <option value="">— Выберите —</option>
                            <option value="__NEW__">+ Добавить новую крышку</option>
                            <?php
                            $pdo = _planPdo();
                            $st = $pdo->query("SELECT DISTINCT cap_name FROM cap_stock ORDER BY cap_name");
                            if ($st) {
                                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = ($analog_data['down_cap'] ?? '') == $row['cap_name'] ? 'selected' : '';
                                    echo "<option value='".htmlspecialchars($row['cap_name'])."' $selected>".htmlspecialchars($row['cap_name'])."</option>";
                                }
                            }
                            ?>
                        </select>
                        <div id="new_metal_cap_down" class="new-cap-input hidden" style="margin-top:12px">
                            <label>Название новой крышки</label>
                            <input type="text" id="new_metal_cap_input_down" name="down_cap_new" 
                                   placeholder="AF1600 (скв/гл)" 
                                   onfocus="generateNewMetalCapName('down')">
                        </div>
                    </div>
                    
                    <div id="pu_options_down" class="cap-options <?= empty($analog_data['down_cap_PU']) ? 'hidden' : '' ?>">
                        <label>Название (формируется автоматически)</label>
                        <input type="text" id="pu_name_down" name="down_cap_PU" 
                               value="<?= htmlspecialchars($analog_data['down_cap_PU'] ?? '') ?>" 
                               readonly>
                    </div>
                </div>
            </section>

            <!-- Предфильтр -->
            <section class="card">
                <h3>Предфильтр</h3>
                <div>
                    <label>Наличие</label>
                    <select name="prefilter">
                        <option value=""></option>
                        <option value="Есть" <?= !empty($analog_data['prefilter_name']) ? 'selected' : '' ?>>Есть</option>
                    </select>
                </div>
            </section>

            <!-- Вставка PP -->
            <section class="card">
                <h3>Вставка PP</h3>
                <div>
                    <label>Вставка</label>
                    <?php load_insertions() ?>
                </div>
            </section>

            <!-- Групповая упаковка -->
            <section class="card">
                <h3>Групповая упаковка</h3>
                <div>
                    <label>Упаковка</label>
                    <input type="text" name="packing" value="<?= htmlspecialchars($analog_data['packing']) ?>" placeholder="Номер упаковки">
                </div>
            </section>

            <!-- Технологические параметры -->
            <section class="card">
                <h3>Технологические параметры</h3>
                <div class="row-2">
                    <div>
                        <label>Производительность в смену</label>
                        <input type="text" name="productivity" value="<?= htmlspecialchars($analog_data['productivity'] ?? '') ?>" placeholder="шт/смену">
                    </div>
                    <div>
                        <label>Прижимать прессом</label>
                        <select name="press">
                            <?php 
                            // Получаем значение press из данных
                            $press_value = '';
                            if (array_key_exists('press', $analog_data)) {
                                $press_value = $analog_data['press'];
                            }
                            // В БД: 1 или ничего (NULL/пусто)
                            // Если значение равно 1 (число или строка) - выбираем "Да"
                            $is_press_yes = ($press_value === 1 || $press_value === '1');
                            ?>
                            <option value="" <?= !$is_press_yes ? 'selected' : '' ?>>Нет</option>
                            <option value="1" <?= $is_press_yes ? 'selected' : '' ?>>Да</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Примечание -->
            <section class="card" style="grid-column:1/-1">
                <h3>Примечание</h3>
                <input type="text" name="remark" 
                       value="<?= htmlspecialchars($analog_data['comment'] ?? '') ?>"
                       placeholder="Произвольный комментарий" />
            </section>
        </div>
    </form>

    <!-- Кнопки -->
    <div class="actions">
        <div class="muted">Проверьте корректность параметров перед сохранением.</div>
        <div style="display:flex; gap:10px">
            <button type="submit" form="saveForm" class="btn">
                <?= $work_mode === 'edit' ? 'Сохранить изменения' : 'Добавить фильтр в БД' ?>
            </button>
            <button type="button" class="btn secondary" onclick="history.back()">Отмена</button>
        </div>
    </div>

</div>

</body>
</html>
