<?php
/**
 * Обеспечивает наличие полей «сдача на склад» и «закрыто в ЗП авансом» в manufactured_production,
 * а также таблицы выбора режима оплаты (сдельно/почасово) мастером.
 * Подключать через require_once из настроенного окружения (settings.php уже загружен).
 */
if (!isset($mysql_host) || !isset($mysql_database)) {
    return;
}
$pdo_salary = null;
try {
    $pdo_salary = new PDO("mysql:host={$mysql_host};dbname={$mysql_database};charset=utf8mb4", $mysql_user, $mysql_user_pass);
    $pdo_salary->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    return;
}
// manufactured_production: сдача на склад и закрытие в ЗП авансом
try {
    $cols = $pdo_salary->query("SHOW COLUMNS FROM manufactured_production")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('handed_to_warehouse_at', $cols)) {
        $pdo_salary->exec("ALTER TABLE manufactured_production ADD COLUMN handed_to_warehouse_at DATETIME NULL DEFAULT NULL COMMENT 'Дата сдачи на склад' AFTER team");
    }
    if (!in_array('salary_closed_advance', $cols)) {
        $pdo_salary->exec("ALTER TABLE manufactured_production ADD COLUMN salary_closed_advance TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Закрыто в зарплату авансом (ещё не сдано)' AFTER handed_to_warehouse_at");
        // Существующие записи считаем уже сданными на склад, чтобы не терять их в отчёте ЗП
        $pdo_salary->exec("UPDATE manufactured_production SET handed_to_warehouse_at = CONCAT(date_of_production, ' 00:00:00') WHERE handed_to_warehouse_at IS NULL");
    }
} catch (PDOException $e) {
    // игнорируем, таблица может иметь другую структуру
}

// Таблица выбора мастером: сдельная или почасовая оплата по сменам (user_id, date)
try {
    $pdo_salary->exec("
        CREATE TABLE IF NOT EXISTS salary_payment_choice (
            user_id INT NOT NULL COMMENT 'ID из auth_users',
            date DATE NOT NULL,
            pay_mode ENUM('piece','hourly') NOT NULL DEFAULT 'piece' COMMENT 'piece=сдельно, hourly=почасово',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, date),
            INDEX idx_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // игнорируем
}

// Режим закрытия ЗП по смене бригады: сдельно или почасово (тариф «Сборщица почасово»)
try {
    $pdo_salary->exec("
        CREATE TABLE IF NOT EXISTS salary_brigade_shift_pay_mode (
            team_id INT NOT NULL COMMENT 'Номер бригады 1-4',
            date DATE NOT NULL,
            pay_mode ENUM('piece','hourly') NOT NULL DEFAULT 'piece' COMMENT 'piece=сдельно, hourly=почасово по тарифу Сборщица почасово',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, date),
            INDEX idx_date (date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // игнорируем
}

// Таблица почасовых тарифов для рабочих (отдельно от тарифов по фильтрам)
try {
    $pdo_salary->exec("
        CREATE TABLE IF NOT EXISTS salary_hourly_worker_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL COMMENT 'Название тарифа',
            rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Ставка за час, грн',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // игнорируем
}
