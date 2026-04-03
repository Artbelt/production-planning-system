<script>
    function generateReport() {
        // Отримуємо таблицю
        var table = document.getElementById('produced_filters_table');
        var rows = table.rows;

        // Об'єкти для зберігання підрахунків
        var filterCounts = {};
        var packagingCounts = {};

        // Проходимо по кожному рядку таблиці, починаючи з другого (індекс 1)
        for (var i = 1; i < rows.length; i++) {
            var cells = rows[i].cells;
            var filter = cells[1].textContent;
            var quantity = parseInt(cells[2].textContent);
            var packaging = cells[4].textContent;

            // Підраховуємо кількість фільтрів
            if (filterCounts[filter]) {
                filterCounts[filter] += quantity;
            } else {
                filterCounts[filter] = quantity;
            }

            // Підраховуємо кількість упаковок
            if (packagingCounts[packaging]) {
                packagingCounts[packaging] += quantity;
            } else {
                packagingCounts[packaging] = quantity;
            }
        }

        // Створюємо нове вікно для звіту
        var reportWindow = window.open("", "Report", "width=800,height=600");

        // Створюємо таблицю для фільтрів
        var filterTable = "<h2>Фільтри</h2><table border='1'><tr><th>Фільтр</th><th>Кількість</th></tr>";
        for (var filter in filterCounts) {
            filterTable += "<tr><td>" + filter + "</td><td>" + filterCounts[filter] + "</td></tr>";
        }
        filterTable += "</table>";

        // Сортуємо упаковки за кількістю
        var sortedPackagings = Object.keys(packagingCounts).sort(function(a, b) {
            return packagingCounts[b] - packagingCounts[a];
        });

        // Створюємо таблицю для упаковок
        var packagingTable = "<h2>Упаковки</h2><table border='1'><tr><th>Упаковка</th><th>Кількість</th></tr>";
        sortedPackagings.forEach(function(packaging) {
            packagingTable += "<tr><td>" + packaging + "</td><td>" + packagingCounts[packaging] + "</td></tr>";
        });
        packagingTable += "</table>";

        // Вставляємо таблиці у нове вікно
        reportWindow.document.write("<html><head><title>Звіт</title></head><body>");
        //reportWindow.document.write(filterTable);
        reportWindow.document.write(packagingTable);
        reportWindow.document.write("</body></html>");
    }
</script>
<script>
    function show_raiting() {
        // Отримуємо таблицю
        var table = document.getElementById('produced_filters_table');

// Створюємо об'єкт для збереження суми кількостей за кожним фільтром
        var sums = {};

// Проходимо по кожному рядку таблиці (починаючи з другого рядка, оскільки перший - заголовок)
        for (var i = 1; i < table.rows.length; i++) {
            var row = table.rows[i];
            var filter = row.cells[1].innerText; // Отримуємо назву фільтру з другого стовпця
            var quantity = parseInt(row.cells[2].innerText); // Отримуємо кількість з третього стовпця

            // Додаємо кількість до суми для відповідного фільтру
            if (sums[filter]) {
                sums[filter] += quantity;
            } else {
                sums[filter] = quantity;
            }
        }

// Створюємо масив об'єктів для подальшого сортування
        var sumsArray = [];
        for (var filter in sums) {
            sumsArray.push({ filter: filter, quantity: sums[filter] });
        }

// Сортуємо масив за кількістю у спадаючому порядку
        sumsArray.sort(function(a, b) {
            return b.quantity - a.quantity;
        });

// Створюємо нову таблицю
        var newTable = document.createElement('table');
        var headerRow = newTable.insertRow();
        var filterHeader = headerRow.insertCell();
        var quantityHeader = headerRow.insertCell();
        filterHeader.innerText = 'Фильтр';
        quantityHeader.innerText = 'Количество';

// Додаємо відсортовані дані до нової таблиці
        sumsArray.forEach(function(item) {
            var newRow = newTable.insertRow();
            var filterCell = newRow.insertCell();
            var quantityCell = newRow.insertCell();
            filterCell.innerText = item.filter;
            quantityCell.innerText = item.quantity;
        });

// Відкриваємо нове вікно для відображення нової таблиці
        var newWindow = window.open('', 'Нове вікно', 'width=600,height=400');
        newWindow.document.body.appendChild(newTable);
        newWindow.document.body.clearAll();
    }
</script>

<?php require_once('tools/tools.php')?>


