<?php
/** Менеджер планирования заявки */
require_once('tools/tools.php');
require_once('settings.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Менеджер планирования</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f7f7f7;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            text-align: center;
        }
        .title {
            font-size: 20px;
            margin-bottom: 20px;
            color: #333;
        }
        form {
            margin-top: 10px;
        }
        select, input[type="submit"] {
            padding: 10px 15px;
            margin: 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
        select {
            border: 1px solid #ccc;
            background-color: white;
        }
        .btn-green {
            background-color: #4CAF50;
            color: white;
        }
        .btn-green:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="title">ВЫБОР ФИЛЬТРА ДЛЯ РЕДАКТИРОВАНИЯ:</div>
    <form action="add_panel_filter_into_db.php" method="post">
        <input type="hidden" name="workshop" value="U2">
        <?php load_filters_into_select('выбор фильтра'); ?>
        <input type="submit" class="btn-green" value="Добавить / изменить фильтр в БД">
    </form>
</div>
</body>
</html>
