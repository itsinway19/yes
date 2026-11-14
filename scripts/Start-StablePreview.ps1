[CmdletBinding()]
param([switch] $Stop, [switch] $Status)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($Stop -and $Status) { throw 'Choose Start (no switches), -Status, or -Stop.' }
$root = Split-Path -Parent $PSScriptRoot
$previewRoot = Join-Path $root 'storage/app/retirement-preview'
$envPath = Join-Path $previewRoot 'private/environment.json'
$tokenPath = Join-Path $previewRoot 'private/probe-token'
$statePath = Join-Path $previewRoot 'stable-preview.json'
$logsDir = Join-Path $previewRoot 'logs'
$router = Join-Path $PSScriptRoot 'stable-preview-router.php'
$legacyRouter = Join-Path $previewRoot 'router.php'
$redisConf = Join-Path $previewRoot 'redis.conf'
$php = 'C:\tools\php85\php.exe'
$redisServer = 'C:\Program Files\Redis\redis-server.exe'
$appPort = 8038
$redisPort = 16408
$appUrl = 'http://localhost:8038'

function Get-ProcessInfo([int] $Id) {
    if ($Id -le 0) { return $null }
    # A metadata-access failure must not be reported as a stopped process.
    @(Get-CimInstance Win32_Process -Filter "ProcessId=$Id" -ErrorAction Stop) | Select-Object -First 1
}
function Test-Port([int] $Port) {
    $client = [Net.Sockets.TcpClient]::new()
    try { return $client.ConnectAsync('127.0.0.1', $Port).Wait(800) -and $client.Connected }
    catch { return $false } finally { $client.Dispose() }
}
function Test-PreviewCommand($Process) {
    if ($null -eq $Process -or $Process.Name -ine 'php.exe' -or -not $Process.CommandLine -or
        -not $Process.ExecutablePath -or $Process.ExecutablePath.Replace('/', '\') -ine $php) { return $false }
    $command = $Process.CommandLine.Replace('/', '\').Replace('"', '')
    foreach ($path in @($router, $legacyRouter)) {
        $expected = "$php -S 127.0.0.1:$appPort -t $(Join-Path $root 'public') $path"
        if ($command -eq $expected) { return $true }
    }
    return $false
}
function Get-PreviewServerProcesses {
    @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction Stop | Where-Object { Test-PreviewCommand $_ })
}
function Assert-PreviewServer([int] $Id, [string] $Created = '') {
    $process = Get-ProcessInfo $Id
    if (-not (Test-PreviewCommand $process) -or ($Created -and $process.CreationDate.ToUniversalTime().ToString('o') -ne $Created)) {
        throw "Process $Id does not match this preview's executable, exact root/router/port and creation identity; left untouched."
    }
    return $process
}
function Get-RedisPreviewProcesses {
    $expected = $redisConf.Replace('/', '\')
    @(Get-CimInstance Win32_Process -Filter "Name='redis-server.exe'" -ErrorAction Stop | Where-Object {
        $_.ExecutablePath -and $_.ExecutablePath.Replace('/', '\') -ieq $redisServer -and
        $_.CommandLine -and $_.CommandLine.Replace('/', '\').Replace('"', '') -eq "$redisServer $expected"
    })
}
function Read-Settings {
    if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { throw "Missing private local preview settings: $envPath" }
    try { $values = Get-Content -LiteralPath $envPath -Raw | ConvertFrom-Json -AsHashtable }
    catch { throw 'The private preview configuration is invalid; its contents were not rendered.' }
    $required = @{ APP_URL=$appUrl; DB_HOST='127.0.0.1'; DB_PORT='5432'; DB_DATABASE='builderad_dev'; DB_OWNER_DATABASE='builderad_dev'; DB_USERNAME='builderad_app'; DB_AUTHORITY_USERNAME='builderad_authority'; DB_OWNER_USERNAME='builderad_owner'; REDIS_HOST='127.0.0.1'; REDIS_PORT="$redisPort"; QUEUE_CONNECTION='sync' }
    foreach ($key in $required.Keys) { if ($values[$key] -ne $required[$key]) { throw "Private setting $key does not match the approved preview. No settings were rewritten." } }
    foreach ($key in @('APP_KEY','PASSKEYS_USER_HANDLE_SECRET','DB_PASSWORD','DB_AUTHORITY_PASSWORD','DB_OWNER_PASSWORD')) {
        if ([string]::IsNullOrWhiteSpace($values[$key])) { throw "Required original private setting $key is missing; no replacement was generated." }
    }
    return $values
}
function Reset-PreviewEnvironment([hashtable] $Values) {
    foreach ($name in @([Environment]::GetEnvironmentVariables('Process').Keys)) {
        if ([string] $name -match '^(APP_|DB_|PASSKEYS_|SESSION_|CACHE_|REDIS_|QUEUE_|MAIL_|PG|LARAVEL_STORAGE_PATH$)') { Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue }
    }
    foreach ($key in $Values.Keys) { Set-Item -LiteralPath "Env:$key" -Value ([string] $Values[$key]) }
}
function Get-HttpRuntime {
    if (-not (Test-Path -LiteralPath $tokenPath -PathType Leaf)) { throw 'Local HTTP verification credential is missing.' }
    $token = [IO.File]::ReadAllText($tokenPath)
    try {
        $response = Invoke-WebRequest -Uri "$appUrl/__builderad_local_preview_status" -Headers @{ 'X-Builderad-Preview-Probe'=$token } -TimeoutSec 10 -UseBasicParsing -SkipHttpErrorCheck
    } catch {
        $error = [InvalidOperationException]::new('The actual HTTP process is not ready for verification.')
        $error.Data['ProbeTransportFailure']=$true
        throw $error
    }
    if ($response.StatusCode -ne 200) { throw 'HTTP verification rejected this process/configuration; it was not reused.' }
    try { $runtime = $response.Content | ConvertFrom-Json } catch { throw 'Invalid local HTTP verification response; process not reused.' }
    if (-not $runtime.verified -or $runtime.protocol -ne 'stable-preview-v1' -or $runtime.root -ne $root -or $runtime.public -ne (Join-Path $root 'public')) {
        throw 'The HTTP process does not match the approved root, configuration, database roles, Redis stores and Passkey origin.'
    }
    $process = Assert-PreviewServer ([int] $runtime.pid)
    $runtime | Add-Member -NotePropertyName router -NotePropertyValue $(if ($process.CommandLine.Replace('/', '\').Contains($router)) { $router } else { $legacyRouter })
    return $runtime
}
function Wait-HttpRuntime($Process) {
    # TCP acceptance precedes Laravel readiness on a cold start. Retain the
    # launcher's bounded readiness loop; never retry a rejected configuration.
    $deadline=[DateTime]::UtcNow.AddSeconds(30)
    do {
        if ($Process.HasExited) { throw 'The newly started PHP process exited before HTTP verification.' }
        try { return Get-HttpRuntime } catch {
            if (-not $_.Exception.Data.Contains('ProbeTransportFailure') -or [DateTime]::UtcNow -ge $deadline) { throw }
            Start-Sleep -Milliseconds 250
        }
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'Laravel did not become HTTP-ready within the startup window.'
}
function Assert-LoginPages {
    foreach ($path in @('/login','/internal/login')) {
        $page = Invoke-WebRequest -Uri ($appUrl+$path) -Headers @{ Accept='text/html' } -TimeoutSec 10 -UseBasicParsing
        if ($page.StatusCode -ne 200 -or $page.Content -notmatch '(?i)<!doctype html>' -or $page.Content -notmatch 'application-bootstrap' -or
            $page.Content -match '(?i)Fatal error|Failed opening required|Warning: require') { throw "The actual login document $path is invalid; HTTP status alone was not accepted." }
    }
}
function Save-State($Runtime, [bool] $RedisOwned) {
    $server = Assert-PreviewServer ([int] $Runtime.pid)
    $redis = Get-ProcessInfo ([int] $Runtime.redis.pid)
    [ordered]@{ Root=$root; Url=$appUrl; Database='builderad_dev'; DatabasePort=5432; ServerPid=[int] $Runtime.pid;
        ServerCreated=$server.CreationDate.ToUniversalTime().ToString('o'); RedisPid=[int] $Runtime.redis.pid;
        RedisCreated=$(if ($redis) { $redis.CreationDate.ToUniversalTime().ToString('o') } else { '' }); RedisStartedByLauncher=$RedisOwned;
        Router=$Runtime.router; LastVerifiedAt=(Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
}
function Show-PreviewStatus {
    $report = [ordered]@{ Expected=[ordered]@{ Root=$root; Public=(Join-Path $root 'public'); Url=$appUrl; Database='builderad_dev'; DatabaseHost='127.0.0.1'; DatabasePort=5432; RedisPort=$redisPort };
        Laravel='stopped'; Verified=$null; Redis=$(if (Test-Port $redisPort) { "TCP reachable on $redisPort; process/configuration not yet verified" } else { 'stopped' }); Worker='not required by expected queue=sync; runtime not yet verified' }
    if (Test-Port $appPort) {
        try {
            $runtime = Get-HttpRuntime
            $report.Laravel = 'running and HTTP-verified'
            $report.Verified = $runtime
            $report.Redis = 'PING and process identity verified by HTTP'
            $report.Worker = if ($runtime.queue -eq 'sync') { 'not required (verified queue=sync)' } else { 'not managed' }
        } catch { $report.Laravel='occupied but UNVERIFIED; untouched' }
    } elseif (@(Get-PreviewServerProcesses).Count) { $report.Laravel='owned process present but HTTP unavailable; UNVERIFIED' }
    $report | ConvertTo-Json -Depth 8
}
function Stop-PreviewServer {
    $redisStopped=$false
    $servers = @(Get-PreviewServerProcesses)
    if (Test-Port $appPort) {
        # An authenticated response identifies the actual listener; a saved PID alone cannot.
        $runtime = Get-HttpRuntime
        $actual = Assert-PreviewServer ([int] $runtime.pid)
        if ($servers.Count -ne 1 -or [int] $servers[0].ProcessId -ne [int] $actual.ProcessId) { throw 'Ambiguous preview process ownership; nothing stopped.' }
    } elseif ($servers.Count) { throw 'A preview process exists but its HTTP identity is unavailable; left untouched.' }
    $state = if (Test-Path -LiteralPath $statePath) { Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json -AsHashtable } else { @{} }
    foreach ($server in $servers) {
        $created = $server.CreationDate.ToUniversalTime().ToString('o')
        Assert-PreviewServer ([int] $server.ProcessId) $created | Out-Null
        Stop-Process -Id ([int] $server.ProcessId) -ErrorAction Stop
    }
    for ($i=0; $i -lt 30 -and (Test-Port $appPort); $i++) { Start-Sleep -Milliseconds 100 }
    if (Test-Port $appPort) { throw 'The preview port did not close; no other process was stopped.' }
    # Existing Redis is reused but not claimed as owned. Only a Redis started by this launcher,
    # whose PID, creation time and exact config path still match, may be stopped here.
    if ($state.ContainsKey('RedisStartedByLauncher') -and $state.RedisStartedByLauncher) {
        $owned = @(Get-RedisPreviewProcesses | Where-Object { [int] $_.ProcessId -eq [int] $state.RedisPid -and $_.CreationDate.ToUniversalTime().ToString('o') -eq $state.RedisCreated })
        if ($owned.Count -eq 1) { Stop-Process -Id ([int] $owned[0].ProcessId) -ErrorAction Stop; $redisStopped=$true }
        else { Write-Warning 'Recorded Redis ownership could not be verified; Redis was left untouched.' }
    }
    if ($state.Count) { $state.ServerPid=0; $state.StoppedAt=(Get-Date).ToUniversalTime().ToString('o'); $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8 }
    Write-Output "STABLE_PREVIEW_STOPPED; verified Laravel stopped; launcher-started Redis stopped=$redisStopped; pre-existing/shared Redis and PostgreSQL untouched."
}

try {
    if ($Status) { Show-PreviewStatus; exit 0 }
    if ($Stop) { Stop-PreviewServer; exit 0 }
    $values = Read-Settings
    if (Test-Port $appPort) {
        $runtime = Get-HttpRuntime
        Assert-LoginPages
        $redisOwned=$false
        if (Test-Path -LiteralPath $statePath) {
            $state=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json -AsHashtable
            $redisOwned=$state.ContainsKey('RedisStartedByLauncher') -and $state.RedisStartedByLauncher -and [int] $state.RedisPid -eq [int] $runtime.redis.pid
        }
        Save-State $runtime $redisOwned
        Write-Output "STABLE_PREVIEW_READY; existing HTTP process verified and reused: $appUrl; builderad_dev:5432"
        exit 0
    }
    if (@(Get-PreviewServerProcesses).Count) { throw 'An owned PHP process exists without a responsive listener; inspect it before starting another.' }
    foreach ($file in @($php,$router,$redisServer,$redisConf,$tokenPath)) { if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { throw "Required preview file missing: $file" } }
    Reset-PreviewEnvironment $values
    New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
    $startedRedis=$null
    $startedServer=$null
    try {
        if (-not (Test-Port $redisPort)) {
            if (@(Get-RedisPreviewProcesses).Count) { throw 'Existing Redis process is not ready; it was left untouched.' }
            $startedRedis=Start-Process -FilePath $redisServer -ArgumentList "`"$redisConf`"" -WorkingDirectory $previewRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logsDir 'redis.out.log') -RedirectStandardError (Join-Path $logsDir 'redis.err.log')
            for ($i=0; $i -lt 30 -and -not (Test-Port $redisPort); $i++) { Start-Sleep -Milliseconds 100 }
            if (-not (Test-Port $redisPort)) { throw 'Existing preview Redis configuration did not start.' }
        }
        $startedServer=Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$appPort",'-t',"`"$(Join-Path $root 'public')`"","`"$router`"") -WorkingDirectory $root -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logsDir 'server.out.log') -RedirectStandardError (Join-Path $logsDir 'server.err.log')
        for ($i=0; $i -lt 30 -and -not (Test-Port $appPort); $i++) { Start-Sleep -Milliseconds 100 }
        $runtime=Wait-HttpRuntime $startedServer
        if ([int] $runtime.pid -ne $startedServer.Id) { throw 'The responding HTTP process is not the process just started.' }
        Assert-LoginPages
        Save-State $runtime ($null -ne $startedRedis)
        Write-Output "STABLE_PREVIEW_READY; HTTP PID $($runtime.pid); $appUrl; builderad_dev:5432; three PostgreSQL roles and Redis-backed sessions verified."
    } catch {
        if ($null -ne $startedServer) { $process=Get-ProcessInfo $startedServer.Id; if (Test-PreviewCommand $process) { Stop-Process -Id $startedServer.Id } }
        if ($null -ne $startedRedis) { foreach ($process in @(Get-RedisPreviewProcesses | Where-Object { [int] $_.ProcessId -eq $startedRedis.Id })) { Stop-Process -Id ([int] $process.ProcessId) } }
        throw
    }
} catch {
    # Never render subprocess/configuration contents, database exceptions or secret values.
    Write-Error $_.Exception.Message
    exit 1
}
