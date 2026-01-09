@echo off
REM Скрипт для запуска бэкенда на Windows
REM Используется для запуска через Apache

cd /d "%~dp0"

REM Проверяем, собран ли проект
if not exist "dist\server.js" (
    echo Building backend...
    call npm run build
)

REM Запускаем сервер
echo Starting Yum Backend API...
node dist/server.js
