@echo off
REM ==========================================================
REM QuickSite - Post-Clone Setup Script (Windows)
REM ==========================================================
REM
REM Run this after cloning to configure QuickSite.
REM
REM What it does:
REM   - Optionally renames the "public" folder to match your vhost
REM   - Updates PUBLIC_FOLDER_NAME in init.php
REM
REM Config files (target.php, auth.php, roles.php) are auto-created from
REM .example templates on first page load - no action needed.
REM On nginx, a first-load instructions page will guide you through
REM including the generated dynamic_routes.conf.
REM
REM Usage:
REM   setup.bat                       (interactive)
REM   setup.bat www.example.com       (rename public folder)
REM ==========================================================

setlocal

set "SCRIPT_DIR=%~dp0"
set "PUBLIC_DIR=%SCRIPT_DIR%public"
set "SECURE_DIR=%SCRIPT_DIR%secure"
set "CONFIG_DIR=%SECURE_DIR%\management\config"
set "PUBLIC_FOLDER_NAME=public"

echo.
echo ========================================
echo       QuickSite Setup (Windows)
echo ========================================
echo.

REM ==========================================================
REM Step 1: Rename public folder (optional)
REM ==========================================================

set "NEW_PUBLIC_NAME=%~1"

if not "%NEW_PUBLIC_NAME%"=="" goto :do_rename_check

echo Public folder configuration
echo.
echo   Your vhost DocumentRoot should point to the public folder.
echo   If your vhost expects a specific name (e.g. "www",
echo   "www.example.com"), you can rename it now.
echo.
echo   Current name: public
echo.
set /p "NEW_PUBLIC_NAME=  New name (Enter to keep 'public'): "

if "%NEW_PUBLIC_NAME%"=="" (
    echo   OK Keeping "public"
    echo.
    goto :done
)

:do_rename_check
REM Validate
echo "%NEW_PUBLIC_NAME%" | findstr /r "[/\\]" >nul 2>&1
if %errorlevel% equ 0 (
    echo   X Error: folder name cannot contain slashes
    goto :done
)
if "%NEW_PUBLIC_NAME%"=="secure" (
    echo   X Error: cannot use "secure" as the public folder name
    goto :done
)
if "%NEW_PUBLIC_NAME%"=="public" (
    echo   OK Keeping "public"
    goto :done
)
if not exist "%PUBLIC_DIR%" (
    echo   X Error: "public" folder not found - already renamed?
    REM Try to detect existing renamed folder
    for /d %%d in ("%SCRIPT_DIR%*") do (
        if exist "%%d\init.php" (
            set "PUBLIC_DIR=%%d"
            set "PUBLIC_FOLDER_NAME=%%~nxd"
        )
    )
    goto :done
)
set "NEW_PUBLIC_DIR=%SCRIPT_DIR%%NEW_PUBLIC_NAME%"
if exist "%NEW_PUBLIC_DIR%" (
    echo   X Error: folder "%NEW_PUBLIC_NAME%" already exists
    goto :done
)

REM Do the rename
echo   Renaming: public -^> %NEW_PUBLIC_NAME%
rename "%PUBLIC_DIR%" "%NEW_PUBLIC_NAME%"
if errorlevel 1 (
    echo   X Failed to rename. Check permissions or close open files.
    goto :done
)

echo   + Renamed to %NEW_PUBLIC_NAME%

REM Update variables (use goto to escape the block scope problem)
set "PUBLIC_DIR=%NEW_PUBLIC_DIR%"
set "PUBLIC_FOLDER_NAME=%NEW_PUBLIC_NAME%"

REM Update PUBLIC_FOLDER_NAME in init.php via temp PS1 script
set "INIT_FILE=%SCRIPT_DIR%%NEW_PUBLIC_NAME%\init.php"
if not exist "%INIT_FILE%" goto :done

set "PS_TEMP=%TEMP%\qs_setup_rename.ps1"
echo $f = '%INIT_FILE%' > "%PS_TEMP%"
echo $n = '%NEW_PUBLIC_NAME%' >> "%PS_TEMP%"
echo $c = Get-Content $f -Raw >> "%PS_TEMP%"
echo $c = $c -replace "define\('PUBLIC_FOLDER_NAME',\s*'[^']*'\)", "define('PUBLIC_FOLDER_NAME', '$n')" >> "%PS_TEMP%"
echo [IO.File]::WriteAllText($f, $c, [System.Text.Encoding]::UTF8) >> "%PS_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_TEMP%" 2>nul
if errorlevel 1 (
    echo   ? Could not update init.php - edit PUBLIC_FOLDER_NAME in init.php manually
) else (
    echo   + Updated PUBLIC_FOLDER_NAME in init.php
)
del "%PS_TEMP%" 2>nul
echo.

:done
echo.
echo ========================================
echo   Setup complete
echo ========================================
echo.
echo   Public folder: %PUBLIC_FOLDER_NAME%
echo.
echo   Next steps:
echo     1. Ensure your vhost DocumentRoot points to the public folder
echo     2. Restart your web server
echo     3. Open http://your-domain/admin/
echo.
echo   Default API token: CHANGE_ME_superadmin_token
echo   Config files (auth.php, target.php, roles.php) are auto-created
echo   on first page load from .example templates.
echo   On nginx, you will see a first-load instructions page.
echo.

pause
