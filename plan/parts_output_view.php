<?php require_once('tools/tools.php'); ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Plan system — факт гофропакетов</title>
    <style>
        body{font-family:sans-serif;margin:16px}
        .wrap{max-width:720px;margin:0 auto}
        .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
        input[type="date"],button{padding:8px 10px;font-size:14px}
        button{cursor:pointer}
        #show_filters_place{margin-top:14px}

        /* Таблица и тултипы (как у тебя) */
        table{border:1px solid #000;border-collapse:collapse;font-size:14px;margin-top:10px;width:100%}
        th,td{border:1px solid #000;padding:5px 8px;text-align:center}

        .tooltip{position:relative;display:inline-block;cursor:help}
        .tooltip .tooltiptext{
            visibility:hidden;max-width:400px;background:#333;color:#fff;text-align:left;
            padding:6px 10px;border-radius:6px;position:absolute;z-index:10;bottom:125%;
            left:50%;transform:translateX(-50%);opacity:0;transition:opacity .25s;white-space:pre-line
        }
        .tooltip:hover .tooltiptext{visibility:visible;opacity:1}
    </style>
</head>
<body>
<div class="wrap">
    <h2>Изготовленные гофропакеты</h2>

    <div class="row">
        <label>Дата:</label>
        <input type="date" id="date_one" />
        <button type="button" onclick="show_one()">Показать за день</button>
    </div>

    <div class="row">
        <label>Период:</label>
        <input type="date" id="date_start" />
        <span>—</span>
        <input type="date" id="date_end" />
        <button type="button" onclick="show_range()">Показать за период</button>
    </div>

    <div id="show_filters_place"></div>

    <div style="margin-top:14px">
        <button id="back_button" onclick="history.back()" style="width:150px">Назад</button>
    </div>
</div>

<script>
    function show_one(){
        const d = document.getElementById('date_one').value;
        if(!d){ alert('Выберите дату'); return; }
        post('show_corr_fact.php', 'date='+encodeURIComponent(d));
    }
    function show_range(){
        const s = document.getElementById('date_start').value;
        const e = document.getElementById('date_end').value;
        if(!s || !e){ alert('Выберите диапазон дат'); return; }
        post('show_corr_fact_range.php', 'start='+encodeURIComponent(s)+'&end='+encodeURIComponent(e));
    }
    function post(url, body){
        const x = new XMLHttpRequest();
        x.onreadystatechange=function(){
            if(this.readyState===4){
                if(this.status===200){ document.getElementById('show_filters_place').innerHTML=this.responseText; }
                else { alert('Ошибка загрузки: '+this.status); }
            }
        };
        x.open('POST', url, true);
        x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        x.send(body);
    }
</script>
</body>
</html>
