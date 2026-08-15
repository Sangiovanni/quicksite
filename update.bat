@echo off
REM ==========================================================
REM QuickSite - Update (Windows)
REM ==========================================================
REM
REM Usage:
REM   update.bat --check     report whether a newer release exists, then exit
REM   update.bat             check, ask, and apply
REM   update.bat --yes       apply without asking
REM   update.bat --help
REM
REM Exit codes:  0 = up to date / applied   1 = error   10 = update available
REM
REM WHY THIS IS A SCRIPT AND NOT A COMMAND. Applying an update rewrites the code
REM that runs every project on the installation. QuickSite's authority model is
REM per-project - there is no installation-wide role, deliberately - so there is
REM no principal an HTTP endpoint could gate this on. The credential here is
REM FILESYSTEM ACCESS: whoever can run this can already edit users.php, so they
REM hold strictly more than any role could grant. Same principle as the
REM first-run setup token. The panel still TELLS you an update exists
REM (checkForUpdates, with operator.php deciding who sees the notice) - it just
REM cannot apply one.
REM
REM ---- WHY THIS FILE IS THIN --------------------------------------------------
REM
REM The work is in update.ps1, next to this file. Generating that logic into a
REM temp .ps1 through a run of `echo` lines - the way setup.bat does for its own
REM short snippets - would put every line through TWO layers of escaping: cmd's
REM (`|` becomes `^|`, a trailing `^` silently continues the line, `>` needs
REM care even inside quotes) and then PowerShell's. That is the class of bug
REM S2.1 spent a slice on. One committed script has one layer of quoting, reads
REM like code, and can be run directly when something goes wrong.
REM
REM ---- TWO WINDOWS RULES THIS FILE OBEYS --------------------------------------
REM
REM 1. NO `<nul` ON THIS POWERSHELL CALL, and that is the OPPOSITE of setup.bat
REM    - deliberately. setup.bat needs `<nul` because it calls PowerShell for
REM    sub-tasks in the MIDDLE of its own menu: PowerShell inherits the console
REM    input handle and drains it, eating the operator's next keystroke, so the
REM    menu then answers a question nobody asked.
REM
REM    Here PowerShell IS the whole program, and update.ps1 has to prompt
REM    "Apply this update now?" itself. Redirecting stdin from nul makes
REM    Read-Host return EOF, so a human could never answer - the confirmation
REM    would be unanswerable rather than merely awkward. Nothing runs after the
REM    call in this file, so there is no later keystroke left to protect.
REM
REM    update.ps1 handles the genuinely-redirected case (a scheduled task, CI)
REM    by refusing rather than applying unconfirmed - see the note there.
REM
REM 2. THIS FILE MUST HAVE CRLF LINE ENDINGS. cmd.exe reads a batch file by BYTE
REM    OFFSET, re-seeking after every command, so an LF-only .bat mis-resolves
REM    GOTO and silently runs the wrong branch. `.gitattributes` pins
REM    `*.bat text eol=crlf` for exactly this reason - do not "normalise" it.
REM ==========================================================

REM enabledelayedexpansion is required: PS_ARGS is appended to INSIDE a
REM parenthesised block, and %PS_ARGS% would expand to its value from before the
REM block was entered.
setlocal enabledelayedexpansion

set "SCRIPT_DIR=%~dp0"
set "PS1=%SCRIPT_DIR%update.ps1"

if not exist "%PS1%" (
    echo.
    echo   X update.ps1 is missing next to update.bat:
    echo     %PS1%
    echo     Both files ship together - re-download the release.
    echo.
    exit /b 1
)

REM Translate the long-form flags to the .ps1's switches. Kept to an explicit
REM list rather than forwarding %* verbatim: an unknown flag must be refused
REM here, not silently swallowed by a parameter binder.
set "PS_ARGS="
:parse
if "%~1"=="" goto :run
if /i "%~1"=="--check" (set "PS_ARGS=!PS_ARGS! -Check" & goto :next)
if /i "%~1"=="-c"      (set "PS_ARGS=!PS_ARGS! -Check" & goto :next)
if /i "%~1"=="--yes"   (set "PS_ARGS=!PS_ARGS! -Yes"   & goto :next)
if /i "%~1"=="-y"      (set "PS_ARGS=!PS_ARGS! -Yes"   & goto :next)
if /i "%~1"=="--help"  goto :help
if /i "%~1"=="-h"      goto :help
if /i "%~1"=="/?"      goto :help
echo Unknown option: %~1
echo Try: update.bat --help
exit /b 1
:next
shift
goto :parse

:run
REM Stdin is NOT redirected - see rule 1 in the header. update.ps1 prompts.
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%"%PS_ARGS%
exit /b %errorlevel%

:help
echo.
echo QuickSite update (Windows)
echo.
echo   update.bat --check     report whether a newer release exists, then exit
echo   update.bat             check, ask, and apply
echo   update.bat --yes       apply without asking
echo   update.bat --help      this text
echo.
echo Exit codes:  0 = up to date / applied   1 = error   10 = update available
echo.
echo Applying rewrites the engine every project runs on, so it is a script and
echo not an API command: the credential is filesystem access to this machine.
echo Your configuration (users.php, auth.php, environment.php, operator.php,
echo deploy-roots.php, every project) is never touched, and a backup is taken
echo before anything is written.
echo.
exit /b 0
