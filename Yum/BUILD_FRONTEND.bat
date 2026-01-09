@echo off
echo ====================================
echo   Сборка фронтенда Yum
echo ====================================
echo.

cd /d "%~dp0frontend"

echo Проверка зависимостей...
if not exist "node_modules" (
    echo Установка зависимостей...
    call npm install
)

echo.
echo Сборка проекта...
call npm run build

echo.
echo ====================================
echo   Сборка завершена!
echo ====================================
echo.
echo Теперь скопируйте ВСЁ содержимое папки:
echo   frontend\dist
echo в папку:
echo   C:\xampp\htdocs\yum\
echo.
pause
