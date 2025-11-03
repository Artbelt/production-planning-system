<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "");
$stmt = $pdo->query("SELECT filter, paper_package FROM panel_filter_structure");
$result = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $stmt2 = $pdo->prepare("SELECT * FROM paper_package_panel WHERE p_p_name = ?");
    $stmt2->execute([$row['paper_package']]);
    if ($p = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $result[] = [
            'filter' => $row['filter'],
            'paper' => $row['paper_package'],
            'width' => (float)$p['p_p_width'],
            'height' => (float)$p['p_p_height'],
            'length' => 1000
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($result);
