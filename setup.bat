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
        if "%%a"=="PUBLIC_SPACE" set "PUBLIC_SPACE=%%b"
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

REM Auto-detect if configured folder doesn't exist (crash recovery)
if not exist "%PUBLIC_DIR%" (
    for /d %%d in ("%SCRIPT_DIR%*") do (
        if exist "%%d\init.php" (
            set "PUBLIC_FOLDER_NAME=%%~nxd"
            set "PUBLIC_DIR=!SCRIPT_DIR!!PUBLIC_FOLDER_NAME!"
        )
    )
)

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
    echo   X Error: public folder "%PUBLIC_FOLDER_NAME%" not found
    goto :eof
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

REM Update PUBLIC_FOLDER_NAME in init.php (account for URL space)
if not "%PUBLIC_SPACE%"=="" (
    set "INIT_FILE=%SCRIPT_DIR%%NEW_PUBLIC_NAME%\%PUBLIC_SPACE%\init.php"
) else (
    set "INIT_FILE=%SCRIPT_DIR%%NEW_PUBLIC_NAME%\init.php"
)
if not exist "!INIT_FILE!" goto :step2

call :update_init_constant "!INIT_FILE!" "PUBLIC_FOLDER_NAME" "%NEW_PUBLIC_NAME%"

REM Save config after step 1 (crash recovery)
(
    echo PUBLIC_FOLDER_NAME=%PUBLIC_FOLDER_NAME%
    echo SECURE_FOLDER_NAME=%SECURE_FOLDER_NAME%
    echo PUBLIC_SPACE=%PUBLIC_SPACE%
) > "%CONF_FILE%"
echo.

REM ==========================================================
REM Step 2: Rename secure folder
REM ==========================================================
:step2

