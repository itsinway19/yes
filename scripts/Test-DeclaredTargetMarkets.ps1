param(
    [ValidateSet('Focused', 'Full', 'Durability', 'UI')][string] $Phase = 'Focused',
    [string] $Filter = 'BusinessFoundationTest',
    [string] $Baseline = '8ebd9a1f1ffd7b23307d277304203c706e67d95d',
    [string] $DurabilityReference = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskPgBin = 'C:\Program Files\PostgreSQL\18\bin'
$taskRun = Join-Path $taskRoot ('storage/framework/testing/dtm-db-' + [guid]::NewGuid().ToString())
$taskToken = [guid]::NewGuid().ToString()
$taskStarted = $false
$taskExit = 1
$taskUiServer = $null
New-Item -ItemType Directory -Force -Path $taskRun | Out-Null
Set-Location -LiteralPath $taskRoot
function Invoke-TaskPsql([string[]] $TaskArguments) {
    & (Join-Path $taskPgBin 'psql.exe') -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres @TaskArguments
    if ($LASTEXITCODE -ne 0) { throw 'Isolated DTM database provisioning failed.' }
}
try {
    if ($Baseline -ne '8ebd9a1f1ffd7b23307d277304203c706e67d95d') { throw 'Unexpected authoritative baseline.' }
    & (Join-Path $taskPgBin 'pg_isready.exe') -h 127.0.0.1 -p 5433
    if ($LASTEXITCODE -ne 2) { throw 'Port 5433 is not free; no existing server will be modified.' }
    & (Join-Path $taskPgBin 'initdb.exe') -D (Join-Path $taskRun 'data') -U postgres -A trust --encoding=UTF8 --locale=C
    if ($LASTEXITCODE -ne 0) { throw 'Task initdb failed.' }
    $taskStart = Start-Process -FilePath (Join-Path $taskPgBin 'pg_ctl.exe') -ArgumentList @('-D', (Join-Path $taskRun 'data'), '-l', (Join-Path $taskRun 'postgres.log'), '-o', '"-p 5433 -h 127.0.0.1"', '-w', 'start') -WindowStyle Hidden -PassThru
    $taskStart.WaitForExit()
    if ($taskStart.ExitCode -ne 0) { throw 'Task PostgreSQL failed to start.' }
    $taskStarted = $true
    Invoke-TaskPsql @('-d', 'postgres', '-f', 'database/bootstrap/postgresql.sql')
    Invoke-TaskPsql @('-d', 'builderad_test', '-f', 'database/bootstrap/test-authority-role.sql')
    Invoke-TaskPsql @('-d', 'postgres', '-v', "isolation_token=$taskToken", '-f', 'database/bootstrap/disposable-test.sql')
    $env:APP_ENV = 'testing'
    $env:APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '5433'
    $env:DB_DATABASE = 'builderad_test_disposable'
    $env:DB_OWNER_DATABASE = 'builderad_test_disposable'
    $env:DB_USERNAME = 'builderad_app'
    $env:DB_OWNER_USERNAME = 'builderad_owner'
    $env:DB_AUTHORITY_USERNAME = 'builderad_authority'
    $env:DB_PASSWORD = ''
    $env:DB_OWNER_PASSWORD = ''
    $env:DB_AUTHORITY_PASSWORD = ''
    $env:BUILDERAD_TEST_ISOLATION_TOKEN = $taskToken
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:DTM_PROOF_RUN = $taskRun
    $env:DTM_PROOF_BASELINE = $Baseline
    $env:DTM_DURABILITY_REFERENCE = $DurabilityReference
    if ($Phase -eq 'Durability') {
        $env:DTM_CLUSTER_SYSTEM_IDENTIFIER = (& (Join-Path $taskPgBin 'psql.exe') -X -At -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres -d builderad_test_disposable -c 'SELECT system_identifier FROM pg_control_system()').Trim()
        if ($LASTEXITCODE -ne 0) { throw 'Task cluster system identity read failed.' }
    }
    & pwsh -NoProfile -File scripts/builderad.ps1 php artisan security:assert-disposable-test-db
    if ($LASTEXITCODE -ne 0) { throw 'Disposable guard failed before package discovery/tests.' }
    & pwsh -NoProfile -File scripts/builderad.ps1 php artisan package:discover --ansi
    if ($LASTEXITCODE -ne 0) { throw 'Package discovery failed in isolated environment.' }
    $taskRelativeResult = 'storage/framework/testing/' + (Split-Path -Leaf $taskRun) + '/results.xml'
    switch ($Phase) {
        'Focused' { & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--filter=$Filter" "--log-junit=$taskRelativeResult" 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'tests.log') }
        'Full' { & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--log-junit=$taskRelativeResult" 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'tests.log') }
        'Durability' { & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/DeclaredTargetMarketsDurabilityProof.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'durability.log') }
        'UI' {
            if (Get-NetTCPConnection -LocalPort 8017 -State Listen -ErrorAction SilentlyContinue) { throw 'UI port is not free.' }
            if (Test-Path -LiteralPath (Join-Path $taskRoot 'public/hot')) { throw 'An existing Vite hot file would bypass the isolated built preview.' }
            $env:SESSION_DRIVER = 'file'
            $env:APP_URL = 'http://127.0.0.1:8017'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/DeclaredTargetMarketsUiFixture.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'ui.log')
            if ($LASTEXITCODE -ne 0) { throw 'Isolated UI fixture failed.' }
            $taskUiServer = Start-Process -FilePath 'C:\tools\php85\php.exe' -ArgumentList @('-S','127.0.0.1:8017','-t','.',(Join-Path $taskRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')) -WorkingDirectory (Join-Path $taskRoot 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRun 'ui-server.log') -RedirectStandardError (Join-Path $taskRun 'ui-server-error.log')
            & 'C:\tools\node24\node.exe' tests/Support/declared-target-markets-browser.mjs 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'ui-browser.log')
        }
    }
    $taskExit = $LASTEXITCODE
    if ($Phase -eq 'Durability' -and $taskExit -eq 0) {
        # Regression for a real standalone Laravel exception that previously rendered with exit 0.
        $taskOriginalReference = $env:DTM_DURABILITY_REFERENCE
        try {
            $env:DTM_DURABILITY_REFERENCE = Join-Path $taskRun 'missing-negative-reference.json'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/DeclaredTargetMarketsDurabilityProof.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'negative-proof.log')
            if ($LASTEXITCODE -eq 0) { throw 'A failed durability proof incorrectly returned success.' }
            Write-Output 'DURABILITY_FAILURE_EXIT_CLASSIFICATION=PASS'
        } finally {
            $env:DTM_DURABILITY_REFERENCE = $taskOriginalReference
        }
        & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings '--filter=DisposableTestDatabaseGuardTest' "--log-junit=storage/framework/testing/$(Split-Path -Leaf $taskRun)/guard-results.xml" 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'guard-tests.log')
        $taskExit = $LASTEXITCODE
    }
} finally {
    if ($null -ne $taskUiServer -and -not $taskUiServer.HasExited) { Stop-Process -Id $taskUiServer.Id -Force; Write-Output 'TASK_UI_SERVER=STOPPED' }
    $taskUiFixture = Join-Path $taskRun 'ui-fixture.json'
    if (Test-Path -LiteralPath $taskUiFixture) {
        $taskSessionRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'storage/framework/sessions'))
        foreach ($taskSessionFile in (Get-Content -LiteralPath $taskUiFixture -Raw | ConvertFrom-Json).sessionFiles) {
            $taskSessionPath = [IO.Path]::GetFullPath($taskSessionFile)
            if (-not $taskSessionPath.StartsWith($taskSessionRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'UI session cleanup escaped the task session directory.' }
            Remove-Item -LiteralPath $taskSessionPath -Force -ErrorAction SilentlyContinue
        }
        Remove-Item -LiteralPath $taskUiFixture -Force
    }
    if ($taskStarted) {
        & (Join-Path $taskPgBin 'pg_ctl.exe') -D (Join-Path $taskRun 'data') -m fast -t 120 -w stop
        if ($LASTEXITCODE -ne 0) { throw 'Task PostgreSQL stop failed; data was preserved.' }
        Write-Output 'TASK_POSTGRESQL_PROCESS=STOPPED'
        $taskDataPath = [IO.Path]::GetFullPath((Join-Path $taskRun 'data'))
        $taskAllowedRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'storage/framework/testing'))
        if (-not $taskDataPath.StartsWith($taskAllowedRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Task cleanup escaped workspace.' }
        Remove-Item -LiteralPath $taskDataPath -Recurse -Force
        Write-Output 'TASK_POSTGRESQL_DATA=CLEANED_LOGS_PRESERVED'
    }
    Write-Output "TASK_RUN=$taskRun"
    Write-Output 'PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0'
}
exit $taskExit
