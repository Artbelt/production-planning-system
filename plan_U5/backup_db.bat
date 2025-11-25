@echo off
REM Automatic database backup script for multiple databases
REM Usage: backup_db.bat

setlocal enabledelayedexpansion

REM Settings
set MYSQL_DIR=C:\xampp\mysql\bin
set BACKUP_DIR=G:\BACKUP
set DB_USER=root
set DB_PASS=
set DATE_FORMAT=%date:~-4,4%%date:~-7,2%%date:~-10,2%
set TIME_FORMAT=%time:~0,2%%time:~3,2%%time:~6,2%
set TIME_FORMAT=!TIME_FORMAT: =0!

REM List of databases to backup
set DATABASES=plan plan_u3 plan_u4 plan_u5 press_module auth

REM Create backup directory if it doesn't exist
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Check if mysqldump exists
if not exist "%MYSQL_DIR%\mysqldump.exe" (
    echo ERROR: mysqldump.exe not found in %MYSQL_DIR%
    echo Please check MySQL path in script settings
    pause
    exit /b 1
)

echo ========================================
echo Starting database backup process...
echo ========================================
echo Date: %DATE_FORMAT% %TIME_FORMAT%
echo Backup directory: %BACKUP_DIR%
echo Databases to backup: %DATABASES%
echo.

set TOTAL_SUCCESS=0
set TOTAL_FAILED=0

REM Loop through each database
for %%D in (%DATABASES%) do (
    set DB_NAME=%%D
    set BACKUP_FILE=%BACKUP_DIR%\!DB_NAME!_%DATE_FORMAT%_%TIME_FORMAT%.sql
    
    echo ----------------------------------------
    echo Backing up database: !DB_NAME!
    echo Backup file: !BACKUP_FILE!
    echo.
    
    REM Create backup
    if "%DB_PASS%"=="" (
        "%MYSQL_DIR%\mysqldump.exe" -u %DB_USER% --single-transaction --routines --triggers !DB_NAME! > "!BACKUP_FILE!" 2>nul
    ) else (
        "%MYSQL_DIR%\mysqldump.exe" -u %DB_USER% -p%DB_PASS% --single-transaction --routines --triggers !DB_NAME! > "!BACKUP_FILE!" 2>nul
    )
    
    REM Check result
    if !ERRORLEVEL! EQU 0 (
        if exist "!BACKUP_FILE!" (
            REM Get file size
            for %%A in ("!BACKUP_FILE!") do set SIZE=%%~zA
            if !SIZE! GTR 0 (
                set /a SIZE_MB=!SIZE!/1024/1024
                echo [OK] Backup successfully created: !BACKUP_FILE!
                echo File size: !SIZE_MB! MB
                
                REM Compress backup (if 7-Zip is installed)
                set ZIP_PATH=C:\Program Files\7-Zip\7z.exe
                if exist "%ZIP_PATH%" (
                    echo Compressing backup...
                    "%ZIP_PATH%" a -tzip "!BACKUP_FILE!.zip" "!BACKUP_FILE!" >nul 2>&1
                    if !ERRORLEVEL! EQU 0 (
                        del "!BACKUP_FILE!"
                        echo [OK] Backup compressed: !BACKUP_FILE!.zip
                    )
                )
                
                set /a TOTAL_SUCCESS+=1
            ) else (
                echo [ERROR] Backup file is empty!
                del "!BACKUP_FILE!" 2>nul
                set /a TOTAL_FAILED+=1
            )
        ) else (
            echo [ERROR] Backup file was not created!
            set /a TOTAL_FAILED+=1
        )
    ) else (
        echo [ERROR] Failed to create backup for !DB_NAME!
        echo Please check MySQL connection settings
        set /a TOTAL_FAILED+=1
    )
    echo.
)

REM Summary
echo ========================================
echo Backup Summary:
echo ========================================
echo Successful backups: %TOTAL_SUCCESS%
echo Failed backups: %TOTAL_FAILED%
echo.

REM Cleanup old backups (older than 30 days)
echo Cleaning up old backups (older than 30 days)...
for %%D in (%DATABASES%) do (
    forfiles /p "%BACKUP_DIR%" /m "%%D_*.sql" /d -30 /c "cmd /c del @path" >nul 2>&1
    forfiles /p "%BACKUP_DIR%" /m "%%D_*.zip" /d -30 /c "cmd /c del @path" >nul 2>&1
)
echo [OK] Old backups deleted

REM Show list of recent backups
echo.
echo Recent backups:
for %%D in (%DATABASES%) do (
    echo.
    echo Database: %%D
    dir /b /o-d "%BACKUP_DIR%\%%D_*.sql" "%BACKUP_DIR%\%%D_*.zip" 2>nul | findstr /C:"%%D_" | head -n 3
)

echo.
echo ========================================
echo Backup process completed!
echo ========================================

endlocal

