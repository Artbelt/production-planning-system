<?php
/**
 * Функция для создания резервной копии записи перед обновлением
 */

function backup_filter_before_update($mysqli, $filter_name) {
    try {
        // Создаем таблицу для резервных копий, если её нет
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS salon_filter_structure_backup (
                id INT AUTO_INCREMENT PRIMARY KEY,
                backup_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                filter VARCHAR(255) NOT NULL,
                box VARCHAR(255),
                insertion_count VARCHAR(255),
                g_box VARCHAR(255),
                side_type VARCHAR(255),
                category VARCHAR(255),
                comment TEXT,
                foam_rubber VARCHAR(255),
                form_factor VARCHAR(255),
                tail VARCHAR(255),
                has_edge_cuts TINYINT,
                paper_package VARCHAR(255),
                tariff_id INT,
                build_complexity DECIMAL(10,3),
                INDEX idx_filter (filter),
                INDEX idx_backup_time (backup_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $mysqli->query($create_table_sql);
        
        // Получаем текущие данные
        $stmt = $mysqli->prepare("SELECT * FROM salon_filter_structure WHERE filter = ?");
        $stmt->bind_param('s', $filter_name);
        $stmt->execute();
        $current_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($current_data) {
            // Сохраняем резервную копию
            $insert_sql = "
                INSERT INTO salon_filter_structure_backup 
                (filter, box, insertion_count, g_box, side_type, category, comment, 
                 foam_rubber, form_factor, tail, has_edge_cuts, paper_package, 
                 tariff_id, build_complexity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $mysqli->prepare($insert_sql);
            $stmt->bind_param('ssssssssssissi',
                $current_data['filter'],
                $current_data['box'],
                $current_data['insertion_count'],
                $current_data['g_box'],
                $current_data['side_type'],
                $current_data['category'],
                $current_data['comment'],
                $current_data['foam_rubber'],
                $current_data['form_factor'],
                $current_data['tail'],
                $current_data['has_edge_cuts'],
                $current_data['paper_package'],
                $current_data['tariff_id'],
                $current_data['build_complexity']
            );
            
            $stmt->execute();
            $stmt->close();
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Ошибка создания резервной копии для фильтра {$filter_name}: " . $e->getMessage());
        return false;
    }
}

/**
 * Восстановление данных из резервной копии
 */
function restore_filter_from_backup($mysqli, $filter_name, $backup_id = null) {
    try {
        if ($backup_id) {
            // Восстанавливаем из конкретной резервной копии
            $stmt = $mysqli->prepare("SELECT * FROM salon_filter_structure_backup WHERE id = ? AND filter = ?");
            $stmt->bind_param('is', $backup_id, $filter_name);
        } else {
            // Восстанавливаем из последней резервной копии
            $stmt = $mysqli->prepare("SELECT * FROM salon_filter_structure_backup WHERE filter = ? ORDER BY backup_time DESC LIMIT 1");
            $stmt->bind_param('s', $filter_name);
        }
        
        $stmt->execute();
        $backup_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($backup_data) {
            $update_sql = "
                UPDATE salon_filter_structure SET
                    box = ?,
                    insertion_count = ?,
                    g_box = ?,
                    side_type = ?,
                    category = ?,
                    comment = ?,
                    foam_rubber = ?,
                    form_factor = ?,
                    tail = ?,
                    has_edge_cuts = ?,
                    paper_package = ?,
                    tariff_id = ?,
                    build_complexity = ?
                WHERE filter = ?
            ";
            
            $stmt = $mysqli->prepare($update_sql);
            $stmt->bind_param('ssssssssssissi',
                $backup_data['box'],
                $backup_data['insertion_count'],
                $backup_data['g_box'],
                $backup_data['side_type'],
                $backup_data['category'],
                $backup_data['comment'],
                $backup_data['foam_rubber'],
                $backup_data['form_factor'],
                $backup_data['tail'],
                $backup_data['has_edge_cuts'],
                $backup_data['paper_package'],
                $backup_data['tariff_id'],
                $backup_data['build_complexity'],
                $filter_name
            );
            
            $stmt->execute();
            $stmt->close();
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Ошибка восстановления фильтра {$filter_name}: " . $e->getMessage());
        return false;
    }
}



