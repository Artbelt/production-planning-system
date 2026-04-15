<?php require_once('tools/tools.php'); ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Обзор выпуска гофропакетов</title>
    <style>
        :root{
            --bg:#f6f7f9;
            --panel:#ffffff;
            --ink:#1f2937;
            --muted:#6b7280;
            --border:#e5e7eb;
            --accent:#2457e6;
            --accent-ink:#ffffff;
            --radius:12px;
            --shadow:0 2px 12px rgba(2,8,20,.06);
            --shadow-soft:0 1px 8px rgba(2,8,20,.05);
        }
        html,body{height:100%}
        body{
            margin:0; background:var(--bg); color:var(--ink);
            font:14px/1.45 "Segoe UI", Roboto, Arial, sans-serif;
            -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
        }
        a{color:var(--accent); text-decoration:none}
        a:hover{text-decoration:underline}

        .container{ max-width:1280px; margin:0 auto; padding:16px; }

        .panel{
            background:var(--panel);
            border:1px solid var(--border);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            padding:16px;
            margin-bottom:16px;
        }
        .section-title{
            font-size:15px; font-weight:600; color:#111827;
            margin:0 0 12px; padding-bottom:6px; border-bottom:1px solid var(--border);
        }
        .muted{color:var(--muted); font-size:12px}

        button, input[type="submit"], .btn{
            appearance:none;
            border:1px solid transparent;
            cursor:pointer;
            background:var(--accent);
            color:var(--accent-ink);
            padding:7px 14px;
            border-radius:9px;
            font-weight:600;
            transition:background .2s, box-shadow .2s, transform .04s, border-color .2s;
            box-shadow:0 3px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08);
            text-decoration:none;
            display:inline-block;
        }
        button:hover, input[type="submit"]:hover, .btn:hover{
            background:#1e47c5;
            box-shadow:0 2px 8px rgba(2,8,20,.10);
            transform:translateY(-1px);
        }
        button:active, input[type="submit"]:active, .btn:active{ transform:translateY(0); }

        input[type="text"], input[type="date"], input[type="number"], input[type="password"],
        textarea, select{
            min-width:180px; padding:7px 10px;
            border:1px solid var(--border); border-radius:9px;
            background:#fff; color:var(--ink); outline:none;
            transition:border-color .2s, box-shadow .2s;
        }
        input:focus, textarea:focus, select:focus{
            border-color:#c7d2fe; box-shadow:0 0 0 3px #e0e7ff;
        }

        .form-group{
            display:flex; align-items:center; gap:12px; margin-bottom:12px;
        }
        .form-group label{
            font-weight:600; min-width:120px; color:var(--ink);
        }
        .form-group input{
            flex:1; max-width:220px;
        }

        .panels-row{
            display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;
        }

        #show_parts_place table, table {
            width: 100% !important;
            border-collapse: collapse !important;
            background: #fff !important;
            margin: 12px 0 !important;
            border: 2px solid #6b7280 !important;
            border-radius: 8px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        #show_parts_place table th,
        #show_parts_place table td,
        table th,
        table td {
            padding: 5px 7px !important;
            vertical-align: middle !important;
            border: 1px solid #e5e7eb !important;
            font-size: 12px !important;
            line-height: 1.3 !important;
        }
        #show_parts_place table th,
        table th {
            background: #f8fafc !important;
            text-align: left !important;
            font-weight: 600 !important;
            color: var(--ink) !important;
            padding: 6px 7px !important;
            font-size: 12px !important;
        }

        .tooltip { position: relative; display: inline-block; cursor: help; }
        .tooltip .tooltiptext{
            visibility:hidden; max-width:400px; background:#333; color:#fff; text-align:left;
            padding:6px 10px; border-radius:6px; position:absolute; z-index:10; bottom:125%;
            left:50%; transform:translateX(-50%); opacity:0; transition:opacity .25s; white-space:pre-line;
        }
        .tooltip:hover .tooltiptext{visibility:visible;opacity:1}

        @media (max-width:768px){
            .panels-row{grid-template-columns:1fr; gap:12px}
            .form-group{flex-direction:column; align-items:stretch; gap:6px}
            .form-group label{min-width:auto}
            .form-group input{max-width:none}
            table{font-size:12px}
            th, td{padding:8px 6px}
        }
    </style>
</head>
<body>
<div class="container">
    <div class="panel">
        <div class="section-title">Обзор выпуска гофропакетов</div>
        <p class="muted">Просмотр и анализ изготовленных гофропакетов по датам</p>
    </div>

    <div class="panels-row">
        <div class="panel">
            <div class="section-title">Просмотр за конкретную дату</div>
            <div class="form-group">
                <label for="date_one">Выбор даты:</label>
                <input type="date" id="date_one" />
            </div>
            <button type="button" onclick="show_one()">📅 Просмотр выпущенной за выбранную дату</button>
        </div>

        <div class="panel">
            <div class="section-title">Просмотр за диапазон дат</div>
            <div class="form-group">
                <label for="date_start">Дата начала:</label>
                <input type="date" id="date_start" />
            </div>
            <div class="form-group">
                <label for="date_end">Дата окончания:</label>
                <input type="date" id="date_end" />
            </div>
            <button type="button" onclick="show_range()">📊 Просмотр выпущенной в заданном диапазоне дат</button>
        </div>
    </div>

    <div class="panel">
        <div id="show_parts_place"></div>
    </div>

    <div class="panel">
        <button id="close_button" onclick="window.close()">✖ Закрыть</button>
    </div>
</div>

<script>
    function show_one() {
        const d = document.getElementById('date_one').value;
        if (!d) { alert('Выберите дату'); return; }
        post('show_parts_fact.php', 'date=' + encodeURIComponent(d));
    }

    function show_range() {
        const s = document.getElementById('date_start').value;
        const e = document.getElementById('date_end').value;
        if (!s || !e) { alert('Выберите диапазон дат'); return; }
        post('show_parts_fact_range.php', 'start=' + encodeURIComponent(s) + '&end=' + encodeURIComponent(e));
    }

    function post(url, body) {
        const x = new XMLHttpRequest();
        x.onreadystatechange = function() {
            if (this.readyState === 4) {
                if (this.status === 200) {
                    document.getElementById('show_parts_place').innerHTML = this.responseText;
                } else {
                    alert('Ошибка загрузки: ' + this.status);
                }
            }
        };
        x.open('POST', url, true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.send(body);
    }
</script>
</body>
</html>
