# Восстановление таблицы salon_filter_structure из файла бэкапа
# Использование: в PowerShell выполнить:
#   cd c:\xampp\htdocs\plan_U5\tools
#   .\restore_salon_filter_structure_from_backup.ps1
#
# Бэкап: G:\BACKUP\plan_u5_20260201_230002.sql
# Параметры БД из settings.php: plan_U5, root, без пароля

$ErrorActionPreference = "Stop"
$BackupPath = "G:\BACKUP\plan_u5_20260201_230002.sql"
$Database   = "plan_U5"
$MySQLExe   = "C:\xampp\mysql\bin\mysql.exe"
$TableMarker = "salon_filter_structure"

if (-not (Test-Path $BackupPath)) {
    Write-Host "ОШИБКА: Файл бэкапа не найден: $BackupPath" -ForegroundColor Red
    exit 1
}
if (-not (Test-Path $MySQLExe)) {
    Write-Host "ОШИБКА: MySQL не найден: $MySQLExe" -ForegroundColor Red
    exit 1
}

Write-Host "Извлечение таблицы $TableMarker из бэкапа..." -ForegroundColor Cyan
$lines = Get-Content $BackupPath -Encoding UTF8
$block = New-Object System.Collections.ArrayList
$capture = $false

foreach ($line in $lines) {
    if ($line -match "DROP TABLE IF EXISTS.*$TableMarker") {
        $capture = $true
    }
    if ($capture) {
        [void]$block.Add($line)
        # Конец блока таблицы — после UNLOCK TABLES
        if ($line -match "UNLOCK TABLES\s*;?\s*$" -and $block.Count -gt 5) {
            break
        }
        # Следующая таблица — не включаем её в блок
        if ($line -match "DROP TABLE IF EXISTS" -and $line -notmatch $TableMarker -and $block.Count -gt 3) {
            $block.RemoveAt($block.Count - 1)
            break
        }
    }
}

$extracted = $block -join "`r`n"
if ([string]::IsNullOrWhiteSpace($extracted)) {
    Write-Host "ОШИБКА: В бэкапе не найден блок для таблицы $TableMarker." -ForegroundColor Red
    exit 1
}

$TempSql = [System.IO.Path]::GetTempFileName() + ".sql"
[System.IO.File]::WriteAllText($TempSql, $extracted + "`r`n", [System.Text.Encoding]::UTF8)

Write-Host "Восстановление в БД $Database..." -ForegroundColor Yellow
# На Windows перенаправление ввода через cmd
$TempSqlEscaped = $TempSql -replace '"', '""'
cmd /c "`"$MySQLExe`" -u root $Database < `"$TempSqlEscaped`""
if ($LASTEXITCODE -ne 0) {
    Write-Host "ОШИБКА при выполнении MySQL (код $LASTEXITCODE)." -ForegroundColor Red
    Remove-Item $TempSql -ErrorAction SilentlyContinue
    exit 1
}

Remove-Item $TempSql -ErrorAction SilentlyContinue
Write-Host "Таблица salon_filter_structure восстановлена из бэкапа." -ForegroundColor Green
