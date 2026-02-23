# Исправления подключения к БД (env/plan_user)

Обновлено: перевод с root/пустой пароль на env.php (DB_HOST, DB_USER, DB_PASS) для работы на сервере Hetzner.

## Исправленные модули

- **auth** — config.php, db.php
- **plan** — settings, main, NP_*, worker_modules, load_*, hiding_order, save_g_box, product_output, new_order, gofra_packages_table
- **plan_U3** — settings, main, tools, worker_modules, load_*, save_*, hiding_order, gofra_packages_table, NP_*, new_order, save_order_into_DB
- **plan_U4** — settings, worker_modules, load_*, save_*, hiding_order, save_order_into_DB, new_order
- **plan_U5** — settings, main, show_order, worker_modules, load_*, save_*, hiding_order, product_editor_api, tasks_api
- **laser_operator** — index, detailed, api/*
- **cut_operator** — index
- **press_operator** — index, statistics, manage_boxes
- **tasks_manager** — tasks_api
- **monitoring** — databases config

## Возможные оставшиеся файлы

В части файлов (NP_*, печатные формы, редкие скрипты) подключение к БД могло остаться без изменений. При ошибке "Access denied for user 'root'@" нужно заменить:

```php
// Было:
$pdo = new PDO("mysql:host=127.0.0.1;dbname=plan_u5;...", "root", "");
// Стало:
require_once __DIR__ . '/../auth/includes/db.php';
$pdo = getPdo('plan_u5');
```

Для mysqli:
```php
// Было:
$mysqli = new mysqli('127.0.0.1','root','','plan_u5');
// Стало:
if (file_exists(__DIR__ . '/../env.php')) require __DIR__ . '/../env.php';
$mysqli = new mysqli(defined('DB_HOST') ? DB_HOST : '127.0.0.1', defined('DB_USER') ? DB_USER : 'root', defined('DB_PASS') ? DB_PASS : '', 'plan_u5');
```

## Деплой на сервер

Скопировать все изменённые каталоги:
```powershell
cd c:\xampp\htdocs
scp -r plan plan_U3 plan_U4 plan_U5 auth laser_operator cut_operator press_operator tasks_manager monitoring.php root@49.13.143.76:/var/www/html/
```
