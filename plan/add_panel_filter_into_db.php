<?php
require_once('tools/tools.php');

// Загружаем список форм-факторов из БД
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$form_factors = $pdo->query("SELECT id, name FROM form_factors ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Добавление нового панельного фильтра в БД</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f7f7f7;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        h3 {
            text-align: center;
            color: #444;
        }
        form {
            max-width: 900px;
            margin: 20px auto;
            background: #fff;
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        label {
            display: inline-block;
            margin: 10px 0;
            font-weight: bold;
        }
        input[type="text"], select {
            padding: 5px 8px;
            margin-left: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #ddd;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        input[type="submit"] {
            display: block;
            margin: 20px auto;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        input[type="submit"]:hover {
            background-color: #45a049;
        }
        .field-group {
            margin-bottom: 10px;
        }
        .field-group label {
            font-weight: normal;
            margin-right: 10px;
        }
        #change-log {
            margin-top: 20px;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
            max-height: 150px;
            overflow-y: auto;
        }
        #change-log div {
            margin-bottom: 4px;
        }
        
        /* Модальные окна */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,.2);
            max-width: 400px;
            width: 90%;
            animation: slideIn .2s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #9ca3af;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all .15s;
        }
        .modal-close:hover {
            background: #fef3f2;
            color: #f87171;
        }
        .modal-body {
            padding: 20px;
        }
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            font-size: 13px;
            color: #374151;
        }
        .form-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }
        .btn-modal {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .15s;
        }
        .btn-modal.primary {
            background: #2563eb;
            color: white;
        }
        .btn-modal.primary:hover {
            background: #1e40af;
        }
        .btn-modal.secondary {
            background: #f3f4f6;
            color: #374151;
            border-color: #d1d5db;
        }
        .btn-modal.secondary:hover {
            background: #e5e7eb;
        }
    </style>
</head>
<body>

<h3><b>Добавление / редактирование фильтра в БД</b></h3>

<?php
if (isset($_POST['filter_name'])){
    $filter_name = $_POST['filter_name'];
} else if (isset($_GET['filter_name'])) {
    $filter_name = $_GET['filter_name'];
} else {
    $filter_name = '';
}

if (isset($_POST['analog_filter']) AND ($_POST['analog_filter'] != '')){
    $analog_filter = $_POST['analog_filter'];
    echo "<p style='text-align: center'>ANALOG_FILTER = " . $analog_filter . "</p>";
    $analog_data = get_filter_data($analog_filter);
} else if (isset($_GET['analog_filter']) AND ($_GET['analog_filter'] != '')) {
    // Если передан параметр analog_filter через GET, используем его
    $analog_filter = $_GET['analog_filter'];
    echo "<p style='text-align: center'>ANALOG_FILTER = " . $analog_filter . "</p>";
    $analog_data = get_filter_data($analog_filter);
} else if (isset($_GET['filter_name']) && (!isset($_GET['analog_filter']) || $_GET['analog_filter'] == '')) {
    // Если передан только filter_name через GET, но без analog_filter или с пустым analog_filter
    $analog_filter = '';
    $analog_data = array(
        'paper_package_length' => '',
        'paper_package_width' => '',
        'paper_package_height' => '',
        'paper_package_pleats_count' => '',
        'paper_package_amplifier' => '',
        'paper_package_supplier' => '',
        'paper_package_name' => '',
        'g_box' => '',
        'box' => '',
        'comment' => ''
    );
} else {
    $analog_filter = '';
    echo "<p style='text-align: center';>Аналог не определен</p>";
    $analog_data = array(
        'paper_package_length' => '',
        'paper_package_width' => '',
        'paper_package_height' => '',
        'paper_package_pleats_count' => '',
        'paper_package_amplifier' => '',
        'paper_package_remark' => '',
        'paper_package_supplier' => '',
        'wireframe_length' => '',
        'wireframe_width' => '',
        'wireframe_material' => '',
        'wireframe_supplier' => '',
        'prefilter_length' => '',
        'prefilter_width' => '',
        'prefilter_material' => '',
        'prefilter_supplier' => '',
        'prefilter_remark' => '',
        'form_factor_id' => '',
        'form_factor_remark' => '',
        'glueing' => '',
        'glueing_remark' => '',
        'box' => '',
        'g_box' => '',
        'comment' => ''  // добавлено
    );
}
?>

