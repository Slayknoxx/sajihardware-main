@echo off
cd /d "%~dp0"

set "PHP_EXE="
if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if exist "C:\laragon\bin\php\php-8.3.0-Win32-vs16-x64\php.exe" set "PHP_EXE=C:\laragon\bin\php\php-8.3.0-Win32-vs16-x64\php.exe"
if exist "C:\wamp64\bin\php" for /d %%D in ("C:\wamp64\bin\php\php*") do if exist "%%D\php.exe" set "PHP_EXE=%%D\php.exe"
if "%PHP_EXE%"=="" for /f "delims=" %%P in ('where php 2^>nul') do if "%PHP_EXE%"=="" set "PHP_EXE=%%P"

if "%PHP_EXE%"=="" (
  echo PHP was not found. Install XAMPP, Laragon, WampServer, or PHP for Windows.
  pause
  exit /b 1
)

for %%I in ("%PHP_EXE%") do set "PHP_DIR=%%~dpI"
set "PHP_DIR=%PHP_DIR:~0,-1%"

if not exist "%PHP_DIR%\ext\php_sqlite3.dll" (
  echo SQLite DLLs were not found in "%PHP_DIR%\ext".
  echo Install a full PHP build or enable SQLite in your XAMPP/Laragon/Wamp PHP folder.
  pause
  exit /b 1
)

(
  echo extension_dir="%PHP_DIR%\ext"
  echo extension=pdo_sqlite
  echo extension=sqlite3
  echo extension=openssl
  echo extension=curl
  echo extension=mbstring
  echo extension=sodium
  echo extension=gd
  echo extension=fileinfo
  echo extension=zip
  echo opcache.enable=0
  echo opcache.enable_cli=0
  echo date.timezone=Africa/Nairobi
  echo session.name=SAJIPOSID
  echo session.use_strict_mode=1
  echo session.cookie_httponly=1
  echo session.cookie_samesite=Lax
  echo display_errors=0
  echo log_errors=1
  echo error_reporting=E_ALL
) > php-runtime.ini

rem Always use php.exe: php-win.exe cannot host PHP's built-in web server.
start "SAJI POS Server" /min "%PHP_EXE%" -c "%~dp0php-runtime.ini" -S 127.0.0.1:8000 router.php
timeout /t 2 /nobreak >nul
start "" "http://127.0.0.1:8000"
