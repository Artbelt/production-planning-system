<?php
/**
 * Конфигурация единой системы авторизации
 * Версия: 1.0
 * Дата: 2 октября 2025
 */

// Предотвращение прямого доступа
if (!defined('AUTH_SYSTEM')) {
    die('Прямой доступ запрещен');
}

// =====================================================
// Настройки базы данных
// =====================================================
define('AUTH_DB_CONFIG', [
    'host' => '127.0.0.1',
    'database' => 'plan', // Используем существующую БД
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
]);

// =====================================================
// Настройки безопасности
// =====================================================
define('AUTH_SECURITY', [
    // Сессии
    'session_lifetime' => 24 * 3600, // 24 часа
    'session_name' => 'AUTH_SESSION_ID',
    'session_cookie_secure' => false, // true для HTTPS
    'session_cookie_httponly' => true,
    'session_cookie_samesite' => 'Strict',
    
    // Блокировка аккаунтов
    'max_failed_attempts' => 5,
    'lockout_duration' => 30 * 60, // 30 минут
    'lockout_progressive' => true, // Увеличивать время блокировки
    
    // Пароли
    'password_min_length' => 6,
    'password_require_numbers' => false,
    'password_require_symbols' => false,
    'password_hash_algo' => PASSWORD_DEFAULT,
    'password_hash_cost' => 12,
    
    // Токены
    'reset_token_length' => 32,
    'reset_token_lifetime' => 24 * 3600, // 24 часа
    'csrf_token_length' => 32,
    
    // SMS коды
    'sms_code_length' => 6,
    'sms_code_lifetime' => 10 * 60, // 10 минут
    'sms_resend_delay' => 60, // 1 минута между отправками
]);

// =====================================================
// Настройки SMS сервиса
// =====================================================
define('SMS_CONFIG', [
    'provider' => 'smsc', // smsc, smsru, test
    'test_mode' => true, // Для разработки
    
    // SMSC.ru
    'smsc' => [
        'login' => 'your_login',
        'password' => 'your_password',
        'sender' => 'AlphaFilter',
        'api_url' => 'https://smsc.ru/sys/send.php'
    ],
    
    // SMS.ru
    'smsru' => [
        'api_id' => 'your_api_id',
        'sender' => 'AlphaFilter',
        'api_url' => 'https://sms.ru/sms/send'
    ],
    
    // Тестовый режим (логи в файл)
    'test' => [
        'log_file' => __DIR__ . '/logs/sms_test.log'
    ]
]);

// =====================================================
// Настройки цехов
// =====================================================
define('DEPARTMENTS', [
    'U1' => [
        'name' => 'Цех У1',
        'description' => 'Производственный цех У1',
        'system_path' => '/plan_U1/',
        'is_active' => true
    ],
    'U2' => [
        'name' => 'Цех У2', 
        'description' => 'Производственный цех У2',
        'system_path' => '/plan/',
        'is_active' => true
    ],
    'U3' => [
        'name' => 'Цех У3',
        'description' => 'Производственный цех У3', 
        'system_path' => '/plan_U3/',
        'is_active' => true
    ],
    'U4' => [
        'name' => 'Цех У4',
        'description' => 'Производственный цех У4',
        'system_path' => '/plan_U4/',
        'is_active' => true
    ],
    'U5' => [
        'name' => 'Цех У5',
        'description' => 'Производственный цех У5',
        'system_path' => '/plan_U5/',
        'is_active' => true
    ],
    'U6' => [
        'name' => 'Цех У6',
        'description' => 'Производственный цех У6',
        'system_path' => '/plan_U6/',
        'is_active' => false
    ],
    'ZU' => [
        'name' => 'Заготовительный участок',
        'description' => 'Заготовительный участок',
        'system_path' => '/plan_ZU/',
        'is_active' => false
    ]
]);

// =====================================================
// Права доступа по ролям
// =====================================================
define('ROLE_PERMISSIONS', [
    'worker' => [
        'view_orders',
        'update_order_status', 
        'view_production_plan',
        'mark_task_complete',
        'view_own_tasks'
    ],
    
    'manager' => [
        'view_orders',
        'create_orders',
        'edit_orders', 
        'delete_orders',
        'view_production_plan',
        'edit_production_plan',
        'view_reports',
        'manage_filters',
        'assign_tasks'
    ],
    
    'supervisor' => [
        '*_orders', // Все права на заказы
        '*_production_plan', // Все права на планы
        '*_reports', // Все права на отчеты
        '*_filters', // Все права на фильтры
        'manage_workers',
        'view_analytics',
        'export_data',
        'manage_department_users'
    ],
    
    'director' => [
        '*', // Все права
        'manage_users',
        'system_settings',
        'view_all_departments',
        'financial_reports',
        'manage_roles',
        'view_system_logs'
    ]
]);

