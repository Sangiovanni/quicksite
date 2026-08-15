<#
==========================================================
 QuickSite - Update (Windows implementation)
==========================================================

 Invoked by update.bat. Runnable directly too:
     powershell -ExecutionPolicy Bypass -File update.ps1 -Check

 WHY A SEPARATE .ps1 AND NOT ALL-IN-THE-.bat. setup.bat generates its
 PowerShell into a temp file with a long run of `echo` lines, and that works
 because those snippets are short. This logic is not short, and every line of
 it would have to survive TWO layers of escaping: cmd's (`|` -> `^|`, a
 trailing `^` silently continuing the line, `>` needing care inside a quoted
 set) and then PowerShell's own. That is precisely the class of bug S2.1 spent
 a slice on. A committed script has one layer of quoting, can be read by a
 human, and can be run directly when something goes wrong.

 This is not a dependency: PowerShell ships with Windows, the same way bash
 ships with the Linux targets update.sh runs on.

 EXIT CODES (so -Check can be scheduled):
   0   up to date, or the apply succeeded
   1   error
   10  -Check only: a newer release is available
#>

[CmdletBinding()]
param(
    [switch]$Check,
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$VersionFile = Join-Path $ScriptDir 'VERSION'

$GithubOwner = 'Sangiovanni'
$GithubRepo  = 'quicksite'

$DefaultApi = "https://api.github.com/repos/$GithubOwner/$GithubRepo/releases/latest"

# Overridable so a fork or an internal mirror can be updated from, and so the
# probe can point at a fixture instead of the live internet. Grants nothing new:
# anyone who can set these can already edit this file - they are on the machine.
# An override in effect is announced, so it can never be quietly active.
$Api = if ($env:QS_UPDATE_API) { $env:QS_UPDATE_API } else { $DefaultApi }
$ZipTemplate = if ($env:QS_UPDATE_ZIP) { $env:QS_UPDATE_ZIP } `
               else { "https://github.com/$GithubOwner/$GithubRepo/archive/refs/tags/__TAG__.zip" }

function Write-Ok   ($m) { Write-Host "  + $m" }
function Write-Bad  ($m) { Write-Host "  X $m" }
function Write-Warn ($m) { Write-Host "  ! $m" }
function Write-Dim  ($m) { Write-Host "    $m" }

<#
 Run an external program and judge it by its EXIT CODE.

 ⚠ THIS WRAPPER IS NOT CEREMONY. Windows PowerShell 5.1 wraps every line a
 NATIVE command writes to stderr in an ErrorRecord, and with
 $ErrorActionPreference = 'Stop' that turns ordinary chatter into a TERMINATING
 error - the script dies on a command that succeeded. Both programs this script
 runs do exactly that on a good day:

   git   writes fetch/pull progress to stderr on every single invocation.
   php   writes a loader warning on any box with a mis-pathed xdebug .dll,
         which is most WAMP installs (it is what caught this during testing).

 So native calls run with the preference relaxed and are judged on the exit
 code, which is the thing that actually means failure. Output is returned as
 plain strings so a caller can print it.
#>
function Invoke-Native {
    param([string]$Exe, [string[]]$Arguments)
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $Exe @Arguments 2>&1 | ForEach-Object { $_.ToString() }
        return [pscustomobject]@{ Code = $LASTEXITCODE; Output = $out }
    } catch {
        return [pscustomobject]@{ Code = 1; Output = @($_.Exception.Message) }
    } finally {
        $ErrorActionPreference = $prev
    }
}

# Returns the body, and reports the HTTP status through $script:HttpStatus so the
# caller can tell "no network" from "reached GitHub, got a 404" — the same
# distinction update.sh makes, and for the same reason: those two need different
# things from the operator.
$script:HttpStatus = ''
function Get-Text($Url) {
    $script:HttpStatus = ''
    if ($Url -like 'file://*') {
        # A local fixture, for the probe. Real use never takes this branch.
        $script:HttpStatus = 200
        return (Get-Content ($Url -replace '^file://', '') -Raw)
    }
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 60 -Headers @{
            'User-Agent' = 'QuickSite-Updater/1.0'
            'Accept'     = 'application/vnd.github.v3+json'
        }
        $script:HttpStatus = [int]$r.StatusCode
        return $r.Content
    } catch [Net.WebException] {
        # An HTTP error still carries a response; a transport failure does not,
        # and that absence is exactly what distinguishes the two.
        if ($_.Exception.Response) {
            $script:HttpStatus = [int]$_.Exception.Response.StatusCode
        }
        return ''
    }
}

# The secure folder, which setup.bat may have renamed or nested.
function Get-SecureName {
    $conf = Join-Path $ScriptDir '.quicksite.conf'
    if (Test-Path $conf) {
        foreach ($line in (Get-Content $conf)) {
            if ($line -match '^SECURE_FOLDER_NAME=(.+)$') {
                $n = $Matches[1].Trim()
                if ($n -and (Test-Path (Join-Path $ScriptDir $n))) { return $n }
            }
        }
    }
    return 'secure'
}

# PHP's version_compare is what the ENGINE uses (checkForUpdates). Delegating to
# it rather than reimplementing means the panel notice and this script can never
# disagree about the same pair of versions. Windows always has a PHP to hand
# here - WAMP/XAMPP ship one and QuickSite needs one to run at all.
function Find-Php {
    if ($env:QS_PHP -and (Test-Path $env:QS_PHP)) { return $env:QS_PHP }
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }
    foreach ($v in @('8.4.0', '8.3.14', '8.2.26', '8.1.31', '8.0.30')) {
        $p = "C:\wamp64\bin\php\php$v\php.exe"
        if (Test-Path $p) { return $p }
    }
    return $null
}

# ==========================================================
# Local version
# ==========================================================
if (-not (Test-Path $VersionFile)) {
    Write-Host ''
    Write-Bad "No VERSION file at $VersionFile"
    Write-Dim 'This does not look like a QuickSite install root.'
    exit 1
}

$Current = ((Get-Content $VersionFile -Raw) -replace '\s', '') -replace '^[vV]', ''
if (-not $Current) { Write-Bad 'VERSION is empty.'; exit 1 }

$Method = if (Test-Path (Join-Path $ScriptDir '.git')) { 'git' } else { 'zip' }

Write-Host ''
Write-Host 'QuickSite update'
Write-Host ''
Write-Host "  Install:  $ScriptDir"
Write-Host "  Version:  $Current"
Write-Host "  Method:   $Method"
if ($Api -ne $DefaultApi) { Write-Host "  Source:   $Api  (OVERRIDDEN via QS_UPDATE_API)" }
Write-Host ''

# ==========================================================
# Latest release
# ==========================================================
$LatestTag = $null
try {
    $raw = Get-Text $Api
    # ConvertFrom-Json is a real parser, not a pattern that hopes. The Linux
    # side has to use sed because a POSIX box has no JSON parser to rely on;
    # here there is one, so it gets used.
    $LatestTag = (ConvertFrom-Json $raw).tag_name
} catch {
    $LatestTag = $null
}

if (-not $LatestTag) {
    # SAY WHICH — mirrors update.sh. These need different things from the
    # operator, and one message told them apart from nothing.
    switch -Regex ([string]$script:HttpStatus) {
        '^404$' {
            Write-Bad 'GitHub has no published release for this repository.'
            Write-Dim 'GitHub answered (404). Either the repository is private to this'
            Write-Dim 'machine, or no release has been published yet. Outbound access'
            Write-Dim 'is working, so there is nothing to fix here.'
        }
        '^(403|429)$' {
            Write-Bad "GitHub refused the request (HTTP $script:HttpStatus) - most likely rate-limited."
            Write-Dim 'Unauthenticated requests are capped per address, per hour.'
            Write-Dim 'Wait and run this again; nothing has been changed.'
        }
        '^2\d\d$' {
            Write-Bad 'GitHub answered, but the response carried no release tag.'
            Write-Dim "HTTP $script:HttpStatus with no 'tag_name'. If this persists, the API"
            Write-Dim 'shape may have changed - report it rather than working around it.'
        }
        '^$' {
            Write-Bad 'Could not reach GitHub.'
            Write-Dim 'No HTTP response at all: no outbound network, DNS failure, or a'
            Write-Dim 'firewall blocking api.github.com. The admin panel update notice'
            Write-Dim 'will be silent for the same reason.'
        }
        default {
            Write-Bad "Could not read the latest release (HTTP $script:HttpStatus)."
            Write-Dim 'Nothing has been changed.'
        }
    }
    Write-Host ''
    exit 1
}

$Latest = $LatestTag -replace '^[vV]', ''
Write-Host "  Latest:   $Latest  ($LatestTag)"
Write-Host ''

# ==========================================================
# Compare
# ==========================================================
$Php = Find-Php
if (-not $Php) {
    Write-Bad 'No PHP interpreter found - cannot compare versions reliably.'
    Write-Dim 'Add php.exe to PATH, or set QS_PHP to its full path.'
    exit 1
}

$cmp = Invoke-Native $Php @('-d', 'xdebug.mode=off', '-r',
    "exit(version_compare(`$argv[1], `$argv[2], '<') ? 10 : 0);", $Current, $Latest)
$UpdateAvailable = ($cmp.Code -eq 10)

if (-not $UpdateAvailable) {
    Write-Ok "You are up to date ($Current)."
    Write-Host ''
    exit 0
}

Write-Warn "An update is available: $Current -> $Latest"
Write-Dim  "https://github.com/$GithubOwner/$GithubRepo/releases/tag/$LatestTag"
Write-Host ''

if ($Check) {
    Write-Dim 'Run update.bat (without --check) to apply it.'
    Write-Host ''
    exit 10
}

# ==========================================================
# Confirm
# ==========================================================
if (-not $Yes) {
    # ⚠ TWO TRAPS HERE, BOTH FOUND BY TESTING, BOTH SAFETY-RELEVANT.
    #
    # 1. Read-Host returns $null at EOF (stdin redirected from nul, a scheduled
    #    task, a CI runner). And `$null -notmatch '…'` does NOT evaluate to
    #    $true — it yields an EMPTY collection, which `if ()` treats as FALSE.
    #    So the obvious `if ($answer -notmatch '^(y|yes)$') { exit }` fell
    #    through and APPLIED THE UPDATE WITHOUT ANY CONFIRMATION. Casting to
    #    [string] first turns $null into '' and makes the test behave.
    #
    # 2. EOF is not the same as "answered no". update.sh refuses outright when
    #    stdin is not a terminal, rather than silently cancelling, because an
    #    apply launched by a scheduler that then sat on a prompt would be worse
    #    than one that stops and says why. Mirrored here: $null means nobody
    #    could be asked, and that is an error, not a decline.
    $raw = Read-Host '  Apply this update now? [y/N]'
    if ($null -eq $raw) {
        Write-Bad 'Not running interactively and -Yes was not given. Nothing changed.'
        exit 1
    }
    if (([string]$raw).Trim() -notmatch '^(y|yes)$') {
        Write-Host ''
        Write-Host '  Cancelled. Nothing changed.'
        Write-Host ''
        exit 0
    }
    Write-Host ''
}

# ==========================================================
# Backup
# ==========================================================
# Copies of the things an apply could plausibly damage, before it runs. Not a
# full-install backup - the engine's own files are what an update REPLACES, and
# restoring them from here would undo the update. What is captured is the state
# that is not in the repository and therefore cannot be recovered from it.
$SecureName = Get-SecureName
$BackupDir  = Join-Path $ScriptDir (Join-Path '.quicksite-backups' ((Get-Date -Format 'yyyyMMdd-HHmmss') + "-$Current"))

Write-Host '  Backing up your configuration...'
try {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
} catch {
    Write-Bad "Could not create $BackupDir"
    Write-Dim 'Refusing to update without a backup.'
    exit 1
}

$cfgRel = Join-Path (Join-Path $SecureName 'management') 'config'
$cfgSrc = Join-Path $ScriptDir $cfgRel
$cfgDst = Join-Path $BackupDir $cfgRel
New-Item -ItemType Directory -Path $cfgDst -Force | Out-Null

Copy-Item $VersionFile (Join-Path $BackupDir 'VERSION') -Force -ErrorAction SilentlyContinue
$conf = Join-Path $ScriptDir '.quicksite.conf'
if (Test-Path $conf) { Copy-Item $conf (Join-Path $BackupDir '.quicksite.conf') -Force -ErrorAction SilentlyContinue }

foreach ($f in @('users.php','auth.php','environment.php','operator.php',
                 'deploy-roots.php','roles.php','import-policy.php','api-secrets.php')) {
    $src = Join-Path $cfgSrc $f
    if (Test-Path $src) { Copy-Item $src (Join-Path $cfgDst $f) -Force -ErrorAction SilentlyContinue }
}

$projSrc = Join-Path $ScriptDir (Join-Path $SecureName 'projects')
if (Test-Path $projSrc) {
    Copy-Item $projSrc (Join-Path $BackupDir $SecureName) -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Ok "Backup: $BackupDir"
Write-Host ''

# ==========================================================
# Apply - git
# ==========================================================
function Invoke-GitApply {
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        Write-Bad 'This is a git install but git is not on PATH.'
        return $false
    }

    $r = Invoke-Native 'git' @('-C', $ScriptDir, 'rev-parse', '--abbrev-ref', 'HEAD')
    $branch = if ($r.Code -eq 0 -and $r.Output) { ($r.Output | Select-Object -First 1).Trim() } else { 'main' }
    if (-not $branch) { $branch = 'main' }

    $r = Invoke-Native 'git' @('-C', $ScriptDir, 'status', '--porcelain')
    $dirty = @($r.Output | Where-Object { $_ -and $_.Trim() -ne '' })
    if ($dirty.Count -gt 0) {
        Write-Bad 'The working tree has uncommitted changes:'
        $dirty | ForEach-Object { Write-Host "      $_" }
        Write-Host ''
        Write-Dim 'Commit or stash them, then run this again. A pull over local'
        Write-Dim 'edits is how people lose work they forgot they had made.'
        return $false
    }

    Write-Host '  Fetching...'
    $r = Invoke-Native 'git' @('-C', $ScriptDir, 'fetch', '--tags', 'origin')
    if ($r.Code -ne 0) { Write-Bad 'git fetch failed.'; return $false }

    Write-Host "  Pulling $branch..."
    $r = Invoke-Native 'git' @('-C', $ScriptDir, 'pull', 'origin', $branch)
    if ($r.Code -ne 0) {
        Write-Bad 'git pull failed:'
        $r.Output | ForEach-Object { Write-Host "      $_" }
        return $false
    }
    return $true
}

# ==========================================================
# Apply - ZIP
# ==========================================================
# The dangerous path, and the one that needs the explicit skip list: git cannot
# touch an untracked file, a naive unpack-over-the-top can and will.
function Invoke-ZipApply {
    # .Replace(), not -replace: the tag is a literal, and -replace would read
    # any regex metacharacter in it as a pattern.
    $url = $ZipTemplate.Replace('__TAG__', $LatestTag)
    $tmp = Join-Path $env:TEMP ('qs_update_' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp -Force | Out-Null

    try {
        $zip = Join-Path $tmp 'update.zip'
        Write-Host "  Downloading $LatestTag..."
        if ($url -like 'file://*') {
            Copy-Item ($url -replace '^file://', '') $zip
        } else {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing -TimeoutSec 600 `
                -Headers @{ 'User-Agent' = 'QuickSite-Updater/1.0' }
        }

        Write-Host '  Extracting...'
        $x = Join-Path $tmp 'x'
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [IO.Compression.ZipFile]::ExtractToDirectory($zip, $x)

        # GitHub archives unpack into a single <repo>-<tag>\ directory.
        $src = Get-ChildItem $x -Directory | Select-Object -First 1
        if (-not $src)                                            { Write-Bad 'The archive is empty.'; return $false }
        if (-not (Test-Path (Join-Path $src.FullName 'VERSION'))) { Write-Bad 'The archive does not look like a QuickSite release.'; return $false }

        # ---- THE SKIP LIST -------------------------------------------------
        # Everything a release must never write over. Two kinds of entry:
        #   a) files that are gitignored, so the archive does not contain them
        #      and nothing would be copied anyway. Listed regardless: the
        #      protection must not depend on a .gitignore in another repository
        #      staying the way it is today.
        #   b) directories holding the author's own work - secure/projects/
        #      above all. A release ships a starter project, and unpacking it
        #      over a live install would overwrite the site somebody built.
        $skip = @(
            '^secure/projects(/|$)',
            '^secure/logs(/|$)',
            '^secure/nginx(/|$)',
            '^\.git(/|$)',
            '^\.quicksite\.conf$',
            '^secure/management/config/(users|auth|roles|environment|operator|deploy-roots|import-policy|api-secrets)\.php$',
            '^secure/management/config/setup-token\.txt$',
            '^secure/management/config/.*\.(json|lock)$'
        )

        Write-Host '  Applying...'
        $copied = 0; $skipped = 0; $failed = 0
        $base = $src.FullName.TrimEnd('\') + '\'

        foreach ($f in (Get-ChildItem $src.FullName -Recurse -File)) {
            $rel = $f.FullName.Substring($base.Length) -replace '\\', '/'
            $hit = $false
            foreach ($p in $skip) { if ($rel -match $p) { $hit = $true; break } }
            if ($hit) { $skipped++; continue }

            $dest = Join-Path $ScriptDir ($rel -replace '/', '\')
            $dir  = Split-Path $dest
            if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
            try { Copy-Item $f.FullName $dest -Force; $copied++ }
            catch { $failed++; Write-Host "      could not write: $rel" }
        }

        if ($failed -gt 0) {
            Write-Bad "$failed file(s) could not be written - the install is PART-UPDATED."
            Write-Dim 'Usually a permissions problem. Fix it and run this again;'
            Write-Dim 're-applying the same release is safe.'
            return $false
        }

        Write-Ok "Applied $LatestTag - $copied file(s) written, $skipped left alone"

        # A renamed public/ or secure/ is where the ZIP path stops being able to
        # do the right thing on its own: a release archive always lays its files
        # out under public\ and secure\, and has no way to know this install
        # calls them something else.
        if ($SecureName -ne 'secure' -or -not (Test-Path (Join-Path $ScriptDir 'public'))) {
            Write-Warn 'This install renamed its public and/or secure folder.'
            Write-Dim  'A ZIP release unpacks under "public\" and "secure\", so it has just'
            Write-Dim  'created those names alongside your renamed ones. Move the new engine'
            Write-Dim  'files into your own folders, then delete the leftovers - or switch to'
            Write-Dim  'a git install, which has no such problem.'
        }
        return $true

    } catch {
        Write-Bad $_.Exception.Message
        return $false
    } finally {
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

$applied = if ($Method -eq 'git') { Invoke-GitApply } else { Invoke-ZipApply }

if (-not $applied) {
    Write-Host ''
    Write-Bad 'Update did not complete. Your install is unchanged.'
    Write-Dim "Backup of your configuration: $BackupDir"
    Write-Host ''
    exit 1
}

# ==========================================================
# Report
# ==========================================================
$New = $Current
if (Test-Path $VersionFile) {
    $New = ((Get-Content $VersionFile -Raw) -replace '\s', '') -replace '^[vV]', ''
}

Write-Host ''
Write-Host '========================================'
Write-Host "  Updated: $Current -> $New"
Write-Host '========================================'
Write-Host ''
Write-Host '  Your configuration was not touched:'
Write-Dim  'users.php, auth.php, environment.php, operator.php,'
Write-Dim  "deploy-roots.php and every project under $SecureName\projects\"
Write-Host ''
Write-Host "  Backup taken first: $BackupDir"
Write-Host ''
Write-Host '  Next:'
Write-Host '    1. Load /admin/ once - a new release may add config keys, and the'
Write-Host '       engine writes any it is missing (an absent key always has a'
Write-Host '       sensible default, so nothing breaks in the meantime).'
Write-Host '    2. On nginx, if the routing config was regenerated, reload nginx.'
Write-Host '    3. Check the release notes:'
Write-Dim  "https://github.com/$GithubOwner/$GithubRepo/releases/tag/$LatestTag"
Write-Host ''
exit 0
