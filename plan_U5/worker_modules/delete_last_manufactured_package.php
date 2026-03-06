<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../auth/includes/db.php';
    $pdo = getPdo('plan_u5');

    $date_of_production = $_POST['date_of_production'] ?? '';
    $id_to_delete = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    $deleteId = null;

    if ($id_to_delete > 0) {
        // Удаление конкретной записи по ID (проверяем, что запись существует)
        $stmt = $pdo->prepare("
            SELECT id FROM manufactured_corrugated_packages WHERE id = ?
        ");
        $stmt->execute([$id_to_delete]);
        $row = $stmt->fetch();
        if ($row) {
            $deleteId = (int)$row['id'];
        }
    }

    if ($deleteId === null && !empty($date_of_production)) {
        // Удаление последней записи по дате (как раньше)
        $stmt = $pdo->prepare("
            SELECT id 
            FROM manufactured_corrugated_packages 
            WHERE date_of_production = ? 
            ORDER BY timestamp DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([$date_of_production]);
        $lastRecord = $stmt->fetch();
        if ($lastRecord) {
            $deleteId = (int)$lastRecord['id'];
        }
    }

    if ($deleteId === null) {
        echo json_encode([
            'success' => false,
            'message' => $id_to_delete > 0 ? 'Запись не найдена' : 'Дата не указана или записей для удаления не найдено'
        ]);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM manufactured_corrugated_packages WHERE id = ?
    ");
    $deleteStmt->execute([$deleteId]);

    echo json_encode([
        'success' => true,
        'message' => 'Запись удалена'
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка: ' . $e->getMessage()
    ]);
}