<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>Обзор выпуска продукции</title>
    <style>
        /* ===== Pro UI (neutral + single accent) ===== */
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

        /* контейнер и сетка */
        .container{ max-width:1280px; margin:0 auto; padding:16px; }

        /* панели */
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

        /* кнопки (единый стиль) */
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
        button:disabled, input[type="submit"]:disabled{
            background:#e5e7eb; color:#9ca3af; border-color:#e5e7eb; box-shadow:none; cursor:not-allowed;
        }

        /* поля ввода/селекты */
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

        /* инфоблоки */
        .alert{
            background:#fffbe6; border:1px solid #f4e4a4; color:#634100;
            padding:10px; border-radius:9px; margin:12px 0; font-weight:600;
        }
        .muted{color:var(--muted); font-size:12px}

        /* форма */
        .form-group{
            display:flex; align-items:center; gap:12px; margin-bottom:12px;
        }
        .form-group label{
            font-weight:600; min-width:120px; color:var(--ink);
        }
        .form-group input{
            flex:1; max-width:200px;
        }

        /* таблицы - финальные стили */
        body div table,
        #show_filters_place table,
        table {
            width: 100% !important;
            border-collapse: collapse !important;
            background: #fff !important;
            margin: 12px 0 !important;
            border: 2px solid #6b7280 !important;
            border-radius: 8px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        
        body div table th,
        body div table td,
        #show_filters_place table th,
        #show_filters_place table td,
        table th,
        table td {
            padding: 5px 7px !important;
            vertical-align: middle !important;
            border: 1px solid #e5e7eb !important;
            font-size: 12px !important;
            line-height: 1.3 !important;
        }
        
        body div table th,
        #show_filters_place table th,
        table th {
            background: #f8fafc !important;
            text-align: left !important;
            font-weight: 600 !important;
            color: var(--ink) !important;
            padding: 6px 7px !important;
            font-size: 12px !important;
        }

        /* Убираем стрелки у input type="number" */
        #show_filters_place input[type="number"]::-webkit-inner-spin-button,
        #show_filters_place input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
            display: none !important;
        }
        #show_filters_place input[type="number"] {
            -moz-appearance: textfield !important;
        }

        /* календарь */
        .calendar-input{
            background:#fff; border:1px solid var(--border); border-radius:9px;
            padding:7px 10px; font-size:14px; color:var(--ink);
            width:100%; box-sizing:border-box;
        }

        /* стили для календарного виджета */
        .calendar-widget{
            position:absolute; z-index:1000; background:var(--panel);
            border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:var(--shadow); padding:12px; margin-top:4px;
            max-width:300px; font-size:13px;
        }

        .calendar-widget *{box-sizing:border-box}
        .calendar-widget table{width:100%; border-collapse:collapse}
        .calendar-widget td, .calendar-widget th{
            padding:6px; text-align:center; cursor:pointer;
            border:1px solid var(--border); font-size:12px;
        }
        .calendar-widget td:hover{background:var(--accent); color:var(--accent-ink)}
        .calendar-widget .today{background:#e3f2fd; font-weight:600}
        .calendar-widget .selected{background:var(--accent); color:var(--accent-ink)}

        /* панели в ряд */
        .panels-row{
            display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;
        }

        /* предотвращение выхода календаря за край */
        .form-group{position:relative; overflow:visible}
        .calendar-widget{
            left:0; right:auto; max-width:280px;
            transform:translateX(0);
        }
        
        /* если календарь выходит за правый край */
        .calendar-widget.right-aligned{
            left:auto; right:0;
        }

        /* анимация загрузки */
        .loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid transparent;
            border-top: 2px solid var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text::before {
            content: '⏳ Загрузка...';
        }

        /* адаптив */
        @media (max-width:768px){
            .panels-row{grid-template-columns:1fr; gap:12px}
            .form-group{flex-direction:column; align-items:stretch; gap:6px}
            .form-group label{min-width:auto}
            .form-group input{max-width:none}
            table{font-size:12px}
            th, td{padding:8px 6px}
            .calendar-widget{max-width:250px; font-size:12px}
            .calendar-widget td, .calendar-widget th{padding:4px; font-size:11px}
        }
    </style>
<body>
<div class="container">
<script>
        // собственный календарь
        let currentDate = new Date();
        let selectedDate = null;

        function formatDate(date) {
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = String(date.getFullYear()).slice(-2);
            return `${year}-${month}-${day}`;
        }

        function getMonthName(month) {
            const months = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
                          'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
            return months[month];
        }

        function getDaysInMonth(year, month) {
            return new Date(year, month + 1, 0).getDate();
        }

        function getFirstDayOfMonth(year, month) {
            // Приводим воскресенье (0) к концу недели, чтобы календарь начинался с понедельника
            return (new Date(year, month, 1).getDay() + 6) % 7;
        }

        function createCalendar(year, month, targetInput) {
            try {
                const monthName = getMonthName(month);
                const daysHTML = generateCalendarDays(year, month, targetInput);
                const todayFormatted = formatDate(new Date());
                
                const calendarHTML = `
                    <div class="calendar-widget" style="
                        position: fixed; 
                        top: 50%; 
                        left: 50%; 
                        transform: translate(-50%, -50%);
                        z-index: 9999; 
                        background: var(--panel); 
                        border: 1px solid var(--border); 
                        border-radius: var(--radius);
                        box-shadow: var(--shadow); 
                        padding: 16px; 
                        max-width: 300px;
                    ">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <button onclick="changeMonth(-1, '${targetInput}')" style="background:none; border:none; font-size:18px; cursor:pointer;">‹</button>
                            <span style="font-weight:600;">${monthName} ${year}</span>
                            <button onclick="changeMonth(1, '${targetInput}')" style="background:none; border:none; font-size:18px; cursor:pointer;">›</button>
                        </div>
                        <table style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr>
                                    <th>П</th><th>В</th><th>С</th><th>Ч</th><th>П</th><th>С</th><th>В</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${daysHTML}
                            </tbody>
                        </table>
                        <div style="margin-top:8px; font-size:12px; color:var(--muted); text-align:center;">
                            Сегодня: ${formatDate(new Date())}
                        </div>
                        <button onclick="hideCalendar()" style="
                            width: 100%; 
                            margin-top: 10px; 
                            background: var(--accent); 
                            color: var(--accent-ink); 
                            border: none; 
                            padding: 8px; 
                            border-radius: 6px; 
                            cursor: pointer;
                        ">Закрыть</button>
                    </div>
                `;
                return calendarHTML;
            } catch (error) {
                console.error('Error in createCalendar:', error);
                return '<div class="calendar-widget">Ошибка создания календаря</div>';
            }
        }

        function generateCalendarDays(year, month, targetInput) {
            try {
                const daysInMonth = getDaysInMonth(year, month);
                const firstDay = getFirstDayOfMonth(year, month);
                const today = new Date();
                const isToday = today.getFullYear() === year && today.getMonth() === month;
                
                let html = '';
                let day = 1;
                
                // недели
                for (let week = 0; week < 6; week++) {
                    html += '<tr>';
                    // дни недели
                    for (let dayOfWeek = 0; dayOfWeek < 7; dayOfWeek++) {
                        if (week === 0 && dayOfWeek < firstDay) {
                            html += '<td></td>';
                        } else if (day <= daysInMonth) {
                            const isCurrentDay = isToday && day === today.getDate();
                            const cellClass = isCurrentDay ? 'today' : '';
                            html += `<td class="${cellClass}" onclick="selectDate(${day}, ${month}, ${year}, '${targetInput}')" style="cursor:pointer;">${day}</td>`;
                            day++;
                        } else {
                            html += '<td></td>';
                        }
                    }
                    html += '</tr>';
                    if (day > daysInMonth) break;
                }
                
                return html;
            } catch (error) {
                console.error('Error in generateCalendarDays:', error);
                return '<tr><td colspan="7">Ошибка генерации дней</td></tr>';
            }
        }

        function changeMonth(direction, targetInput) {
            currentDate.setMonth(currentDate.getMonth() + direction);
            showCalendar(targetInput);
        }

        function selectDate(day, month, year, targetInput) {
            const date = new Date(year, month, day);
            document.getElementById(targetInput).value = formatDate(date);
            hideCalendar();
        }

        function showCalendar(inputId) {
            hideCalendar(); // скрыть предыдущий календарь
            
            const input = document.getElementById(inputId);
            if (!input) {
                return;
            }
            
            try {
                const year = currentDate.getFullYear();
                const month = currentDate.getMonth();
                
                const calendarHTML = createCalendar(year, month, inputId);
                
                // вставляем календарь прямо в body для фиксированного позиционирования
                document.body.insertAdjacentHTML('beforeend', calendarHTML);
                
                // позиционирование
                positionCalendar();
                
            } catch (error) {
                console.error('Error creating calendar:', error);
            }
        }

        function hideCalendar() {
            const existing = document.querySelector('.calendar-widget');
            if (existing) {
                existing.remove();
            }
        }

        // функция для правильного позиционирования календаря
        function positionCalendar() {
            setTimeout(() => {
                const calendars = document.querySelectorAll('.calendar-widget');
                calendars.forEach(cal => {
                    const rect = cal.getBoundingClientRect();
                    const viewportWidth = window.innerWidth;
                    
                    // если календарь выходит за правый край
                    if (rect.right > viewportWidth - 20) {
                        cal.classList.add('right-aligned');
                    } else {
                        cal.classList.remove('right-aligned');
                    }
                });
            }, 10);
        }

        // обработчики для полей календаря
        function setupCalendarInputs() {
            document.querySelectorAll('.calendar-input').forEach(input => {
                // удаляем старые обработчики если есть
                input.removeEventListener('focus', showCalendar);
                input.removeEventListener('click', showCalendar);
                
                // добавляем новые обработчики
                input.addEventListener('focus', (e) => {
                    e.preventDefault();
                    showCalendar(input.id);
                });
                input.addEventListener('click', (e) => {
                    e.preventDefault();
                    showCalendar(input.id);
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        showCalendar(input.id);
                    }
                });
            });
        }

        // скрытие календаря при клике вне его
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.calendar-input') && !e.target.closest('.calendar-widget')) {
                hideCalendar();
            }
        });


        // запускаем после загрузки DOM
        document.addEventListener('DOMContentLoaded', () => {
            setupCalendarInputs();
        });

        // дополнительная инициализация через небольшую задержку
        setTimeout(setupCalendarInputs, 100);

        // функция для фиксации стилей столбца "Часы"
        function fixHoursColumnStyles() {
            const tables = document.querySelectorAll('table');
            tables.forEach((table) => {
                const rows = table.querySelectorAll('tr');
                rows.forEach((row) => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length > 0) {
                        const lastCell = cells[cells.length - 1];
                        // Проверяем, является ли это столбцом "Часы" (содержит input)
                        if (lastCell.querySelector('input[type="number"]')) {
                            lastCell.style.cssText += `
                                min-width: 65px !important;
                                max-width: 70px !important;
                                width: 65px !important;
                                overflow: hidden !important;
                                white-space: nowrap !important;
                                padding: 4px 6px !important;
                            `;
                            const input = lastCell.querySelector('input[type="number"]');
                            if (input) {
                                input.style.cssText += `
                                    width: 55px !important;
                                    max-width: 55px !important;
                                    min-width: 55px !important;
                                    box-sizing: border-box !important;
                                    padding: 2px 4px !important;
                                    overflow: hidden !important;
                                    -moz-appearance: textfield !important;
                                    border-radius: 0 !important;
                                `;
                                // Убираем стрелки для WebKit браузеров
                                input.style.setProperty('-webkit-appearance', 'none', 'important');
                                if (!input.hasAttribute('maxlength')) {
                                    input.setAttribute('maxlength', '6');
                                }
                            }
                        }
                    }
                });
            });
        }

        // функция для стилизации таблиц
        function applyTableStyles() {
            const tables = document.querySelectorAll('table');
            
            tables.forEach((table) => {
                // принудительно перезаписываем все стили
                table.removeAttribute('style');
                table.style.cssText = `
                    width: 100% !important;
                    border-collapse: collapse !important;
                    background: #fff !important;
                    margin: 16px 0 !important;
                    border: 2px solid #6b7280 !important;
                    border-radius: 8px !important;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
                `;
                
                // принудительно стилизуем ячейки
                const cells = table.querySelectorAll('th, td');
                cells.forEach(cell => {
                    cell.removeAttribute('style');
                    cell.style.cssText = `
                        padding: 5px 7px !important;
                        vertical-align: middle !important;
                        border: 1px solid #e5e7eb !important;
                        font-size: 12px !important;
                        line-height: 1.3 !important;
                    `;
                });
                
                // стилизуем заголовки
                const headers = table.querySelectorAll('th');
                headers.forEach(header => {
                    header.style.cssText += `
                        background: #f8fafc !important;
                        text-align: left !important;
                        font-weight: 600 !important;
                        color: var(--ink) !important;
                        padding: 6px 7px !important;
                        font-size: 12px !important;
                    `;
                });
            });
        }

    function show_manufactured_filters() {//отправка данных для списания выпуска продукции

        //проверка заполненности полей для проведения выпуска продукции

        //проверка календаря
        let calendar_box = document.getElementById('calendar');
        if (calendar_box.value == "yy-mm-dd" || calendar_box.value == ""){
            alert("Не выбрана дата");
            return;
        }

        //выбор даты производства
        let production_date = document.getElementById("calendar").value;

        // получаем кнопку и добавляем анимацию загрузки
        const btn = document.getElementById('btn-single-load');
        const originalText = btn.innerHTML;
        
        // показываем анимацию загрузки
        btn.classList.add('loading');
        btn.innerHTML = '⏳ Загрузка...';
        btn.disabled = true;

        //AJAX запрос
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function() {
            if (this.readyState == 4) {
                // убираем анимацию загрузки
                btn.classList.remove('loading');
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                if (this.status == 200) {
                    document.getElementById("show_filters_place").innerHTML = this.responseText;
                    // принудительно применяем стили к новым таблицам
                    setTimeout(applyTableStyles, 100);
                    // фиксируем стили для столбца "Часы" после применения общих стилей
                    setTimeout(fixHoursColumnStyles, 150);
                } else {
                    alert("❌ Ошибка загрузки данных. Попробуйте еще раз.");
                }
            }
        };

        xhttp.open("POST", "show_manufactured_filters.php", true);
        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send("production_date="+production_date);

    }

    function show_manufactured_filters_more() {//отправка данных для списания выпуска продукции

        //проверка заполненности полей для проведения выпуска продукции

        //проверка календаря
        let calendar_box_start = document.getElementById('calendar_start');
        if (calendar_box_start.value == "yy-mm-dd" || calendar_box_start.value == ""){
            alert("Не выбрана дата начала");
            return;
        }
        let calendar_box_end = document.getElementById('calendar_end');
        if (calendar_box_end.value == "yy-mm-dd" || calendar_box_end.value == ""){
            alert("Не выбрана дата окончания");
            return;
        }

        //выбор даты производства
        let production_date_start = document.getElementById("calendar_start").value;
        let production_date_end = document.getElementById("calendar_end").value;

        // получаем кнопку и добавляем анимацию загрузки
        const btn = document.getElementById('btn-range-load');
        const originalText = btn.innerHTML;
        
        // показываем анимацию загрузки
        btn.classList.add('loading');
        btn.innerHTML = '⏳ Загрузка...';
        btn.disabled = true;

        //AJAX запрос
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function() {
            if (this.readyState == 4) {
                // убираем анимацию загрузки
                btn.classList.remove('loading');
                btn.innerHTML = originalText;
                btn.disabled = false;
                
                if (this.status == 200) {
                    document.getElementById("show_filters_place").innerHTML = this.responseText;
                    // принудительно применяем стили к новым таблицам
                    setTimeout(applyTableStyles, 100);
                    // фиксируем стили для столбца "Часы" после применения общих стилей
                    setTimeout(fixHoursColumnStyles, 150);
                } else {
                    alert("❌ Ошибка загрузки данных. Попробуйте еще раз.");
                }
            }
        };

        xhttp.open("POST", "show_manufactured_filters_more.php", true);
        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send("production_date_start="+production_date_start+'&production_date_end='+production_date_end);

    }