REM Save config after step 1 even if public wasn't renamed
if not exist "%CONF_FILE%" (
    (
        echo PUBLIC_FOLDER_NAME=%PUBLIC_FOLDER_NAME%
        echo SECURE_FOLDER_NAME=%SECURE_FOLDER_NAME%
        echo PUBLIC_SPACE=%PUBLIC_SPACE%
    ) > "%CONF_FILE%"
)
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
echo if (Test-Path $dest) { >> "%PS_SEC_TEMP%"
echo   # Check un-nesting: source is inside destination (e.g. secure/test -^> secure) >> "%PS_SEC_TEMP%"
echo   if ($src.StartsWith($dest + '\')) { >> "%PS_SEC_TEMP%"
echo     # Un-nesting - don't error, will handle below >> "%PS_SEC_TEMP%"
echo   } else { >> "%PS_SEC_TEMP%"
echo     Write-Host '  X Error: destination already exists'; exit 1 >> "%PS_SEC_TEMP%"
echo   } >> "%PS_SEC_TEMP%"
echo } >> "%PS_SEC_TEMP%"
echo $segments = ($name -split '/').Count >> "%PS_SEC_TEMP%"
echo if ($segments -gt 5) { Write-Host '  X Error: path too deep (max 5 levels)'; exit 1 } >> "%PS_SEC_TEMP%"
echo $parent = Split-Path $dest >> "%PS_SEC_TEMP%"
echo # Check if target is nested inside source (e.g. secure -^> secure/test) >> "%PS_SEC_TEMP%"
echo if ($src.StartsWith($dest + '\')) { >> "%PS_SEC_TEMP%"
echo   # Un-nesting (e.g. secure/test -^> secure): target is ancestor of source >> "%PS_SEC_TEMP%"
echo   $tmp = Join-Path $root '.secure_move_tmp' >> "%PS_SEC_TEMP%"
echo   Move-Item $src $tmp >> "%PS_SEC_TEMP%"
echo   # Remove empty parent chain up to target >> "%PS_SEC_TEMP%"
echo   $cleanDir = Split-Path $src >> "%PS_SEC_TEMP%"
echo   while ($cleanDir -ne $root -and (Test-Path $cleanDir)) { >> "%PS_SEC_TEMP%"
echo     if ((Get-ChildItem $cleanDir -Force).Count -eq 0) { >> "%PS_SEC_TEMP%"
echo       Remove-Item $cleanDir >> "%PS_SEC_TEMP%"
echo       $cleanDir = Split-Path $cleanDir >> "%PS_SEC_TEMP%"
echo     } else { break } >> "%PS_SEC_TEMP%"
echo   } >> "%PS_SEC_TEMP%"
echo   Move-Item $tmp $dest >> "%PS_SEC_TEMP%"
echo } elseif ($dest.StartsWith($src + '\')) { >> "%PS_SEC_TEMP%"
echo   # Self-nesting (e.g. secure -^> secure/test) >> "%PS_SEC_TEMP%"
echo   $tmp = Join-Path $root '.secure_move_tmp' >> "%PS_SEC_TEMP%"
echo   Move-Item $src $tmp >> "%PS_SEC_TEMP%"
echo   if ($parent) { New-Item -ItemType Directory -Path $parent -Force ^| Out-Null } >> "%PS_SEC_TEMP%"
echo   Move-Item $tmp $dest >> "%PS_SEC_TEMP%"
echo } elseif ($parent -and -not (Test-Path $parent)) { >> "%PS_SEC_TEMP%"
echo   New-Item -ItemType Directory -Path $parent -Force ^| Out-Null >> "%PS_SEC_TEMP%"
echo   Move-Item $src $dest >> "%PS_SEC_TEMP%"
echo } else { >> "%PS_SEC_TEMP%"
echo   Move-Item $src $dest >> "%PS_SEC_TEMP%"
echo } >> "%PS_SEC_TEMP%"
echo # Cleanup empty parent directories from old nested path >> "%PS_SEC_TEMP%"
echo $oldParent = Split-Path $src >> "%PS_SEC_TEMP%"
echo while ($oldParent -ne $root -and (Test-Path $oldParent)) { >> "%PS_SEC_TEMP%"
echo   if ((Get-ChildItem $oldParent -Force).Count -eq 0) { >> "%PS_SEC_TEMP%"
echo     Remove-Item $oldParent >> "%PS_SEC_TEMP%"
echo     $oldParent = Split-Path $oldParent >> "%PS_SEC_TEMP%"
echo   } else { break } >> "%PS_SEC_TEMP%"
echo } >> "%PS_SEC_TEMP%"
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

REM Update SECURE_FOLDER_NAME in init.php (account for URL space)
if not "%PUBLIC_SPACE%"=="" (
    set "INIT_FILE=%PUBLIC_DIR%\%PUBLIC_SPACE%\init.php"
) else (
    set "INIT_FILE=%PUBLIC_DIR%\init.php"
)
if exist "!INIT_FILE!" (
    call :update_init_constant "!INIT_FILE!" "SECURE_FOLDER_NAME" "%NEW_SECURE_NAME%"
)

REM Save config after step 2 (crash recovery)
(
    echo PUBLIC_FOLDER_NAME=%PUBLIC_FOLDER_NAME%
    echo SECURE_FOLDER_NAME=%SECURE_FOLDER_NAME%
    echo PUBLIC_SPACE=%PUBLIC_SPACE%
) > "%CONF_FILE%"
echo.

REM ==========================================================
REM Step 3: Set URL space / prefix
REM ==========================================================
:step3
echo.
echo Step 3 - URL space / prefix
echo.
echo   A URL space serves the site from a subdirectory.
echo   Example: "web" makes the site http://domain/web/

if "%PUBLIC_SPACE%"=="" goto :step3_no_space

echo.
echo   Current space: %PUBLIC_SPACE%
echo.
echo   Enter a new space, type "none" to remove it,
echo   or press Enter to keep the current space.
echo.
set "SPACE_INPUT="
set /p "SPACE_INPUT=  Space: "

if "!SPACE_INPUT!"=="" (
    echo   OK Keeping "%PUBLIC_SPACE%"
    goto :done
)
if /i "!SPACE_INPUT!"=="none" (
    set "DESIRED_SPACE="
    goto :step3_apply
)
if "!SPACE_INPUT!"=="%PUBLIC_SPACE%" (
    echo   OK Keeping "%PUBLIC_SPACE%"
    goto :done
)
set "DESIRED_SPACE=!SPACE_INPUT!"
goto :step3_apply

:step3_no_space
echo.
echo   No space currently set.
echo   Press Enter to skip, or enter a space name.
echo.
set "SPACE_INPUT="
set /p "SPACE_INPUT=  Space (Enter for none): "

if "!SPACE_INPUT!"=="" (
    echo   OK No space - serving from root
    goto :done
)
set "DESIRED_SPACE=!SPACE_INPUT!"

:step3_apply
REM Apply space change via PowerShell (handles validation, file moves, config updates)
set "PS_SPACE_TEMP=%TEMP%\qs_setup_space.ps1"

echo $publicDir = '%PUBLIC_DIR%' > "%PS_SPACE_TEMP%"
echo $oldSpace = '%PUBLIC_SPACE%' >> "%PS_SPACE_TEMP%"
echo $newSpace = '!DESIRED_SPACE!' >> "%PS_SPACE_TEMP%"
echo $newSpace = $newSpace.Trim('/\') >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Validate new space >> "%PS_SPACE_TEMP%"
echo if ($newSpace -and $newSpace -notmatch '^[a-zA-Z0-9._/\-]+$') { >> "%PS_SPACE_TEMP%"
echo   Write-Host '  X Error: invalid characters in space name' >> "%PS_SPACE_TEMP%"
echo   Write-Host '  Allowed: a-z A-Z 0-9 . - _ /' >> "%PS_SPACE_TEMP%"
echo   exit 1 >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo if ($newSpace -and ($newSpace -split '/').Count -gt 5) { >> "%PS_SPACE_TEMP%"
echo   Write-Host '  X Error: path too deep (max 5 levels)' >> "%PS_SPACE_TEMP%"
echo   exit 1 >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Remove old space (move files back to public root) >> "%PS_SPACE_TEMP%"
echo if ($oldSpace) { >> "%PS_SPACE_TEMP%"
echo   $oldDir = Join-Path $publicDir ($oldSpace -replace '/','\'  ) >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $oldDir) { >> "%PS_SPACE_TEMP%"
echo     Get-ChildItem $oldDir -Force ^| ForEach-Object { Move-Item $_.FullName $publicDir -Force } >> "%PS_SPACE_TEMP%"
echo     $top = ($oldSpace -split '/')[0] >> "%PS_SPACE_TEMP%"
echo     Remove-Item (Join-Path $publicDir $top) -Recurse -Force -ErrorAction SilentlyContinue >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Set new space (move files into space dir) >> "%PS_SPACE_TEMP%"
echo if ($newSpace) { >> "%PS_SPACE_TEMP%"
echo   $newDir = Join-Path $publicDir ($newSpace -replace '/','\') >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $newDir) { Write-Host "  X Error: '$newSpace' already exists"; exit 1 } >> "%PS_SPACE_TEMP%"
echo   New-Item -ItemType Directory -Path $newDir -Force ^| Out-Null >> "%PS_SPACE_TEMP%"
echo   $top = ($newSpace -split '/')[0] >> "%PS_SPACE_TEMP%"
echo   Get-ChildItem $publicDir -Force ^| Where-Object { $_.Name -ne $top } ^| ForEach-Object { >> "%PS_SPACE_TEMP%"
echo     Move-Item $_.FullName $newDir -Force >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Determine where init.php and .htaccess are now >> "%PS_SPACE_TEMP%"
echo $initDir = if ($newSpace) { Join-Path $publicDir ($newSpace -replace '/','\') } else { $publicDir } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Update init.php >> "%PS_SPACE_TEMP%"
echo $initFile = Join-Path $initDir 'init.php' >> "%PS_SPACE_TEMP%"
echo if (Test-Path $initFile) { >> "%PS_SPACE_TEMP%"
echo   $c = Get-Content $initFile -Raw >> "%PS_SPACE_TEMP%"
echo   $c = $c -replace "define\('PUBLIC_FOLDER_SPACE',\s*'[^']*'\)", "define('PUBLIC_FOLDER_SPACE', '$newSpace')" >> "%PS_SPACE_TEMP%"
echo   [IO.File]::WriteAllText($initFile, $c, [System.Text.Encoding]::UTF8) >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo # Update .htaccess FallbackResource >> "%PS_SPACE_TEMP%"
echo $prefix = if ($newSpace) { "/$newSpace" } else { '' } >> "%PS_SPACE_TEMP%"
echo foreach ($pair in @( >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir '.htaccess'); Fallback="$prefix/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir 'management\.htaccess'); Fallback="$prefix/management/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir 'admin\.htaccess'); Fallback="$prefix/admin/index.php" } >> "%PS_SPACE_TEMP%"
echo )) { >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $pair.File) { >> "%PS_SPACE_TEMP%"
echo     $c = Get-Content $pair.File -Raw >> "%PS_SPACE_TEMP%"
echo     $c = $c -replace 'FallbackResource .*', ('FallbackResource ' + $pair.Fallback) >> "%PS_SPACE_TEMP%"
echo     [IO.File]::WriteAllText($pair.File, $c, [System.Text.Encoding]::UTF8) >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo. >> "%PS_SPACE_TEMP%"
echo if ($newSpace) { Write-Host "  + Space set: $newSpace" } else { Write-Host '  + Space removed - serving from root' } >> "%PS_SPACE_TEMP%"
echo exit 0 >> "%PS_SPACE_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SPACE_TEMP%" 2>nul
if errorlevel 1 (
    echo   X Failed to update URL space
    del "%PS_SPACE_TEMP%" 2>nul
    goto :eof
)
set "PUBLIC_SPACE=!DESIRED_SPACE!"
del "%PS_SPACE_TEMP%" 2>nul
echo.

:done

REM Save config for re-run detection
(
    echo PUBLIC_FOLDER_NAME=%PUBLIC_FOLDER_NAME%
    echo SECURE_FOLDER_NAME=%SECURE_FOLDER_NAME%
    echo PUBLIC_SPACE=%PUBLIC_SPACE%
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
