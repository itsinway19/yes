[CmdletBinding()]
param([switch] $Stop, [switch] $Status)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root
$envPath = Join-Path $root '.env'
$statePath = Join-Path $root 'storage/app/content-preview/stable-preview.json'
$previewRoot = Split-Path -Parent $statePath
$logsDir = Join-Path $previewRoot 'logs'
$hostAddress = '127.0.0.1'
$database = 'builderad_test'
$dbPort = 5432
$redisPort = 6398
$appPort = 8037
$appUrl = "http://localhost:$appPort"
$router = Join-Path $root 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
$php = if ($env:BUILDERAD_PHP) { $env:BUILDERAD_PHP } else { 'C:\tools\php85\php.exe' }
$redisServer = if ($env:BUILDERAD_REDIS_SERVER) { $env:BUILDERAD_REDIS_SERVER } else { 'C:\Program Files\Redis\redis-server.exe' }
$redisConf = Join-Path $previewRoot 'redis/redis.conf'
$redisLog = Join-Path $logsDir 'redis.log'

# Laravel's immutable dotenv keeps variables already present in the process. Another worktree or
# an earlier test run can therefore export DB_PORT=5433, REDIS_PORT=6379 or CACHE_STORE=array into
# this shell. The launcher owns that namespace: inherited values are removed, then these pinned
# preview values are applied to both .env and the child process.
$ownedPrefixes = '^(APP_|DB_|SESSION_|CACHE_|REDIS_|QUEUE_)'
$pinned = [ordered]@{
    APP_URL = $appUrl
    APP_ENV = 'local'
    DB_CONNECTION = 'pgsql'
    DB_HOST = $hostAddress
    DB_PORT = "$dbPort"
    DB_DATABASE = $database
    DB_USERNAME = 'builderad_app'
    DB_OWNER_CONNECTION = 'pgsql_migrations'
    DB_OWNER_DATABASE = $database
    DB_OWNER_USERNAME = 'builderad_owner'
    DB_AUTHORITY_USERNAME = 'builderad_authority'
    SESSION_DRIVER = 'redis'
    SESSION_STORE = 'redis'
    SESSION_CONNECTION = 'default'
    SESSION_SECURE_COOKIE = 'false'
    CACHE_STORE = 'redis'
    REDIS_CLIENT = 'predis'
    REDIS_HOST = $hostAddress
    REDIS_PORT = "$redisPort"
    REDIS_DB = '0'
    REDIS_CACHE_DB = '1'
}

function Read-DotEnv([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing local environment file: $Path" }
    $map = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$') {
            $value = $Matches[2].Trim()
            if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $map[$Matches[1]] = $value
        }
    }
    return $map
}

function Set-DotEnv([string] $Path, [string] $Key, [string] $Value) {
    $lines = [Collections.Generic.List[string]]::new()
    foreach ($line in [IO.File]::ReadAllLines($Path)) { $lines.Add($line) }
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) { $lines[$i] = "$Key=$Value"; $found = $true }
    }
    if (-not $found) { $lines.Add("$Key=$Value") }
    [IO.File]::WriteAllLines($Path, $lines, [Text.UTF8Encoding]::new($false))
}

function Reset-PreviewEnvironment([hashtable] $Values) {
    foreach ($name in @([Environment]::GetEnvironmentVariables('Process').Keys)) {
        if ([string] $name -match $ownedPrefixes) { Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue }
    }
    foreach ($key in $Values.Keys) { Set-Item -Path "Env:$key" -Value $Values[$key] }
}

function Get-ProcessInfo([int] $Id) {
    if ($Id -le 0) { return $null }
    try { Get-CimInstance Win32_Process -Filter "ProcessId=$Id" -ErrorAction Stop } catch { return $null }
}

