<?php
/**
 * Главная страница системы планирования
 * Интеграция с единой системой авторизации
 */

// Подключаем новую систему авторизации
define('AUTH_SYSTEM', true);
require_once 'auth/includes/config.php';
require_once 'auth/includes/auth-functions.php';
require_once 'auth/includes/password-functions.php';
require_once 'auth/includes/password-reminder-banner.php';

// Инициализация системы
initAuthSystem();

$auth = new AuthManager();

// Проверка авторизации
$session = $auth->checkSession();
if (!$session) {
    // Пользователь не авторизован - перенаправляем на вход
    header('Location: auth/login.php');
    exit;
}

// Проверяем, выбран ли цех
if (!isset($_SESSION['auth_department']) || !$_SESSION['auth_department']) {
    // Цех не выбран - перенаправляем на выбор цеха
    header('Location: auth/select-department.php');
    exit;
}

$currentDepartment = $_SESSION['auth_department'];
$userRole = null;

// Получаем информацию о пользователе и его цехах
$db = Database::getInstance();
$users = $db->select("SELECT * FROM auth_users WHERE id = ?", [$session['user_id']]);
$user = $users[0] ?? null;

$userDepartments = $db->select("
    SELECT ud.department_code, r.name as role_name, r.display_name as role_display_name
    FROM auth_user_departments ud
    JOIN auth_roles r ON ud.role_id = r.id
    WHERE ud.user_id = ?
", [$session['user_id']]);

// Получаем роль пользователя в текущем цехе
foreach ($userDepartments as $dept) {
    if ($dept['department_code'] === $currentDepartment) {
        $userRole = $dept['role_name'];
        break;
    }
}

// Определяем права доступа
$canViewRequests = in_array($userRole, ['worker', 'manager', 'supervisor', 'director', 'assembler', 'corr_operator', 'cut_operator']);
$canViewPlans = in_array($userRole, ['manager', 'supervisor', 'director', 'assembler', 'corr_operator']);
$canAccessSystems = in_array($userRole, ['supervisor', 'director']);

// Проверяем права доступа к модулю лазерной резки по всем ролям пользователя
$canAccessLaserOperator = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'laser_operator'])) {
        $canAccessLaserOperator = true;
        break;
    }
}

// Проверяем права доступа к модулю оператора бумагорезки по всем ролям пользователя
$canAccessCutOperator = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'cut_operator'])) {
        $canAccessCutOperator = true;
        break;
    }
}

// Проверяем права доступа к модулю оператора тигельного пресса по всем ролям пользователя
$canAccessBoxOperator = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director', 'box_operator'])) {
        $canAccessBoxOperator = true;
        break;
    }
}

// Проверяем специальный доступ к глобальному модулю (admin/director имеют приоритет над отдельными кнопками)
$hasGlobalCutOperatorAccess = false;
foreach ($userDepartments as $dept) {
    if (in_array($dept['role_name'], ['admin', 'director'])) {
        $hasGlobalCutOperatorAccess = true;
        break;
    }
}

// Функция для проверки доступа к лазерным заявкам только для сборщиков
function canAccessLaserRequests($userDepartments, $departmentCode) {
    // Проверяем доступ для указанного департамента
    foreach ($userDepartments as $dept) {
        if ($dept['department_code'] === $departmentCode) {
            $role = $dept['role_name'];
            // Доступ предоставляется только сборщикам
            return $role === 'assembler';
        }
    }
    return false;
}

// Функция для проверки доступа к лазерным заявкам для гофропакетчиков
function canAccessLaserRequestsForCorr($userDepartments, $departmentCode) {
    // Проверяем доступ для указанного департамента
    foreach ($userDepartments as $dept) {
        if ($dept['department_code'] === $departmentCode) {
            $role = $dept['role_name'];
            // Доступ предоставляется гофропакетчикам
            return $role === 'corr_operator';
        }
    }
    return false;
}

// Получаем доступные цеха для пользователя
$availableDepartments = [];
foreach ($userDepartments as $dept) {
    $availableDepartments[] = $dept['department_code'];
}

