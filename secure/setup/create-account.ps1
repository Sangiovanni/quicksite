<#
    QuickSite — first-account prompt for setup.bat (Windows).

    setup.bat cannot hide typed input: `set /p` always echoes, so a password typed
    there would be visible on screen and left in the console buffer. PowerShell ships
    on every supported Windows and has Read-Host -AsSecureString, so the prompt lives
    here and setup.bat just calls it.

    THE PASSWORD NEVER REACHES A COMMAND LINE. It is held as a SecureString, converted
    to plain text only in this process, and handed to the PHP helper through STDIN —
    so it cannot be read from the process list by another local user. The plaintext is
    zeroed as soon as it has been piped.

    Exit codes mirror the PHP helper:
      0 created · 3 accounts already exist (nothing done) · 4 gave up after retries
      6 no PHP interpreter found (caller prints the manual fallback)
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $SecureDir
)

$ErrorActionPreference = 'Stop'
$helper = Join-Path $SecureDir 'setup\create-account.php'

if (-not (Test-Path $helper)) {
    Write-Host "  X Could not find $helper"
    exit 6
}

# ---- locate PHP -----------------------------------------------------------
# Neither installer assumed PHP was on PATH before, and plenty of Windows stacks
# (WAMP, XAMPP, Laragon) do not put it there. Probe PATH first, then the usual
# install roots, newest version last-wins so a machine with several picks one.
$php = $null
$onPath = Get-Command php -ErrorAction SilentlyContinue
if ($onPath) { $php = $onPath.Source }

if (-not $php) {
    $candidates = @()
    foreach ($root in @('C:\wamp64\bin\php', 'C:\wamp\bin\php', 'C:\xampp\php',
                        'C:\laragon\bin\php', 'C:\php')) {
        if (-not (Test-Path $root)) { continue }
        $candidates += Get-ChildItem -Path $root -Filter 'php.exe' -Recurse -Depth 1 -ErrorAction SilentlyContinue
    }
    if ($candidates.Count -gt 0) {
        $php = ($candidates | Sort-Object FullName | Select-Object -Last 1).FullName
    }
}

if (-not $php) { exit 6 }

# ---- already configured? --------------------------------------------------
& $php $helper --status | Out-Null
if ($LASTEXITCODE -eq 3) { exit 3 }

# ---- the app's own rules --------------------------------------------------
$minPw    = 12
$userRule = '3-32 characters: lowercase letters, digits, - or _'
foreach ($line in (& $php $helper --rules)) {
    if ($line -match '^MINPW=(\d+)$')   { $minPw    = [int]$Matches[1] }
    if ($line -match '^USERRULE=(.+)$') { $userRule = $Matches[1] }
}

Write-Host ''
Write-Host '  Create your admin account.'
Write-Host "    Username: $userRule"
Write-Host "    Password: at least $minPw characters"
Write-Host ''

for ($attempt = 1; $attempt -le 3; $attempt++) {
    $username = (Read-Host '  Username').Trim()

    $pw1 = Read-Host '  Password' -AsSecureString
    $pw2 = Read-Host '  Confirm password' -AsSecureString

    $b1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($pw1)
    $b2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($pw2)
    try {
        $plain1 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b1)
        $plain2 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b2)

        if ($plain1 -cne $plain2) {
            Write-Host '  X The two passwords do not match.'
            Write-Host ''
            continue
        }

        # Two lines on STDIN: username, then password. Never argv.
        $output = @($username, $plain1) | & $php $helper 2>&1
        $code = $LASTEXITCODE
    }
    finally {
        # Zero the plaintext and the unmanaged buffers regardless of outcome.
        $plain1 = $null; $plain2 = $null
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b1)
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b2)
    }

    if ($code -eq 0) {
        Write-Host "  OK Account created: $username"
        exit 0
    }
    if ($code -eq 3) { exit 3 }

    foreach ($line in $output) { Write-Host "  X $line" }
    Write-Host ''
}

exit 4
