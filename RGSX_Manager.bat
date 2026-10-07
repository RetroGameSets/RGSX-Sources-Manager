@echo off
setlocal

rem Resolve script dir (this .bat is in windows\)
set "SCRIPT_DIR=%~dp0"
set "PORTABLE_PHP=%SCRIPT_DIR%\php_local_server\php.exe"
set "PHP_BIN="

rem Prefer bundled portable PHP if present
if exist "%PORTABLE_PHP%" (
  set "PHP_BIN=%PORTABLE_PHP%"
  set "PHPRC=%SCRIPT_DIR%\php_local_server"
  set "PATH=%SCRIPT_DIR%\php_local_server;%PATH%"
) else (
  echo [ERREUR] PHP non trouvé.
)


rem Move to scripts folder
pushd "%SCRIPT_DIR%" 2>nul
if errorlevel 1 (
  echo [ERREUR] Dossier introuvable: %SCRIPT_DIR%
  pause
  exit /b 1
)

set PORT=8088
set HOST=127.0.0.1
set URL=http://%HOST%:%PORT%/rgsx_database_manager.php

echo Lancement du serveur PHP integre sur %HOST%:%PORT% ...
start "PHP Server" "%PHP_BIN%" -S %HOST%:%PORT% -t .

rem Attendre un court instant
ping -n 2 127.0.0.1 >nul

echo Ouverture du navigateur: %URL%
start "" "%URL%"

popd
endlocal
