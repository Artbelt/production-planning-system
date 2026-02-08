Восстановление таблицы salon_filter_structure из бэкапа
========================================================

Файл бэкапа: G:\BACKUP\plan_u5_20260201_230002.sql
База данных: plan_U5 (логин root, без пароля — из settings.php)

Способ 1 — PowerShell (рекомендуется)
-------------------------------------
1. Откройте PowerShell.
2. Перейдите в папку: cd c:\xampp\htdocs\plan_U5\tools
3. Выполните: .\restore_salon_filter_structure_from_backup.ps1

Скрипт извлечёт из полного дампа только блок для таблицы salon_filter_structure
(DROP TABLE, CREATE TABLE, INSERT) и применит его к БД plan_U5. Остальные таблицы не затрагиваются.

Способ 2 — вручную через mysql
------------------------------
Если нужно восстановить всю БД из бэкапа (все таблицы будут перезаписаны):
  C:\xampp\mysql\bin\mysql.exe -u root plan_U5 < "G:\BACKUP\plan_u5_20260201_230002.sql"

Внимание: это перезапишет ВСЕ таблицы в plan_U5. Для восстановления только salon_filter_structure используйте PowerShell-скрипт.
