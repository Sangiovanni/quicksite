@echo off
REM ==========================================================
REM QuickSite - Post-Clone Setup (Windows)
REM ==========================================================
REM
REM Run this after cloning, before your first page load.
REM
REM Usage:
REM   setup.bat                       (menu)
REM   setup.bat www.example.com       (pre-answer the public-folder rename,
REM                                    then show the menu)
REM
REM A MENU, NOT A WALK. Every item is independent and re-runnable: come back
REM later to change one thing without answering the other five again. The
REM settings you choose are remembered in .quicksite.conf and read back the
REM next time you run this.
REM
REM   1. Public folder name   - match your vhost DocumentRoot (www, public_html)
REM   2. Secure folder name   - rename/nest the engine folder for obscurity
REM   3. URL space            - serve from a subdirectory (http://domain/web/)
REM   4. Environment          - production (default) or development
REM   5. Self-registration    - may visitors create their own accounts?
REM   6. Setup token          - read the first-run credential off disk
REM
REM Items 1-3 rewrite init.php constants and the .htaccess routing. Items 4-5
REM write config files under the secure folder. Item 6 only reads.
REM
REM WHAT THIS SCRIPT CANNOT DO: create your account. No account exists yet, so
REM there is nobody to be one. That happens in the browser at /admin/, and the
REM setup token (item 6) is what authorises it - see the notes there.
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
REM Has setup ever run here? Captured BEFORE save_conf writes the file, because
REM the landing-page drop below is a first-launch-only action.
set "FIRST_LAUNCH=yes"
if exist "%CONF_FILE%" set "FIRST_LAUNCH=no"
if exist "%CONF_FILE%" (
    for /f "usebackq tokens=1,2 delims==" %%a in ("%CONF_FILE%") do (
        if "%%a"=="PUBLIC_FOLDER_NAME" set "PUBLIC_FOLDER_NAME=%%b"
        if "%%a"=="SECURE_FOLDER_NAME" set "SECURE_FOLDER_NAME=%%b"
        if "%%a"=="PUBLIC_SPACE" set "PUBLIC_SPACE=%%b"
    )
    set "PUBLIC_DIR=!SCRIPT_DIR!!PUBLIC_FOLDER_NAME!"
    set "SECURE_DIR=!SCRIPT_DIR!!SECURE_FOLDER_NAME!"
)

REM ---- Crash recovery: locate folders renamed without us -------------------
if not exist "!PUBLIC_DIR!" (
    for /d %%d in ("%SCRIPT_DIR%*") do (
        if exist "%%d\init.php" (
            set "PUBLIC_FOLDER_NAME=%%~nxd"
            set "PUBLIC_DIR=!SCRIPT_DIR!%%~nxd"
        )
    )
)
if not exist "!SECURE_DIR!" (
    if exist "!PUBLIC_DIR!\init.php" (
        for /f "tokens=2 delims='" %%a in ('findstr /c:"SECURE_FOLDER_NAME" "!PUBLIC_DIR!\init.php" 2^>nul') do (
            if exist "%SCRIPT_DIR%%%a" (
                set "SECURE_DIR=%SCRIPT_DIR%%%a"
                set "SECURE_FOLDER_NAME=%%a"
            )
        )
    )
)

echo.
echo ========================================
echo        QuickSite Setup (Windows)
echo ========================================

call :maybe_place_landing_page
call :save_conf

REM A public folder name given on the command line pre-answers item 1.
if not "%~1"=="" (
    set "ARG_PUBLIC=%~1"
    call :item_public
    call :save_conf
)

REM ==========================================================
REM The menu
REM ==========================================================
:menu
call :read_state
echo.
echo   Public folder:      !PUBLIC_FOLDER_NAME!
echo   Secure folder:      !SECURE_FOLDER_NAME!
echo   URL space:          !SPACE_LABEL!
echo   Environment:        !ENV_LABEL!
echo   Self-registration:  !SELFREG_LABEL!
echo   Storage quotas:     !QUOTA_LABEL!
echo.
echo     1^) Rename the public folder
echo     2^) Rename the secure folder
echo     3^) Change the URL space / prefix
echo     4^) Switch environment (development / production)
echo     5^) Turn self-registration on / off
echo     6^) Show my setup token
echo     7^) Storage quotas (per user)
echo     q^) Finish
echo.
set "CHOICE="
set /p "CHOICE=  Choice: "

if "!CHOICE!"=="1" goto :go_public
if "!CHOICE!"=="2" goto :go_secure
if "!CHOICE!"=="3" goto :go_space
if "!CHOICE!"=="4" goto :go_env
if "!CHOICE!"=="5" goto :go_selfreg
if "!CHOICE!"=="6" goto :go_token
if "!CHOICE!"=="7" goto :go_quota
if /i "!CHOICE!"=="q" goto :finish
if "!CHOICE!"=="" goto :finish
echo   Unknown choice: !CHOICE!
goto :menu

:go_public
set "ARG_PUBLIC="
call :item_public
call :save_conf
call :pause_menu
goto :menu

:go_secure
call :item_secure
call :save_conf
call :pause_menu
goto :menu

:go_space
call :item_space
call :save_conf
call :pause_menu
goto :menu

:go_env
call :item_environment
call :pause_menu
goto :menu

:go_selfreg
call :item_selfreg
call :pause_menu
goto :menu

:go_token
call :item_token
call :pause_menu
goto :menu

:go_quota
call :item_quota
call :pause_menu
goto :menu

