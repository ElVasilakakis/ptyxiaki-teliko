@echo off
setlocal enabledelayedexpansion

cd sensor-monitor
if not exist build mkdir build

echo Auto-build watcher started for ESP32 project
echo Watching for changes in sensor-monitor.ino...
echo Press Ctrl+C to stop

set "lastModified="
:watch
for %%f in (sensor-monitor.ino) do (
    set "currentModified=%%~tf"
    if not "!currentModified!"=="!lastModified!" (
        if not "!lastModified!"=="" (
            echo.
            echo [%date% %time%] File changed, building...
            call :build
        )
        set "lastModified=!currentModified!"
    )
)
timeout /t 2 /nobreak >nul
goto watch

:build
echo Building ESP32 project...
arduino-cli compile --fqbn esp32:esp32:esp32doit-devkit-v1 --output-dir build sensor-monitor.ino
if %errorlevel% equ 0 (
    echo ✅ Build successful!
    echo Files created:
    dir build\*.bin 2>nul
    dir build\*.elf 2>nul
) else (
    echo ❌ Build failed!
)
echo Watching for changes...
goto :eof
