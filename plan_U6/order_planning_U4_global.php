<?php
require_once('tools/tools.php');
require_once('settings.php');

require_once ('style/table_1.txt');

/** Номер заявки которую надо нарисовать */
//$order_number = $_POST['order_number'];

/** Заголовок страницы */
echo "Режим планирования заявок:";

/** оформление номера заявки */
//echo "<section id='order_number'><b> ".$order_number."</b></section><p>";

/** Подключаемся к БД для вывода заявки по подключению №1*/
$mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
if ($mysqli->connect_errno) {
    /** Если не получилось подключиться */
    echo 'Возникла проблема на сайте'
        . "Номер ошибки: " . $mysqli->connect_errno . "\n"
        . "Ошибка: " . $mysqli->connect_error . "\n";
    exit;
}

//** Выбираем актуальные заявки */
$orders = select_actual_orders();

/** Формируем шапку таблицы для вывода заявки */
echo "<table id='main_table' border='1' style='font-size: 13px'>
        <tr>
            <td style=' border: 1px solid black'> Заявка
            </td>        
            <td style=' border: 1px solid black'> Фильтр
            </td>
            <td style=' border: 1px solid black'> Заказано, шт           
            </td>  
            <td style=' border: 1px solid black'> Сделано, шт           
            </td>  
            <td style=' border: 1px solid black'> Остаток, шт           
            </td>  
            <td style=' border: 1px solid black'> В плане
            </td>  
            <td style=' border: 1px solid black'> Не в плане
            </td>   
            <td style=' border: 1px solid black'> Гофропакеты
            </td>   
            <td style=' border: 1px solid black'> Крышки
            </td>   
                                                                   
        </tr>";


/** перебор каждой заявки */
foreach ($orders as $order){

    /** Выполняем запрос SQL по подключению №1*/
    $sql = "SELECT * FROM orders WHERE order_number = '$order';";
    if (!$result = $mysqli->query($sql)) {
        echo "Ошибка: Наш запрос не удался и вот почему: \n"
            . "Запрос: " . $sql . "\n"
            . "Номер ошибки: " . $mysqli->errno . "\n"
            . "Ошибка: " . $mysqli->error . "\n";
        exit;
    }

    while ($row = $result->fetch_assoc()){
        $difference = (int)$row['count'];
        $difference = (int)$row['count'] - (int)select_produced_filters_by_order($row['filter'],$order)[1];
        //$difference = $difference / (int)$row['count'];

        if ((int)$row['count'] != 0){
            $diff = (int)select_produced_filters_by_order($row['filter'],$order)[1] / (int)$row['count'];
        } else{
            $diff = 0;
        }


        /** Если остаток отрицательный позицию скрываем или если попадает в диапазон 20% */
        if ( $row['count'] != 0) {
            $twenty_percent_koefficient = $difference / $row['count'];
        }

        if($diff < 0.8){
            echo
                "<tr>"
                ."<td>".$row['order_number']."</td>"
                ."<td>".$row['filter']."</td>"
                ."<td>".$row['count']."</td>"
               // ."<td>".$row['count']."diff=".$diff."</td>"
                ."<td>".select_produced_filters_by_order($row['filter'],$order)[1]."</td>"
                ."<td>".$difference."</td>"
                ."<td>0</td>"
                ."<td>".$difference."</td>"
                //."<td>".manufactured_part_count($row['filter'],$row['order_number'])."</td>"
                ."<td>".count_of_manufactured_parts_in_the_storage($row['filter'])."</td>"
                ."<td>".show_prepared_caps($row['filter'])."</td>"
                ."</tr>";
        }else{
           // echo
           //     "<tr>"
           //     ."<td>".$row['order_number']."</td>"
           //     ."<td>".$row['filter']."</td>"
           //     ."<td>".$row['count']."</td>"
           //     ."<td>".select_produced_filters_by_order($row['filter'],$order)[1]."</td>"
           //     ."<td><mark>".$difference."</mark></td>"
           //     ."<td>-</td>"
           //     ."<td>.$twenty_percent_koefficient.</td>"
           //     ."</tr>";
        }

    }

}

/** Разбор массива значений по подключению №1 */


echo    "<tr><td>Итого:</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>";

echo "</table>";


?>



