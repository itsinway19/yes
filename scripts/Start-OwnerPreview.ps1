param([switch]$NoOpenBrowser)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$previewRoot = Split-Path -Parent $PSScriptRoot
$previewDirectory = Join-Path $previewRoot 'storage/app/owner-preview-db'
$previewData = Join-Path $previewDirectory 'pg-data'
$previewUrl = 'http://localhost:8027'
$php = 'C:/tools/php85/php.exe'
$pgCtl = 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe'
$redis = 'C:/Program Files/Redis/redis-server.exe'
$recordPath = Join-Path $previewDirectory 'experience-preview-process.json'

function Test-PreviewPort([int]$Port) {
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $connection = $client.ConnectAsync('127.0.0.1', $Port)
        return $connection.Wait(500) -and $client.Connected
    } catch { return $false } finally { $client.Dispose() }
}
function Start-PreviewProcess([string]$File, [string[]]$Arguments, [string]$Name, [string]$Directory) {
    Start-Process -FilePath $File -ArgumentList $Arguments -WorkingDirectory $Directory -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $previewDirectory "$Name.out.log") `
        -RedirectStandardError (Join-Path $previewDirectory "$Name.err.log")
}

foreach ($required in @($php, $pgCtl, $redis, (Join-Path $previewData 'PG_VERSION'))) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Required preview file is missing: $required" }
}
$environmentText = [IO.File]::ReadAllText((Join-Path $previewRoot '.env'))
foreach ($setting in @('DB_PORT=5434', 'REDIS_PORT=6379', 'APP_URL=http://localhost:8027')) {
    if ($environmentText -notmatch ('(?m)^' + [regex]::Escape($setting) + '\r?$')) {
        throw "Preview configuration must contain $setting"
    }
}
$record = @{ Root = $previewRoot; Url = $previewUrl; DatabasePort = 5434 }
if (Test-Path -LiteralPath $recordPath) {
    $previous = Get-Content -LiteralPath $recordPath -Raw | ConvertFrom-Json -AsHashtable
    if ($previous.Root -and [IO.Path]::GetFullPath($previous.Root) -eq [IO.Path]::GetFullPath($previewRoot)) {
        foreach ($key in @('Pid', 'QueuePid', 'RedisPid')) {
            if ($previous.ContainsKey($key)) { $record[$key] = $previous[$key] }
        }
    }
}
& $pgCtl status -D $previewData | Out-Null
if ($LASTEXITCODE -ne 0) {
    if (Test-PreviewPort 5434) { throw 'Port 5434 is occupied by another PostgreSQL instance.' }
    & $pgCtl start -D $previewData -l (Join-Path $previewDirectory 'preview-postgres.log') -o '-p 5434 -h 127.0.0.1' -w -t 45
    if ($LASTEXITCODE -ne 0) { throw 'Preview database failed to start. See preview-postgres.log.' }
}
if (-not (Test-PreviewPort 6379)) {
    $process = Start-PreviewProcess $redis @('--bind', '127.0.0.1', '--port', '6379', '--dir', $previewDirectory, '--dbfilename', 'experience-preview-redis.rdb') 'experience-redis' $previewRoot
    $record.RedisPid = $process.Id
}
if (-not (Test-PreviewPort 8027)) {
    $router = Join-Path $previewRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
    $process = Start-PreviewProcess $php @('-S', '127.0.0.1:8027', ('"' + $router + '"')) 'experience-server' (Join-Path $previewRoot 'public')
    $record.Pid = $process.Id
}
$queueRunning = $false
if ($record.ContainsKey('QueuePid') -and $record.QueuePid) {
    $worker = Get-CimInstance Win32_Process -Filter "ProcessId=$($record.QueuePid)" -ErrorAction SilentlyContinue
    $queueRunning = $null -ne $worker -and $worker.Name -eq 'php.exe' -and $worker.CommandLine -like '*queue:work*'
}
if (-not $queueRunning) {
    $artisan = Join-Path $previewRoot 'artisan'
    $process = Start-PreviewProcess $php @(('"' + $artisan + '"'), 'queue:work', 'redis', '--queue=default', '--sleep=1', '--tries=1', '--timeout=90') 'experience-queue' $previewRoot
    $record.QueuePid = $process.Id
}
$record | ConvertTo-Json | Set-Content -LiteralPath $recordPath
$record.QueuePid | Set-Content -LiteralPath (Join-Path $previewDirectory 'experience-preview-queue.pid')

$ready = $false
for ($attempt = 0; $attempt -lt 15; $attempt++) {
    try {
        $response = Invoke-WebRequest "$previewUrl/login" -Headers @{ Accept = 'text/html' } -TimeoutSec 3 -UseBasicParsing
        if ($response.StatusCode -eq 200 -and $response.Content -match 'BuilderAd') { $ready = $true; break }
    } catch { Start-Sleep -Milliseconds 400 }
}
if (-not $ready) { throw 'Preview did not return the BuilderAd login page. Check experience-server.err.log and storage/logs/laravel.log.' }
Write-Host "Preview ready: $previewUrl/login"
if (-not $NoOpenBrowser) { Start-Process "$previewUrl/login" }
