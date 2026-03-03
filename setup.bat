@echo off
REM ==========================================================
REM QuickSite - Post-Clone Setup Script (Windows)
REM ==========================================================
REM
REM Run this after cloning to configure QuickSite.
REM
REM What it does:
REM   1. Renames "public/" to match your vhost (e.g. www, public_html)
REM   2. Renames "secure/" for obscurity (e.g. backend, app)
REM   3. Sets a URL prefix/space (e.g. "web" -> http://domain/web/)
REM   - Updates init.php constants and .htaccess files
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

setlocal enabledelayedexpansion

set "SCRIPT_DIR=%~dp0"
set "PUBLIC_DIR=%SCRIPT_DIR%public"
set "SECURE_DIR=%SCRIPT_DIR%secure"
set "PUBLIC_FOLDER_NAME=public"
set "SECURE_FOLDER_NAME=secure"
set "PUBLIC_SPACE="

REM Read existing config if available (re-run detection)
set "CONF_FILE=%SCRIPT_DIR%.quicksite.conf"
if exist "%CONF_FILE%" (
    for /f "usebackq tokens=1,2 delims==" %%a in ("%CONF_FILE%") do (
        if "%%a"=="PUBLIC_FOLDER_NAME" set "PUBLIC_FOLDER_NAME=%%b"
        if "%%a"=="SECURE_FOLDER_NAME" set "SECURE_FOLDER_NAME=%%b"
        if "%%a"=="PUBLIC_FOLDER_SPACE" set "PUBLIC_SPACE=%%b"
    )
    set "PUBLIC_DIR=!SCRIPT_DIR!!PUBLIC_FOLDER_NAME!"
    set "SECURE_DIR=!SCRIPT_DIR!!SECURE_FOLDER_NAME!"
)

echo.
echo ========================================
echo       QuickSite Setup (Windows)
echo ========================================
echo.

REM ==========================================================
REM Step 1: Rename public folder
REM ==========================================================

echo Step 1 - Public folder name
echo.

set "NEW_PUBLIC_NAME=%~1"

if not "%NEW_PUBLIC_NAME%"=="" goto :do_public_check

echo   Your vhost DocumentRoot should point to the public folder.
echo   If your vhost expects a specific name (e.g. "www",
echo   "www.example.com"), you can rename it now.
echo.
echo   Current name: %PUBLIC_FOLDER_NAME%
echo.
set /p "NEW_PUBLIC_NAME=  New name (Enter to keep '%PUBLIC_FOLDER_NAME%'): "

if "%NEW_PUBLIC_NAME%"=="" (
    echo   OK Keeping "%PUBLIC_FOLDER_NAME%"
    echo.
    goto :step2
)

:do_public_check
REM Validate
echo "%NEW_PUBLIC_NAME%" | findstr /r "[/\\]" >nul 2>&1
if %errorlevel% equ 0 (
    echo   X Error: folder name cannot contain slashes
    goto :eof
)
if "%NEW_PUBLIC_NAME%"=="%SECURE_FOLDER_NAME%" (
    echo   X Error: cannot use the same name as the secure folder
    goto :eof
)
if "%NEW_PUBLIC_NAME%"=="%PUBLIC_FOLDER_NAME%" (
    echo   OK Keeping "%PUBLIC_FOLDER_NAME%"
    goto :step2
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
    goto :step2
)
set "NEW_PUBLIC_DIR=%SCRIPT_DIR%%NEW_PUBLIC_NAME%"
if exist "%NEW_PUBLIC_DIR%" (
    echo   X Error: folder "%NEW_PUBLIC_NAME%" already exists
    goto :eof
)

REM Do the rename
echo   Renaming: public -^> %NEW_PUBLIC_NAME%
rename "%PUBLIC_DIR%" "%NEW_PUBLIC_NAME%"
if errorlevel 1 (
    echo   X Failed to rename. Check permissions or close open files.
    goto :eof
)

echo   + Renamed to %NEW_PUBLIC_NAME%

set "PUBLIC_DIR=%NEW_PUBLIC_DIR%"
set "PUBLIC_FOLDER_NAME=%NEW_PUBLIC_NAME%"

REM Update PUBLIC_FOLDER_NAME in init.php via temp PS1 script
set "INIT_FILE=%SCRIPT_DIR%%NEW_PUBLIC_NAME%\init.php"
if not exist "%INIT_FILE%" goto :step2

call :update_init_constant "%INIT_FILE%" "PUBLIC_FOLDER_NAME" "%NEW_PUBLIC_NAME%"
echo.

REM ==========================================================
REM Step 2: Rename secure folder
REM ==========================================================
:step2
echo.
echo Step 2 - Secure folder name
echo.

REM Detect current secure folder (may already be renamed)
if not exist "%SECURE_DIR%" (
    REM Try to find it via init.php
    set "DETECT_INIT=%PUBLIC_DIR%\init.php"
    if exist "!DETECT_INIT!" (
        for /f "tokens=2 delims='" %%a in ('findstr /c:"SECURE_FOLDER_NAME" "!DETECT_INIT!" 2^>nul') do (
            if exist "%SCRIPT_DIR%%%a" (
                set "SECURE_DIR=%SCRIPT_DIR%%%a"
                set "SECURE_FOLDER_NAME=%%a"
            )
        )
    )
)

echo   The secure folder holds the QuickSite engine.
echo   Rename it for obscurity, or nest it in a subdirectory.
echo   Examples: "backend", "app", "backends/project1"
echo.
echo   Current name: %SECURE_FOLDER_NAME%
echo.
set "NEW_SECURE_NAME="
set /p "NEW_SECURE_NAME=  New name (Enter to keep '%SECURE_FOLDER_NAME%'): "

if "%NEW_SECURE_NAME%"=="" (
    echo   OK Keeping "%SECURE_FOLDER_NAME%"
    goto :step3
)
if "%NEW_SECURE_NAME%"=="%SECURE_FOLDER_NAME%" (
    echo   OK Keeping "%SECURE_FOLDER_NAME%"
    goto :step3
)

REM Validate
if "%NEW_SECURE_NAME%"=="%PUBLIC_FOLDER_NAME%" (
    echo   X Error: cannot use the same name as the public folder
    goto :eof
)
if not exist "%SECURE_DIR%" (
    echo   X Error: secure folder "%SECURE_FOLDER_NAME%" not found
    goto :eof
)

REM Move via PowerShell (supports nested paths like backends/project1)
echo   Renaming: %SECURE_FOLDER_NAME% -^> %NEW_SECURE_NAME%
set "PS_SEC_TEMP=%TEMP%\qs_setup_secure.ps1"
echo $src = '%SECURE_DIR%' > "%PS_SEC_TEMP%"
echo $name = '%NEW_SECURE_NAME%' -replace '\\','/' >> "%PS_SEC_TEMP%"
echo $root = '%SCRIPT_DIR%'.TrimEnd('\') >> "%PS_SEC_TEMP%"
echo $dest = Join-Path $root ($name -replace '/','\') >> "%PS_SEC_TEMP%"
echo if (Test-Path $dest) { Write-Host '  X Error: destination already exists'; exit 1 } >> "%PS_SEC_TEMP%"
echo $segments = ($name -split '/').Count >> "%PS_SEC_TEMP%"
echo if ($segments -gt 5) { Write-Host '  X Error: path too deep (max 5 levels)'; exit 1 } >> "%PS_SEC_TEMP%"
echo $parent = Split-Path $dest >> "%PS_SEC_TEMP%"
echo if ($parent -and -not (Test-Path $parent)) { New-Item -ItemType Directory -Path $parent -Force ^| Out-Null } >> "%PS_SEC_TEMP%"
echo Move-Item $src $dest >> "%PS_SEC_TEMP%"
echo Write-Host "  + Renamed to $name" >> "%PS_SEC_TEMP%"
echo exit 0 >> "%PS_SEC_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SEC_TEMP%" 2>nul
if errorlevel 1 (
    echo   X Failed to rename. Check permissions or close open files.
    del "%PS_SEC_TEMP%" 2>nul
    goto :eof
)
del "%PS_SEC_TEMP%" 2>nul

set "SECURE_FOLDER_NAME=%NEW_SECURE_NAME%"
set "SECURE_DIR=%SCRIPT_DIR%%NEW_SECURE_NAME%"

REM Update SECURE_FOLDER_NAME in init.php
set "INIT_FILE=%PUBLIC_DIR%\init.php"
if exist "%INIT_FILE%" (
    call :update_init_constant "%INIT_FILE%" "SECURE_FOLDER_NAME" "%NEW_SECURE_NAME%"
)
echo.

REM ==========================================================
REM Step 3: Set URL space / prefix
REM ==========================================================
:step3
echo.
echo Step 3 - URL space / prefix
echo.
echo   Add a URL prefix so the site is served from a subdirectory.
echo   Example: "web" makes the site http://domain/web/
echo   Leave empty to serve from root (http://domain/).
echo.
set "NEW_SPACE="
set /p "NEW_SPACE=  Space (Enter for none): "

if "%NEW_SPACE%"=="" (
    echo   OK No space - serving from root
    goto :done
)

REM Validate characters and move files via PowerShell (more reliable for complex ops)
set "PS_SPACE_TEMP=%TEMP%\qs_setup_space.ps1"

echo $space = '%NEW_SPACE%' > "%PS_SPACE_TEMP%"
echo $publicDir = '%PUBLIC_DIR%' >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Trim slashes >> "%PS_SPACE_TEMP%"
echo $space = $space.Trim('/\') >> "%PS_SPACE_TEMP%"
echo if (-not $space) { Write-Host '  OK No space - serving from root'; exit 0 } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Validate chars >> "%PS_SPACE_TEMP%"
echo if ($space -notmatch '^[a-zA-Z0-9._/\-]+$') { >> "%PS_SPACE_TEMP%"
echo   Write-Host '  X Error: invalid characters in space name' >> "%PS_SPACE_TEMP%"
echo   Write-Host '  Allowed: a-z A-Z 0-9 . - _ /' >> "%PS_SPACE_TEMP%"
echo   exit 1 >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Validate depth >> "%PS_SPACE_TEMP%"
echo $depth = ($space -split '/').Count >> "%PS_SPACE_TEMP%"
echo if ($depth -gt 5) { Write-Host '  X Error: space path too deep (max 5 levels)'; exit 1 } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo $spaceDir = Join-Path $publicDir $space >> "%PS_SPACE_TEMP%"
echo if (Test-Path $spaceDir) { Write-Host "  X Error: directory '$space' already exists inside public folder"; exit 1 } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Create space directory >> "%PS_SPACE_TEMP%"
echo New-Item -ItemType Directory -Path $spaceDir -Force ^| Out-Null >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Get top-level segment to skip >> "%PS_SPACE_TEMP%"
echo $topSegment = ($space -split '/')[0] >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Move everything from public root into space directory >> "%PS_SPACE_TEMP%"
echo Get-ChildItem -Path $publicDir -Force ^| Where-Object { $_.Name -ne $topSegment } ^| ForEach-Object { >> "%PS_SPACE_TEMP%"
echo   Move-Item $_.FullName -Destination $spaceDir -Force >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Update PUBLIC_FOLDER_SPACE in init.php >> "%PS_SPACE_TEMP%"
echo $initFile = Join-Path $spaceDir 'init.php' >> "%PS_SPACE_TEMP%"
echo if (Test-Path $initFile) { >> "%PS_SPACE_TEMP%"
echo   $c = Get-Content $initFile -Raw >> "%PS_SPACE_TEMP%"
echo   $c = $c -replace "define\('PUBLIC_FOLDER_SPACE',\s*'[^']*'\)", "define('PUBLIC_FOLDER_SPACE', '$space')" >> "%PS_SPACE_TEMP%"
echo   [IO.File]::WriteAllText($initFile, $c, [System.Text.Encoding]::UTF8) >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Update .htaccess FallbackResource lines >> "%PS_SPACE_TEMP%"
echo $htMain = Join-Path $spaceDir '.htaccess' >> "%PS_SPACE_TEMP%"
echo $htMgmt = Join-Path $spaceDir 'management\.htaccess' >> "%PS_SPACE_TEMP%"
echo $htAdmin = Join-Path $spaceDir 'admin\.htaccess' >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo foreach ($pair in @( >> "%PS_SPACE_TEMP%"
echo   @{ File=$htMain; Fallback="/$space/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=$htMgmt; Fallback="/$space/management/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=$htAdmin; Fallback="/$space/admin/index.php" } >> "%PS_SPACE_TEMP%"
echo )) { >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $pair.File) { >> "%PS_SPACE_TEMP%"
echo     $c = Get-Content $pair.File -Raw >> "%PS_SPACE_TEMP%"
echo     $c = $c -replace 'FallbackResource .*', ('FallbackResource ' + $pair.Fallback) >> "%PS_SPACE_TEMP%"
echo     [IO.File]::WriteAllText($pair.File, $c, [System.Text.Encoding]::UTF8) >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo Write-Host "  + Space set: site at http://domain/$space/" >> "%PS_SPACE_TEMP%"
echo exit 0 >> "%PS_SPACE_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SPACE_TEMP%" 2>nul
if errorlevel 1 (
    echo   X Failed to set URL space
    del "%PS_SPACE_TEMP%" 2>nul
    goto :eof
)
set "PUBLIC_SPACE=%NEW_SPACE%"
del "%PS_SPACE_TEMP%" 2>nul
echo.

:done

REM Save config for re-run detection
(
    echo PUBLIC_FOLDER_NAME=%PUBLIC_FOLDER_NAME%
    echo SECURE_FOLDER_NAME=%SECURE_FOLDER_NAME%
    echo PUBLIC_FOLDER_SPACE=%PUBLIC_SPACE%
) > "%CONF_FILE%"

echo.
echo ========================================
echo   Setup complete
echo ========================================
echo.
echo   Public folder:  %PUBLIC_FOLDER_NAME%
echo   Secure folder:  %SECURE_FOLDER_NAME%
if not "%PUBLIC_SPACE%"=="" (
    echo   URL space:      %PUBLIC_SPACE%
)
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
goto :eof

REM ==========================================================
REM Helper: Update a define() constant in init.php
REM Usage: call :update_init_constant "file.php" "CONSTANT_NAME" "new_value"
REM ==========================================================
:update_init_constant
set "UIC_FILE=%~1"
set "UIC_NAME=%~2"
set "UIC_VALUE=%~3"

set "PS_TEMP=%TEMP%\qs_setup_const.ps1"
echo $f = '%UIC_FILE%' > "%PS_TEMP%"
echo $n = '%UIC_NAME%' >> "%PS_TEMP%"
echo $v = '%UIC_VALUE%' >> "%PS_TEMP%"
echo $c = Get-Content $f -Raw >> "%PS_TEMP%"
echo $c = $c -replace "define\('$n',\s*'[^']*'\)", "define('$n', '$v')" >> "%PS_TEMP%"
echo [IO.File]::WriteAllText($f, $c, [System.Text.Encoding]::UTF8) >> "%PS_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_TEMP%" 2>nul
if errorlevel 1 (
    echo   ? Could not update %UIC_NAME% in init.php - edit manually
) else (
    echo   + Updated %UIC_NAME% in init.php
)
del "%PS_TEMP%" 2>nul
goto :eof
