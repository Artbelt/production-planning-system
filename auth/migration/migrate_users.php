<?php
/**
 * Скрипт миграции пользователей из БД plan в БД auth
 * Версия: 1.0
 * Дата: 2 октября 2025
 */

define('AUTH_SYSTEM', true);
require_once '../includes/config.php';

// Проверка запуска из командной строки или с правами администратора
if (php_sapi_name() !== 'cli' && !isset($_GET['admin_key'])) {
    die('Доступ запрещен. Используйте командную строку или добавьте ?admin_key=your_secret_key');
}

echo "=== Миграция пользователей из БД plan в БД auth ===\n\n";

try {
    // Подключение к БД auth
    $authConfig = AUTH_DB_CONFIG;
    $authDsn = "mysql:host={$authConfig['host']};dbname={$authConfig['database']};charset={$authConfig['charset']}";
    $authDb = new PDO($authDsn, $authConfig['username'], $authConfig['password'], $authConfig['options']);
    
    // Подключение к БД plan
    $planConfig = PLAN_DB_CONFIG;
    $planDsn = "mysql:host={$planConfig['host']};dbname={$planConfig['database']};charset={$planConfig['charset']}";
    $planDb = new PDO($planDsn, $planConfig['username'], $planConfig['password']);
    
    echo "✅ Подключение к базам данных успешно\n";
    
    // Получение пользователей из старой БД
    $oldUsers = $planDb->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "📊 Найдено пользователей в БД plan: " . count($oldUsers) . "\n\n";
    
    if (empty($oldUsers)) {
        echo "⚠️  Пользователи не найдены. Создаем тестовых пользователей...\n";
        createTestUsers($authDb);
        exit;
    }
    
    $migrated = 0;
    $errors = 0;
    
    foreach ($oldUsers as $oldUser) {
        try {
            echo "Миграция пользователя: {$oldUser['user']}... ";
            
            // Генерация номера телефона (нужно будет заполнить вручную)
            $phone = generatePhoneForUser($oldUser['user']);
            
            // Хеширование пароля
            $passwordHash = password_hash($oldUser['pass'], PASSWORD_DEFAULT);
            
            // Создание пользователя в новой БД
            $sql = "INSERT INTO auth_users (phone, password_hash, full_name, is_active, is_verified) 
                    VALUES (?, ?, ?, 1, 1)";
            
            $stmt = $authDb->prepare($sql);
            $stmt->execute([
                $phone,
                $passwordHash,
                $oldUser['user'] // Временно используем username как full_name
            ]);
            
            $newUserId = $authDb->lastInsertId();
            
            // Миграция прав доступа к цехам
            $departments = ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'ZU'];
            $assignedDepartments = 0;
            
            foreach ($departments as $dept) {
                if (isset($oldUser[$dept]) && (int)$oldUser[$dept] > 0) {
                    // Определение роли (пока все как менеджеры)
                    $roleId = 2; // manager
                    
                    $sql = "INSERT INTO auth_user_departments (user_id, department_code, role_id) 
                            VALUES (?, ?, ?)";
                    
                    $stmt = $authDb->prepare($sql);
                    $stmt->execute([$newUserId, $dept, $roleId]);
                    $assignedDepartments++;
                }
            }
            
            echo "✅ (ID: {$newUserId}, цехов: {$assignedDepartments})\n";
            $migrated++;
            
        } catch (Exception $e) {
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n=== Результаты миграции ===\n";
    echo "✅ Успешно мигрировано: {$migrated}\n";
    echo "❌ Ошибок: {$errors}\n";
    
    if ($migrated > 0) {
        echo "\n⚠️  ВАЖНО: Обновите номера телефонов пользователей!\n";
        echo "Сейчас используются автоматически сгенерированные номера.\n";
        
        // Показать список для обновления
        echo "\n📋 Список пользователей для обновления:\n";
        $users = $authDb->query("SELECT id, phone, full_name FROM auth_users ORDER BY id")->fetchAll();
        
        foreach ($users as $user) {
            echo "ID {$user['id']}: {$user['full_name']} -> {$user['phone']}\n";
        }
        
        echo "\nДля обновления используйте:\n";
        echo "UPDATE auth_users SET phone = '+79001234567', full_name = 'Реальное ФИО' WHERE id = 1;\n";
    }
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Генерация номера телефона для пользователя
 */
function generatePhoneForUser($username) {
    // Генерируем уникальный номер на основе имени пользователя
    $hash = crc32($username);
    $number = abs($hash) % 10000000; // 7 цифр
    return '+7900' . str_pad($number, 7, '0', STR_PAD_LEFT);
}

/**
 * Создание тестовых пользователей
 */
function createTestUsers($authDb) {
    $testUsers = [
        [
            'phone' => '+380995527932',
            'password' => 'password',
            'full_name' => 'Администратор системы',
            'departments' => ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'ZU'],
            'role' => 4 // director
        ],
        [
            'phone' => '+79001234567',
            'password' => 'test123',
            'full_name' => 'Тестовый Рабочий',
            'departments' => ['U2'],
            'role' => 1 // worker
        ],
        [
            'phone' => '+79001234568',
            'password' => 'test123',
            'full_name' => 'Тестовый Менеджер',
            'departments' => ['U2', 'U3'],
            'role' => 2 // manager
        ],
        [
            'phone' => '+79001234569',
            'password' => 'test123',
            'full_name' => 'Тестовый Мастер',
            'departments' => ['U3', 'U4', 'U5'],
            'role' => 3 // supervisor
        ]
    ];
    
    foreach ($testUsers as $user) {
        try {
            // Создание пользователя
            $sql = "INSERT INTO auth_users (phone, password_hash, full_name, is_active, is_verified) 
                    VALUES (?, ?, ?, 1, 1)";
            
            $stmt = $authDb->prepare($sql);
            $stmt->execute([
                $user['phone'],
                password_hash($user['password'], PASSWORD_DEFAULT),
                $user['full_name']
            ]);
            
            $userId = $authDb->lastInsertId();
            
            // Назначение доступа к цехам
            foreach ($user['departments'] as $dept) {
                $sql = "INSERT INTO auth_user_departments (user_id, department_code, role_id) 
                        VALUES (?, ?, ?)";
                
                $stmt = $authDb->prepare($sql);
                $stmt->execute([$userId, $dept, $user['role']]);
            }
            
            echo "✅ Создан тестовый пользователь: {$user['full_name']} ({$user['phone']})\n";
            
        } catch (Exception $e) {
            echo "❌ Ошибка создания пользователя {$user['full_name']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Тестовые пользователи созданы!\n";
    echo "\nДанные для входа:\n";
    echo "Администратор: +380995527932 / password\n";
    echo "Рабочий: +79001234567 / test123\n";
    echo "Менеджер: +79001234568 / test123\n";
    echo "Мастер: +79001234569 / test123\n";
}

echo "\n✅ Миграция завершена!\n";

?>
