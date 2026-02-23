<?php

if(isset($_GET['part'])){
    if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    if (strlen($_GET['part'])<2) die();

    $stmt = $pdo->prepare("SELECT p_p_name FROM paper_package_round WHERE p_p_name LIKE ?");
    $stmt->execute(['%'.$_GET['part'].'%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<select id='select_filter' size=".count($rows).">";
    foreach ($rows as $row) {
        echo "<option>".htmlspecialchars($row['p_p_name'])."</option><br>";
    }
    echo "</select>";
}
?>
