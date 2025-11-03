<?php
/**
 * Класс для работы с базой данных
 */

if (!defined('AUTH_SYSTEM')) {
    die('Прямой доступ запрещен');
}

class Database {
    private static $instance = null;
    private static $planInstance = null;
    private $pdo;
    
    private function __construct($config = null) {
        try {
            $config = $config ?: AUTH_DB_CONFIG;
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            
            $options = $config['options'] ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            if (DEV_CONFIG['debug_mode']) {
                die('Ошибка подключения к БД: ' . $e->getMessage());
            } else {
                die('Ошибка подключения к базе данных');
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Получить экземпляр для подключения к БД plan (для миграции и интеграции)
     */
    public static function getPlanInstance() {
        if (self::$planInstance === null) {
            self::$planInstance = new self(PLAN_DB_CONFIG);
        }
        return self::$planInstance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Выполнить запрос SELECT
     */
    public function select($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError('SELECT', $sql, $params, $e);
            return false;
        }
    }
    
    /**
     * Выполнить запрос SELECT и получить одну строку
     */
    public function selectOne($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $this->logError('SELECT_ONE', $sql, $params, $e);
            return false;
        }
    }
    
    /**
     * Выполнить запрос INSERT
     */
    public function insert($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                // Для таблиц с AUTO_INCREMENT возвращаем lastInsertId
                $lastId = $this->pdo->lastInsertId();
                // Если lastInsertId пустой (например, для VARCHAR ключей), возвращаем true
                return $lastId ?: true;
            }
            
            return false;
        } catch (PDOException $e) {
            $this->logError('INSERT', $sql, $params, $e);
            return false;
        }
    }
    
    /**
     * Выполнить запрос UPDATE
     */
    public function update($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError('UPDATE', $sql, $params, $e);
            return false;
        }
    }
    
    /**
     * Выполнить запрос DELETE
     */
    public function delete($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->logError('DELETE', $sql, $params, $e);
            return false;
        }
    }
    
    /**
     * Начать транзакцию
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Подтвердить транзакцию
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Откатить транзакцию
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Логирование ошибок БД
     */
    private function logError($type, $sql, $params, $exception) {
        $error = [
            'type' => $type,
            'sql' => $sql,
            'params' => $params,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'time' => date('Y-m-d H:i:s')
        ];
        
        $logFile = AUTH_PATHS['logs'] . '/database_errors.log';
        file_put_contents($logFile, json_encode($error) . "\n", FILE_APPEND | LOCK_EX);
        
        if (DEV_CONFIG['show_sql_errors']) {
            echo "<div style='background: #ffebee; border: 1px solid #f44336; padding: 10px; margin: 10px; border-radius: 4px;'>";
            echo "<strong>Database Error ({$type}):</strong><br>";
            echo "SQL: " . htmlspecialchars($sql) . "<br>";
            echo "Params: " . htmlspecialchars(json_encode($params)) . "<br>";
            echo "Error: " . htmlspecialchars($exception->getMessage());
            echo "</div>";
        }
    }
}

?>
