@echo off
echo ====================================
echo   Запуск бэкенда Yum
echo ====================================
echo.

cd /d "%~dp0backend"

echo Проверка зависимостей...
if not exist "node_modules" (
    echo Установка зависимостей...
    call npm install
)

echo Проверка сборки...
if not exist "dist\server.js" (
    echo Сборка проекта...
    call npm run build
)

echo.
echo Запуск сервера на http://localhost:3001
echo Нажмите Ctrl+C для остановки
echo.

node dist/server.js

pause
