@echo off
echo Configuring Arduino CLI cache...
arduino-cli config set build_cache.path %USERPROFILE%\.arduino-cache
arduino-cli config set build_cache.ttl 720h

cd sensor-monitor
if not exist build mkdir build
echo Building ESP32 project (with global cache)...
arduino-cli compile --fqbn esp32:esp32:esp32doit-devkit-v1 --build-path build --jobs %NUMBER_OF_PROCESSORS% sensor-monitor.ino
if %errorlevel% equ 0 (
    echo ✅ Build successful!
    for %%f in (build\*.bin) do echo   Binary: %%~nxf [%%~zf bytes]
) else (
    echo ❌ Build failed!
)
pause