<script type="text/javascript">
    function tableToJson() {

        let result = [];
        let table = document.getElementById("main_table").getElementsByTagName("tbody")[0];
        let trs = table.getElementsByTagName("tr");
        let tds = table.getElementsByTagName("td");
        //получаем даты


        for (let i = 1; i < trs.length-1; i++) {
        //for (let i = 0; i < trs.length; i++) {
            let tds = trs[i].getElementsByTagName("td");
            let obj = {};
            for (let a = 0; a <tds.length; a++){

                if (a != 2 && a != 3 && a != 4  && a != 5 && a != 6 && a != 7 && a != 8 ){
                    obj[a]=tds[a].innerHTML;

                }

            }
            result.push(obj);
            //obj.dates = ;
            //obj.order = tds[0].innerHTML;
            //obj.filter = tds[1].innerHTML;
            //obj.phone = tds[2].innerHTML;

        }

        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                document.getElementById("result_area").innerHTML = this.responseText; /* здесь будет выведен результат php-скрипта */
            }
        };
        //план
        let JSON_send_array = JSON.stringify(result);
        //идентификатор плана
        let order_number_text = document.getElementById("order_number").value;
        //дата начала плана
        let start_date = document.getElementById('main_table').rows[0].cells[9].innerHTML;
        //количество смен распланированных
        //let days_count = trs.length - 9;


        xhttp.open("POST", "save_planned_filters_into_db.php", true);
        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send("JSON_send_array="+JSON_send_array+"&order_number="+order_number_text+"&start_date="+start_date);

    }
</script>

<script src="tools/calendar.js" type="text/javascript"></script>

<script type="text/javascript">

    trs = main_table.getElementsByTagName('tr');
    cnt = trs.length;
    cols = 2;
    let x=0;

    /* Занесение в ячейку количества фильтров которые мы добавляем в план */
    document.querySelector('table').onclick = (event) => {
        let cell = event.target;
        if (cell.tagName.toLowerCase() != 'td')
            return;
        let i = cell.parentNode.rowIndex;
        let j = cell.cellIndex;
        let main_table = document.getElementById('main_table');
        let added_into_plan = main_table.rows[i].cells[5].innerHTML; /* в плане */
        let need_count = main_table.rows[i].cells[6].innerHTML; /* не в плане */
        /* добавляем дату если это заголовочные ячейки */
        if ((j >8)&&(i == 0)){
            let add_date = prompt("введите дату");
            main_table.rows[i].cells[j].innerHTML = add_date;
        }
        /* добавляем значения если это не заголовочные ячейки */
        if ((j >8)&&(i !== 0)&&(i !== main_table.rows.length-1)){
            let add_count = prompt("введите число");
            let difference_count = need_count - add_count;
            main_table.rows[i].cells[j].innerHTML = add_count;
            /* получаем сумму строки */
            let string_sum = 0;
            for (let z = 9; z <= main_table.rows[i].cells.length - 1; z++){
                string_sum = string_sum + Number(main_table.rows[i].cells[z].innerText);
            }
            /* вносим сумму распланированных изделий */
            main_table.rows[i].cells[5].innerHTML = string_sum;
            /* вносим сумму не распланированных изделий */
            main_table.rows[i].cells[6].innerHTML = main_table.rows[i].cells[4].innerHTML - Number(string_sum);
        }
        auto_calculate();
    }

    /* Добавление смены  в таблицу*/
    function add_row(){
        let i =0;
        for (i;i < cnt; i++){
            var newTd = document.createElement('td');
            if (i==0){
                x++;
                 newTd.innerHTML = 'Смена №'+x;

                //newTd.innerHTML = ' <input type="date" id="myDate" >';

            }else{
                newTd.innerHTML = ' ';
            }
            trs[i].appendChild(newTd);
        }
    }

    /* Удаление смены  из таблицы*/
    function delete_row(){
        let i =0;
        for (i;i < cnt; i++){
            var newTd = document.createElement('td');

                newTd.innerHTML = ' ';

            trs[i].appendChild(newTd);
        }
    }

    /* функция складывает суммы по столбцам */
    function auto_calculate() {
        /* складываем сумму во 2,3,4 столбцах */
        let ii =0;
        let main_table = document.getElementById('main_table');
        let count = 0;
        let planned =0;
        let not_planned=0;
        for (let i = 1; i < main_table.rows.length-1; i++ ){
            count = count + Number(main_table.rows[i].cells[2].innerText);
            planned = planned + Number(main_table.rows[i].cells[5].innerText);
            not_planned = not_planned + Number(main_table.rows[i].cells[6].innerText);
             ii=i;
        }

        main_table.rows[ii+1].cells[2].innerHTML=count+"(...)";
        main_table.rows[ii+1].cells[5].innerHTML=planned;
        main_table.rows[ii+1].cells[6].innerHTML=not_planned;

        /* складываем столбцы смен */
        let shift_sum = 0; // сменная сумма
        if (main_table.rows.length > 9){
            /* для каждого столбца */
            for (let z = 9; z <= main_table.rows[0].cells.length - 1; z++){

                /* делаем проходы по каждой строке и собираем сумму для каждой строки */
                for (let x = 1; x < main_table.rows.length-1; x++ ){
                    shift_sum = shift_sum + Number(main_table.rows[x].cells[z].innerText);

                }
                main_table.rows[main_table.rows.length-1].cells[z].innerText = shift_sum;
                shift_sum = 0;
            }
        }
    }

