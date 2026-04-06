<?php /** Блок сохранения заявки в БД — по образцу plan/save_order_into_DB.php */

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Ожидается POST-запрос.';
    exit;
}

$orderStr = $_POST['order_str'] ?? '';
$orderName = $_POST['order_name'] ?? '';
$workshop = $_POST['workshop'] ?? '';

if ($orderStr === '') {
    echo 'Нет данных заявки (order_str). Вернитесь назад и загрузите файл снова.';
    exit;
}

if ($orderName === '' || $workshop === '') {
    echo '<p>Не заполнены поля номера заявки или участка.</p>';
    exit;
}

echo 'сохранение заявки...';
if (function_exists('ob_get_level') && ob_get_level() > 0) {
    @ob_flush();
}
@flush();

// Как в plan: order_str может быть base64(serialize) — надёжнее для HTML
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
    echo '<p>Ошибка: данные заявки повреждены или обрезаны при отправке формы.</p>';
    exit;
}

if (file_exists(__DIR__ . '/../env.php')) {
    require __DIR__ . '/../env.php';
}
require_once __DIR__ . '/../auth/includes/db.php';

$pdo = null;
try {
    $pdo = getPdo('plan_u5');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 25');

    $stmt = $pdo->prepare(
        'INSERT INTO orders (order_number, workshop, filter, `count`, marking, personal_packaging, personal_label, group_packaging, packaging_rate, group_label, remark) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $pdo->beginTransaction();
    foreach ($order as $value) {
        if (!is_array($value)) {
            continue;
        }
        /** Колонки C и H в Excel — числовые в БД; текст (напр. «стандарт») даёт (int) → 0, как в plan */
        $stmt->execute([
            $orderName,
            $workshop,
            $value['B'] ?? '',
            (int) ($value['C'] ?? 0),
            $value['D'] ?? '',
            $value['E'] ?? '',
            $value['F'] ?? '',
            $value['G'] ?? '',
            (int) ($value['H'] ?? 0),
            $value['I'] ?? '',
            $value['J'] ?? '',
        ]);
    }
    $pdo->commit();

    echo '<p>успешно завершено</p>';
    echo "<a href='main.php'>на главную</a>";
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<p>Ошибка сохранения заявки: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}
