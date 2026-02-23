<?php
require_once __DIR__ . '/../../auth/includes/db.php';
$pdo = getPdo('plan_u3');
$date = $_GET['date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT id, order_number, plan_date, filter_label, `count`, fact_count, status
    FROM corrugation_plans
    WHERE plan_date = ?
    ORDER BY order_number, id
");
$stmt->execute([$date]);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Задания гофромашины</title>
    <style>
        body{font-family:sans-serif;background:#f0f0f0;padding:10px}
        h2{text-align:center;margin:6px 0 12px}
        form{text-align:center;margin-bottom:10px}
        .section{max-width:800px;margin:0 auto;background:#fff;padding:10px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08)}

        table{border-collapse:collapse;width:100%;font-size:14px}
        thead th{background:#f5f5f5}
        th,td{border:1px solid #ddd;padding:6px 8px;text-align:center}
        tbody tr:nth-child(even){background:#fafafa}

        /* выполненная строка */
        .is-done td{
            text-decoration: line-through;
            color:#6b7280;            /* серый */
            background:#eaf7ea !important; /* лёгкий зелёный фон */
        }

        /* кнопки / инпуты */
        button{padding:6px 10px;font-size:14px;cursor:pointer}
        input[type="number"]{width:80px;padding:4px 6px;text-align:center}
        input[type="date"]{padding:4px 6px}
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover {
            color: #000;
        }
        .btn-history {
            background: #0891b2;
            color: white;
            border: none;
            padding: 6px 10px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
        }
        .btn-history:hover {
            background: #0e7490;
        }

        /* мобильная версия: компактнее, но таблица остаётся таблицей */
        @media (max-width:600px){
            .section{padding:8px}
            table{font-size:13px}
            th,td{padding:4px}
            input[type="number"]{width:70px}
            button{width:100%;padding:10px 0;font-size:15px}
        }
    </style>
    <script>
        function saveFact(id){
            const inp = document.getElementById('fact-'+id);
            const val = (inp.value || '').trim();
            if(val === '' || isNaN(val) || Number(val) < 0){
                alert('Введите корректное число'); return;
            }
            fetch('save_corr_fact.php',{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'id='+encodeURIComponent(id)+'&fact='+encodeURIComponent(val)
            })
                .then(r=>r.json())
                .then(d=>{
                    if(!d.success){ alert('Ошибка: '+(d.message||'не удалось сохранить')); return; }
                    // Ничего не меняем визуально — факт может быть частичным.
                })
                .catch(e=>alert('Ошибка запроса: '+e));
        }

        function saveStatus(id, checked){
            fetch('save_corr_fact.php',{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'id='+encodeURIComponent(id)+'&status='+(checked?1:0)
            })
                .then(r=>r.json())
                .then(d=>{
                    if(!d.success){ alert('Ошибка: '+(d.message||'не удалось сохранить статус'));
                        // откат чекбокса при ошибке:
                        const cb = document.getElementById('status-'+id);
                        if(cb) cb.checked = !checked;
                        return;
                    }
                    // Переключаем оформление строки
                    const row = document.getElementById('row-'+id);
                    if(row){
                        if(checked) row.classList.add('is-done');
                        else row.classList.remove('is-done');
                    }
                })
                .catch(e=>{
                    alert('Ошибка запроса: '+e);
                    const cb = document.getElementById('status-'+id);
                    if(cb) cb.checked = !checked;
                });
        }
    </script>
</head>
<body>

<h2>Задания гофромашины на <?= htmlspecialchars($date) ?></h2>
<form method="get">
    Дата:
    <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
    <button type="submit">Показать</button>
</form>

<div class="section">
    <?php if ($plans): ?>
        <table>
            <thead>
            <tr>
                <th>Заявка</th>
                <th>Фильтр</th>
                <th>План, шт</th>
                <th>Факт, шт</th>
                <th>Готово</th>
                <th>Действие</th>
                <th>История</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr id="row-<?= (int)$p['id'] ?>" class="<?= $p['status'] ? 'is-done' : '' ?>">
                    <td><?= htmlspecialchars($p['order_number']) ?></td>
                    <td><?= htmlspecialchars($p['filter_label']) ?></td>
                    <td><?= (int)$p['count'] ?></td>
                    <td>
                        <input type="number" id="fact-<?= (int)$p['id'] ?>" value="<?= (int)$p['fact_count'] ?>" min="0">
                    </td>
                    <td>
                        <input type="checkbox" id="status-<?= (int)$p['id'] ?>" <?= $p['status'] ? 'checked' : '' ?>
                               onchange="saveStatus(<?= (int)$p['id'] ?>, this.checked)">
                    </td>
                    <td>
                        <button type="button" onclick="saveFact(<?= (int)$p['id'] ?>)">Сохранить</button>
                    </td>
                    <td>
                        <button type="button" class="btn-history" onclick="showHistory(<?= (int)$p['id'] ?>)" title="История изготовления">📋</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align:center;margin:10px 0;">Заданий нет</p>
    <?php endif; ?>
</div>

<!-- Modal для просмотра истории изготовления -->
<div id="historyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 style="margin:0;">История изготовления</h3>
            <span class="close" onclick="closeHistory()">&times;</span>
        </div>
        <div id="historyContent" style="padding: 10px;">
            <p>Загрузка...</p>
        </div>
    </div>
</div>

<script>
    // Функции для модального окна истории
    function showHistory(id) {
        const modal = document.getElementById('historyModal');
        const content = document.getElementById('historyContent');
        
        modal.style.display = 'block';
        content.innerHTML = '<p>Загрузка...</p>';
        
        // Загружаем историю
        fetch('get_corr_history.php?id=' + id)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayHistory(data.data);
                } else {
                    content.innerHTML = '<p>Ошибка: ' + (data.message || 'Неизвестная ошибка') + '</p>';
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки истории:', error);
                content.innerHTML = '<p>Ошибка загрузки данных</p>';
            });
    }

    function displayHistory(data) {
        const content = document.getElementById('historyContent');
        
        let html = '<div style="margin-bottom: 15px;">';
        html += '<p><strong>Заявка:</strong> ' + data.order_number + '</p>';
        html += '<p><strong>Фильтр:</strong> ' + data.filter_label + '</p>';
        html += '<p><strong>План:</strong> <span style="color: #2563eb; font-weight: 600;">' + data.plan_count + ' шт</span></p>';
        html += '<p><strong>Факт:</strong> <span style="color: #16a34a; font-weight: 600;">' + data.fact_count + ' шт</span></p>';
        html += '</div>';
        
        if (data.history && data.history.length > 0) {
            html += '<h4 style="margin-bottom: 10px;">История изготовления:</h4>';
            html += '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';
            html += '<thead><tr style="background: #f5f5f5;">';
            html += '<th style="padding: 8px; border: 1px solid #ddd;">Дата</th>';
            html += '<th style="padding: 8px; border: 1px solid #ddd;">Количество</th>';
            html += '<th style="padding: 8px; border: 1px solid #ddd;">Время</th>';
            html += '</tr></thead><tbody>';
            
            data.history.forEach(entry => {
                html += '<tr>';
                html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;"><strong>' + entry.date + '</strong></td>';
                html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: 600; color: #16a34a;">' + entry.quantity + ' шт</td>';
                html += '<td style="padding: 8px; border: 1px solid #ddd; text-align: center;">' + (entry.timestamp || '-') + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            html += '<div style="margin-top: 15px; padding: 12px; background: #f9fafb; border-radius: 6px;">';
            html += '<p><strong>Итого из истории:</strong> <span style="color: #0891b2; font-weight: 600;">' + data.stats.total_from_history + ' шт</span></p>';
            html += '<p><strong>Дней изготовления:</strong> ' + data.stats.production_days + '</p>';
            
            if (data.stats.is_match) {
                html += '<p style="color: #16a34a; font-weight: 600;">✓ История совпадает с фактом</p>';
            } else {
                html += '<p style="color: #d97706; font-weight: 600;">⚠ История не совпадает с фактом</p>';
            }
            html += '</div>';
        } else {
            html += '<p style="text-align:center; color:#6b7280;">История изготовления пока пуста</p>';
        }
        
        content.innerHTML = html;
    }

    function closeHistory() {
        document.getElementById('historyModal').style.display = 'none';
    }

    // Закрытие модального окна при клике вне его
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('historyModal');
        if (event.target === modal) {
            closeHistory();
        }
    });
</script>

</body>
</html>
