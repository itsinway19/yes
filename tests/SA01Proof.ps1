param([string]$Filter = 'CommercialScopeTest|WorkspaceIsolationTest|DatabaseAuthorityBoundaryTest|InternalAuthorityTest|OwnerGrantReassessmentTest', [switch]$Browser,
    [ValidateSet('','source','target')][string]$N1DurabilityPhase = '', [string]$N1PreservationFile = '')

$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $taskRoot
$php = 'C:\tools\php85\php.exe'
$pg = 'C:\Program Files\PostgreSQL\18\bin'
$token = [guid]::NewGuid().ToString()
$runRoot = Join-Path $taskRoot "storage/framework/testing/sa01-$token"
$dataRoot = Join-Path $runRoot 'data'
$started = $false
$createdEnvironmentFile = $false
$webProcess = $null
New-Item -ItemType Directory -Force -Path $runRoot | Out-Null

function Assert-Exit([string]$operation) {
    if ($LASTEXITCODE -ne 0) { throw "$operation failed ($LASTEXITCODE)." }
}
function New-Secret { return [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).ToLowerInvariant() }

try {
    if ($N1DurabilityPhase) {
        $preservationRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'storage/framework/testing')) + [IO.Path]::DirectorySeparatorChar
        if (-not $N1PreservationFile -or -not [IO.Path]::GetFullPath($N1PreservationFile).StartsWith($preservationRoot, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'N1 preservation file must be inside the task testing directory.'
        }
        if ($N1DurabilityPhase -eq 'target' -and -not (Test-Path -LiteralPath $N1PreservationFile)) { throw 'Missing N1 source preservation file.' }
    }
    # Never probe/connect to 5432. A pre-existing 5433 listener is a blocking collision.
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 5433)
    try { $listener.Start() } finally { $listener.Stop() }
    $superSecret = New-Secret
    $appSecret = New-Secret
    $authoritySecret = New-Secret
    $ownerSecret = New-Secret
    $pwFile = Join-Path $runRoot 'bootstrap-password'
    Set-Content -LiteralPath $pwFile -Value $superSecret -NoNewline
    & "$pg/initdb.exe" -D $dataRoot -U postgres --pwfile=$pwFile --auth-host=scram-sha-256 --auth-local=scram-sha-256 --encoding=UTF8 --locale=C
    Assert-Exit 'fresh initdb'
    Add-Content -LiteralPath (Join-Path $dataRoot 'postgresql.conf') -Value "`nlisten_addresses='127.0.0.1'`nport=5433`n"
    & "$pg/pg_ctl.exe" -D $dataRoot -l (Join-Path $runRoot 'postgres.log') -w start
    Assert-Exit 'disposable PostgreSQL start'
    $started = $true
    $env:PGPASSWORD = $superSecret
    $bootstrapFile = Join-Path $runRoot 'roles.sql'
    @"
