while ($true) {
    php artisan queue:work --once --tries=3
    Start-Sleep -Seconds 3
}
