<?php
/**
 * Выход из системы
 */

define('AUTH_SYSTEM', true);
require_once 'includes/config.php';
require_once 'includes/auth-functions.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Уничтожение сессии
$auth->destroySession();

// Перенаправление на страницу входа
header('Location: login.php?message=logout');
exit;

?>
