<form action="processing_add_panel_filter_into_db.php" method="post">
    <div class="field-group">
        <label>Наименование фильтра</label>
        <input type="text" name="filter_name" size="40" value="<?php echo htmlspecialchars($filter_name ?: $analog_filter)?>">
    </div>
    <div class="field-group">
        <label>Категория</label>
        <select name="category">
            <option>Панельный</option>
        </select>
    </div>

    <hr>
    <div class="section-title">Гофропакет:</div>
    <div class="field-group">
        <label>Длина г/п:<input type="text" size="5" name="p_p_length" value="<?php echo $analog_data['paper_package_length'] ?>"></label>
        <label>Ширина г/п:<input type="text" size="5" name="p_p_width" value="<?php echo $analog_data['paper_package_width'] ?>"></label>
        <label>Высота г/п:<input type="text" size="5" name="p_p_height" value="<?php echo $analog_data['paper_package_height'] ?>"></label>
        <label>Кол-во ребер  г/п:<input type="text" size="5" name="p_p_pleats_count" value="<?php echo $analog_data['paper_package_pleats_count'] ?>"></label>
    </div>
    <div class="field-group">
        <label>Усилитель:<input type="text" size="2" name="p_p_amplifier" value="<?php echo $analog_data['paper_package_amplifier'] ?>"></label>
        <label>Поставщик  г/п:
            <select name="p_p_supplier">
                <option></option>
                <option <?php if ($analog_data['paper_package_supplier'] == 'У2'){echo 'selected';} ?> >У2</option>
            </select>
        </label>
    </div>
    <div class="field-group">
        <label>Комментарий  г/п:<input type="text" size="50" name="p_p_remark" value="<?php echo $analog_data['paper_package_remark'] ?>"></label>
    </div>

    <hr>
    <div class="section-title">Каркас:</div>
    <div class="field-group">
        <label>Длина каркаса:<input type="text" size="5" name="wf_length" value="<?php echo $analog_data['wireframe_length'] ?>"></label>
        <label>Ширина каркаса:<input type="text" size="5" name="wf_width" value="<?php echo $analog_data['wireframe_width'] ?>"></label>
        <label>Материал каркаса:
            <select name="wf_material">
                <option></option>
                <option <?php if ($analog_data['wireframe_material'] == 'ОЦ 0,45'){echo 'selected';} ?>>ОЦ 0,45</option>
                <option <?php if ($analog_data['wireframe_material'] == 'Жесть 0,22'){echo 'selected';} ?>>Жесть 0,22</option>
            </select>
        </label>
        <label>Поставщик каркаса:
            <select name="wf_supplier">
                <option></option>
                <option <?php if ($analog_data['wireframe_supplier'] == 'ЗУ'){echo 'selected';} ?>>ЗУ</option>
            </select>
        </label>
    </div>

    <hr>
    <div class="section-title">Предфильтр:</div>
    <div class="field-group">
        <label>Длина предфильтра:<input type="text" size="5" name="pf_length" value="<?php echo $analog_data['prefilter_length'] ?>"></label>
        <label>Ширина предфильтра:<input type="text" size="5" name="pf_width" value="<?php echo $analog_data['prefilter_width'] ?>"></label>
        <label>Материал предфильтра:
            <select name="pf_material">
                <option></option>
                <option <?php if ($analog_data['prefilter_material'] == 'Н/т полотно'){echo 'selected';} ?>>Н/т полотно</option>
            </select>
        </label>
        <label>Поставщик предфильтра:
            <select name="pf_supplier">
                <option></option>
                <option <?php if ($analog_data['prefilter_supplier'] == 'УУ'){echo 'selected';} ?>>УУ</option>
            </select>
        </label>
    </div>
    <div class="field-group">
        <label>Комментарий к предфильтру:<input type="text" size="50" name="pf_remark" value="<?php echo $analog_data['prefilter_remark'] ?>"></label>
    </div>
    <hr>
    <div class="section-title">Проливка:</div>
    <select name="glueing">
        <option></option>
        <option <?php if ($analog_data['glueing'] == '1'){echo 'selected';} ?>>1</option>
        <option <?php if ($analog_data['glueing'] == '2'){echo 'selected';} ?>>2</option>
        <option <?php if ($analog_data['glueing'] == '3'){echo 'selected';} ?>>3</option>
        <option <?php if ($analog_data['glueing'] == '4'){echo 'selected';} ?>>4</option>
    </select>
    <div class="field-group">
        <label>Комментарий к проливке:<input type="text" size="50" name="glueing_remark" value="<?php echo $analog_data['glueing_remark']; ?>"></label>
    </div>
    <hr>
    <div class="section-title">Форм-фактор:</div>
    <select name="form_factor">
        <option value="">-- Выберите форм-фактор --</option>
        <?php foreach ($form_factors as $ff): ?>
            <option value="<?= htmlspecialchars($ff['id']) ?>"
                <?= ($analog_data['form_factor_id'] == $ff['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($ff['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="field-group">
        <label>Комментарий к форм-фактору:<input type="text" size="50" name="form_factor_remark" value="<?php echo $analog_data['form_factor_remark'] ?? ''; ?>"></label>
    </div>

    <hr>
    <div class="section-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Индивидуальная упаковка:</span>
        <button type="button" onclick="openBoxModal()" 
                style="width:28px; height:28px; border-radius:50%; background:#2563eb; color:white; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:bold;"
                title="Добавить новую коробку">+</button>
    </div>
    <div class="field-group">
        <label>Коробка №: <select name="box" id="box_select"><?php select_boxes($analog_data['box']);?></select></label>
    </div>

    <hr>
    <div class="section-title" style="display:flex; justify-content:space-between; align-items:center;">
        <span>Групповая упаковка:</span>
        <button type="button" onclick="openGBoxModal()" 
                style="width:28px; height:28px; border-radius:50%; background:#059669; color:white; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:bold;"
                title="Добавить новый ящик">+</button>
    </div>
    <div class="field-group">
        <label>Ящик №: <select name="g_box" id="g_box_select"><?php select_g_boxes($analog_data['g_box']);?></select></label>
    </div>

    <hr>
    <div class="field-group">
        <label>Примечание:<input type="text" size="100" name="remark" value="<?php echo $analog_data['comment'] ?>"></label>
    </div>

    <hr>
    <div id="change-log"><b>Изменения:</b></div>
    <input type="hidden" name="changes_log" id="changes_log">
    <?php if (!empty($analog_filter)): ?>
        <input type="hidden" name="analog_filter" value="<?php echo htmlspecialchars($analog_filter) ?>">
    <?php endif; ?>

    <input type="submit" value="Сохранить фильтр">
</form>

<!-- Модальное окно для добавления индивидуальной коробки -->
<div id="boxModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Добавить новую коробку</h3>
            <button class="modal-close" onclick="closeBoxModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Номер коробки *</label>
                <input type="text" id="b_name" placeholder="Например: 5" required>
            </div>
            <div class="form-group">
                <label>Длина (мм) *</label>
                <input type="text" id="b_length" placeholder="Например: 250" required>
            </div>
            <div class="form-group">
                <label>Ширина (мм) *</label>
                <input type="text" id="b_width" placeholder="Например: 180" required>
            </div>
            <div class="form-group">
                <label>Высота (мм) *</label>
                <input type="text" id="b_heght" placeholder="Например: 50" required>
            </div>
            <div class="form-group">
                <label>Поставщик</label>
                <input type="text" id="b_supplier" placeholder="Например: УУ" value="УУ">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal secondary" onclick="closeBoxModal()">Отмена</button>
            <button type="button" class="btn-modal primary" onclick="saveBox()">Сохранить</button>
        </div>
    </div>
</div>

<!-- Модальное окно для добавления группового ящика -->
<div id="gBoxModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Добавить новый ящик</h3>
            <button class="modal-close" onclick="closeGBoxModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Номер ящика *</label>
                <input type="text" id="gb_name" placeholder="Например: 10" required>
            </div>
            <div class="form-group">
                <label>Длина (мм) *</label>
                <input type="text" id="gb_length" placeholder="Например: 465" required>
            </div>
            <div class="form-group">
                <label>Ширина (мм) *</label>
                <input type="text" id="gb_width" placeholder="Например: 232" required>
            </div>
            <div class="form-group">
                <label>Высота (мм) *</label>
                <input type="text" id="gb_heght" placeholder="Например: 427" required>
            </div>
            <div class="form-group">
                <label>Поставщик</label>
                <input type="text" id="gb_supplier" placeholder="Например: УУ" value="УУ">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal secondary" onclick="closeGBoxModal()">Отмена</button>
            <button type="button" class="btn-modal primary" onclick="saveGBox()">Сохранить</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const logDiv = document.getElementById("change-log");
        const logField = document.getElementById("changes_log");

        document.querySelectorAll("input[type=text], select").forEach(el => {
            el.dataset.oldValue = el.value;

            el.addEventListener("change", function() {
                let oldVal = this.dataset.oldValue || "пусто";
                let newVal = this.value || "пусто";
                if (oldVal !== newVal) {
                    let label = this.closest("label") ? this.closest("label").innerText.split(':')[0] : this.name;
                    let msg = `  ${label}  : [ ${oldVal} ] => [ ${newVal} ];`;
                    let p = document.createElement("div");
                    p.textContent = msg;
                    logDiv.appendChild(p);
                    this.dataset.oldValue = newVal;
                    logField.value += msg + "\n";
                }
            });
        });
    });
    
    // ========== Функции модального окна для индивидуальных коробок ==========
    function openBoxModal() {
        document.getElementById('boxModal').style.display = 'flex';
        document.getElementById('b_name').value = '';
        document.getElementById('b_length').value = '';
        document.getElementById('b_width').value = '';
        document.getElementById('b_heght').value = '';
        document.getElementById('b_supplier').value = 'УУ';
    }
    
    function closeBoxModal() {
        document.getElementById('boxModal').style.display = 'none';
    }
    
    document.getElementById('boxModal').addEventListener('click', function(e) {
        if (e.target === this) closeBoxModal();
    });
    
    async function saveBox() {
        const b_name = document.getElementById('b_name').value.trim();
        const b_length = document.getElementById('b_length').value.trim();
        const b_width = document.getElementById('b_width').value.trim();
        const b_heght = document.getElementById('b_heght').value.trim();
        const b_supplier = document.getElementById('b_supplier').value.trim();
        
        if (!b_name || !b_length || !b_width || !b_heght) {
            alert('Пожалуйста, заполните все обязательные поля (помеченные *)');
            return;
        }
        
        if (isNaN(parseFloat(b_length)) || isNaN(parseFloat(b_width)) || isNaN(parseFloat(b_heght))) {
            alert('Длина, ширина и высота должны быть числами');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('b_name', b_name);
            formData.append('b_length', b_length.replace(/,/g, '.'));
            formData.append('b_width', b_width.replace(/,/g, '.'));
            formData.append('b_heght', b_heght.replace(/,/g, '.'));
            formData.append('b_supplier', b_supplier);
            
            const response = await fetch('save_box.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('✓ Коробка успешно добавлена!');
                closeBoxModal();
                
                const select = document.getElementById('box_select');
                const option = document.createElement('option');
                option.value = b_name;
                option.text = b_name;
                option.selected = true;
                select.appendChild(option);
            } else {
                alert('Ошибка при добавлении коробки: ' + (result.error || 'неизвестная ошибка'));
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка при сохранении коробки');
        }
    }
    
    // ========== Функции модального окна для групповых ящиков ==========
    function openGBoxModal() {
        document.getElementById('gBoxModal').style.display = 'flex';
        document.getElementById('gb_name').value = '';
        document.getElementById('gb_length').value = '';
        document.getElementById('gb_width').value = '';
        document.getElementById('gb_heght').value = '';
        document.getElementById('gb_supplier').value = 'УУ';
    }
    
    function closeGBoxModal() {
        document.getElementById('gBoxModal').style.display = 'none';
    }
    
    document.getElementById('gBoxModal').addEventListener('click', function(e) {
        if (e.target === this) closeGBoxModal();
    });
    
    async function saveGBox() {
        const gb_name = document.getElementById('gb_name').value.trim();
        const gb_length = document.getElementById('gb_length').value.trim();
        const gb_width = document.getElementById('gb_width').value.trim();
        const gb_heght = document.getElementById('gb_heght').value.trim();
        const gb_supplier = document.getElementById('gb_supplier').value.trim();
        
        if (!gb_name || !gb_length || !gb_width || !gb_heght) {
            alert('Пожалуйста, заполните все обязательные поля (помеченные *)');
            return;
        }
        
        if (isNaN(parseFloat(gb_length)) || isNaN(parseFloat(gb_width)) || isNaN(parseFloat(gb_heght))) {
            alert('Длина, ширина и высота должны быть числами');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('gb_name', gb_name);
            formData.append('gb_length', gb_length.replace(/,/g, '.'));
            formData.append('gb_width', gb_width.replace(/,/g, '.'));
            formData.append('gb_heght', gb_heght.replace(/,/g, '.'));
            formData.append('gb_supplier', gb_supplier);
            
            const response = await fetch('save_g_box.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                alert('✓ Ящик успешно добавлен!');
                closeGBoxModal();
                
                const select = document.getElementById('g_box_select');
                const option = document.createElement('option');
                option.value = gb_name;
                option.text = gb_name;
                option.selected = true;
                select.appendChild(option);
            } else {
                alert('Ошибка при добавлении ящика: ' + (result.error || 'неизвестная ошибка'));
            }
        } catch (error) {
            console.error('Ошибка:', error);
            alert('Ошибка при сохранении ящика');
        }
    }
</script>

</body>
</html>
