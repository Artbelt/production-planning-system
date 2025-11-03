<?php
/**
 * Страница смены пароля
 */

define('AUTH_SYSTEM', true);
require_once 'includes/config.php';
require_once 'includes/auth-functions.php';
require_once 'includes/password-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();
$message = '';
$error = '';

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    header('Location: login.php');
    exit;
}

// Обработка формы смены пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Заполните все поля';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Новый пароль и подтверждение не совпадают';
    } else {
        $result = changeUserPassword($session['user_id'], $newPassword, $currentPassword);
        
        if ($result['success']) {
            $message = $result['message'];
            
            // Обновляем информацию о сессии
            $session = $auth->checkSession();
        } else {
            $error = $result['error'];
        }
    }
}

// Получаем информацию о пользователе
$db = Database::getInstance();
$user = $db->selectOne("
    SELECT phone, full_name, is_default_password, password_changed_at 
    FROM auth_users 
    WHERE id = ?
", [$session['user_id']]);

// Генерация CSRF токена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Смена пароля - <?= UI_CONFIG['app_name'] ?></title>
    <link rel="stylesheet" href="assets/css/auth.css">
    <style>
        .password-info {
            background: var(--gray-100);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .password-status {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-default {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .status-custom {
            background: var(--success-light);
            color: var(--success);
        }
        
        .password-tips {
            background: var(--primary-light);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-top: 20px;
        }
        
        .password-tips h4 {
            margin: 0 0 10px;
            color: var(--primary);
        }
        
        .password-tips ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .password-tips li {
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .show-password {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--gray-500);
            font-size: 14px;
        }
        
        .toggle-password:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="pic/logo.svg" alt="<?= UI_CONFIG['company_name'] ?>" class="logo-image">
                </div>
                <h1 class="auth-title">Смена пароля</h1>
                <p class="auth-subtitle">
                    <?= htmlspecialchars($user['full_name']) ?><br>
                    <?= htmlspecialchars($user['phone']) ?>
                </p>
            </div>

            <!-- Информация о текущем пароле -->
            <div class="password-info">
                <div class="password-status">
                    <span>Статус пароля:</span>
                    <?php if ($user['is_default_password']): ?>
                        <span class="status-badge status-default">Базовый пароль</span>
                    <?php else: ?>
                        <span class="status-badge status-custom">Персональный пароль</span>
                    <?php endif; ?>
                </div>
                
                <?php if ($user['is_default_password']): ?>
                    <p style="margin: 0; color: var(--warning);">
                        ⚠️ Вы используете базовый пароль. Рекомендуем сменить его на персональный для повышения безопасности.
                    </p>
                <?php else: ?>
                    <p style="margin: 0; color: var(--success);">
                        ✅ Вы используете персональный пароль. Последняя смена: <?= date('d.m.Y H:i', strtotime($user['password_changed_at'])) ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                
                <div class="form-group">
                    <label class="form-label" for="current_password">Текущий пароль</label>
                    <div class="show-password">
                        <input 
                            type="password" 
                            id="current_password" 
                            name="current_password" 
                            class="form-input" 
                            placeholder="<?= $user['is_default_password'] ? 'Последние 4 цифры вашего телефона' : 'Введите текущий пароль' ?>"
                            required
                            autofocus
                        >
                        <button type="button" class="toggle-password" onclick="togglePassword('current_password')">👁️</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="new_password">Новый пароль</label>
                    <div class="show-password">
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            class="form-input" 
                            placeholder="Минимум 4 символа"
                            required
                        >
                        <button type="button" class="toggle-password" onclick="togglePassword('new_password')">👁️</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Подтверждение пароля</label>
                    <div class="show-password">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input" 
                            placeholder="Повторите новый пароль"
                            required
                        >
                        <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">👁️</button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-full">
                    🔐 Сменить пароль
                </button>
            </form>

            <!-- Советы по паролям -->
            <div class="password-tips">
                <h4>💡 Советы по созданию пароля:</h4>
                <ul>
                    <li>Используйте минимум 4 символа</li>
                    <li>Комбинируйте буквы и цифры</li>
                    <li>Не используйте номер телефона или простые пароли</li>
                    <li>Пароль должен быть легким для запоминания именно вам</li>
                </ul>
            </div>

            <div class="auth-footer">
                <p>
                    <a href="select-department.php">🔙 Вернуться к выбору цеха</a> | 
                    <a href="logout.php">🚪 Выйти из системы</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            
            if (field.type === 'password') {
                field.type = 'text';
                button.textContent = '🙈';
            } else {
                field.type = 'password';
                button.textContent = '👁️';
            }
        }
        
        // Проверка совпадения паролей в реальном времени
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.style.borderColor = 'var(--danger)';
            } else {
                this.style.borderColor = '';
            }
        });
        
        // Автофокус на следующее поле при Enter
        document.getElementById('current_password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('new_password').focus();
            }
        });
        
        document.getElementById('new_password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('confirm_password').focus();
            }
        });
    </script>
</body>
</html>
















