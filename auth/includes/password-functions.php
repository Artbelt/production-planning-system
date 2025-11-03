<?php
/**
 * Функции для работы с паролями
 */

if (!defined('AUTH_SYSTEM')) {
    die('Прямой доступ запрещен');
}

/**
 * Генерация базового пароля из номера телефона
 */
function generateDefaultPassword($phone) {
    // Извлекаем только цифры из номера
    $digits = preg_replace('/\D/', '', $phone);
    
    // Берем последние 4 цифры
    $lastFour = substr($digits, -4);
    
    // Если меньше 4 цифр, дополняем нулями
    if (strlen($lastFour) < 4) {
        $lastFour = str_pad($lastFour, 4, '0', STR_PAD_LEFT);
    }
    
    return $lastFour;
}

/**
 * Проверка, является ли пароль базовым для данного телефона
 */
function isDefaultPassword($password, $phone) {
    return $password === generateDefaultPassword($phone);
}

/**
 * Проверка политики паролей
 */
function validatePasswordPolicy($password, $phone = null) {
    $errors = [];
    
    // Минимальная длина
    if (strlen($password) < 4) {
        $errors[] = 'Пароль должен содержать минимум 4 символа';
    }
    
    // Максимальная длина
    if (strlen($password) > 50) {
        $errors[] = 'Пароль не должен превышать 50 символов';
    }
    
    // Не должен быть только цифрами (кроме базового пароля)
    if (strlen($password) > 4 && ctype_digit($password)) {
        $errors[] = 'Пароль не должен состоять только из цифр';
    }
    
    // Запрещенные пароли
    $forbidden = ['password', '123456', '1234', 'qwerty', 'admin'];
    if (in_array(strtolower($password), $forbidden)) {
        $errors[] = 'Этот пароль слишком простой и запрещен';
    }
    
    // Не должен совпадать с номером телефона
    if ($phone && $password === preg_replace('/\D/', '', $phone)) {
        $errors[] = 'Пароль не должен совпадать с номером телефона';
    }
    
    return $errors;
}

/**
 * Смена пароля пользователя
 */
function changeUserPassword($userId, $newPassword, $currentPassword = null) {
    $db = Database::getInstance();
    
    try {
        // Получаем данные пользователя
        $user = $db->selectOne("SELECT * FROM auth_users WHERE id = ?", [$userId]);
        
        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }
        
        // Если указан текущий пароль, проверяем его
        if ($currentPassword !== null) {
            if (!password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'error' => 'Неверный текущий пароль'];
            }
        }
        
        // Проверяем политику паролей
        $policyErrors = validatePasswordPolicy($newPassword, $user['phone']);
        if (!empty($policyErrors)) {
            return ['success' => false, 'error' => implode(', ', $policyErrors)];
        }
        
        // Проверяем, что новый пароль отличается от текущего
        if (password_verify($newPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Новый пароль должен отличаться от текущего'];
        }
        
        // Хешируем новый пароль
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Обновляем пароль в БД
        $sql = "UPDATE auth_users 
                SET password_hash = ?, 
                    password_changed_at = NOW(), 
                    is_default_password = ?
                WHERE id = ?";
        
        $isDefault = isDefaultPassword($newPassword, $user['phone']);
        
        $result = $db->update($sql, [$passwordHash, $isDefault, $userId]);
        
        if ($result !== false) {
            // Логируем смену пароля
            $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'password_changed', ?, ?, ?)", [
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'System',
                json_encode(['is_default_password' => $isDefault])
            ]);
            
            return ['success' => true, 'message' => 'Пароль успешно изменен'];
        } else {
            return ['success' => false, 'error' => 'Ошибка при сохранении пароля'];
        }
        
    } catch (Exception $e) {
        error_log("Password change error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Системная ошибка при смене пароля'];
    }
}

/**
 * Проверка, нужно ли напомнить о смене пароля
 */
function shouldRemindPasswordChange($userId) {
    $db = Database::getInstance();
    
    $user = $db->selectOne("
        SELECT 
            is_default_password,
            password_changed_at,
            password_reminder_sent_at,
            password_reminder_count
        FROM auth_users 
        WHERE id = ?
    ", [$userId]);
    
    if (!$user) {
        return false;
    }
    
    // Если использует базовый пароль и прошло больше 7 дней с создания
    if ($user['is_default_password']) {
        $daysSinceChange = (time() - strtotime($user['password_changed_at'])) / (24 * 60 * 60);
        
        // Первое напоминание через 7 дней
        if ($daysSinceChange >= 7 && $user['password_reminder_count'] == 0) {
            return true;
        }
        
        // Повторные напоминания каждые 30 дней
        if ($user['password_reminder_sent_at']) {
            $daysSinceReminder = (time() - strtotime($user['password_reminder_sent_at'])) / (24 * 60 * 60);
            if ($daysSinceReminder >= 30) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Отметка об отправке напоминания
 */
function markPasswordReminderSent($userId) {
    $db = Database::getInstance();
    
    $db->update("
        UPDATE auth_users 
        SET password_reminder_sent_at = NOW(),
            password_reminder_count = password_reminder_count + 1
        WHERE id = ?
    ", [$userId]);
}

/**
 * Получение статистики по паролям
 */
function getPasswordStats() {
    $db = Database::getInstance();
    
    return [
        'total_users' => $db->selectOne("SELECT COUNT(*) as count FROM auth_users WHERE is_active = 1")['count'],
        'default_passwords' => $db->selectOne("SELECT COUNT(*) as count FROM auth_users WHERE is_default_password = 1 AND is_active = 1")['count'],
        'changed_passwords' => $db->selectOne("SELECT COUNT(*) as count FROM auth_users WHERE is_default_password = 0 AND is_active = 1")['count'],
        'need_reminders' => $db->selectOne("
            SELECT COUNT(*) as count FROM auth_users 
            WHERE is_default_password = 1 
            AND is_active = 1 
            AND (
                (password_reminder_count = 0 AND DATEDIFF(NOW(), password_changed_at) >= 7)
                OR 
                (password_reminder_sent_at IS NOT NULL AND DATEDIFF(NOW(), password_reminder_sent_at) >= 30)
            )
        ")['count']
    ];
}

/**
 * Создание пользователя с базовым паролем
 */
function createUserWithDefaultPassword($phone, $fullName, $email = null) {
    $db = Database::getInstance();
    
    try {
        // Генерируем базовый пароль
        $defaultPassword = generateDefaultPassword($phone);
        $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        
        // Создаем пользователя
        $userId = $db->insert("
            INSERT INTO auth_users (phone, password_hash, full_name, email, is_active, is_verified, is_default_password, password_changed_at) 
            VALUES (?, ?, ?, ?, 1, 1, 1, NOW())
        ", [$phone, $passwordHash, $fullName, $email]);
        
        if ($userId) {
            // Логируем создание
            $db->insert("INSERT INTO auth_logs (user_id, action, ip_address, user_agent, details) VALUES (?, 'user_created', ?, ?, ?)", [
                $userId,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'System',
                json_encode(['default_password' => $defaultPassword])
            ]);
            
            return [
                'success' => true, 
                'user_id' => $userId,
                'default_password' => $defaultPassword,
                'message' => 'Пользователь создан с базовым паролем'
            ];
        } else {
            return ['success' => false, 'error' => 'Ошибка создания пользователя'];
        }
        
    } catch (Exception $e) {
        error_log("User creation error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Системная ошибка при создании пользователя'];
    }
}

?>
















