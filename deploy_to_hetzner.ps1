# Деплой на Hetzner 49.13.143.76
# Запуск: PowerShell, перейти в c:\xampp\htdocs и выполнить: .\deploy_to_hetzner.ps1
# По запросу ввести пароль root.

$Server = "49.13.143.76"
Write-Host "Копирование всего проекта на root@${Server}:/var/www/html/ ..."
Write-Host "Пароль будет запрошен один раз."
scp -o StrictHostKeyChecking=accept-new -r * root@${Server}:/var/www/html/
if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "Копирование завершено. Дальше на сервере:"
    Write-Host "  1. ssh root@${Server}"
    Write-Host "  2. cp /var/www/html/env.php.example /var/www/html/env.php"
    Write-Host "  3. nano /var/www/html/env.php  -> задать DB_HOST=127.0.0.1, DB_USER=plan_user, DB_PASS=ваш_пароль"
    Write-Host "  4. chown -R www-data:www-data /var/www/html"
} else {
    Write-Host "Ошибка. Проверьте: ssh root@${Server}"
}
