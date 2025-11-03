<?php
/**
 * Страница входа в систему
 */

define('AUTH_SYSTEM', true);
require_once 'includes/config.php';
require_once 'includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();
$error = '';
$success = '';

// Проверка существующей сессии
$session = $auth->checkSession();
if ($session) {
    header('Location: ../index.php');
    exit;
}

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($phone) || empty($password)) {
        $error = 'Заполните все поля';
    } else {
        $result = $auth->authenticate($phone, $password);
        
        if ($result['success']) {
            // Получаем доступные цеха для пользователя
            $userDepartments = $auth->getUserDepartments($result['user']['id']);
            
            // Выбираем первый доступный цех
            $firstDepartment = $userDepartments[0]['department_code'] ?? 'U2';
            
            // Создание сессии с указанием отдела
            $sessionId = $auth->createSession($result['user']['id'], $firstDepartment);
            
            if ($sessionId) {
                // Устанавливаем отдел в сессию (уже должно быть установлено в createSession)
                $_SESSION['auth_department'] = $firstDepartment;
                
                // Всегда перенаправляем на главную страницу
                header('Location: ../index.php');
                exit;
            } else {
                $error = 'Ошибка создания сессии';
            }
        } else {
            $error = $result['error'];
        }
    }
}

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
    <title>Вход в систему - <?= UI_CONFIG['app_name'] ?></title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="pic/logo.svg" alt="<?= UI_CONFIG['company_name'] ?>" class="logo-image">
                </div>
                <h1 class="auth-title"><?= UI_CONFIG['company_name'] ?></h1>
                <p class="auth-subtitle">Вход в систему планирования</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form class="auth-form" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                
                <div class="form-group">
                    <label class="form-label" for="phone">Номер телефона</label>
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone" 
                        class="form-input" 
                        placeholder="+380 (99) 000-00-00"
                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input" 
                        placeholder="Введите пароль"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-full btn-lg">
                    Войти в систему
                </button>
            </form>

            <div class="auth-footer">
                <p>Проблемы со входом? Обратитесь к администратору</p>
                <p>Телефон: <?= UI_CONFIG['support_phone'] ?></p>
                <p>&copy; <?= date('Y') ?> <?= UI_CONFIG['company_name'] ?></p>
            </div>
        </div>
    </div>

    <script>
        // Форматирование номера телефона (украинский формат)
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length > 0) {
                // Обработка украинских номеров
                if (value.startsWith('380')) {
                    value = value.slice(0, 12);
                    let formatted = '+380';
                    if (value.length > 3) {
                        formatted += ' (' + value.slice(3, 5);
                        if (value.length > 5) {
                            formatted += ') ' + value.slice(5, 8);
                            if (value.length > 8) {
                                formatted += '-' + value.slice(8, 10);
                                if (value.length > 10) {
                                    formatted += '-' + value.slice(10, 12);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else if (value.startsWith('0')) {
                    // Украинский номер без кода страны
                    value = '380' + value.slice(1);
                    value = value.slice(0, 12);
                    let formatted = '+380';
                    if (value.length > 3) {
                        formatted += ' (' + value.slice(3, 5);
                        if (value.length > 5) {
                            formatted += ') ' + value.slice(5, 8);
                            if (value.length > 8) {
                                formatted += '-' + value.slice(8, 10);
                                if (value.length > 10) {
                                    formatted += '-' + value.slice(10, 12);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else if (value[0] === '8') {
                    // Российский номер (для совместимости)
                    value = '7' + value.slice(1);
                    value = value.slice(0, 11);
                    let formatted = '+7';
                    if (value.length > 1) {
                        formatted += ' (' + value.slice(1, 4);
                        if (value.length > 4) {
                            formatted += ') ' + value.slice(4, 7);
                            if (value.length > 7) {
                                formatted += '-' + value.slice(7, 9);
                                if (value.length > 9) {
                                    formatted += '-' + value.slice(9, 11);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else if (value[0] === '7') {
                    // Российский номер (для совместимости)
                    value = value.slice(0, 11);
                    let formatted = '+7';
                    if (value.length > 1) {
                        formatted += ' (' + value.slice(1, 4);
                        if (value.length > 4) {
                            formatted += ') ' + value.slice(4, 7);
                            if (value.length > 7) {
                                formatted += '-' + value.slice(7, 9);
                                if (value.length > 9) {
                                    formatted += '-' + value.slice(9, 11);
                                }
                            }
                        }
                    }
                    e.target.value = formatted;
                } else {
                    e.target.value = '+' + value;
                }
            }
        });

        // Автофокус на поле пароля при заполненном телефоне
        document.getElementById('phone').addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && this.value.length >= 10) {
                e.preventDefault();
                document.getElementById('password').focus();
            }
        });

        // Отправка формы по Enter в поле пароля
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('form').submit();
            }
        });
    </script>
</body>
</html>