// =====================================================
// Настройки логирования
// =====================================================
define('LOG_CONFIG', [
    'enabled' => true,
    'log_failed_attempts' => true,
    'log_successful_logins' => true,
    'log_department_switches' => true,
    'log_permission_checks' => false, // Может создать много записей
    'max_log_age_days' => 180, // 6 месяцев
    
    // Файловые логи (дополнительно к БД)
    'file_logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/logs/',
        'max_file_size' => 10 * 1024 * 1024, // 10MB
        'rotate_files' => true
    ]
]);

// =====================================================
// Настройки интерфейса
// =====================================================
define('UI_CONFIG', [
    'app_name' => 'AlphaFilter - Система планирования',
    'company_name' => 'AlphaFilter',
    'support_phone' => '+7 (900) 000-00-00',
    'support_email' => 'support@alphafilter.ru',
    
    // Темы оформления
    'theme' => 'default',
    'available_themes' => ['default', 'dark', 'blue'],
    
    // Языки
    'default_language' => 'ru',
    'available_languages' => ['ru', 'en'],
    
    // Настройки форм
    'remember_me_duration' => 30 * 24 * 3600, // 30 дней
    'show_last_login' => true,
    'show_online_users' => true
]);

// =====================================================
// Настройки разработки
// =====================================================
define('DEV_CONFIG', [
    'debug_mode' => true, // Отключить в продакшене!
    'show_sql_errors' => true,
    'log_sql_queries' => false,
    'bypass_sms_verification' => true, // Для тестирования
    'test_users_enabled' => true,
    
    // Тестовые пользователи
    'test_users' => [
        [
            'phone' => '+79001234567',
            'password' => 'test123',
            'full_name' => 'Тестовый Рабочий',
            'departments' => ['U2' => 'worker']
        ],
        [
            'phone' => '+79001234568', 
            'password' => 'test123',
            'full_name' => 'Тестовый Менеджер',
            'departments' => ['U2' => 'manager', 'U3' => 'manager']
        ]
    ]
]);

// =====================================================
// Пути к файлам системы
// =====================================================
define('AUTH_PATHS', [
    'root' => __DIR__,
    'includes' => __DIR__ . '/includes/',
    'templates' => __DIR__ . '/templates/',
    'assets' => __DIR__ . '/assets/',
    'logs' => __DIR__ . '/logs/',
    'uploads' => __DIR__ . '/uploads/',
    
    // URL пути
    'base_url' => '/auth/',
    'assets_url' => '/auth/assets/',
    'api_url' => '/auth/api/'
]);

// =====================================================
// Автозагрузка классов
// =====================================================
spl_autoload_register(function ($class) {
    $prefix = 'Auth\\';
    $base_dir = __DIR__ . '/classes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// =====================================================
// Функции инициализации
// =====================================================

/**
 * Инициализация системы авторизации
 */
function initAuthSystem() {
    // Настройка сессий
    ini_set('session.gc_maxlifetime', AUTH_SECURITY['session_lifetime']);
    ini_set('session.cookie_lifetime', AUTH_SECURITY['session_lifetime']);
    ini_set('session.cookie_httponly', AUTH_SECURITY['session_cookie_httponly']);
    ini_set('session.cookie_secure', AUTH_SECURITY['session_cookie_secure']);
    ini_set('session.cookie_samesite', AUTH_SECURITY['session_cookie_samesite']);
    
    session_name(AUTH_SECURITY['session_name']);
    
    // Создание необходимых директорий
    $dirs = [
        AUTH_PATHS['logs'],
        AUTH_PATHS['uploads'],
        AUTH_PATHS['templates'] . '/cache/'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    // Настройка часового пояса
    date_default_timezone_set('Europe/Moscow');
    
    // Настройка обработки ошибок
    if (DEV_CONFIG['debug_mode']) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } else {
        error_reporting(0);
        ini_set('display_errors', 0);
    }
}

// =====================================================
// Константы для проверки включения
// =====================================================
define('AUTH_CONFIG_LOADED', true);
define('AUTH_VERSION', '1.0.0');
define('AUTH_BUILD_DATE', '2025-10-02');

?>
















