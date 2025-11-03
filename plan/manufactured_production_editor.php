<?php


require_once('settings.php');
require_once('tools/tools.php');

?>

<script> /* Загрузка выпущенной продукции */
    function load_manufactured_production(object_id) {

        //AJAX запрос
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function () {
            if (this.readyState == 4 && this.status == 200) {
                document.getElementById("data_div").innerHTML = this.responseText;
            }
        };

        //выбор номера заявки из заголовка списка для передачи в ajax-запрос
        // let selected_date_index = document.getElementById("selected_date").selectedIndex;
        let selected_date = document.getElementById("selected_date").value;

        xhttp.open("POST", "manufactured_production_editor_processing.php", true);
        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send("reason=1&date=" + selected_date);
    }
</script>


<div id="header" style="background-color: #5450ff; height: 50px; width: 100%; font-family: Calibri; font-size: 20px">
    <p style="color: white">Редактор данных:</p>
</div><p>
    <label>Выбор выпущенной продукции по дате:</label>
<p>
    <input type="date" id="selected_date">

    <button onclick="load_manufactured_production(document.getElementById('selected_date'))">Выбрать / Обновить</button>

<div id="data_div">

</div>
<p>
    <button onclick="window.close();">Закрыть окно</button>