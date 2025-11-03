<?php
/**
 * Главная страница системы авторизации
 * Проверяет авторизацию и перенаправляет на соответствующую страницу
 */

define('AUTH_SYSTEM', true);
require_once 'includes/config.php';
require_once 'includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка существующей сессии
$session = $auth->checkSession();

if ($session) {
    // Пользователь уже авторизован
    if (isset($_SESSION['auth_department']) && $_SESSION['auth_department']) {
        // Есть выбранный цех - перенаправляем в главную систему
        header('Location: ../index.php');
        exit;
    }
    
    // Нет выбранного цеха - перенаправляем на выбор
    header('Location: select-department.php');
    exit;
}

// Пользователь не авторизован - показываем форму входа
header('Location: login.php');
exit;

?>
