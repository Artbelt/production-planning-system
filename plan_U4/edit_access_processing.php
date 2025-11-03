<?php

require_once('settings.php') ;
require_once('tools/tools.php') ;

global $mysql_host,$mysql_user,$mysql_user_pass,$mysql_database;

$now_time = strtotime(date("Y-m-d H:i:s"));
$acces_time = date("Y-m-d H:i:s", strtotime('+15 minutes', $now_time));
$sql = "UPDATE editor_access_time SET access_time = '".$acces_time."'";
$result = mysql_execute($sql);

echo 'Доступ к редактированию предоставлен до '.$acces_time.'<p>';
?>
<button onclick="window.close();">Закрыть окно</button>
