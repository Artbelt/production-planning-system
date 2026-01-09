@echo off
echo ====================================
echo   Копирование файлов в htdocs
echo ====================================
echo.

REM Проверяем, существует ли dist
if not exist "frontend\dist" (
    echo ОШИБКА: Папка frontend\dist не найдена!
    echo.
    echo Сначала нужно собрать проект:
    echo   1. Запустите BUILD_FRONTEND.bat
    echo   2. Затем запустите этот файл снова
    echo.
    pause
    exit /b 1
)

REM Создаем папку в htdocs если её нет
if not exist "C:\xampp\htdocs\yum" (
    echo Создание папки C:\xampp\htdocs\yum...
    mkdir "C:\xampp\htdocs\yum"
)

REM Копируем файлы
echo Копирование файлов из frontend\dist в C:\xampp\htdocs\yum...
xcopy /E /I /Y "frontend\dist\*" "C:\xampp\htdocs\yum\"

REM Копируем .htaccess
if exist "htdocs-yum-htaccess.txt" (
    echo Копирование .htaccess...
    copy /Y "htdocs-yum-htaccess.txt" "C:\xampp\htdocs\yum\.htaccess"
)

echo.
echo ====================================
echo   Готово!
echo ====================================
echo.
echo Файлы скопированы в C:\xampp\htdocs\yum\
echo.
echo Теперь откройте в браузере: http://localhost/yum/
echo.
pause
