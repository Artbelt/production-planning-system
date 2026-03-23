<?php
/** Блок сохранения заявки в БД */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Ожидается POST-запрос.');
}

$orderStr = $_POST['order_str'] ?? '';
$orderName = $_POST['order_name'] ?? '';
$workshop = $_POST['workshop'] ?? '';

if ($orderStr === '') {
    http_response_code(400);
    exit('Нет данных order_str.');
}

// Лучше явно валидировать входные параметры: тогда ошибки не превращаются в "HTTP 500".
if ($orderName === '' || $workshop === '') {
    http_response_code(400);
    exit('Не заполнены поля order_name или workshop.');
}

try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan');

    // order_str передается в hidden поле как base64 от serialize($order).
    // Так мы исключаем порчу байтов при HTML-экранировании/кодировках.
    if (strpos($orderStr, '&') !== false) {
        $orderStr = html_entity_decode($orderStr, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $orderStrRaw = $orderStr;
    $maybeDecoded = base64_decode($orderStr, true);
    if ($maybeDecoded !== false) {
        $orderStrRaw = $maybeDecoded;
    }

    $order = @unserialize($orderStrRaw, ['allowed_classes' => false]);
    if (!is_array($order)) {
        http_response_code(400);
        exit('Некорректный формат order_str (ожидался массив сериализации).');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO orders (
            `order_number`, `workshop`, `filter`, `count`, `marking`,
            `personal_packaging`, `personal_label`, `group_packaging`, `packaging_rate`, `group_label`, `remark`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $pdo->beginTransaction();
    foreach ($order as $value) {
        if (!is_array($value)) continue;

        $stmt->execute([
            $orderName,
            $workshop,
            $value['B'] ?? '',
            (int)($value['C'] ?? 0),
            $value['D'] ?? '',
            $value['E'] ?? '',
            $value['F'] ?? '',
            $value['G'] ?? '',
            (int)($value['H'] ?? 0),
            $value['I'] ?? '',
            $value['J'] ?? '',
        ]);
    }
    $pdo->commit();

    echo 'успешно завершено <p>';
    echo "<a href='main.php'>на главную</a>";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('Ошибка сохранения заявки: ' . $e->getMessage());
}
?>