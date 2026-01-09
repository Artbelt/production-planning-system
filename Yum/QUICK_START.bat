@echo off
echo ====================================
echo   БЫСТРЫЙ СТАРТ Yum
echo ====================================
echo.
echo Этот скрипт выполнит все шаги автоматически:
echo   1. Соберет фронтенд
echo   2. Скопирует файлы в htdocs
echo.
echo Нажмите любую клавишу для продолжения...
pause >nul

echo.
echo Шаг 1: Сборка фронтенда...
call BUILD_FRONTEND.bat

echo.
echo Шаг 2: Копирование файлов...
call COPY_TO_HTDOCS.bat

echo.
echo ====================================
echo   ВСЁ ГОТОВО!
echo ====================================
echo.
echo Теперь:
echo   1. Убедитесь, что модули Apache включены (см. README_SIMPLE.md)
echo   2. Запустите START_BACKEND.bat
echo   3. Откройте http://localhost/yum/
echo.
pause
