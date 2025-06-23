@echo off
echo Starting PHP development server and MQTT services in separate terminals...

start "PHP Server" cmd /k "php -S 127.0.0.1:8000 -t public"
start "MQTT Subscribe" cmd /k "php artisan mqtt:subscribe"
start "MQTT Listen" cmd /k "php artisan mqtt:listen --verbose"

echo All services started in separate terminal windows
pause