CREATE ROLE builderad_owner LOGIN PASSWORD '$ownerSecret' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
CREATE ROLE builderad_app LOGIN PASSWORD '$appSecret' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
CREATE ROLE builderad_authority LOGIN PASSWORD '$authoritySecret' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS;
CREATE DATABASE builderad_test_disposable OWNER builderad_owner;
-- Synthetic metadata fixtures required by the pre-existing Foundation bootstrap tests.
CREATE DATABASE builderad OWNER builderad_owner;
CREATE DATABASE builderad_test OWNER builderad_owner;
REVOKE ALL ON DATABASE builderad,builderad_test FROM PUBLIC,builderad_app;
GRANT CONNECT ON DATABASE builderad,builderad_test TO builderad_owner,builderad_app;
REVOKE ALL ON DATABASE builderad_test_disposable FROM PUBLIC;
GRANT CONNECT ON DATABASE builderad_test_disposable TO builderad_owner,builderad_app,builderad_authority;
COMMENT ON DATABASE builderad_test_disposable IS 'builderad-disposable-test:v1:$token';
\connect builderad
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC,builderad_app;
GRANT USAGE ON SCHEMA public TO builderad_app;
\connect builderad_test
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC,builderad_app;
GRANT USAGE ON SCHEMA public TO builderad_app;
\connect builderad_test_disposable
ALTER SCHEMA public OWNER TO builderad_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC,builderad_app,builderad_authority;
GRANT USAGE ON SCHEMA public TO builderad_app,builderad_authority;
ALTER ROLE builderad_app SET statement_timeout='15s';
ALTER ROLE builderad_app SET lock_timeout='3s';
ALTER ROLE builderad_authority SET lock_timeout='3s';
"@ | Set-Content -LiteralPath $bootstrapFile
    & "$pg/psql.exe" -X -h 127.0.0.1 -p 5433 -U postgres -d postgres -v ON_ERROR_STOP=1 -f $bootstrapFile
    Assert-Exit 'isolated role bootstrap'
    if ($N1DurabilityPhase -eq 'source') {
        # Each standalone SELECT consumes an actual top-level XID. The independent
        # target is initialized separately without this source history advancement.
        $advanceFile = Join-Path $runRoot 'n1-source-history.sql'
        (1..1200 | ForEach-Object { 'SELECT pg_current_xact_id();' }) | Set-Content -LiteralPath $advanceFile
        & "$pg/psql.exe" -X -q -h 127.0.0.1 -p 5433 -U postgres -d builderad_test_disposable -v ON_ERROR_STOP=1 -f $advanceFile *> (Join-Path $runRoot 'n1-history.log')
        Assert-Exit 'real source transaction history advancement'
    }
    if ($N1DurabilityPhase) {
        & "$pg/pg_controldata.exe" -D $dataRoot | Select-String 'Database system identifier'
        Assert-Exit 'independent installation identity'
    }
    Remove-Item Env:PGPASSWORD
    # Explicit process-local configuration, before any Laravel boot/destructive action.
    $env:APP_ENV = 'testing'
    # An empty testing env file avoids Dotenv's suppressed missing-file warning in PHPUnit.
    # Connection credentials remain process-local, never in this file.
    $testingEnvironmentFile = Join-Path $taskRoot '.env.testing'
    if (-not (Test-Path -LiteralPath $testingEnvironmentFile)) {
        Set-Content -LiteralPath $testingEnvironmentFile -Value '# SA-01 disposable runner: configuration is process-local.'
        $createdEnvironmentFile = $true
    }
    $env:APP_KEY = 'base64:' + [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_URL = ''
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '5433'
    $env:DB_DATABASE = 'builderad_test_disposable'
    $env:DB_OWNER_DATABASE = 'builderad_test_disposable'
    $env:DB_USERNAME = 'builderad_app'
    $env:DB_PASSWORD = $appSecret
    $env:DB_OWNER_USERNAME = 'builderad_owner'
    $env:DB_OWNER_PASSWORD = $ownerSecret
    $env:DB_AUTHORITY_USERNAME = 'builderad_authority'
    $env:DB_AUTHORITY_PASSWORD = $authoritySecret
    $env:BUILDERAD_TEST_ISOLATION_TOKEN = $token
    $env:SA01_N1_DURABILITY_PHASE = $N1DurabilityPhase
    $env:SA01_N1_PRESERVATION_FILE = $N1PreservationFile
    $env:GITHUB_ACTIONS = ''
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:MAIL_MAILER = 'array'
    $env:OPENSSL_CONF = 'C:\tools\php85\extras\ssl\openssl.cnf'
    $env:TEMP = $runRoot
    $env:TMP = $runRoot
    $env:Path = "$pg;C:\tools\php85;" + $env:Path
    & $php artisan security:assert-disposable-test-db
    Assert-Exit 'real DisposableTestDatabaseGuard'
    & $php artisan migrate --database=pgsql_migrations --force
    Assert-Exit 'zero-to-head full migration'
    & $php artisan test --filter=$Filter
    Assert-Exit 'focused proof tests'
    if ($Browser) {
        $env:SESSION_DRIVER = 'file'
        $env:APP_URL = 'http://127.0.0.1:8765'
        $env:SESSION_SECURE_COOKIE = 'false'
        $env:APP_DEBUG = 'false'
        & $php tests/SA01BrowserFixture.php $runRoot
        Assert-Exit 'guarded synthetic browser fixture'
        $webProcess = Start-Process -FilePath $php -ArgumentList @('-S','127.0.0.1:8765','-t','public',(Join-Path $runRoot 'browser-router.php')) -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runRoot 'web.log') -RedirectStandardError (Join-Path $runRoot 'web-errors.log')
        Write-Output "BROWSER_RUN_ROOT=$runRoot; WEB_PID=$($webProcess.Id); SESSION_FIXTURE=REAL_SESSION_AUTHORITY_SYNTHETIC_NOT_WEBAUTHN_PROOF"
        $deadline = (Get-Date).AddMinutes(15)
        while (-not (Test-Path -LiteralPath (Join-Path $runRoot 'finish')) -and (Get-Date) -lt $deadline -and -not $webProcess.HasExited) { Start-Sleep -Seconds 1 }
    }
    $env:PGPASSWORD = $ownerSecret
    & "$pg/psql.exe" -X -h 127.0.0.1 -p 5433 -U builderad_owner -d builderad_test_disposable -v ON_ERROR_STOP=1 -f (Join-Path $PSScriptRoot 'sa01-catalog-proof.sql')
    Assert-Exit 'actual database catalog evidence'
    & "$pg/psql.exe" -X -h 127.0.0.1 -p 5433 -U builderad_owner -d builderad_test_disposable -v ON_ERROR_STOP=1 -f (Join-Path $PSScriptRoot 'source-catalog-proof.sql')
    Assert-Exit 'actual BB1 source database catalog evidence'
    Write-Output "DISPOSABLE_RUN=$token; DATA=$dataRoot; GUARD=PASS; SERVICE_5432_CONNECTIONS=0; SERVICE_5432_WRITES=0"
} finally {
    if ($webProcess -and -not $webProcess.HasExited) { Stop-Process -Id $webProcess.Id; Write-Output 'TASK_HTTP_PROCESS=STOPPED' }
    if ($started) {
        & "$pg/pg_ctl.exe" -D $dataRoot -m fast -w stop
        if ($LASTEXITCODE -ne 0) { throw 'Disposable PostgreSQL shutdown failed.' }
        Write-Output 'TASK_POSTGRESQL_PROCESS=STOPPED'
    }
    # All secrets stay in this ignored disposable lifecycle directory/process; no output includes them.
    Remove-Item Env:PGPASSWORD,Env:DB_PASSWORD,Env:DB_OWNER_PASSWORD,Env:DB_AUTHORITY_PASSWORD -ErrorAction SilentlyContinue
    if ($createdEnvironmentFile) { Remove-Item -LiteralPath $testingEnvironmentFile }
}