// Проверяем общий доступ к лазерным заявкам (есть ли хотя бы один департамент с доступом)
$canAccessLaser = false;
foreach ($availableDepartments as $dept) {
    if (canAccessLaserRequests($userDepartments, $dept)) {
        $canAccessLaser = true;
        break;
    }
}

// Проверяем общий доступ к лазерным заявкам для гофропакетчиков
$canAccessLaserCorr = false;
foreach ($availableDepartments as $dept) {
    if (canAccessLaserRequestsForCorr($userDepartments, $dept)) {
        $canAccessLaserCorr = true;
        break;
    }
}

// Проверяем, есть ли у пользователя роль гофропакетчика в любом из департаментов
$hasCorrOperatorRole = false;
foreach ($userDepartments as $dept) {
    if ($dept['role_name'] === 'corr_operator') {
        $hasCorrOperatorRole = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Система планирования - <?= UI_CONFIG['company_name'] ?></title>
	<?php
	// Отображаем баннер напоминания о смене пароля
	if (isset($session['user_id'])) {
		echo renderPasswordReminderBanner($session['user_id']);
	}
	?>
	<style>
		* {
			margin: 0;
			padding: 0;
			box-sizing: border-box;
		}

		html, body {
			height: 100%;
			font-family: Arial, sans-serif;
			display: flex;
			justify-content: center;
			align-items: center;
		}
		
		/* Отступ для баннера напоминания о пароле */
		body {
			padding-top: 0;
		}
		
		#password-reminder-banner {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			z-index: 10000;
		}

		.button-container {
			display: flex;
			flex-direction: column;
			gap: 15px;
		}

		.row {
			display: flex;
			gap: 10px;
		}

		.btn {
			padding: 12px 20px;
			font-size: 16px;
			background-color: #4CAF50;
			color: white;
			border: none;
			border-radius: 5px;
			cursor: pointer;
			transition: background-color 0.3s;
			min-width: 180px;
		}

		.btn.plan {
			background-color: #2196F3;
		}

		.btn.system {
			background-color: rgb(137, 150, 215);
		}

		.btn.admin {
			background-color: #e91e63;
		}

		.btn.production {
			background-color: #FF9800;
		}

		.btn.corr-tasks {
			background-color: #9C27B0;
		}

		.btn.cut-tasks {
			background-color: #607D8B;
		}

		.btn.laser {
			background-color: #FF5722;
		}

		.btn.cut {
			background-color: #4CAF50;
		}

		.btn.box {
			background-color: #00BCD4;
		}

		.btn.calculation {
			background-color: #795548;
		}

		.btn:disabled {
			background-color: #999;
			cursor: not-allowed;
		}

		.btn:hover:not(:disabled) {
			opacity: 0.9;
		}
	</style>
</head>
<body>

<!-- Аккуратная панель авторизации -->
<div style="position: fixed; top: 10px; right: 10px; background: white; padding: 12px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; border: 1px solid #e5e7eb;">
	<div style="display: flex; align-items: center; gap: 12px;">
		<div style="width: 32px; height: 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px;">
			<?= mb_substr($user['full_name'] ?? 'П', 0, 1, 'UTF-8') ?>
		</div>
		<div>
			<div style="font-weight: 600; font-size: 14px; color: #1f2937;"><?= htmlspecialchars($user['full_name'] ?? 'Пользователь') ?></div>
			<div style="font-size: 12px; color: #6b7280;"><?= htmlspecialchars($user['phone'] ?? '') ?></div>
			<div style="font-size: 11px; color: #9ca3af;"><?= $currentDepartment ?> • <?= ucfirst($userRole ?? 'guest') ?></div>
		</div>
		<a href="auth/change-password.php" style="padding: 4px 8px; background: transparent; color: #9ca3af; text-decoration: none; border-radius: 3px; font-size: 11px; font-weight: 400; transition: all 0.2s; border: 1px solid #e5e7eb;" onmouseover="this.style.background='#f9fafb'; this.style.color='#6b7280'; this.style.borderColor='#d1d5db'" onmouseout="this.style.background='transparent'; this.style.color='#9ca3af'; this.style.borderColor='#e5e7eb'">Пароль</a>
		<a href="auth/logout.php" style="padding: 6px 12px; background: #f3f4f6; color: #374151; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: 500; transition: background-color 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">Выход</a>
	</div>
</div>

<div class="button-container">

	<!-- Заявки и планы (для всех ролей с соответствующими правами) -->
	<?php if ($userRole === 'assembler'): ?>
		<!-- Для сборщиц показываем только их цеха -->
		<?php foreach ($availableDepartments as $dept): ?>
		<div class="row">
			<?php if ($canViewRequests): ?>
			<button class="btn" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/archived_orders.php', '_blank')">Заявки <?= $dept ?></button>
			<?php endif; ?>
			
			<?php if ($canViewPlans): ?>
				<?php if ($dept !== 'U4'): ?>
				<button class="btn plan" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/production_plans.php', '_blank')">План <?= $dept ?></button>
				<?php else: ?>
				<button class="btn plan" disabled>План <?= $dept ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	<?php elseif ($userRole === 'supervisor'): ?>
		<!-- Для мастеров показываем только их цеха -->
		<?php foreach ($availableDepartments as $dept): ?>
		<div class="row">
			<?php if ($canViewRequests): ?>
			<button class="btn" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/archived_orders.php', '_blank')">Заявки <?= $dept ?></button>
			<?php endif; ?>
			
			<?php if ($canViewPlans): ?>
				<?php if ($dept !== 'U4'): ?>
				<button class="btn plan" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/production_plans.php', '_blank')">План <?= $dept ?></button>
				<?php else: ?>
				<button class="btn plan" disabled>План <?= $dept ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	<?php elseif ($userRole === 'corr_operator'): ?>
		<!-- Для операторов гофромашины показываем только их цеха -->
		<?php foreach ($availableDepartments as $dept): ?>
		<div class="row">
			<?php if ($canViewRequests): ?>
			<button class="btn" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/archived_orders.php', '_blank')">Заявки <?= $dept ?></button>
			<?php endif; ?>
			
			<?php if ($canViewPlans): ?>
				<?php if ($dept !== 'U4'): ?>
				<button class="btn plan" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/production_plans.php', '_blank')">План <?= $dept ?></button>
				<?php else: ?>
				<button class="btn plan" disabled>План <?= $dept ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	<?php elseif ($userRole === 'cut_operator'): ?>
		<!-- Для операторов бобинорезки показываем только кнопки бобинорезки (без заявок и планов) -->
		<!-- Кнопки бобинорезки будут показаны в отдельной секции ниже -->
	<?php else: ?>
		<!-- Для остальных ролей показываем все цеха (как было) -->
		<div class="row">
			<?php if ($canViewRequests && in_array('U2', $availableDepartments)): ?>
			<button class="btn" onclick="window.open('/plan/archived_orders.php', '_blank')">Заявки У2</button>
			<?php else: ?>
			<button class="btn" disabled style="opacity: 0.3;">Заявки У2</button>
			<?php endif; ?>
			
			<?php if ($canViewPlans && in_array('U2', $availableDepartments)): ?>
			<button class="btn plan" onclick="window.open('/plan/production_plans.php', '_blank')">План У2</button>
			<?php else: ?>
			<button class="btn plan" disabled style="opacity: 0.3;">План У2</button>
			<?php endif; ?>
		</div>
		<div class="row">
			<?php if ($canViewRequests && in_array('U3', $availableDepartments)): ?>
			<button class="btn" onclick="window.open('/plan_U3/archived_orders.php', '_blank')">Заявки У3</button>
			<?php else: ?>
			<button class="btn" disabled style="opacity: 0.3;">Заявки У3</button>
			<?php endif; ?>
			
			<?php if ($canViewPlans && in_array('U3', $availableDepartments)): ?>
			<button class="btn plan" onclick="window.open('/plan_U3/production_plans.php', '_blank')">План У3</button>
			<?php else: ?>
			<button class="btn plan" disabled style="opacity: 0.3;">План У3</button>
			<?php endif; ?>
		</div>
		<div class="row">
			<?php if ($canViewRequests && in_array('U4', $availableDepartments)): ?>
			<button class="btn" onclick="window.open('/plan_U4/archived_orders.php', '_blank')">Заявки У4</button>
			<?php else: ?>
			<button class="btn" disabled style="opacity: 0.3;">Заявки У4</button>
			<?php endif; ?>
			
			<?php if (in_array('U4', $availableDepartments)): ?>
			<button class="btn plan" disabled>План У4</button>
			<?php else: ?>
			<button class="btn plan" disabled style="opacity: 0.3;">План У4</button>
			<?php endif; ?>
		</div>
		<div class="row">
			<?php if ($canViewRequests && in_array('U5', $availableDepartments)): ?>
			<button class="btn" onclick="window.open('/plan_U5/archived_orders.php', '_blank')">Заявки У5</button>
			<?php else: ?>
			<button class="btn" disabled style="opacity: 0.3;">Заявки У5</button>
			<?php endif; ?>
			
			<?php if ($canViewPlans && in_array('U5', $availableDepartments)): ?>
			<button class="btn plan" onclick="window.open('/plan_U5/production_plans.php', '_blank')">План У5</button>
			<?php else: ?>
			<button class="btn plan" disabled style="opacity: 0.3;">План У5</button>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	
	<!-- Системы планирования (только для мастеров и директоров) -->
	<?php if ($canAccessSystems): ?>
		<?php if (in_array('U2', $availableDepartments)): ?>
		<button class="btn system" onclick="window.open('/plan/main.php', '_blank')">У2</button>
		<?php endif; ?>
		<?php if (in_array('U3', $availableDepartments)): ?>
		<button class="btn system" onclick="window.open('/plan_U3/main.php', '_blank')">У3</button>
		<?php endif; ?>
		<?php if (in_array('U4', $availableDepartments)): ?>
		<button class="btn system" onclick="window.open('/plan_U4/main.php', '_blank')">У4</button>
		<?php endif; ?>
		<?php if (in_array('U5', $availableDepartments)): ?>
		<button class="btn system" onclick="window.open('/plan_U5/main.php', '_blank')">У5</button>
		<?php endif; ?>
	<?php endif; ?>
	
	<!-- Гофрирование (только для операторов гофромашины) -->
	<?php if ($hasCorrOperatorRole): ?>
		<?php foreach ($availableDepartments as $dept): ?>
		<button class="btn corr-tasks" onclick="window.open('/plan<?= $dept === 'U2' ? '' : '_' . $dept ?>/worker_modules/tasks_corrugation.php', '_blank')">Гофрирование <?= $dept ?></button>
		<?php endforeach; ?>
		
	<?php endif; ?>
	
	
	<!-- Внесение продукции (только для сборщиц) -->
	<?php if ($userRole === 'assembler'): ?>
		<?php if (in_array('U2', $availableDepartments)): ?>
		<button class="btn production" onclick="window.open('/plan/product_output.php', '_blank')">Внести выпущенную продукцию У2</button>
		<?php endif; ?>
		<?php if (in_array('U3', $availableDepartments)): ?>
		<button class="btn production" onclick="window.open('/plan_U3/product_output.php', '_blank')">Внести выпущенную продукцию У3</button>
		<button class="btn plan" onclick="window.open('/plan_U3/summary_plan_U3.php', '_blank')">Сводный план У3</button>
		<?php endif; ?>
		<?php if (in_array('U4', $availableDepartments)): ?>
		<button class="btn production" onclick="window.open('/plan_U4/product_output.php', '_blank')">Внести выпущенную продукцию У4</button>
		<?php endif; ?>
		<?php if (in_array('U5', $availableDepartments)): ?>
		<button class="btn production" onclick="window.open('/plan_U5/product_output.php', '_blank')">Внести выпущенную продукцию У5</button>
		<?php endif; ?>
	<?php endif; ?>
	
	<!-- Сводный план (только для сборщиц У5) -->
	<?php if ($userRole === 'assembler'): ?>
		<?php if (in_array('U5', $availableDepartments)): ?>
		<button class="btn plan" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);" onclick="window.open('/plan_U5/mobile_build_plan.php', '_blank')">Сводный план У5</button>
		<button class="btn plan" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);" onclick="window.open('/plan_U5/buffer_stock.php', '_blank')">Буфер гофропакетов У5</button>
		<?php endif; ?>
	<?php endif; ?>
	
	<!-- Заявки на лазер (только для сборщиков) -->
	<?php if ($canAccessLaser): ?>
		<?php if (in_array('U2', $availableDepartments) && canAccessLaserRequests($userDepartments, 'U2')): ?>
		<button class="btn laser" onclick="window.open('/plan/laser_request.php', '_blank')">Заявка на лазер У2</button>
		<?php endif; ?>
		<?php if (in_array('U3', $availableDepartments) && canAccessLaserRequests($userDepartments, 'U3')): ?>
		<button class="btn laser" onclick="window.open('/plan_U3/laser_request.php', '_blank')">Заявка на лазер У3</button>
		<?php endif; ?>
		<?php if (in_array('U4', $availableDepartments) && canAccessLaserRequests($userDepartments, 'U4')): ?>
		<button class="btn laser" onclick="window.open('/plan_U4/laser_request.php', '_blank')">Заявка на лазер У4</button>
		<?php endif; ?>
		<?php if (in_array('U5', $availableDepartments) && canAccessLaserRequests($userDepartments, 'U5')): ?>
		<button class="btn laser" onclick="window.open('/plan_U5/laser_request.php', '_blank')">Заявка на лазер У5</button>
		<?php endif; ?>
	<?php endif; ?>
	
	<!-- Заявки на лазер (для гофропакетчиков) -->
	<?php if ($canAccessLaserCorr): ?>
		<?php if (in_array('U2', $availableDepartments) && canAccessLaserRequestsForCorr($userDepartments, 'U2')): ?>
		<button class="btn laser" onclick="window.open('/plan/laser_request.php', '_blank')">Заявка на лазер У2</button>
		<?php endif; ?>
		<?php if (in_array('U3', $availableDepartments) && canAccessLaserRequestsForCorr($userDepartments, 'U3')): ?>
		<button class="btn laser" onclick="window.open('/plan_U3/laser_request.php', '_blank')">Заявка на лазер У3</button>
		<?php endif; ?>
		<?php if (in_array('U4', $availableDepartments) && canAccessLaserRequestsForCorr($userDepartments, 'U4')): ?>
		<button class="btn laser" onclick="window.open('/plan_U4/laser_request.php', '_blank')">Заявка на лазер У4</button>
		<?php endif; ?>
		<?php if (in_array('U5', $availableDepartments) && canAccessLaserRequestsForCorr($userDepartments, 'U5')): ?>
		<button class="btn laser" onclick="window.open('/plan_U5/laser_request.php', '_blank')">Заявка на лазер У5</button>
		<?php endif; ?>
	<?php endif; ?>
	
	<!-- Модуль оператора лазерной резки -->
	<?php if ($canAccessLaserOperator): ?>
	<button class="btn laser" onclick="window.open('laser_operator/', '_blank')">Оператор лазерной резки</button>
	<?php endif; ?>
	
	<!-- Модуль оператора бумагорезки -->
	<?php if ($canAccessCutOperator): ?>
	<button class="btn cut" onclick="window.open('cut_operator/', '_blank')">Оператор бумагорезки</button>
	<?php endif; ?>
	
	<!-- Модуль оператора тигельного пресса -->
	<?php if ($canAccessBoxOperator): ?>
	<button class="btn box" onclick="window.open('press_operator/', '_blank')">Оператор тигельного пресса</button>
	<?php endif; ?>
	
	<!-- Задачи (только для директоров) -->
	<?php if ($userRole === 'director'): ?>
	<button class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);" onclick="window.open('/tasks_manager/', '_blank')">Задачи</button>
	<?php endif; ?>
	
	<!-- Админ панель (только для директоров) -->
	<?php if ($userRole === 'director'): ?>
	<button class="btn admin" onclick="window.open('auth/admin/', '_blank')">Админ</button>
	<?php endif; ?>
	
	<!-- Мониторинг (доступен всем авторизованным) -->
	<button class="btn" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);" onclick="window.open('monitoring.php', '_blank')">Мониторинг</button>
		</div>
	</div>


</body>
</html>