</script>
<script>
    function compress_table(){
        /* функция убирает пустые строки в таблице после окончания планирования */
        let main_table = document.getElementById('main_table');
        let row = 1;
        let rowcount = main_table.rows.length-1

        for (let x=1; x < rowcount; x++){

            if (main_table.rows[x].cells[5].innerHTML == 0){
                main_table.deleteRow(x);
                x = x-1;
                rowcount = rowcount - 1;
            }
        }

        auto_calculate();

    }
</script>
<script>
    /* функция сохраняет распланированные фильтры по заявке в БД */
    /* проходим построчно все записи таблицы */
    function save_function() {
        let order_number; /* номер заявки */
        let filter_name; /* номер фильтра */
        let send_array; /* массив для отправки данных в php-скрипт
        формат массива: {send_array_part,send_array_part,send_array_part...send_array_part}*/
        let send_array_part; /* массив для формирования массива для отправки данных в php-скрипт для сохранения
        формат массива: {order_number, filter_name, {date,count},{date,count},..{date,count}} */
        let main_table = document.getElementById('main_table');
        send_array =[]; /* обнуляем отправочный массив */
        let date_count_array;
        if (main_table.rows[0].cells.length > 4){ /* если столбцов больше 4 значит есть распланированные дни -> выполняем */
            for (let i = 1; i < main_table.rows.length-1; i++){ /* для каждой строки таблицы... */
                send_array_part = []; /* обнуляем массив для формирования отправочного массива */
                order_number = document.getElementById('order_number').innerText; /* получаем номер заявки */
                filter_name = main_table.rows[i].cells[0].innerText; /* получаем номер фильтра */
                /* send_array_part.push(order_number); номер заявки будет передан скрипту в post-запросе */
                send_array_part.push(filter_name);
                for (let j = 4; j < main_table.rows[0].cells.length; j++){ /* проходим по ячейкам с 4 по последнюю и читаем
                    дату и количество фильтров, добавляем эту информацию в массив и массив добавляем в отправочный массив*/
                    date_count_array = []; /* обнуляем массив даты-количества */
                    date_count_array.push(main_table.rows[0].cells[j].children[0].value); /* получаем значение из календаря в ячейке */
                    date_count_array.push(main_table.rows[i].cells[j].innerText); /* получаем значение количества фильтров в ячейке */
                    send_array_part.push(date_count_array); /* */
                }
                /* Здесь будем добавлять массив send_array_part в массив send_array */
                send_array.push(send_array_part); /* имеем сформированный массив для отправки */
            }
            /* Здесь отправим массив php-скрипту в AJAX запросе */
            let xhttp = new XMLHttpRequest();
            xhttp.onreadystatechange = function() {
                if (this.readyState == 4 && this.status == 200) {
                    document.getElementById("result_area").innerHTML = this.responseText; /* здесь будет выведен результат php-скрипта */
                }
            };
            let JSON_send_array = JSON.stringify(send_array);
            xhttp.open("POST", "save_planned_filters_into_db.php", true);
            xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
            xhttp.send("JSON_send_array="+JSON_send_array+"&order_number="+order_number);
            //xhttp.send("JSON_send_array="+JSON_send_array);

            alert("Сохранено")
        } else {
            alert("Нечего сохранять") /* столбцов 4 и ни чего не спланировано */
        }
    }
</script>
<p>
<button id='add_button' value='' onclick="add_row()">Добавить смену</button>
<p>
<button id='compress_button' value='' onclick="compress_table()">Убрать пустые строки</button>
<p>
    <input type="text" name="order_number" id="order_number">

<button id="save_button_to_JSON" value="" onclick="tableToJson()">Сохранить план</button>
<div id="result_area">..... </div>


<button onclick="window.close();">Закрыть окно</button>