REM ==========================================================
REM Finish
REM ==========================================================
:finish
call :save_conf
call :read_state
echo.
echo ========================================
echo   Setup complete
echo ========================================
echo.
echo   Public folder:      !PUBLIC_FOLDER_NAME!
echo   Secure folder:      !SECURE_FOLDER_NAME!
echo   URL space:          !SPACE_LABEL!
echo   Environment:        !ENV_LABEL!
echo   Self-registration:  !SELFREG_LABEL!
echo.
echo   Next steps:
echo     1. Point your vhost DocumentRoot at the public folder
echo     2. Restart your web server
echo     3. Load http://your-domain/admin/ once - this creates the config
echo        files and mints your setup token
echo     4. On nginx: the page that appears tells you which file to include
echo        in your server block; add it and reload nginx. On Apache: nothing
echo        to do.
echo     5. Run setup.bat again and choose item 6 to read your setup token,
echo        then create your first account on the /admin/ page.
echo.
echo   That first account becomes the owner of the starter project, so the
echo   panel has something to edit the moment you sign in.
echo.
pause
goto :eof

REM ==========================================================
REM State readers - the menu shows what is actually on disk
REM ==========================================================
REM ==========================================================
REM Item 7 - storage quotas
REM ==========================================================
REM Absent means NO LIMITS, and that is a real setting rather than a missing
REM one: a fresh install has no quota.php and must not refuse uploads out of
REM the box. Leaving both prompts blank therefore leaves the file ABSENT — it
REM does not write zeros. Setup also never deletes a quota.php somebody wrote;
REM it says where the file is instead.
:item_quota
call :read_state
echo.
echo   Storage quotas
echo.
echo   Limits applied per ACCOUNT, on two independent axes. Any signed-in
echo   user can otherwise upload until the disk is full.
echo.
echo     total bytes   the combined size of everything the account owns.
echo                   Refused with 507 before the write, not after.
echo     upload rate   how many uploads it may perform per period. Bounds
echo                   churn (upload, delete, repeat) rather than volume.
echo.
echo   Currently: !QUOTA_LABEL!
echo.
echo   Leave both blank for no limits. Full documentation of every option
echo   lives in quota.php.example, which is never modified.
echo.

set "Q_MB="
set /p "Q_MB=  Max total size per user, in MB [blank = unlimited]: "
set "Q_UP="
set /p "Q_UP=  Max uploads per period       [blank = unlimited]: "

REM Reject non-numeric input rather than coercing it: "2GB" would otherwise
REM become 0, i.e. unlimited, which is the opposite of what was asked for.
if not "!Q_MB!"=="" (
    for /f "delims=0123456789" %%A in ("!Q_MB!") do (
        echo   X Not a whole number of MB: !Q_MB!
        goto :eof
    )
)
if not "!Q_UP!"=="" (
    for /f "delims=0123456789" %%A in ("!Q_UP!") do (
        echo   X Not a whole number: !Q_UP!
        goto :eof
    )
)

set "Q_HOURS="
if not "!Q_UP!"=="" if not "!Q_UP!"=="0" (
    set /p "Q_HOURS=  ...per how many hours [1]: "
    if "!Q_HOURS!"=="" set "Q_HOURS=1"
    for /f "delims=0123456789" %%A in ("!Q_HOURS!") do (
        echo   X Not a whole number of hours: !Q_HOURS!
        goto :eof
    )
    if "!Q_HOURS!"=="0" (
        echo   X Not a whole number of hours: 0
        goto :eof
    )
)

set "Q_BYTES=0"
set "Q_MAXUP=0"
set "Q_PERIOD=3600"
if not "!Q_MB!"==""    set /a Q_BYTES=!Q_MB! * 1024 * 1024
if not "!Q_UP!"==""    set "Q_MAXUP=!Q_UP!"
if not "!Q_HOURS!"=="" set /a Q_PERIOD=!Q_HOURS! * 3600

if "!Q_BYTES!"=="0" if "!Q_MAXUP!"=="0" (
    if not exist "!QUOTA_FILE!" (
        echo.
        echo   + No limits - quota.php left absent, which is what a fresh
        echo     install has. Nothing is refused.
        goto :eof
    )
    echo.
    echo   quota.php already exists. To go back to no limits, delete it:
    echo   !QUOTA_FILE!
    echo   Setup does not remove a config file you wrote.
    goto :eof
)

REM quota.php.example is the shipped documentation and is never edited: the
REM live file is a COPY with the numbers patched, so every explanatory comment
REM survives into the file the deployer will actually read later.
if not exist "!QUOTA_FILE!" (
    if not exist "!CONFIG_DIR!\quota.php.example" (
        echo   X Error: quota.php.example is missing
        goto :eof
    )
    copy /y "!CONFIG_DIR!\quota.php.example" "!QUOTA_FILE!" >nul
)

