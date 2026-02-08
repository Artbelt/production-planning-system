<?php
/**
 * Класс для записи аудита изменений в БД
 */
class AuditLogger {
    private $mysqli;
    
    public function __construct($mysqli) {
        $this->mysqli = $mysqli;
    }
    
    /**
     * Записать операцию в аудит
     *
     * @param string $user_name Имя пользователя (опционально)
     */
    public function log($table_name, $record_id, $operation, $old_values = null, $new_values = null, $changed_fields = null, $additional_info = null, $user_name = null) {
        try {
            $this->ensureUserColumn();
            $user_ip = $this->getUserIP();
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $session_id = session_id();
            $user_name = $user_name !== null && $user_name !== '' ? (string) $user_name : null;

            $old_values_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
            $new_values_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;
            $changed_fields_str = $changed_fields ? implode(',', $changed_fields) : null;

            $query = "INSERT INTO audit_log (
                table_name, record_id, operation, old_values, new_values, changed_fields,
                user_ip, user_agent, session_id, additional_info, user_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $this->mysqli->prepare($query);
            $stmt->bind_param(
                'sssssssssss',
                $table_name,
                $record_id,
                $operation,
                $old_values_json,
                $new_values_json,
                $changed_fields_str,
                $user_ip,
                $user_agent,
                $session_id,
                $additional_info,
                $user_name
            );

            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (Exception $e) {
            error_log("Audit Logger Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Добавить колонку user_name в audit_log, если её ещё нет
     */
    private function ensureUserColumn() {
        static $checked = false;
        if ($checked) {
            return;
        }
        $r = $this->mysqli->query("SHOW COLUMNS FROM audit_log LIKE 'user_name'");
        if ($r && $r->num_rows === 0) {
            $this->mysqli->query("ALTER TABLE audit_log ADD COLUMN user_name VARCHAR(255) DEFAULT NULL AFTER user_ip");
        }
        $checked = true;
    }
    
    /**
     * Записать операцию INSERT
     */
    public function logInsert($table_name, $record_id, $new_values, $additional_info = null, $user_name = null) {
        return $this->log($table_name, $record_id, 'INSERT', null, $new_values, null, $additional_info, $user_name);
    }

    /**
     * Записать операцию UPDATE
     */
    public function logUpdate($table_name, $record_id, $old_values, $new_values, $changed_fields, $additional_info = null, $user_name = null) {
        return $this->log($table_name, $record_id, 'UPDATE', $old_values, $new_values, $changed_fields, $additional_info, $user_name);
    }

    /**
     * Записать операцию DELETE
     */
    public function logDelete($table_name, $record_id, $old_values, $additional_info = null, $user_name = null) {
        return $this->log($table_name, $record_id, 'DELETE', $old_values, null, null, $additional_info, $user_name);
    }
    
    /**
     * Получить IP адрес пользователя
     */
    private function getUserIP() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Получить историю изменений для записи
     */
    public function getRecordHistory($table_name, $record_id, $limit = 50) {
        try {
            $query = "SELECT * FROM audit_log 
                     WHERE table_name = ? AND record_id = ? 
                     ORDER BY created_at DESC 
                     LIMIT ?";
            
            $stmt = $this->mysqli->prepare($query);
            $stmt->bind_param('ssi', $table_name, $record_id, $limit);
            $stmt->execute();
            
            $result = $stmt->get_result();
            $history = [];
            
            while ($row = $result->fetch_assoc()) {
                $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
                $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
                $row['changed_fields'] = $row['changed_fields'] ? explode(',', $row['changed_fields']) : null;
                $history[] = $row;
            }
            
            $stmt->close();
            return $history;
            
        } catch (Exception $e) {
            error_log("Audit Logger Error (getRecordHistory): " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Получить статистику изменений по таблице
     */
    public function getTableStats($table_name, $days = 30) {
        try {
            $query = "SELECT 
                        operation,
                        COUNT(*) as count,
                        DATE(created_at) as date
                     FROM audit_log 
                     WHERE table_name = ? 
                     AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     GROUP BY operation, DATE(created_at)
                     ORDER BY date DESC, operation";
            
            $stmt = $this->mysqli->prepare($query);
            $stmt->bind_param('si', $table_name, $days);
            $stmt->execute();
            
            $result = $stmt->get_result();
            $stats = [];
            
            while ($row = $result->fetch_assoc()) {
                $stats[] = $row;
            }
            
            $stmt->close();
            return $stats;
            
        } catch (Exception $e) {
            error_log("Audit Logger Error (getTableStats): " . $e->getMessage());
            return [];
        }
    }
}
?>


