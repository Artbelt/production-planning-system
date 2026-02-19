@echo off
chcp 65001 >nul
REM Копирование бэкапов БД с сервера на локальный ПК

set SERVER=49.13.143.76
set REMOTE_DIR=/var/backups/mysql
set LOCAL_DIR=C:\Backups\mysql

if not exist "%LOCAL_DIR%" mkdir "%LOCAL_DIR%"

echo Копирование бэкапов с %SERVER%...
scp -r root@%SERVER%:%REMOTE_DIR%/*.sql.gz "%LOCAL_DIR%\"

if %ERRORLEVEL% equ 0 (
    echo Готово. Файлы в %LOCAL_DIR%
) else (
    echo Ошибка копирования. Проверьте: ssh root@%SERVER%
)
pause
