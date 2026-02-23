<?php

if(isset($_GET['filter'])){
    if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
    if (strlen($_GET['filter'])<2) die();
    $filter = '%' . $_GET['filter'] . '%';
    $stmt = $pdo->prepare("SELECT filter FROM round_filter_structure WHERE filter LIKE ?");
    $stmt->execute([$filter]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<select id='select_filter' size=".count($rows)." >";
    foreach ($rows as $row) {
        echo "<option>".htmlspecialchars($row['filter'])."</option><br>";
    }
    echo "</select>";
}
?>