set "PS_Q_TEMP=%TEMP%\qs_setup_quota.ps1"
echo $f = '!QUOTA_FILE!' > "%PS_Q_TEMP%"
echo $b = '!Q_BYTES!' >> "%PS_Q_TEMP%"
echo $u = '!Q_MAXUP!' >> "%PS_Q_TEMP%"
echo $p = '!Q_PERIOD!' >> "%PS_Q_TEMP%"
echo $c = Get-Content $f -Raw >> "%PS_Q_TEMP%"
REM Anchored per-line so the COMMENTED example value above each setting (those
REM lines begin with //) is left alone. The '>' characters sit inside double
REM quotes, which is what stops cmd reading them as redirects - no caret.
echo $c = $c -replace "(?m)^(\s*)'max_total_bytes'\s*=>.*", ("${1}'max_total_bytes' => " + $b + ",") >> "%PS_Q_TEMP%"
echo $c = $c -replace "(?m)^(\s*)'max_uploads'\s*=>.*", ("${1}'max_uploads'    => " + $u + ",") >> "%PS_Q_TEMP%"
echo $c = $c -replace "(?m)^(\s*)'period_seconds'\s*=>.*", ("${1}'period_seconds' => " + $p + ",") >> "%PS_Q_TEMP%"
echo [IO.File]::WriteAllText($f, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_Q_TEMP%"
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_Q_TEMP%" >nul 2>&1

call :read_state
echo.
echo   + Quotas written to quota.php
echo     !QUOTA_LABEL!
goto :eof

:read_state
set "CONFIG_DIR=!SECURE_DIR!\management\config"
set "ENV_FILE=!CONFIG_DIR!\environment.php"
set "AUTH_FILE=!CONFIG_DIR!\auth.php"
set "TOKEN_FILE=!CONFIG_DIR!\setup-token.txt"

if "!PUBLIC_SPACE!"=="" (
    set "SPACE_LABEL=(none) - served from the domain root"
) else (
    REM No caret before the '>' here: inside set "..." the quotes already stop
    REM cmd reading it as a redirect, and an escape it does not need survives
    REM into the value as a literal '^'.
    set "SPACE_LABEL=!PUBLIC_SPACE!  -> http://your-domain/!PUBLIC_SPACE!/"
)

set "ENV_LABEL=production  (default - no environment.php yet)"
if exist "!ENV_FILE!" (
    set "ENV_LABEL=production  (value unreadable - check environment.php)"
    findstr /c:"'environment' => 'production'" "!ENV_FILE!" >nul 2>&1 && set "ENV_LABEL=production"
    findstr /c:"'environment' => 'development'" "!ENV_FILE!" >nul 2>&1 && set "ENV_LABEL=development  (may call localhost / LAN addresses)"
)

set "QUOTA_FILE=!CONFIG_DIR!\quota.php"
set "QUOTA_LABEL=no limits  (default - quota.php not created yet)"
if exist "!QUOTA_FILE!" (
    set "QUOTA_LABEL=limits set  (see quota.php)"
    findstr /c:"'max_total_bytes' => 0," "!QUOTA_FILE!" >nul 2>&1 && findstr /c:"'max_uploads'    => 0," "!QUOTA_FILE!" >nul 2>&1 && set "QUOTA_LABEL=no limits  (quota.php present, both axes unlimited)"
)

set "SELFREG_LABEL=off  (default - auth.php not created yet)"
if exist "!AUTH_FILE!" (
    set "SELFREG_LABEL=off  (value unreadable - check auth.php)"
    findstr /c:"'allow_self_registration' => false" "!AUTH_FILE!" >nul 2>&1 && set "SELFREG_LABEL=off"
    findstr /c:"'allow_self_registration' => true" "!AUTH_FILE!" >nul 2>&1 && set "SELFREG_LABEL=on   (anyone may create an account at /admin/register)"
)
goto :eof

REM A line-oriented "press Enter", not `pause`. `pause` consumes a single
REM CHARACTER, so on a redirected stdin it splits a line in half and the menu
REM then reads the remainder as a bogus choice. set /p consumes a whole line,
REM which behaves identically for a person and correctly for a script.
:pause_menu
echo.
set "QS_ANYKEY="
set /p "QS_ANYKEY=  Press Enter to return to the menu... "
goto :eof

:save_conf
(
    echo PUBLIC_FOLDER_NAME=!PUBLIC_FOLDER_NAME!
    echo SECURE_FOLDER_NAME=!SECURE_FOLDER_NAME!
    echo PUBLIC_SPACE=!PUBLIC_SPACE!
) > "%CONF_FILE%"
goto :eof

REM ==========================================================
REM Landing page - first launch only, and only into an empty root
REM ==========================================================
REM QuickSite serves nothing at the web root by design, so a brand-new install
REM has nothing there and answers 403. That is correct and it looks broken,
REM which is the worst combination - hence a placeholder that says where the
REM panel is.
REM
REM TWO CONDITIONS, both required. It runs only on the FIRST setup launch, so
REM deleting the page is permanent rather than something the next run undoes;
REM and only when the root holds no index file of its own, so cloning QuickSite
REM onto a server that already has a site never overwrites that site's front
REM page.
:maybe_place_landing_page
if not "!FIRST_LAUNCH!"=="yes" goto :eof
call :resolve_init
if not exist "!SERVED_DIR!" goto :eof
set "QS_LANDING_SRC=!SECURE_DIR!\deploy\index.html.example"
if not exist "!QS_LANDING_SRC!" goto :eof
REM Any index.* at all - .php, .html, .htm, anything the server might pick as a
REM DirectoryIndex. If something is already there, it is not ours to touch.
if exist "!SERVED_DIR!\index.*" goto :eof
copy /y "!QS_LANDING_SRC!" "!SERVED_DIR!\index.html" >nul 2>&1
if errorlevel 1 goto :eof
echo.
echo   + Added a placeholder page at the web root
echo     The root serves nothing by default, so it would answer 403.
echo     Delete or replace !PUBLIC_FOLDER_NAME!\index.html when you put
echo     your own site there - nothing depends on it.
goto :eof

REM Where init.php lives right now (it moves with the URL space).
:resolve_init
if "!PUBLIC_SPACE!"=="" (
    set "INIT_FILE=!PUBLIC_DIR!\init.php"
    set "SERVED_DIR=!PUBLIC_DIR!"
) else (
    set "INIT_FILE=!PUBLIC_DIR!\!PUBLIC_SPACE!\init.php"
    set "SERVED_DIR=!PUBLIC_DIR!\!PUBLIC_SPACE!"
)
goto :eof

REM ==========================================================
REM Item 1 - public folder name
REM ==========================================================
:item_public
echo.
echo Public folder name
echo.
echo   Your vhost DocumentRoot must point at this folder. If the vhost
echo   expects a specific name (e.g. "www", "www.example.com",
echo   "public_html"), rename it here.
echo.
echo   Current name: !PUBLIC_FOLDER_NAME!
echo.

set "NEW_PUBLIC_NAME=!ARG_PUBLIC!"
if "!NEW_PUBLIC_NAME!"=="" set /p "NEW_PUBLIC_NAME=  New name (Enter to keep '!PUBLIC_FOLDER_NAME!'): "

if "!NEW_PUBLIC_NAME!"=="" (
    echo   OK Keeping "!PUBLIC_FOLDER_NAME!"
    goto :eof
)
if "!NEW_PUBLIC_NAME!"=="!PUBLIC_FOLDER_NAME!" (
    echo   OK Keeping "!PUBLIC_FOLDER_NAME!"
    goto :eof
)
echo "!NEW_PUBLIC_NAME!" | findstr /r "[/\\]" >nul 2>&1
if !errorlevel! equ 0 (
    echo   X Error: folder name cannot contain slashes
    goto :eof
)
if "!NEW_PUBLIC_NAME!"=="!SECURE_FOLDER_NAME!" (
    echo   X Error: cannot use the same name as the secure folder
    goto :eof
)
if not exist "!PUBLIC_DIR!" (
    echo   X Error: public folder "!PUBLIC_FOLDER_NAME!" not found
    goto :eof
)
if exist "!SCRIPT_DIR!!NEW_PUBLIC_NAME!" (
    echo   X Error: "!NEW_PUBLIC_NAME!" already exists
    goto :eof
)

rename "!PUBLIC_DIR!" "!NEW_PUBLIC_NAME!"
if errorlevel 1 (
    echo   X Failed to rename. Check permissions or close open files.
    goto :eof
)
set "PUBLIC_DIR=!SCRIPT_DIR!!NEW_PUBLIC_NAME!"
set "PUBLIC_FOLDER_NAME=!NEW_PUBLIC_NAME!"

call :resolve_init
if exist "!INIT_FILE!" call :update_init_constant "!INIT_FILE!" "PUBLIC_FOLDER_NAME" "!NEW_PUBLIC_NAME!"
echo   + Renamed to !NEW_PUBLIC_NAME!
goto :eof

REM ==========================================================
REM Item 2 - secure folder name
REM ==========================================================
:item_secure
echo.
echo Secure folder name
echo.
echo   The secure folder holds the QuickSite engine and every project's
echo   data. It is never served. Rename it for obscurity, or nest it.
echo   Examples: "backend", "app", "backends/project1"
echo.
echo   Current name: !SECURE_FOLDER_NAME!
echo.

set "NEW_SECURE_NAME="
set /p "NEW_SECURE_NAME=  New name (Enter to keep '!SECURE_FOLDER_NAME!'): "

if "!NEW_SECURE_NAME!"=="" (
    echo   OK Keeping "!SECURE_FOLDER_NAME!"
    goto :eof
)
if "!NEW_SECURE_NAME!"=="!SECURE_FOLDER_NAME!" (
    echo   OK Keeping "!SECURE_FOLDER_NAME!"
    goto :eof
)
if "!NEW_SECURE_NAME!"=="!PUBLIC_FOLDER_NAME!" (
    echo   X Error: cannot use the same name as the public folder
    goto :eof
)
if not exist "!SECURE_DIR!" (
    echo   X Error: secure folder "!SECURE_FOLDER_NAME!" not found
    goto :eof
)

REM Move via PowerShell (supports nested paths like backends/project1)
echo   Renaming: !SECURE_FOLDER_NAME! -^> !NEW_SECURE_NAME!
set "PS_SEC_TEMP=%TEMP%\qs_setup_secure.ps1"
echo $src = '!SECURE_DIR!' -replace '/','\' > "%PS_SEC_TEMP%"
echo $name = '!NEW_SECURE_NAME!' -replace '\\','/' >> "%PS_SEC_TEMP%"
echo $root = '%SCRIPT_DIR%'.TrimEnd('\') >> "%PS_SEC_TEMP%"
echo $dest = Join-Path $root ($name -replace '/','\') >> "%PS_SEC_TEMP%"
echo $segments = ($name -split '/').Count >> "%PS_SEC_TEMP%"
echo if ($segments -gt 5) { Write-Host '  X Error: path too deep (max 5 levels)'; exit 1 } >> "%PS_SEC_TEMP%"
echo if ((Test-Path $dest) -and -not $src.StartsWith($dest + '\')) { >> "%PS_SEC_TEMP%"
echo   Write-Host '  X Error: destination already exists'; exit 1 >> "%PS_SEC_TEMP%"
echo } >> "%PS_SEC_TEMP%"
echo $parent = Split-Path $dest >> "%PS_SEC_TEMP%"
echo if ($src.StartsWith($dest + '\')) { >> "%PS_SEC_TEMP%"
echo   # Un-nesting (e.g. secure/test -^> secure): target is ancestor of source >> "%PS_SEC_TEMP%"
echo   $tmp = Join-Path $root '.secure_move_tmp' >> "%PS_SEC_TEMP%"
echo   Move-Item $src $tmp >> "%PS_SEC_TEMP%"
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
echo # Cleanup empty parent directories from the old nested path >> "%PS_SEC_TEMP%"
echo $oldParent = Split-Path $src >> "%PS_SEC_TEMP%"
echo while ($oldParent -ne $root -and (Test-Path $oldParent)) { >> "%PS_SEC_TEMP%"
echo   if ((Get-ChildItem $oldParent -Force).Count -eq 0) { >> "%PS_SEC_TEMP%"
echo     Remove-Item $oldParent >> "%PS_SEC_TEMP%"
echo     $oldParent = Split-Path $oldParent >> "%PS_SEC_TEMP%"
echo   } else { break } >> "%PS_SEC_TEMP%"
echo } >> "%PS_SEC_TEMP%"
echo Write-Host "  + Renamed to $name" >> "%PS_SEC_TEMP%"
REM The vhost holds an ABSOLUTE include pointing INTO the secure folder, so a
REM rename moves that file out from under it and nginx refuses the next reload.
REM The failure surfaces later, when nobody connects it back to this menu.
echo Write-Host "" >> "%PS_SEC_TEMP%"
echo Write-Host "  ! nginx: your vhost still points at the OLD folder." >> "%PS_SEC_TEMP%"
echo Write-Host "    Update the include line in your server block to:" >> "%PS_SEC_TEMP%"
echo Write-Host "      include $dest\nginx\dynamic_routes.conf;" >> "%PS_SEC_TEMP%"
echo Write-Host "    then reload nginx. An Apache install needs nothing here." >> "%PS_SEC_TEMP%"
echo exit 0 >> "%PS_SEC_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SEC_TEMP%" <nul
if errorlevel 1 (
    del "%PS_SEC_TEMP%" 2>nul
    goto :eof
)
del "%PS_SEC_TEMP%" 2>nul

set "SECURE_FOLDER_NAME=!NEW_SECURE_NAME!"
set "SECURE_DIR=!SCRIPT_DIR!!NEW_SECURE_NAME!"

call :resolve_init
if exist "!INIT_FILE!" call :update_init_constant "!INIT_FILE!" "SECURE_FOLDER_NAME" "!NEW_SECURE_NAME!"
goto :eof

REM ==========================================================
REM Item 3 - URL space / prefix
REM ==========================================================
:item_space
echo.
echo URL space / prefix
echo.
echo   A URL space serves the site from a subdirectory of the domain.
echo   Example: "web" makes the site http://your-domain/web/
echo.

if "!PUBLIC_SPACE!"=="" goto :space_none_set

echo   Current space: !PUBLIC_SPACE!
echo.
echo   Enter a new space, type "none" to remove it, or press Enter
echo   to keep the current one.
echo.
set "SPACE_INPUT="
set /p "SPACE_INPUT=  Space: "
if "!SPACE_INPUT!"=="" (
    echo   OK Keeping "!PUBLIC_SPACE!"
    goto :eof
)
if "!SPACE_INPUT!"=="!PUBLIC_SPACE!" (
    echo   OK Keeping "!PUBLIC_SPACE!"
    goto :eof
)
if /i "!SPACE_INPUT!"=="none" (
    set "DESIRED_SPACE="
) else (
    set "DESIRED_SPACE=!SPACE_INPUT!"
)
goto :space_apply

:space_none_set
echo   No space currently set - the site serves from the domain root.
echo.
set "SPACE_INPUT="
set /p "SPACE_INPUT=  Space (Enter to keep serving from the root): "
if "!SPACE_INPUT!"=="" (
    echo   OK No space - serving from the root
    goto :eof
)
set "DESIRED_SPACE=!SPACE_INPUT!"

:space_apply
REM Everything the space change touches, in one PowerShell pass: validation,
REM the file moves, the init.php constant, and the .htaccess routing.
set "PS_SPACE_TEMP=%TEMP%\qs_setup_space.ps1"

echo $publicDir = '!PUBLIC_DIR!' > "%PS_SPACE_TEMP%"
echo $oldSpace = '!PUBLIC_SPACE!' >> "%PS_SPACE_TEMP%"
echo $newSpace = '!DESIRED_SPACE!' >> "%PS_SPACE_TEMP%"
echo $newSpace = $newSpace.Trim('/\') >> "%PS_SPACE_TEMP%"
echo if ($newSpace -and $newSpace -notmatch '^[a-zA-Z0-9._/\-]+$') { >> "%PS_SPACE_TEMP%"
echo   Write-Host '  X Error: invalid characters in space name' >> "%PS_SPACE_TEMP%"
echo   Write-Host '  Allowed: a-z A-Z 0-9 . - _ /' >> "%PS_SPACE_TEMP%"
echo   exit 1 >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo if ($newSpace -and ($newSpace -split '/').Count -gt 5) { >> "%PS_SPACE_TEMP%"
echo   Write-Host '  X Error: path too deep (max 5 levels)'; exit 1 >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo # Remove the old space (move files back to the public root) >> "%PS_SPACE_TEMP%"
echo if ($oldSpace) { >> "%PS_SPACE_TEMP%"
echo   $oldDir = Join-Path $publicDir ($oldSpace -replace '/','\') >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $oldDir) { >> "%PS_SPACE_TEMP%"
echo     Get-ChildItem $oldDir -Force ^| ForEach-Object { Move-Item $_.FullName $publicDir -Force } >> "%PS_SPACE_TEMP%"
echo     $top = ($oldSpace -split '/')[0] >> "%PS_SPACE_TEMP%"
echo     Remove-Item (Join-Path $publicDir $top) -Recurse -Force -ErrorAction SilentlyContinue >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo # Set the new space (move files into the space dir) >> "%PS_SPACE_TEMP%"
echo if ($newSpace) { >> "%PS_SPACE_TEMP%"
echo   $newDir = Join-Path $publicDir ($newSpace -replace '/','\') >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $newDir) { Write-Host "  X Error: '$newSpace' already exists"; exit 1 } >> "%PS_SPACE_TEMP%"
echo   New-Item -ItemType Directory -Path $newDir -Force ^| Out-Null >> "%PS_SPACE_TEMP%"
echo   $top = ($newSpace -split '/')[0] >> "%PS_SPACE_TEMP%"
echo   Get-ChildItem $publicDir -Force ^| Where-Object { $_.Name -ne $top } ^| ForEach-Object { >> "%PS_SPACE_TEMP%"
echo     Move-Item $_.FullName $newDir -Force >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo $initDir = if ($newSpace) { Join-Path $publicDir ($newSpace -replace '/','\') } else { $publicDir } >> "%PS_SPACE_TEMP%"
echo $initFile = Join-Path $initDir 'init.php' >> "%PS_SPACE_TEMP%"
echo if (Test-Path $initFile) { >> "%PS_SPACE_TEMP%"
echo   $c = Get-Content $initFile -Raw >> "%PS_SPACE_TEMP%"
echo   $c = $c -replace "define\('PUBLIC_FOLDER_SPACE',\s*'.*?'\)", "define('PUBLIC_FOLDER_SPACE', '$newSpace')" >> "%PS_SPACE_TEMP%"
echo   [IO.File]::WriteAllText($initFile, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo # Apache: retarget every FallbackResource to the new prefix. >> "%PS_SPACE_TEMP%"
echo # FallbackResource takes a URL path relative to the DocumentRoot, so each >> "%PS_SPACE_TEMP%"
echo # has to carry the space or Apache looks for the entry point where it no >> "%PS_SPACE_TEMP%"
echo # longer is. /p/ is in this list because /p/^<projectId^>/ is how EVERY >> "%PS_SPACE_TEMP%"
echo # project is served - miss it and the whole public surface 404s on a space >> "%PS_SPACE_TEMP%"
echo # install while /admin/ still works, which reads like a routing bug. >> "%PS_SPACE_TEMP%"
echo $prefix = if ($newSpace) { "/$newSpace" } else { '' } >> "%PS_SPACE_TEMP%"
echo foreach ($pair in @( >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir '.htaccess'); Fallback="$prefix/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir 'p\.htaccess'); Fallback="$prefix/p/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir 'management\.htaccess'); Fallback="$prefix/management/index.php" }, >> "%PS_SPACE_TEMP%"
echo   @{ File=(Join-Path $initDir 'admin\.htaccess'); Fallback="$prefix/admin/index.php" } >> "%PS_SPACE_TEMP%"
echo )) { >> "%PS_SPACE_TEMP%"
echo   if (Test-Path $pair.File) { >> "%PS_SPACE_TEMP%"
echo     $c = Get-Content $pair.File -Raw >> "%PS_SPACE_TEMP%"
echo     $c = $c -replace 'FallbackResource .*', ('FallbackResource ' + $pair.Fallback) >> "%PS_SPACE_TEMP%"
echo     [IO.File]::WriteAllText($pair.File, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_SPACE_TEMP%"
echo   } >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo # nginx: drop the stale routing config, do NOT rewrite it here. The >> "%PS_SPACE_TEMP%"
echo # generator lives in ONE place - secure/src/functions/NginxConfig.php - >> "%PS_SPACE_TEMP%"
echo # and init.php recreates the file on the next page load. This script used >> "%PS_SPACE_TEMP%"
echo # to keep its own copy of that generator, which silently went stale. >> "%PS_SPACE_TEMP%"
echo $nginxConf = Join-Path ('!SECURE_DIR!' -replace '/','\') 'nginx\dynamic_routes.conf' >> "%PS_SPACE_TEMP%"
echo if (Test-Path $nginxConf) { >> "%PS_SPACE_TEMP%"
echo   Remove-Item $nginxConf -Force >> "%PS_SPACE_TEMP%"
echo   Write-Host '  + Removed the old nginx routing config' >> "%PS_SPACE_TEMP%"
echo   Write-Host '  ! nginx: load any page once - that regenerates it for the new' >> "%PS_SPACE_TEMP%"
echo   Write-Host '    prefix. Reload nginx after that, not before.' >> "%PS_SPACE_TEMP%"
REM The regenerated include cannot reach the ONE block that lives in the vhost:
REM location @quicksite_project carries the URL prefix inside SCRIPT_FILENAME and
REM is pasted by hand, because it needs a fastcgi_pass only the operator knows.
REM Left stale, every /p/ request posts at an entry point that is not there.
echo   Write-Host '' >> "%PS_SPACE_TEMP%"
echo   Write-Host '  ! nginx: also update the block you pasted by hand.' >> "%PS_SPACE_TEMP%"
echo   Write-Host '    location @quicksite_project must read:' >> "%PS_SPACE_TEMP%"
echo   if ($newSpace) { Write-Host "      fastcgi_param SCRIPT_FILENAME `$document_root/$newSpace/p/index.php;" } else { Write-Host "      fastcgi_param SCRIPT_FILENAME `$document_root/p/index.php;" } >> "%PS_SPACE_TEMP%"
echo   Write-Host '    Miss it and /p/ answers a bare File-not-found while /admin/ works.' >> "%PS_SPACE_TEMP%"
echo } >> "%PS_SPACE_TEMP%"
echo if ($newSpace) { Write-Host "  + Space set: $newSpace" } else { Write-Host '  + Space removed - serving from the root' } >> "%PS_SPACE_TEMP%"
echo exit 0 >> "%PS_SPACE_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SPACE_TEMP%" <nul
if errorlevel 1 (
    del "%PS_SPACE_TEMP%" 2>nul
    goto :eof
)
del "%PS_SPACE_TEMP%" 2>nul
set "PUBLIC_SPACE=!DESIRED_SPACE!"
goto :eof

REM ==========================================================
REM Item 4 - environment (production / development)
REM ==========================================================
:item_environment
call :read_state
echo.
echo Environment
echo.
echo   QuickSite fetches URLs from the server for you - data resolvers,
echo   API endpoint tests, OAuth back-channels, importing an asset by URL.
echo.
echo   production  those fetches may NOT reach loopback, private or
echo               cloud-metadata addresses. This is what a public or
echo               shared deployment must run as.
echo   development lifts the internal-address block, so a local author
echo               can call http://localhost:3000 or a LAN API. The
echo               http/https scheme rule and DNS pinning stay on.
echo.
echo   Currently: !ENV_LABEL!
echo.
echo   Only ever choose development on a machine you fully trust and
echo   that untrusted callers cannot reach.
echo.

REM Both answers are SPELLED OUT. A bare [y/N] makes the reader infer what N
REM means, and here it is not "no" - it is the other named mode. Getting that
REM backwards on a public server is the one mistake this item can cause.
set "ANSWER="
set /p "ANSWER=  Is this a local/dev install?  y = development, Enter = production: "

set "ENV_VALUE=production"
if /i "!ANSWER!"=="y" set "ENV_VALUE=development"
if /i "!ANSWER!"=="yes" set "ENV_VALUE=development"

if not exist "!CONFIG_DIR!" (
    echo   X Error: config folder not found: !CONFIG_DIR!
    goto :eof
)

REM Start from the .example so the live file keeps the full explanation of
REM what the setting does - the operator edits this file by hand later, and a
REM bare two-line array tells them nothing.
if not exist "!ENV_FILE!" (
    if exist "!CONFIG_DIR!\environment.php.example" (
        copy /y "!CONFIG_DIR!\environment.php.example" "!ENV_FILE!" >nul
    ) else (
        (
            echo ^<?php
            echo.
            echo return [
            echo     'environment' =^> 'production',
            echo ];
        ) > "!ENV_FILE!"
    )
)

set "PS_CFG_TEMP=%TEMP%\qs_setup_env.ps1"
echo $f = '!ENV_FILE!' > "%PS_CFG_TEMP%"
echo $v = '!ENV_VALUE!' >> "%PS_CFG_TEMP%"
echo $c = Get-Content $f -Raw >> "%PS_CFG_TEMP%"
REM The '>' inside the double-quoted regex needs NO caret — the quotes stop cmd
REM reading it as a redirect, and a caret it does not need lands in the .ps1 as
REM a literal '^', which turns the pattern into one that matches nothing. Only
REM the trailing '>>' here is a real redirect.
echo $c = $c -replace "'environment'\s*=>\s*'.*?'", ("'environment' => '" + $v + "'") >> "%PS_CFG_TEMP%"
echo [IO.File]::WriteAllText($f, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_CFG_TEMP%"
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_CFG_TEMP%" <nul >nul 2>&1
del "%PS_CFG_TEMP%" 2>nul

REM Verify rather than assume - report honestly if the file has been reshaped.
findstr /c:"'environment' => '!ENV_VALUE!'" "!ENV_FILE!" >nul 2>&1
if errorlevel 1 (
    echo   X Could not set the value automatically.
    echo     Edit this file by hand and set 'environment' =^> '!ENV_VALUE!':
    echo     !ENV_FILE!
    goto :eof
)
echo   + Environment: !ENV_VALUE!
if /i "!ENV_VALUE!"=="development" echo   ! Do not run a publicly reachable install this way.
echo.
echo   This is a server-side file, on purpose: there is no API that changes
echo   it, so a leaked credential cannot switch the install into development
echo   and re-open outbound access.
goto :eof

REM ==========================================================
REM Item 5 - self-registration
REM ==========================================================
:item_selfreg
call :read_state
echo.
echo Self-registration
echo.
echo   May visitors create their own accounts at /admin/register?
echo.
echo   off  accounts are created by you, from the panel. This is the
echo        default, and what a private install wants.
echo   on   anyone who can reach /admin/register can make an account,
echo        subject to the per-IP and per-hour limits in auth.php.
echo.
echo   Currently: !SELFREG_LABEL!
echo.
echo   Either way, the FIRST account is created from the first-run page
echo   using the setup token - self-registration never bootstraps an
echo   install.
echo.

set "ANSWER="
set /p "ANSWER=  Allow visitors to create their own accounts? [y/N]: "

set "REG_VALUE=false"
if /i "!ANSWER!"=="y" set "REG_VALUE=true"
if /i "!ANSWER!"=="yes" set "REG_VALUE=true"

if not exist "!CONFIG_DIR!" (
    echo   X Error: config folder not found: !CONFIG_DIR!
    goto :eof
)

REM auth.php does not exist yet on a fresh clone - the engine creates it from
REM the .example on the first page load. Creating it here is the same copy,
REM done earlier; the engine's copy is create-if-absent and leaves ours alone.
if not exist "!AUTH_FILE!" (
    if not exist "!CONFIG_DIR!\auth.php.example" (
        echo   X Error: neither auth.php nor auth.php.example is present
        echo     !CONFIG_DIR!
        goto :eof
    )
    copy /y "!CONFIG_DIR!\auth.php.example" "!AUTH_FILE!" >nul
)

set "PS_CFG_TEMP=%TEMP%\qs_setup_reg.ps1"
echo $f = '!AUTH_FILE!' > "%PS_CFG_TEMP%"
echo $v = '!REG_VALUE!' >> "%PS_CFG_TEMP%"
echo $c = Get-Content $f -Raw >> "%PS_CFG_TEMP%"
echo $c = $c -replace "'allow_self_registration'\s*=>\s*\w+", ("'allow_self_registration' => " + $v) >> "%PS_CFG_TEMP%"
echo [IO.File]::WriteAllText($f, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_CFG_TEMP%"
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_CFG_TEMP%" <nul >nul 2>&1
del "%PS_CFG_TEMP%" 2>nul

findstr /c:"'allow_self_registration' => !REG_VALUE!" "!AUTH_FILE!" >nul 2>&1
if errorlevel 1 (
    echo   X Could not set the value automatically.
    echo     Edit this file by hand and set 'allow_self_registration' =^> !REG_VALUE!:
    echo     !AUTH_FILE!
    goto :eof
)
if /i "!REG_VALUE!"=="true" (
    echo   + Self-registration: on
    echo   ! /admin/register is now open to anyone who can reach it.
) else (
    echo   + Self-registration: off
)
goto :eof

REM ==========================================================
REM Item 6 - show my setup token
REM ==========================================================
:item_token
call :read_state
echo.
echo Setup token
echo.
echo   QuickSite ships no default password. The first account is created
echo   in the browser, and what proves you are the person who installed
echo   this - rather than a passer-by who found the address - is being
echo   able to read a file on the server. That file is the token.
echo.

if exist "!TOKEN_FILE!" goto :token_read

REM THE ORDERING THAT SURPRISES PEOPLE. The token is minted by the engine when
REM the first-run page renders, not by this script. On a fresh clone it
REM genuinely does not exist yet, and printing an empty value here would look
REM like a broken install.
echo   The token has not been created yet.
echo.
echo   It is written the first time the admin panel is loaded. Do this,
echo   in this order:
echo.
echo     1. Point your vhost DocumentRoot at !PUBLIC_FOLDER_NAME!\ and
echo        restart your web server.
if "!PUBLIC_SPACE!"=="" (
    echo     2. Open http://your-domain/admin/ in a browser.
) else (
    echo     2. Open http://your-domain/!PUBLIC_SPACE!/admin/ in a browser.
)
echo        Leave the page open - it is the form you will fill in.
echo     3. Come back here and choose this item again.
echo.
echo   Expected location:
echo     !TOKEN_FILE!
goto :eof

:token_read
set "TOKEN="
for /f "usebackq delims=" %%a in ("!TOKEN_FILE!") do if not defined TOKEN set "TOKEN=%%a"

echo !TOKEN!| findstr /r /c:"^[0-9a-f][0-9a-f]*$" >nul 2>&1
if errorlevel 1 goto :token_consumed

echo   Your setup token:
echo.
echo     !TOKEN!
echo.
echo   Paste it into the first-run form at /admin/. It is used once and
echo   destroyed the moment your account is created.
goto :eof

:token_consumed
REM qs_setup_token_consume() overwrites the value with a marker before
REM unlinking, so anything here that is not token-shaped means the bootstrap
REM is over.
echo   This token has already been used.
echo.
echo   An account exists on this installation, so the first-run page is
echo   gone for good and the token grants nothing. Sign in at /admin/
echo   instead.
echo.
echo   Lost the password? There is no reset from here. Delete
echo   !CONFIG_DIR!\users.php
echo   to start the bootstrap over - every account goes with it.
goto :eof

REM ==========================================================
REM Helper: update a define() constant in init.php
REM Usage: call :update_init_constant "file.php" "CONSTANT_NAME" "new_value"
REM
REM Every powershell call in this file runs with <nul on stdin. PowerShell
REM inherits the console handle and DRAINS it, so without that a helper
REM invoked mid-item swallows the operator's next keystroke and the menu
REM answers a question nobody asked.
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
echo $c = $c -replace "define\('$n',\s*'.*?'\)", "define('$n', '$v')" >> "%PS_TEMP%"
echo [IO.File]::WriteAllText($f, $c, (New-Object System.Text.UTF8Encoding $false)) >> "%PS_TEMP%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_TEMP%" <nul 2>nul
if errorlevel 1 (
    echo   ? Could not update %UIC_NAME% in init.php - edit manually
) else (
    echo   + Updated %UIC_NAME% in init.php
)
del "%PS_TEMP%" 2>nul
goto :eof
