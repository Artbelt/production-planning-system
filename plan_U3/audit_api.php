<?php
// API для просмотра логов аудита
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

session_start();

try {
    require_once __DIR__ . '/../auth/includes/db.php';
    $pdo = getPdo('plan_u3');
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_audit_logs':
        getAuditLogs();
        break;
    default:
        echo json_encode(['error' => 'Неизвестное действие']);
        break;
}

function getAuditLogs() {
    global $pdo;
    
    $table_name = $_POST['table_name'] ?? '';
    $operation = $_POST['operation'] ?? '';
    $date_from = $_POST['date_from'] ?? '';
    $date_to = $_POST['date_to'] ?? '';
    $limit = intval($_POST['limit'] ?? 1000);
    
    try {
        $where_conditions = [];
        $params = [];
        
        if (!empty($table_name)) {
            $where_conditions[] = "table_name = ?";
            $params[] = $table_name;
        }
        
        if (!empty($operation)) {
            $where_conditions[] = "operation = ?";
            $params[] = $operation;
        }
        
        if (!empty($date_from)) {
            $where_conditions[] = "DATE(created_at) >= ?";
            $params[] = $date_from;
        }
        
        if (!empty($date_to)) {
            $where_conditions[] = "DATE(created_at) <= ?";
            $params[] = $date_to;
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $query = "SELECT * FROM audit_log {$where_clause} ORDER BY created_at DESC LIMIT ?";
        $query_params = array_merge($params, [$limit]);
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($query_params);
        
        $logs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
            $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
            $row['changed_fields'] = $row['changed_fields'] ? explode(',', $row['changed_fields']) : null;
            $logs[] = $row;
        }
        
        $stats_query = "SELECT 
                           COUNT(*) as total,
                           SUM(CASE WHEN operation = 'INSERT' THEN 1 ELSE 0 END) as `INSERT`,
                           SUM(CASE WHEN operation = 'UPDATE' THEN 1 ELSE 0 END) as `UPDATE`,
                           SUM(CASE WHEN operation = 'DELETE' THEN 1 ELSE 0 END) as `DELETE`
                        FROM audit_log {$where_clause}";
        
        $stats_stmt = $pdo->prepare($stats_query);
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'stats' => $stats,
            'count' => count($logs)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>