function Test-PreviewCommand([string] $CommandLine) {
    $command = $CommandLine.Replace('/', '\')
    return ($command -like "*-S *:$appPort *" -or $command -like "*--port=$appPort*")
}

function Get-PreviewServerProcesses {
    $expectedRoot = $root.Replace('/', '\')
    $candidates = @{}
    foreach ($process in (Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue)) {
        if ($process.CommandLine -and (Test-PreviewCommand $process.CommandLine)) { $candidates[[int] $process.ProcessId] = $process }
    }
    $owned = @{}
    foreach ($id in @($candidates.Keys)) {
        if ($candidates[$id].CommandLine.Replace('/', '\') -like "*$expectedRoot*") { $owned[$id] = $candidates[$id] }
    }
    # `php artisan serve` starts a child that binds the port and names this worktree's router file.
    foreach ($id in @($owned.Keys)) {
        $parentId = [int] $owned[$id].ParentProcessId
        if ($candidates.ContainsKey($parentId)) { $owned[$parentId] = $candidates[$parentId] }
    }
    $owned.Values
}

function Assert-PreviewServer([int] $Id) {
    $process = Get-ProcessInfo $Id
    if ($null -eq $process -or $process.Name -ine 'php.exe' -or -not $process.CommandLine) {
        throw "Cannot verify Laravel preview process $Id; leaving it untouched."
    }
    $ownedIds = @(Get-PreviewServerProcesses | ForEach-Object { [int] $_.ProcessId })
    if ($ownedIds -notcontains $Id) {
        throw "Process $Id does not match this worktree's Laravel preview on port $appPort; leaving it untouched."
    }
    return $process
}

function Test-Port([int] $Port) {
    $client = [Net.Sockets.TcpClient]::new()
    try { $pending = $client.ConnectAsync('127.0.0.1', $Port); return $pending.Wait(800) -and $client.Connected }
    catch { return $false }
    finally { $client.Dispose() }
}

function Get-RedisPreviewProcesses {
    if (-not (Test-Path -LiteralPath $redisConf -PathType Leaf)) { return @() }
    $expected = $redisConf.Replace('/', '\')
    @(Get-CimInstance Win32_Process -Filter "Name='redis-server.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $_.CommandLine -and $_.CommandLine.Replace('/', '\') -like "*$expected*"
    })
}

function Assert-RedisPreview([int] $Id) {
    $process = Get-ProcessInfo $Id
    $expected = $redisConf.Replace('/', '\')
    if ($null -eq $process -or $process.Name -ine 'redis-server.exe' -or -not $process.CommandLine -or
        $process.CommandLine.Replace('/', '\') -notlike "*$expected*") {
        throw "Redis process $Id does not belong to this preview; leaving it untouched."
    }
    return $process
}

function Test-RedisAuthentication([string] $Password) {
    $client = [Net.Sockets.TcpClient]::new()
    try {
        if (-not $client.ConnectAsync('127.0.0.1', $redisPort).Wait(2000) -or -not $client.Connected) { return $false }
        $stream = $client.GetStream()
        $stream.ReadTimeout = 2000
        $stream.WriteTimeout = 2000
        $writer = [IO.StreamWriter]::new($stream, [Text.Encoding]::ASCII, 1024, $true)
        $writer.NewLine = "`r`n"; $writer.AutoFlush = $true
        $reader = [IO.StreamReader]::new($stream, [Text.Encoding]::ASCII, $false, 1024, $true)
        if ($Password) {
            $writer.WriteLine("AUTH $Password")
            if ($reader.ReadLine() -ne '+OK') { return $false }
        }
        $writer.WriteLine('PING')
        return $reader.ReadLine() -eq '+PONG'
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Ensure-RedisPreview([string] $Password) {
    if (Test-RedisAuthentication $Password) { return }
    $owned = @(Get-RedisPreviewProcesses)
    if ($owned.Count -gt 0) {
        throw 'The preview Redis process is running but does not accept the local credential. Stop it before restarting the preview.'
    }
    if (Test-Port $redisPort) {
        throw "Port $redisPort is occupied by a Redis instance outside this preview; leaving it untouched."
    }
    if (-not (Test-Path -LiteralPath $redisServer -PathType Leaf)) { throw "Redis server not found: $redisServer" }
    if (-not (Test-Path -LiteralPath $redisConf -PathType Leaf)) { throw "Preview Redis configuration not found: $redisConf" }
    Start-Process -FilePath $redisServer -ArgumentList "`"$redisConf`"" -WorkingDirectory (Split-Path -Parent $redisConf) `
        -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsDir 'redis.out.log') `
        -RedirectStandardError (Join-Path $logsDir 'redis.err.log') | Out-Null
    for ($i = 0; $i -lt 40; $i++) {
        if (Test-RedisAuthentication $Password) { return }
        Start-Sleep -Milliseconds 250
    }
    throw "Redis preview did not become ready on port $redisPort. See $redisLog."
}

function Stop-RedisPreview {
    foreach ($process in @(Get-RedisPreviewProcesses)) {
        $id = [int] $process.ProcessId
        Assert-RedisPreview $id | Out-Null
        Stop-Process -Id $id -ErrorAction Stop
    }
    for ($i = 0; $i -lt 40 -and (Test-Port $redisPort); $i++) { Start-Sleep -Milliseconds 250 }
    if (Test-Port $redisPort) { throw "Redis preview did not release port $redisPort." }
}

function Get-ProbeFailureHint([string] $Code) {
    switch ($Code) {
        'postgres_target' { 'Laravel is not pointed at builderad_test on port 5432.' }
        'postgres_app_connect' { 'The runtime role builderad_app cannot connect to builderad_test:5432.' }
        'postgres_authority_connect' { 'The authority role builderad_authority cannot connect to builderad_test:5432.' }
        'postgres_owner_connect' { 'The migration role builderad_owner cannot connect to builderad_test:5432.' }
        'postgres_app_identity' { 'The runtime connection does not resolve to builderad_app on builderad_test:5432.' }
        'postgres_authority_identity' { 'The authority connection does not resolve to builderad_authority on builderad_test:5432.' }
        'postgres_owner_identity' { 'The migration connection does not resolve to builderad_owner on builderad_test:5432.' }
        'redis_connection' { "The preview Redis on 127.0.0.1:$redisPort did not answer PING." }
        'session_config' { 'Session is not configured with driver=redis and store=redis.' }
        'session_handler' { 'The session handler is not CacheBasedSessionHandler.' }
        'session_store' { 'The session cache store is not a RedisStore.' }
        'session_roundtrip' { 'A session value could not be written and read back through Redis.' }
        'cache_config' { 'The default cache store is not redis backed by a RedisStore.' }
        'cache_store' { 'The default cache store is not a RedisStore.' }
        default { "The Laravel runtime probe reported $Code." }
    }
}

function Invoke-RuntimeProbe {
    $probe = @'
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fail = function (string $code): void {
    echo "STABLE_PREVIEW_PROBE_FAILED ".$code.PHP_EOL;
    exit(3);
};
if (config("database.connections.pgsql.database") !== "builderad_test"
    || (string) config("database.connections.pgsql.port") !== "5432") { $fail("postgres_target"); }
foreach (["pgsql" => ["builderad_app", "postgres_app"], "pgsql_authority" => ["builderad_authority", "postgres_authority"], "pgsql_migrations" => ["builderad_owner", "postgres_owner"]] as $name => $expected) {
    try {
        $row = Illuminate\Support\Facades\DB::connection($name)->selectOne("select current_user as identity,current_database() as database,inet_server_port() as port");
    } catch (Throwable $error) {
        $fail($expected[1]."_connect");
    }
    if ($row->identity !== $expected[0] || $row->database !== "builderad_test" || (int) $row->port !== 5432) { $fail($expected[1]."_identity"); }
}
try {
    app("redis")->connection("default")->ping();
    app("redis")->connection("cache")->ping();
} catch (Throwable $error) { $fail("redis_connection"); }
if (config("session.driver") !== "redis" || config("session.store") !== "redis") { $fail("session_config"); }
$handler = app("session")->driver()->getHandler();
if (! $handler instanceof Illuminate\Session\CacheBasedSessionHandler) { $fail("session_handler"); }
if (! $handler->getCache()->getStore() instanceof Illuminate\Cache\RedisStore) { $fail("session_store"); }
if (config("cache.default") !== "redis" || ! Illuminate\Support\Facades\Cache::store()->getStore() instanceof Illuminate\Cache\RedisStore) { $fail("cache_config"); }
$id = "preview-check-".bin2hex(random_bytes(12));
$payload = "stable-preview";
try {
    if (! $handler->write($id, $payload) || $handler->read($id) !== $payload) { $fail("session_roundtrip"); }
} finally {
    $handler->destroy($id);
}
echo "STABLE_PREVIEW_RUNTIME_OK".PHP_EOL;
'@
    $output = & $php -d display_errors=0 -d error_reporting=0 -r $probe 2>&1
    $exit = $LASTEXITCODE
    $text = ($output | Out-String).Trim()
    if ($exit -eq 0 -and $text -eq 'STABLE_PREVIEW_RUNTIME_OK') { return }
    if ($text -match 'STABLE_PREVIEW_PROBE_FAILED\s+([a-z_]+)') {
        $code = $Matches[1]
        throw ((Get-ProbeFailureHint $code) + " Probe code: $code. No credentials were printed.")
    }
    throw "The Laravel runtime probe could not run (exit $exit). Run 'php artisan config:clear' and retry. No credentials were printed."
}

function Show-PreviewStatus {
    $servers = @(Get-PreviewServerProcesses)
    $redis = @(Get-RedisPreviewProcesses)
    $state = if (Test-Path -LiteralPath $statePath -PathType Leaf) { Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json } else { $null }
    Write-Host "Worktree: $root"
    Write-Host "Laravel: $(if ($servers.Count -and (Test-Port $appPort)) { "running on $appUrl (pid $($servers.ProcessId -join ','))" } else { 'stopped' })"
    Write-Host "Redis: $(if ($redis.Count -and (Test-Port $redisPort)) { "running on 127.0.0.1:$redisPort (pid $($redis.ProcessId -join ','))" } else { "stopped (port $redisPort)" })"
    Write-Host "PostgreSQL (shared, untouched): ${database}:$dbPort"
    if ($null -ne $state -and $state.PSObject.Properties.Name -contains 'StartedAt') { Write-Host "Last start: $($state.StartedAt)" }
    if ($null -ne $state -and $state.PSObject.Properties.Name -contains 'StoppedAt') { Write-Host "Last stop: $($state.StoppedAt)" }
}

function Stop-PreviewServer {
    $servers = @(Get-PreviewServerProcesses)
    if (-not $servers.Count) {
        if (Test-Port $appPort) { throw "Port $appPort is occupied by a process outside this worktree; leaving it untouched." }
        Stop-RedisPreview
        Write-Host 'Laravel preview is already stopped.'
        return
    }
    foreach ($server in $servers) {
        $id = [int] $server.ProcessId
        Assert-PreviewServer $id | Out-Null
        Stop-Process -Id $id -ErrorAction Stop
    }
    for ($i=0; $i -lt 40 -and (Test-Port $appPort); $i++) { Start-Sleep -Milliseconds 250 }
    if (Test-Port $appPort) { throw "Laravel did not release port $appPort." }
    $state = if (Test-Path -LiteralPath $statePath -PathType Leaf) { Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json } else { [pscustomobject]@{ Root=$root; Url=$appUrl; Database=$database; DatabasePort=$dbPort; RedisHost=$hostAddress; RedisPort=$redisPort; AppPort=$appPort; ServerPid=0 } }
    if ([IO.Path]::GetFullPath($state.Root) -ne [IO.Path]::GetFullPath($root)) { throw 'Preview state belongs to another worktree.' }
    $state | Add-Member -NotePropertyName ServerPid -NotePropertyValue 0 -Force
    $state | Add-Member -NotePropertyName StoppedAt -NotePropertyValue (Get-Date).ToString('o') -Force
    $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
    Stop-RedisPreview
    Write-Host 'Laravel and the preview Redis stopped. PostgreSQL was left untouched; database data was untouched.'
}

try {
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw "PHP executable not found: $php" }
    if (-not (Test-Path -LiteralPath $router -PathType Leaf)) { throw "Laravel router not found: $router" }
    if ($Stop) { Stop-PreviewServer; exit 0 }
    if ($Status) { Show-PreviewStatus; exit 0 }

    New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
    $envMap = Read-DotEnv $envPath
    foreach ($key in $pinned.Keys) {
        if (-not $envMap.ContainsKey($key) -or $envMap[$key] -ne $pinned[$key]) {
            Set-DotEnv $envPath $key $pinned[$key]
        }
    }
    $envMap = Read-DotEnv $envPath
    foreach ($key in @('APP_KEY','DB_PASSWORD','DB_OWNER_PASSWORD','DB_AUTHORITY_PASSWORD')) {
        if (-not $envMap.ContainsKey($key) -or [string]::IsNullOrWhiteSpace($envMap[$key])) { throw "Required local setting $key is missing from .env." }
    }
    Reset-PreviewEnvironment $envMap

    # A cached configuration keeps a previous target even after .env is corrected.
    $clear = & $php artisan config:clear --no-interaction 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Could not clear the Laravel configuration cache: $(($clear | Out-String).Trim())" }
    Ensure-RedisPreview $(if ($envMap.ContainsKey('REDIS_PASSWORD')) { [string] $envMap['REDIS_PASSWORD'] } else { '' })
    Invoke-RuntimeProbe

    $reused = @()
    if (Test-Port $appPort) {
        $reused = @(Get-PreviewServerProcesses)
        if (-not $reused.Count) {
            throw "Port $appPort is occupied by a process outside this worktree (for example another tree's preview). It was left untouched; use another port or stop that service."
        }
        foreach ($server in $reused) { Assert-PreviewServer ([int] $server.ProcessId) | Out-Null }
    }

    if ($reused.Count) {
        # The same worktree already serves this port (launcher or an interactive `artisan serve`).
        # Reuse it instead of starting a second server; the runtime probe above already confirmed
        # exactly what it would serve.
        $process = $null
        $ready = $false
        for ($i=0; $i -lt 20; $i++) {
            try {
                $advertiser = Invoke-WebRequest -Uri "$appUrl/login" -Headers @{ Accept='text/html' } -TimeoutSec 3 -UseBasicParsing
                $admin = Invoke-WebRequest -Uri "$appUrl/internal/login" -Headers @{ Accept='text/html' } -TimeoutSec 3 -UseBasicParsing
                if ($advertiser.StatusCode -eq 200 -and $admin.StatusCode -eq 200) { $ready = $true; break }
            } catch { }
            Start-Sleep -Milliseconds 300
        }
        if (-not $ready) { throw "The preview server owned by this worktree on port $appPort did not serve both login pages. Run the launcher with -Stop, then start it again." }
        $state = [ordered]@{ Root=$root; Url=$appUrl; Database=$database; DatabasePort=$dbPort; RedisHost=$hostAddress; RedisPort=$redisPort; AppPort=$appPort; ServerPid=[int] $reused[0].ProcessId; StartedAt=(Get-Date).ToString('o'); Reused=$true }
        $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
        Write-Host 'STABLE_PREVIEW_READY'
        Write-Host "Laravel: $appUrl reused the existing server for this worktree (pid $($reused.ProcessId -join ',')); both login pages returned HTTP 200."
        Write-Host "PostgreSQL: ${database}:$dbPort; builderad_app, builderad_authority and builderad_owner verified independently."
        Write-Host "Redis: 127.0.0.1:$redisPort; PING plus session/cache RedisStore write-read verified."
        exit 0
    }

    $stdout = Join-Path $logsDir 'stable-server.out.log'
    $stderr = Join-Path $logsDir 'stable-server.err.log'
    $process = Start-Process -FilePath $php -ArgumentList @('-S', "${hostAddress}:$appPort", "`"$router`"") `
        -WorkingDirectory (Join-Path $root 'public') -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    $ready = $false
    for ($i=0; $i -lt 40; $i++) {
        try {
            $advertiser = Invoke-WebRequest -Uri "$appUrl/login" -Headers @{ Accept='text/html' } -TimeoutSec 3 -UseBasicParsing
            $admin = Invoke-WebRequest -Uri "$appUrl/internal/login" -Headers @{ Accept='text/html' } -TimeoutSec 3 -UseBasicParsing
            if ($advertiser.StatusCode -eq 200 -and $admin.StatusCode -eq 200) { $ready = $true; break }
        } catch { }
        if ($process.HasExited) { break }
        Start-Sleep -Milliseconds 300
    }
    if (-not $ready) { throw "Login page verification failed. See $stderr; the preview was not marked ready." }
    $state = [ordered]@{ Root=$root; Url=$appUrl; Database=$database; DatabasePort=$dbPort; RedisHost=$hostAddress; RedisPort=$redisPort; AppPort=$appPort; ServerPid=$process.Id; StartedAt=(Get-Date).ToString('o') }
    $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8
    Write-Host 'STABLE_PREVIEW_READY'
    Write-Host "Laravel: $appUrl (advertiser/admin login HTTP 200)"
    Write-Host "PostgreSQL: ${database}:$dbPort; builderad_app, builderad_authority and builderad_owner verified independently."
    Write-Host "Redis: 127.0.0.1:$redisPort; PING plus session/cache RedisStore write-read verified."
} catch {
    Write-Error $_.Exception.Message
    exit 1
}
