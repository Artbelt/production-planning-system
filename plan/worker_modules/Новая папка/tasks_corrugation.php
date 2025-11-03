<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$date = $_GET['date'] ?? date('Y-m-d');
$filter = $_GET['filter'] ?? '';

// Строим запрос с учетом фильтра
$where_conditions = ["plan_date = ?"];
$params = [$date];

if (!empty($filter)) {
    $where_conditions[] = "filter_label LIKE ?";
    $params[] = "%{$filter}%";
}

$sql = "
    SELECT id, order_number, plan_date, filter_label, `count`, fact_count, status
    FROM corrugation_plan
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY order_number, id
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Задания гофромашины</title>
    <style>
        /* ===== Modern UI palette (to match main.php) ===== */
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1e293b;
            --muted:#64748b;
            --border:#e2e8f0;
            --accent:#667eea;
            --radius:14px;
            --shadow:0 10px 25px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.06);
            --shadow-soft:0 2px 8px rgba(0,0,0,0.08);
        }
        html,body{height:100%}
        body{font-family:"Inter","Segoe UI",Arial,sans-serif;background:var(--bg);color:var(--ink);margin:0;padding:16px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
        h2{text-align:center;margin:0 0 16px;font-size:20px;font-weight:700}
        form{text-align:center;margin-bottom:16px}
        .section{max-width:900px;margin:0 auto;background:var(--panel);padding:20px;border-radius:var(--radius);box-shadow:var(--shadow-soft);border:1px solid var(--border)}

        table{border-collapse:collapse;width:100%;font-size:14px;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-soft)}
        thead th{background:#f8fafc;font-weight:600;color:var(--ink)}
        th,td{border-bottom:1px solid var(--border);padding:10px 12px;text-align:center;color:var(--ink)}
        tbody tr:nth-child(even){background:#f8fafc}
        tr:last-child td{border-bottom:0}

        /* выполненная строка */
        .is-done td{
            text-decoration: line-through;
            color:#6b7280;            /* серый */
            background:#eaf7ea !important; /* лёгкий зелёный фон */
        }

        /* кнопки / инпуты */
        button{padding:8px 16px;font-size:14px;cursor:pointer;border:none;border-radius:8px;background:var(--accent);color:#fff;font-weight:500;box-shadow:var(--shadow-soft);transition:all 0.2s ease}
        button:hover{transform:translateY(-1px);box-shadow:var(--shadow);filter:brightness(1.05)}
        input[type="number"]{width:80px;padding:6px 8px;text-align:center;border:1px solid var(--border);border-radius:6px;font-size:14px}
        input[type="date"]{padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:14px}
        input[type="text"]{padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:14px;min-width:200px}

        /* мобильная версия: компактнее, но таблица остаётся таблицей */
        @media (max-width:600px){
            .section{padding:12px}
            table{font-size:13px}
            th,td{padding:6px 8px}
            input[type="number"]{width:70px}
            button{width:100%;padding:10px 0;font-size:15px}
            input[type="text"]{min-width:150px}
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
<div style="text-align:center; margin-bottom:10px; font-size:18px;">
    <a href="?date=<?= date('Y-m-d', strtotime($date.' -1 day')) ?>"
       style="margin-right:20px; text-decoration:none; display:inline-block;
              width:32px; height:32px; line-height:32px; border-radius:50%;
              background:#2563eb; color:#fff; font-weight:bold;">&#9664;</a>

    <strong><?= htmlspecialchars($date) ?></strong>

    <a href="?date=<?= date('Y-m-d', strtotime($date.' +1 day')) ?>"
       style="margin-left:20px; text-decoration:none; display:inline-block;
              width:32px; height:32px; line-height:32px; border-radius:50%;
              background:#2563eb; color:#fff; font-weight:bold;">&#9654;</a>
</div>


<form method="get" style="display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;">
    <div>
        <label for="date">Дата:</label>
        <input type="date" name="date" id="date" value="<?= htmlspecialchars($date) ?>">
    </div>
    <div>
        <label for="filter_search">Поиск фильтра:</label>
        <input type="text" name="filter" id="filter_search" placeholder="Введите название фильтра" value="<?= htmlspecialchars($_GET['filter'] ?? '') ?>">
    </div>
    <button type="submit">Показать</button>
</form>

<div class="section">
    <?php if ($plans): ?>
        <?php if (!empty($filter)): ?>
            <div style="margin-bottom:16px;padding:12px;background:#e0f2fe;border-radius:8px;border-left:4px solid #0288d1;">
                <strong>🔍 Поиск по фильтру:</strong> "<?= htmlspecialchars($filter) ?>" 
                <span style="color:#666;">(найдено: <?= count($plans) ?> записей)</span>
                <a href="?date=<?= htmlspecialchars($date) ?>" style="margin-left:12px;color:#0288d1;text-decoration:none;">✕ Очистить поиск</a>
            </div>
        <?php endif; ?>
        
        <table>
            <thead>
            <tr>
                <th>Дата</th>
                <th>Заявка</th>
                <th>Фильтр</th>
                <th>План, шт</th>
                <th>Факт, шт</th>
                <th>Готово</th>
                <th>Действие</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $p): ?>
                <tr id="row-<?= (int)$p['id'] ?>" class="<?= $p['status'] ? 'is-done' : '' ?>">
                    <td><?= htmlspecialchars($p['plan_date']) ?></td>
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
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align:center;margin:10px 0;color:#666;">
            <?php if (!empty($filter)): ?>
                По фильтру "<?= htmlspecialchars($filter) ?>" заданий не найдено
                <br><a href="?date=<?= htmlspecialchars($date) ?>" style="color:#667eea;">Показать все задания на эту дату</a>
            <?php else: ?>
                Заданий нет
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

</body>
</html>