</script>

    <div class="panel">
        <div class="section-title">Обзор выпуска продукции</div>
        <p class="muted">Просмотр и анализ выпущенной продукции по датам</p>
    </div>

    <div class="panels-row">
        <div class="panel">
            <div class="section-title">Просмотр за конкретную дату</div>
            <div class="form-group">
                <label for="calendar">Выбор даты:</label>
                <input type="text" id="calendar" class="calendar-input" value="yy-mm-dd" placeholder="yy-mm-dd" onclick="showCalendar('calendar')" onfocus="showCalendar('calendar')">
            </div>
            <button id="btn-single-load" onclick="show_manufactured_filters()">📅 Просмотр выпущенной за выбранную дату</button>
        </div>

        <div class="panel">
            <div class="section-title">Просмотр за диапазон дат</div>
            <div class="form-group">
                <label for="calendar_start">Дата начала:</label>
                <input type="text" id="calendar_start" class="calendar-input" value="yy-mm-dd" placeholder="yy-mm-dd" onclick="showCalendar('calendar_start')" onfocus="showCalendar('calendar_start')">
            </div>
            <div class="form-group">
                <label for="calendar_end">Дата окончания:</label>
                <input type="text" id="calendar_end" class="calendar-input" value="yy-mm-dd" placeholder="yy-mm-dd" onclick="showCalendar('calendar_end')" onfocus="showCalendar('calendar_end')">
            </div>
            <button id="btn-range-load" onclick="show_manufactured_filters_more()">📊 Просмотр выпущенной в заданном диапазоне дат</button>
        </div>
    </div>

    <div class="panel">
        <div id="show_filters_place"></div>
    </div>

    <div class="panel">
        <button id="close_button" onclick="window.close()">✖ Закрыть</button>
    </div>
</div>
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
</body>
</html>