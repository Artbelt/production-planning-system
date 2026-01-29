<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=plan;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $date_of_production = $_POST['date_of_production'] ?? '';

    if (empty($date_of_production)) {
        echo json_encode([
            'success' => false,
            'message' => 'Дата не указана'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id 
        FROM manufactured_corrugated_packages 
        WHERE date_of_production = ? 
        ORDER BY timestamp DESC, id DESC 
        LIMIT 1
    ");
    $stmt->execute([$date_of_production]);
    $lastRecord = $stmt->fetch();

    if (!$lastRecord) {
        echo json_encode([
            'success' => false,
            'message' => 'Записей для удаления не найдено'
        ]);
        exit;
    }

    $deleteStmt = $pdo->prepare("
        DELETE FROM manufactured_corrugated_packages 
        WHERE id = ?
    ");
    $deleteStmt->execute([$lastRecord['id']]);

    echo json_encode([
        'success' => true,
        'message' => 'Последняя запись успешно удалена'
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
