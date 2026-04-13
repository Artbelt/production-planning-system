<?php
require_once('tools/tools.php');
require_once('settings.php');
require_once('tools/ensure_salary_warehouse_tables.php');
require_once('audit_logger.php');

function parse_enum_values($columnType) {
    if (!is_string($columnType) || $columnType === '') {
        return [];
    }
    if (!preg_match_all("/'([^']*)'/", $columnType, $matches)) {
        return [];
    }
    return $matches[1] ?? [];
}

function normalize_tariff_type($mysqli, $requestedType, $currentType = null) {
    $requestedType = trim((string) $requestedType);
    if ($requestedType === '') {
        $requestedType = 'normal';
    }
    $allowed = [];
    $col = $mysqli->query("SHOW COLUMNS FROM salary_tariffs LIKE 'type'");
    if ($col && ($row = $col->fetch_assoc())) {
        $allowed = parse_enum_values($row['Type'] ?? '');
    }
    if (empty($allowed)) {
        return $requestedType;
    }
    if (in_array($requestedType, $allowed, true)) {
        return $requestedType;
    }

    $aliases = [
        'normal' => ['normal', 'обычный', 'obychnyy', 'standard', 'piece'],
        'fixed' => ['fixed', 'фиксированный'],
        'hourly' => ['hourly', 'почасовый', 'почасовой'],
    ];
    $canonical = null;
    foreach ($aliases as $key => $variants) {
        if (in_array($requestedType, $variants, true)) {
            $canonical = $key;
            break;
        }
    }
    if ($canonical !== null) {
        foreach ($aliases[$canonical] as $candidate) {
            if (in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }
    }
    if ($currentType !== null && in_array($currentType, $allowed, true)) {
        return $currentType;
    }
    return $allowed[0];
}

function manage_redirect($locationPath) {
    header('Location: ' . $locationPath);
    exit;
}

$action = $_GET['action'] ?? 'list';
$tariff_id = $_GET['id'] ?? null;
$addition_action = $_GET['addition_action'] ?? null;
$addition_code = $_GET['addition_code'] ?? null;
$hourly_action = $_GET['hourly_action'] ?? null;
$hourly_id = isset($_GET['hourly_id']) ? intval($_GET['hourly_id']) : null;

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add' || $action === 'edit') {
            $tariff_name = trim($_POST['tariff_name'] ?? '');
            $rate_per_unit = floatval($_POST['rate_per_unit'] ?? 0);
            $type = trim($_POST['type'] ?? '');
            // Если type пустой, устанавливаем значение по умолчанию 'normal'
            if (empty($type)) {
                $type = 'normal';
            }
            // Проверяем, что type имеет допустимое значение
            if (!in_array($type, ['normal', 'fixed', 'hourly'])) {
                $type = 'normal';
            }
            $build_complexity = isset($_POST['build_complexity']) && $_POST['build_complexity'] !== '' ? floatval($_POST['build_complexity']) : null;
            
            if (empty($tariff_name)) {
                $error = 'Название тарифа не может быть пустым';
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $auditLogger = new AuditLogger($mysqli);
                    if ($action === 'add') {
                        $type = normalize_tariff_type($mysqli, $type);
                        // Используем отдельные запросы для обработки NULL значений
                        if ($build_complexity !== null) {
                            $stmt = $mysqli->prepare("INSERT INTO salary_tariffs (tariff_name, rate_per_unit, type, build_complexity) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param('sdsd', $tariff_name, $rate_per_unit, $type, $build_complexity);
                        } else {
                            $stmt = $mysqli->prepare("INSERT INTO salary_tariffs (tariff_name, rate_per_unit, type) VALUES (?, ?, ?)");
                            $stmt->bind_param('sds', $tariff_name, $rate_per_unit, $type);
                        }
                    } else {
                        $tariff_id = intval($_POST['tariff_id']);
                        
                        // Получаем старые значения для логирования
                        $old_values = null;
                        $old_stmt = $mysqli->prepare("SELECT * FROM salary_tariffs WHERE id = ?");
                        $old_stmt->bind_param('i', $tariff_id);
                        $old_stmt->execute();
                        $old_result = $old_stmt->get_result();
                        if ($old_row = $old_result->fetch_assoc()) {
                            $old_values = $old_row;
                        }
                        $old_stmt->close();
                        $type = normalize_tariff_type($mysqli, $type, $old_values['type'] ?? null);
                        
                        // Обновляем основные поля
                        $stmt = $mysqli->prepare("UPDATE salary_tariffs SET tariff_name = ?, rate_per_unit = ?, type = ? WHERE id = ?");
                        $stmt->bind_param('sdsi', $tariff_name, $rate_per_unit, $type, $tariff_id);
                    }
                    
                    $stmt_ok = false;
                    try {
                        $stmt_ok = $stmt->execute();
                    } catch (mysqli_sql_exception $e) {
                        $error = 'Ошибка сохранения: ' . $e->getMessage();
                    }
                    if ($stmt_ok) {
                        // Если редактирование, обновляем build_complexity отдельно
                        if ($action === 'edit') {
                            $tariff_id = intval($_POST['tariff_id']);
                            if ($build_complexity !== null) {
                                $stmt2 = $mysqli->prepare("UPDATE salary_tariffs SET build_complexity = ? WHERE id = ?");
                                $stmt2->bind_param('di', $build_complexity, $tariff_id);
                                $stmt2->execute();
                                $stmt2->close();
                            } else {
                                $stmt2 = $mysqli->prepare("UPDATE salary_tariffs SET build_complexity = NULL WHERE id = ?");
                                $stmt2->bind_param('i', $tariff_id);
                                $stmt2->execute();
                                $stmt2->close();
                            }
                            
                            // Получаем новые значения для логирования
                            $new_stmt = $mysqli->prepare("SELECT * FROM salary_tariffs WHERE id = ?");
                            $new_stmt->bind_param('i', $tariff_id);
                            $new_stmt->execute();
                            $new_result = $new_stmt->get_result();
                            $new_values = null;
                            if ($new_row = $new_result->fetch_assoc()) {
                                $new_values = $new_row;
                            }
                            $new_stmt->close();
                            
                            // Определяем измененные поля
                            $changed_fields = [];
                            if ($old_values && $new_values) {
                                foreach ($new_values as $key => $value) {
                                    if (isset($old_values[$key]) && $old_values[$key] != $value) {
                                        $changed_fields[] = $key;
                                    }
                                }
                            }
                            
                            // Логируем изменение тарифа
                            if ($old_values && $new_values) {
                                $auditLogger->logUpdate(
                                    'salary_tariffs',
                                    (string)$tariff_id,
                                    $old_values,
                                    $new_values,
                                    $changed_fields,
                                    'Изменение тарифа через manage_tariffs.php'
                                );
                            }
                        } else {
                            // Логируем добавление тарифа
                            $new_tariff_id = $mysqli->insert_id;
                            $new_values = [
                                'id' => $new_tariff_id,
                                'tariff_name' => $tariff_name,
                                'rate_per_unit' => $rate_per_unit,
                                'type' => $type,
                                'build_complexity' => $build_complexity
                            ];
                            $auditLogger->logInsert(
                                'salary_tariffs',
                                (string)$new_tariff_id,
                                $new_values,
                                'Добавление тарифа через manage_tariffs.php'
                            );
                        }
                        
                        manage_redirect('manage_tariffs.php?success=' . ($action === 'add' ? 'added' : 'updated'));
                    } else {
                        $error = 'Ошибка сохранения: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        } elseif ($action === 'delete') {
            $tariff_id = intval($_POST['tariff_id']);
            
            // Проверяем, используется ли тариф
            $usage_result = mysql_execute("SELECT COUNT(*) as count FROM salon_filter_structure WHERE tariff_id = $tariff_id");
            $usage_row = $usage_result->fetch_assoc();
            $usage_count = $usage_row['count'] ?? 0;
            
            if ($usage_count > 0) {
                $error = "Невозможно удалить тариф: он используется в $usage_count фильтрах";
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $auditLogger = new AuditLogger($mysqli);
                    
                    // Получаем данные тарифа перед удалением для логирования
                    $old_values = null;
                    $old_stmt = $mysqli->prepare("SELECT * FROM salary_tariffs WHERE id = ?");
                    $old_stmt->bind_param('i', $tariff_id);
                    $old_stmt->execute();
                    $old_result = $old_stmt->get_result();
                    if ($old_row = $old_result->fetch_assoc()) {
                        $old_values = $old_row;
                    }
                    $old_stmt->close();
                    
                    $stmt = $mysqli->prepare("DELETE FROM salary_tariffs WHERE id = ?");
                    $stmt->bind_param('i', $tariff_id);
                    
                    if ($stmt->execute()) {
                        // Логируем удаление тарифа
                        if ($old_values) {
                            $auditLogger->logDelete(
                                'salary_tariffs',
                                (string)$tariff_id,
                                $old_values,
                                'Удаление тарифа через manage_tariffs.php'
                            );
                        }
                        
                        manage_redirect('manage_tariffs.php?success=deleted');
                    } else {
                        $error = 'Ошибка удаления: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        }
    }
    
    // Обработка действий с дополнениями
    if (isset($_POST['addition_action'])) {
        $addition_action = $_POST['addition_action'];
        
        if ($addition_action === 'add' || $addition_action === 'edit') {
            $code = trim($_POST['code'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            
            if (empty($code)) {
                $error = 'Код доплаты не может быть пустым';
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $auditLogger = new AuditLogger($mysqli);
                    
                    if ($addition_action === 'add') {
                        // Получаем старые значения для логирования (если доплата уже существует)
                        $old_values = null;
                        $old_stmt = $mysqli->prepare("SELECT * FROM salary_additions WHERE code = ?");
                        $old_stmt->bind_param('s', $code);
                        $old_stmt->execute();
                        $old_result = $old_stmt->get_result();
                        if ($old_row = $old_result->fetch_assoc()) {
                            $old_values = $old_row;
                        }
                        $old_stmt->close();
                        
                        $stmt = $mysqli->prepare("INSERT INTO salary_additions (code, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
                        $stmt->bind_param('sd', $code, $amount);
                    } else {
                        $old_code = trim($_POST['old_code'] ?? '');
                        
                        // Получаем старые значения для логирования
                        $old_values = null;
                        $old_stmt = $mysqli->prepare("SELECT * FROM salary_additions WHERE code = ?");
                        $old_stmt->bind_param('s', $old_code);
                        $old_stmt->execute();
                        $old_result = $old_stmt->get_result();
                        if ($old_row = $old_result->fetch_assoc()) {
                            $old_values = $old_row;
                        }
                        $old_stmt->close();
                        
                        $stmt = $mysqli->prepare("UPDATE salary_additions SET code = ?, amount = ? WHERE code = ?");
                        $stmt->bind_param('sds', $code, $amount, $old_code);
                    }
                    
                    if ($stmt->execute()) {
                        // Получаем новые значения для логирования
                        $new_stmt = $mysqli->prepare("SELECT * FROM salary_additions WHERE code = ?");
                        $new_stmt->bind_param('s', $code);
                        $new_stmt->execute();
                        $new_result = $new_stmt->get_result();
                        $new_values = null;
                        if ($new_row = $new_result->fetch_assoc()) {
                            $new_values = $new_row;
                        }
                        $new_stmt->close();
                        
                        if ($addition_action === 'add') {
                            // Логируем добавление или обновление (если был ON DUPLICATE KEY UPDATE)
                            if ($old_values) {
                                // Это было обновление через ON DUPLICATE KEY UPDATE
                                $changed_fields = [];
                                if ($old_values['amount'] != $new_values['amount']) {
                                    $changed_fields[] = 'amount';
                                }
                                if ($old_values['code'] != $new_values['code']) {
                                    $changed_fields[] = 'code';
                                }
                                $auditLogger->logUpdate(
                                    'salary_additions',
                                    $code,
                                    $old_values,
                                    $new_values,
                                    $changed_fields,
                                    'Добавление/обновление доплаты через manage_tariffs.php (ON DUPLICATE KEY UPDATE)'
                                );
                            } else {
                                // Это было новое добавление
                                $auditLogger->logInsert(
                                    'salary_additions',
                                    $code,
                                    $new_values,
                                    'Добавление доплаты через manage_tariffs.php'
                                );
                            }
                        } else {
                            // Логируем редактирование доплаты
                            $changed_fields = [];
                            if ($old_values && $new_values) {
                                if ($old_values['amount'] != $new_values['amount']) {
                                    $changed_fields[] = 'amount';
                                }
                                if ($old_values['code'] != $new_values['code']) {
                                    $changed_fields[] = 'code';
                                }
                            }
                            $auditLogger->logUpdate(
                                'salary_additions',
                                $code,
                                $old_values,
                                $new_values,
                                $changed_fields,
                                'Изменение доплаты через manage_tariffs.php'
                            );
                        }
                        
                        manage_redirect('manage_tariffs.php?success=addition_' . ($addition_action === 'add' ? 'added' : 'updated'));
                    } else {
                        $error = 'Ошибка сохранения доплаты: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        } elseif ($addition_action === 'delete') {
            $code = trim($_POST['code'] ?? '');
            
            global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
            $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
            
            if ($mysqli->connect_errno) {
                $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
            } else {
                $auditLogger = new AuditLogger($mysqli);
                
                // Получаем данные доплаты перед удалением для логирования
                $old_values = null;
                $old_stmt = $mysqli->prepare("SELECT * FROM salary_additions WHERE code = ?");
                $old_stmt->bind_param('s', $code);
                $old_stmt->execute();
                $old_result = $old_stmt->get_result();
                if ($old_row = $old_result->fetch_assoc()) {
                    $old_values = $old_row;
                }
                $old_stmt->close();
                
                $stmt = $mysqli->prepare("DELETE FROM salary_additions WHERE code = ?");
                $stmt->bind_param('s', $code);
                
                if ($stmt->execute()) {
                    // Логируем удаление доплаты
                    if ($old_values) {
                        $auditLogger->logDelete(
                            'salary_additions',
                            $code,
                            $old_values,
                            'Удаление доплаты через manage_tariffs.php'
                        );
                    }
                    
                    manage_redirect('manage_tariffs.php?success=addition_deleted');
                } else {
                    $error = 'Ошибка удаления доплаты: ' . $stmt->error;
                }
                $stmt->close();
                $mysqli->close();
            }
        }
    }
    
    // Обработка действий с почасовыми тарифами для рабочих
    if (isset($_POST['hourly_action'])) {
        $hourly_action = $_POST['hourly_action'];
        
        if ($hourly_action === 'add' || $hourly_action === 'edit') {
            $hourly_name = trim($_POST['hourly_name'] ?? '');
            $rate_per_hour = floatval($_POST['rate_per_hour'] ?? 0);
            $sort_order = isset($_POST['sort_order']) && $_POST['sort_order'] !== '' ? intval($_POST['sort_order']) : 0;
            
            if (empty($hourly_name)) {
                $error = 'Название почасового тарифа не может быть пустым';
            } else {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $auditLogger = new AuditLogger($mysqli);
                    
                    if ($hourly_action === 'add') {
                        $stmt = $mysqli->prepare("INSERT INTO salary_hourly_worker_rates (name, rate_per_hour, sort_order) VALUES (?, ?, ?)");
                        $stmt->bind_param('sdi', $hourly_name, $rate_per_hour, $sort_order);
                    } else {
                        $hid = intval($_POST['hourly_id'] ?? 0);
                        $old_values = null;
                        $old_stmt = $mysqli->prepare("SELECT * FROM salary_hourly_worker_rates WHERE id = ?");
                        $old_stmt->bind_param('i', $hid);
                        $old_stmt->execute();
                        $old_result = $old_stmt->get_result();
                        if ($old_row = $old_result->fetch_assoc()) {
                            $old_values = $old_row;
                        }
                        $old_stmt->close();
                        
                        $stmt = $mysqli->prepare("UPDATE salary_hourly_worker_rates SET name = ?, rate_per_hour = ?, sort_order = ? WHERE id = ?");
                        $stmt->bind_param('sdii', $hourly_name, $rate_per_hour, $sort_order, $hid);
                    }
                    
                    if ($stmt->execute()) {
                        if ($hourly_action === 'add') {
                            $new_id = $mysqli->insert_id;
                            $new_values = [
                                'id' => $new_id,
                                'name' => $hourly_name,
                                'rate_per_hour' => $rate_per_hour,
                                'sort_order' => $sort_order
                            ];
                            $auditLogger->logInsert(
                                'salary_hourly_worker_rates',
                                (string)$new_id,
                                $new_values,
                                'Добавление почасового тарифа через manage_tariffs.php'
                            );
                        } else {
                            $hid = intval($_POST['hourly_id'] ?? 0);
                            $new_stmt = $mysqli->prepare("SELECT * FROM salary_hourly_worker_rates WHERE id = ?");
                            $new_stmt->bind_param('i', $hid);
                            $new_stmt->execute();
                            $new_result = $new_stmt->get_result();
                            $new_values = $new_result->fetch_assoc();
                            $new_stmt->close();
                            if ($new_values && isset($old_values)) {
                                $changed_fields = [];
                                foreach ($new_values as $k => $v) {
                                    if (isset($old_values[$k]) && $old_values[$k] != $v) {
                                        $changed_fields[] = $k;
                                    }
                                }
                                $auditLogger->logUpdate(
                                    'salary_hourly_worker_rates',
                                    (string)$hid,
                                    $old_values,
                                    $new_values,
                                    $changed_fields,
                                    'Изменение почасового тарифа через manage_tariffs.php'
                                );
                            }
                        }
                        manage_redirect('manage_tariffs.php?success=hourly_' . ($hourly_action === 'add' ? 'added' : 'updated'));
                    } else {
                        $error = 'Ошибка сохранения почасового тарифа: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        } elseif ($hourly_action === 'delete') {
            $hid = intval($_POST['hourly_id'] ?? 0);
            if ($hid > 0) {
                global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
                $mysqli = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
                
                if ($mysqli->connect_errno) {
                    $error = 'Ошибка подключения к БД: ' . $mysqli->connect_error;
                } else {
                    $auditLogger = new AuditLogger($mysqli);
                    $old_stmt = $mysqli->prepare("SELECT * FROM salary_hourly_worker_rates WHERE id = ?");
                    $old_stmt->bind_param('i', $hid);
                    $old_stmt->execute();
                    $old_result = $old_stmt->get_result();
                    $old_values = $old_result->fetch_assoc();
                    $old_stmt->close();
                    
                    $stmt = $mysqli->prepare("DELETE FROM salary_hourly_worker_rates WHERE id = ?");
                    $stmt->bind_param('i', $hid);
                    
                    if ($stmt->execute()) {
                        if ($old_values) {
                            $auditLogger->logDelete(
                                'salary_hourly_worker_rates',
                                (string)$hid,
                                $old_values,
                                'Удаление почасового тарифа через manage_tariffs.php'
                            );
                        }
                        manage_redirect('manage_tariffs.php?success=hourly_deleted');
                    } else {
                        $error = 'Ошибка удаления почасового тарифа: ' . $stmt->error;
                    }
                    $stmt->close();
                    $mysqli->close();
                }
            }
        }
    }
}

// Таблицы тарифов / доплат / почасовых показываются только на «главной» вкладке страницы
$show_main_tariff_dashboard = (
    $action === 'list'
    && !$addition_action
    && $hourly_action !== 'add'
    && $hourly_action !== 'edit'
);

// Загружаем данные тарифа для редактирования
$tariff_data = null;
if ($action === 'edit' && $tariff_id) {
    $result = mysql_execute("SELECT * FROM salary_tariffs WHERE id = " . intval($tariff_id));
    $tariffs = [];
    while ($row = $result->fetch_assoc()) {
        $tariffs[] = $row;
    }
    $result->free();
    if (!empty($tariffs)) {
        $tariff_data = $tariffs[0];
    } else {
        $action = 'list';
    }
}

// Загружаем список тарифов (тяжёлый JOIN — только для главной панели)
$tariffs_list = [];
if ($show_main_tariff_dashboard) {
    try {
        $result = mysql_execute("SELECT st.*, COUNT(sfs.filter) as usage_count 
                                 FROM salary_tariffs st 
                                 LEFT JOIN salon_filter_structure sfs ON sfs.tariff_id = st.id 
                                 GROUP BY st.id 
                                 ORDER BY st.tariff_name");
        while ($row = $result->fetch_assoc()) {
            $tariffs_list[] = $row;
        }
        $result->free();
    } catch (Exception $e) {
        // Если поле build_complexity еще не добавлено, загружаем без него
        $result = mysql_execute("SELECT st.*, COUNT(sfs.filter) as usage_count 
                                 FROM salary_tariffs st 
                                 LEFT JOIN salon_filter_structure sfs ON sfs.tariff_id = st.id 
                                 GROUP BY st.id 
                                 ORDER BY st.tariff_name");
        while ($row = $result->fetch_assoc()) {
            if (!isset($row['build_complexity'])) {
                $row['build_complexity'] = null;
            }
            $tariffs_list[] = $row;
        }
        $result->free();
    }
}

// Загружаем данные доплаты для редактирования
$addition_data = null;
if ($addition_action === 'edit' && $addition_code) {
    global $mysql_host, $mysql_user, $mysql_user_pass, $mysql_database;
    $mysqli_temp = new mysqli($mysql_host, $mysql_user, $mysql_user_pass, $mysql_database);
    $escaped_code = $mysqli_temp->real_escape_string($addition_code);
    $mysqli_temp->close();
    
    $result = mysql_execute("SELECT * FROM salary_additions WHERE code = '" . $escaped_code . "'");
    $additions = [];
    while ($row = $result->fetch_assoc()) {
        $additions[] = $row;
    }
    $result->free();
    if (!empty($additions)) {
        $addition_data = $additions[0];
    } else {
        $addition_action = null;
    }
}

// Списки доплат и почасовых — только для главной панели (формы add/edit их не используют)
$additions_list = [];
$hourly_rates_list = [];
if ($show_main_tariff_dashboard) {
    $result = mysql_execute("SELECT * FROM salary_additions ORDER BY code");
    while ($row = $result->fetch_assoc()) {
        $additions_list[] = $row;
    }
    $result->free();

    try {
        $result = mysql_execute("SELECT * FROM salary_hourly_worker_rates ORDER BY sort_order, name");
        while ($row = $result->fetch_assoc()) {
            $hourly_rates_list[] = $row;
        }
        $result->free();
    } catch (Exception $e) {
        // Таблица может отсутствовать до первого запуска ensure_salary_warehouse_tables
    }
}

// Загружаем данные почасового тарифа для редактирования
$hourly_data = null;
if ($hourly_action === 'edit' && $hourly_id) {
    try {
        $result = mysql_execute("SELECT * FROM salary_hourly_worker_rates WHERE id = " . intval($hourly_id));
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        if (!empty($rows)) {
            $hourly_data = $rows[0];
        } else {
            $hourly_action = null;
        }
    } catch (Exception $e) {
        $hourly_action = null;
    }
}

$success_message = '';
if (isset($_GET['success'])) {
    $messages = [
        'added' => 'Тариф успешно добавлен',
        'updated' => 'Тариф успешно обновлен',
        'deleted' => 'Тариф успешно удален',
        'addition_added' => 'Доплата успешно добавлена',
        'addition_updated' => 'Доплата успешно обновлена',
        'addition_deleted' => 'Доплата успешно удалена',
        'hourly_added' => 'Почасовой тариф успешно добавлен',
        'hourly_updated' => 'Почасовой тариф успешно обновлен',
        'hourly_deleted' => 'Почасовой тариф успешно удален'
    ];
    $success_message = $messages[$_GET['success']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <title>Управление тарифами</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root{
            --bg:#f9fafb;
            --card:#ffffff;
            --muted:#5f6368;
            --text:#1f2937;
            --accent:#2563eb;
            --accent-2:#059669;
            --border:#e5e7eb;
            --danger:#dc2626;
            --radius:12px;
            --shadow:0 4px 12px rgba(0,0,0,.08);
        }
        *{box-sizing:border-box}
        html,body{height:100%}
        body{
            margin:0; background:var(--bg);
            color:var(--text); font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;
        }
        .container{max-width:1000px; margin:24px auto 64px; padding:0 16px;}
        header.top{
            display:flex; align-items:center; justify-content:space-between;
            padding:18px 20px; background:#fff; border:1px solid var(--border);
            border-radius:var(--radius); box-shadow:var(--shadow); margin-bottom:20px;
        }
        .title{font-size:18px; font-weight:700; letter-spacing:.2px}
        .card{
            background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
            box-shadow:var(--shadow); padding:18px; margin-bottom:16px;
        }
        .card h3{margin:0 0 12px; font-size:16px; font-weight:700}
        label{display:block; color:var(--muted); margin-bottom:6px; font-size:13px}
        input[type="text"], input[type="number"], select{
            width:100%; padding:10px 12px; border-radius:8px; border:1px solid var(--border);
            background:#fff; color:var(--text); outline:none;
            transition:border-color .15s, box-shadow .15s;
        }
        input[type="text"]:focus, input[type="number"]:focus, select:focus{
            border-color:var(--accent);
            box-shadow:0 0 0 2px rgba(37,99,235,.15);
        }
        .btn{
            border:1px solid transparent; background:var(--accent);
            color:white; padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer;
            transition:background .15s; text-decoration:none; display:inline-block;
        }
        .btn:hover{background:#1e4ed8}
        .btn.secondary{background:#f3f4f6; color:var(--text); border-color:var(--border)}
        .btn.secondary:hover{background:#e5e7eb}
        .btn.danger{background:var(--danger); color:white}
        .btn.danger:hover{background:#b91c1c}
        .btn.success{background:var(--accent-2); color:white}
        .btn.success:hover{background:#047857}
        .row-2{display:grid; gap:12px; grid-template-columns:1fr 1fr}
        .row-3{display:grid; gap:12px; grid-template-columns:repeat(3,1fr)}
        .actions{display:flex; gap:10px; margin-top:16px}
        .alert{
            padding:12px 16px; border-radius:8px; margin-bottom:16px;
        }
        .alert.success{background:#d1fae5; border:1px solid #10b981; color:#065f46}
        .alert.error{background:#fee2e2; border:1px solid #ef4444; color:#991b1b}
        table{
            width:100%; border-collapse:collapse; margin-top:12px;
        }
        table th, table td{
            padding:12px; text-align:left; border-bottom:1px solid var(--border);
        }
        table th{background:#f9fafb; font-weight:600; color:var(--muted); font-size:12px; text-transform:uppercase}
        table tr:hover{background:#f9fafb}
        .badge{
            display:inline-block; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:600;
        }
        .badge.normal{background:#dbeafe; color:#1e40af}
        .badge.fixed{background:#fef3c7; color:#92400e}
        .badge.hourly{background:#e0e7ff; color:#3730a3}
        @media(max-width:900px){
            .row-2,.row-3{grid-template-columns:1fr}
        }
    </style>
    <script>
        function toggleHelpInfo() {
            const helpInfo = document.getElementById('helpInfo');
            if (helpInfo.style.display === 'none' || helpInfo.style.display === '') {
                helpInfo.style.display = 'block';
            } else {
                helpInfo.style.display = 'none';
            }
        }
    </script>
</head>
<body>

<div class="container">
    <header class="top">
        <div class="title">Управление тарифами</div>
    </header>

    <?php if ($success_message): ?>
        <div class="alert success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($action === 'list' && !$addition_action && $hourly_action !== 'add' && $hourly_action !== 'edit'): ?>
        <!-- Кнопка показа справочной информации -->
        <div class="card" style="margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Управление тарифами и дополнениями</h3>
                <button onclick="toggleHelpInfo()" class="btn" style="background: #6b7280; padding: 8px 16px; font-size: 18px; line-height: 1;">
                    ?
                </button>
            </div>
        </div>

        <!-- Справочная информация -->
        <div id="helpInfo" class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3 style="margin: 0; color: white;">📊 Справочная информация: Тарифы и расчет заработной платы</h3>
                <button onclick="toggleHelpInfo()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 20px; line-height: 1;">×</button>
            </div>
            
            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">🎯 Базовая ставка</strong>
                <ul style="margin: 8px 0; padding-left: 20px; line-height: 1.8;">
                    <li>Каждому фильтру присваивается <strong>тариф</strong> из таблицы salary_tariffs</li>
                    <li>Тариф определяет базовую ставку (rate_per_unit) за единицу продукции</li>
                    <li>Тарифы бывают трех типов:</li>
                    <ul style="margin: 4px 0; padding-left: 20px;">
                        <li><strong>Обычный</strong> — стандартный тариф, к которому применяются доплаты</li>
                        <li><strong>Фиксированный (fixed)</strong> — фиксированная ставка, доплаты НЕ применяются</li>
                        <li><strong>Почасовый</strong> — расчет по часам работы, доплаты НЕ применяются</li>
                    </ul>
                </ul>
            </div>

            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px; margin-bottom: 12px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">💰 Доплаты (additions)</strong>
                <p style="margin: 8px 0;">К базовой ставке могут добавляться доплаты из таблицы salary_additions:</p>
                <ul style="margin: 8px 0; padding-left: 20px; line-height: 1.8;">
                    <li><strong>+Язычок</strong> (tongue_glue) — если у фильтра есть язычок (tail содержит 'языч')<br>
                    <em style="font-size:12px; opacity:0.9;">⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                    
                    <li><strong>+Трапеция</strong> (edge_trim_glue) — если форма фильтра 'трапеция'<br>
                    <em style="font-size:12px; opacity:0.9;">⚠️ НЕ применяется для fixed и почасовых тарифов</em></li>
                    
                    <li><strong>+Надрезы</strong> (edge_cuts) — если у фильтра есть надрезы (has_edge_cuts)<br>
                    <em style="font-size:12px; opacity:0.9;">✅ Применяется для ВСЕХ тарифов кроме почасовых!</em></li>
                </ul>
            </div>

            <div style="background: rgba(255,255,255,0.1); padding: 16px; border-radius: 8px;">
                <strong style="color: #fbbf24; display: block; margin-bottom: 8px;">📐 Формула расчета</strong>
                <p style="margin: 8px 0; font-family: monospace; background: rgba(0,0,0,0.2); padding: 8px; border-radius: 4px;">
                    Итоговая ставка = Базовая ставка + Доплаты (если применимо)
                </p>
                <p style="margin: 8px 0; font-size: 13px; opacity: 0.9;">
                    Заработная плата = Итоговая ставка × Количество фильтров (или часы для почасовых тарифов)
                </p>
            </div>
        </div>

        <!-- Список тарифов -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                <h3 style="margin:0">Список тарифов</h3>
                <a href="?action=add" class="btn success">+ Добавить тариф</a>
            </div>
            
            <?php if (empty($tariffs_list)): ?>
                <p style="color:var(--muted); text-align:center; padding:40px">Тарифы не найдены</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Ставка за единицу</th>
                            <th>Тип</th>
                            <th>Сложность сборки</th>
                            <th>Используется</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tariffs_list as $tariff): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tariff['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($tariff['tariff_name']); ?></strong></td>
                                <td><?php echo number_format($tariff['rate_per_unit'], 2, '.', ' '); ?></td>
                                <td>
                                    <?php
                                    $type_labels = [
                                        'normal' => ['text' => 'Обычный', 'class' => 'normal'],
                                        'fixed' => ['text' => 'Фиксированный', 'class' => 'fixed'],
                                        'hourly' => ['text' => 'Почасовый', 'class' => 'hourly']
                                    ];
                                    // Если type пустой или NULL, используем 'normal' по умолчанию
                                    $tariff_type = !empty($tariff['type']) ? $tariff['type'] : 'normal';
                                    $type_info = $type_labels[$tariff_type] ?? ['text' => 'Обычный', 'class' => 'normal'];
                                    ?>
                                    <span class="badge <?php echo $type_info['class']; ?>"><?php echo htmlspecialchars($type_info['text']); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($tariff['build_complexity'])): ?>
                                        <?php echo number_format($tariff['build_complexity'], 2, '.', ' '); ?> шт/смену
                                    <?php else: ?>
                                        <span style="color:var(--muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo intval($tariff['usage_count']); ?> фильтров</td>
                                <td>
                                    <div style="display:flex; gap:8px">
                                        <a href="?action=edit&id=<?php echo $tariff['id']; ?>" class="btn secondary" style="padding:6px 12px; font-size:12px">Редактировать</a>
                                        <?php if ($tariff['usage_count'] == 0): ?>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Вы уверены, что хотите удалить этот тариф?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="tariff_id" value="<?php echo $tariff['id']; ?>">
                                                <button type="submit" class="btn danger" style="padding:6px 12px; font-size:12px">Удалить</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:var(--muted); font-size:12px" title="Тариф используется в фильтрах">Удалить нельзя</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Управление дополнениями -->
        <?php if (!$addition_action): ?>
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                <h3 style="margin:0">Управление дополнениями</h3>
                <a href="?addition_action=add" class="btn success">+ Добавить доплату</a>
            </div>
            
            <div style="background:#f0f9ff; border-left:4px solid #2563eb; padding:12px; margin-bottom:16px; border-radius:4px; font-size:13px; color:#1e40af;">
                <strong>💡 Подсказка:</strong> Доплаты применяются автоматически при расчете заработной платы. Код доплаты должен соответствовать стандартным кодам: <code>tongue_glue</code>, <code>edge_trim_glue</code>, <code>edge_cuts</code>.
            </div>
            
            <?php if (empty($additions_list)): ?>
                <p style="color:var(--muted); text-align:center; padding:40px">Доплаты не найдены</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Название</th>
                            <th>Сумма доплаты</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $addition_names = [
                            'tongue_glue' => 'Язычок',
                            'edge_trim_glue' => 'Трапеция',
                            'edge_cuts' => 'Надрезы'
                        ];
                        foreach ($additions_list as $addition): 
                            $name = $addition_names[$addition['code']] ?? $addition['code'];
                        ?>
                            <tr>
                                <td><code style="background:#f3f4f6; padding:4px 8px; border-radius:4px; font-size:12px"><?php echo htmlspecialchars($addition['code']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($name); ?></strong></td>
                                <td><?php echo number_format($addition['amount'], 2, '.', ' '); ?></td>
                                <td>
                                    <div style="display:flex; gap:8px">
                                        <a href="?addition_action=edit&addition_code=<?php echo urlencode($addition['code']); ?>" class="btn secondary" style="padding:6px 12px; font-size:12px">Редактировать</a>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Вы уверены, что хотите удалить эту доплату?');">
                                            <input type="hidden" name="addition_action" value="delete">
                                            <input type="hidden" name="code" value="<?php echo htmlspecialchars($addition['code']); ?>">
                                            <button type="submit" class="btn danger" style="padding:6px 12px; font-size:12px">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Почасовые тарифы для рабочих -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px">
                <h3 style="margin:0">Почасовые тарифы для рабочих</h3>
                <a href="?hourly_action=add" class="btn success">+ Добавить почасовой тариф</a>
            </div>
            <p style="color:var(--muted); font-size:13px; margin:0 0 12px;">Тарифы за час работы (для почасовой оплаты смен).</p>
            <?php if (empty($hourly_rates_list)): ?>
                <p style="color:var(--muted); text-align:center; padding:40px">Почасовые тарифы не добавлены</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Ставка за час (грн)</th>
                            <th>Порядок</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hourly_rates_list as $hr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($hr['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($hr['name']); ?></strong></td>
                                <td><?php echo number_format($hr['rate_per_hour'], 2, '.', ' '); ?></td>
                                <td><?php echo (int)$hr['sort_order']; ?></td>
                                <td>
                                    <div style="display:flex; gap:8px">
                                        <a href="?hourly_action=edit&hourly_id=<?php echo (int)$hr['id']; ?>" class="btn secondary" style="padding:6px 12px; font-size:12px">Редактировать</a>
                                        <form method="post" style="display:inline" onsubmit="return confirm('Удалить этот почасовой тариф?');">
                                            <input type="hidden" name="hourly_action" value="delete">
                                            <input type="hidden" name="hourly_id" value="<?php echo (int)$hr['id']; ?>">
                                            <button type="submit" class="btn danger" style="padding:6px 12px; font-size:12px">Удалить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php elseif ($action === 'add' || $action === 'edit'): ?>
        <!-- Форма добавления/редактирования -->
        <div class="card">
            <h3><?php echo $action === 'add' ? 'Добавить тариф' : 'Редактировать тариф'; ?></h3>
            
            <form method="post">
                <input type="hidden" name="action" value="<?php echo $action; ?>">
                <?php if ($action === 'edit' && $tariff_data): ?>
                    <input type="hidden" name="tariff_id" value="<?php echo htmlspecialchars($tariff_data['id']); ?>">
                <?php endif; ?>
                
                <div class="row-3">
                    <div>
                        <label>Название тарифа *</label>
                        <input type="text" name="tariff_name" required 
                               value="<?php echo htmlspecialchars($tariff_data['tariff_name'] ?? ''); ?>" 
                               placeholder="Например: Стандартный">
                    </div>
                    <div>
                        <label>Ставка за единицу *</label>
                        <input type="number" name="rate_per_unit" step="0.01" required 
                               value="<?php echo htmlspecialchars($tariff_data['rate_per_unit'] ?? '0'); ?>" 
                               placeholder="0.00">
                    </div>
                    <div>
                        <label>Тип тарифа *</label>
                        <select name="type" required>
                            <option value="normal" <?php echo ($tariff_data['type'] ?? 'normal') === 'normal' ? 'selected' : ''; ?>>Обычный</option>
                            <option value="fixed" <?php echo ($tariff_data['type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Фиксированный</option>
                            <option value="hourly" <?php echo ($tariff_data['type'] ?? '') === 'hourly' ? 'selected' : ''; ?>>Почасовый</option>
                        </select>
                    </div>
                </div>
                
                <div class="row-2" style="margin-top:12px">
                    <div>
                        <label>Сложность сборки (шт/смену)</label>
                        <input type="number" name="build_complexity" step="0.01" 
                               value="<?php echo htmlspecialchars($tariff_data['build_complexity'] ?? ''); ?>" 
                               placeholder="Например: 600">
                        <small style="color:var(--muted); font-size:11px; margin-top:4px; display:block">Количество фильтров, которое можно собрать за смену</small>
                    </div>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn success">Сохранить</button>
                    <a href="manage_tariffs.php" class="btn secondary">Отмена</a>
                </div>
            </form>
        </div>
    <?php elseif ($addition_action === 'add' || $addition_action === 'edit'): ?>
        <!-- Форма добавления/редактирования доплаты -->
        <div class="card">
            <h3><?php echo $addition_action === 'add' ? 'Добавить доплату' : 'Редактировать доплату'; ?></h3>
            
            <form method="post">
                <input type="hidden" name="addition_action" value="<?php echo $addition_action; ?>">
                <?php if ($addition_action === 'edit' && $addition_data): ?>
                    <input type="hidden" name="old_code" value="<?php echo htmlspecialchars($addition_data['code']); ?>">
                <?php endif; ?>
                
                <div class="row-2">
                    <div>
                        <label>Код доплаты *</label>
                        <select name="code" required <?php echo ($addition_action === 'edit') ? 'disabled' : ''; ?> style="<?php echo ($addition_action === 'edit') ? 'background:#f3f4f6;' : ''; ?>">
                            <option value="">— Выберите код —</option>
                            <option value="tongue_glue" <?php echo ($addition_data['code'] ?? '') === 'tongue_glue' ? 'selected' : ''; ?>>tongue_glue (Язычок)</option>
                            <option value="edge_trim_glue" <?php echo ($addition_data['code'] ?? '') === 'edge_trim_glue' ? 'selected' : ''; ?>>edge_trim_glue (Трапеция)</option>
                            <option value="edge_cuts" <?php echo ($addition_data['code'] ?? '') === 'edge_cuts' ? 'selected' : ''; ?>>edge_cuts (Надрезы)</option>
                        </select>
                        <?php if ($addition_action === 'edit'): ?>
                            <input type="hidden" name="code" value="<?php echo htmlspecialchars($addition_data['code']); ?>">
                            <small style="color:var(--muted); font-size:11px; margin-top:4px; display:block">Код нельзя изменить при редактировании</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label>Сумма доплаты *</label>
                        <input type="number" name="amount" step="0.01" required 
                               value="<?php echo htmlspecialchars($addition_data['amount'] ?? '0'); ?>" 
                               placeholder="0.00">
                    </div>
                </div>
                
                <div style="background:#f9fafb; padding:12px; border-radius:8px; margin-top:16px; font-size:12px; color:var(--muted);">
                    <strong>Примечание:</strong> Доплаты применяются автоматически при расчете заработной платы в зависимости от характеристик фильтра и типа тарифа.
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn success">Сохранить</button>
                    <a href="manage_tariffs.php" class="btn secondary">Отмена</a>
                </div>
            </form>
        </div>
    <?php elseif ($hourly_action === 'add' || $hourly_action === 'edit'): ?>
        <!-- Форма добавления/редактирования почасового тарифа -->
        <div class="card">
            <h3><?php echo $hourly_action === 'add' ? 'Добавить почасовой тариф' : 'Редактировать почасовой тариф'; ?></h3>
            
            <form method="post">
                <input type="hidden" name="hourly_action" value="<?php echo $hourly_action; ?>">
                <?php if ($hourly_action === 'edit' && $hourly_data): ?>
                    <input type="hidden" name="hourly_id" value="<?php echo (int)$hourly_data['id']; ?>">
                <?php endif; ?>
                
                <div class="row-3">
                    <div>
                        <label>Название *</label>
                        <input type="text" name="hourly_name" required 
                               value="<?php echo htmlspecialchars($hourly_data['name'] ?? ''); ?>" 
                               placeholder="Например: Сборщик">
                    </div>
                    <div>
                        <label>Ставка за час (грн) *</label>
                        <input type="number" name="rate_per_hour" step="0.01" min="0" required 
                               value="<?php echo htmlspecialchars($hourly_data['rate_per_hour'] ?? '0'); ?>" 
                               placeholder="0.00">
                    </div>
                    <div>
                        <label>Порядок сортировки</label>
                        <input type="number" name="sort_order" min="0" 
                               value="<?php echo htmlspecialchars($hourly_data['sort_order'] ?? '0'); ?>" 
                               placeholder="0">
                    </div>
                </div>
                
                <div class="actions">
                    <button type="submit" class="btn success">Сохранить</button>
                    <a href="manage_tariffs.php" class="btn secondary">Отмена</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

</body>
</html>

