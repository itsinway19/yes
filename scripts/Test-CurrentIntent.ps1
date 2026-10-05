param(
    [ValidateSet('Focused', 'Full', 'Durability', 'UI')][string] $Phase = 'Focused',
    [string] $Filter = 'BusinessFoundationTest',
    [string] $Baseline = '2cf51ea337b26e07bfcaff59ed9d3e81c632a50e',
    [string] $DurabilityReference = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskPgBin = 'C:\Program Files\PostgreSQL\18\bin'
$taskRun = Join-Path $taskRoot ('storage/framework/testing/current-intent-db-' + [guid]::NewGuid().ToString())
$taskToken = [guid]::NewGuid().ToString()
$taskStarted = $false
$taskExit = 1
$taskUiServer = $null
$taskEnvironmentFileCreated = $false
$taskEnvironmentPath = Join-Path $taskRoot '.env.testing'
New-Item -ItemType Directory -Force -Path $taskRun | Out-Null
Set-Location -LiteralPath $taskRoot
function Invoke-TaskPsql([string[]] $TaskArguments) {
    & (Join-Path $taskPgBin 'psql.exe') -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres @TaskArguments
    if ($LASTEXITCODE -ne 0) { throw 'Isolated CURRENT_INTENT database provisioning failed.' }
}
try {
    if ($Baseline -ne '2cf51ea337b26e07bfcaff59ed9d3e81c632a50e') { throw 'Unexpected authoritative baseline.' }
    if (Get-NetTCPConnection -LocalPort 5433 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 5433 is not free; no existing server will be contacted.' }
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
    if (-not (Test-Path -LiteralPath $taskEnvironmentPath)) {
        [IO.File]::WriteAllText($taskEnvironmentPath, "APP_ENV=testing`n")
        $taskEnvironmentFileCreated = $true
    }
    $env:CURRENT_INTENT_PROOF_RUN = $taskRun
    $env:CURRENT_INTENT_PROOF_BASELINE = $Baseline
    $env:CURRENT_INTENT_DURABILITY_REFERENCE = $DurabilityReference
    if ($Phase -eq 'Durability') {
        $env:CURRENT_INTENT_CLUSTER_SYSTEM_IDENTIFIER = (& (Join-Path $taskPgBin 'psql.exe') -X -At -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres -d builderad_test_disposable -c 'SELECT system_identifier FROM pg_control_system()').Trim()
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
        'Durability' {
            $env:CURRENT_INTENT_DURABILITY_STAGE = 'BeforeRestart'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/CurrentIntentDurabilityProof.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'durability.log')
            if ($LASTEXITCODE -ne 0) { throw 'Durability preparation failed.' }
            $taskRestartState = Join-Path $taskRun 'restart-state.json'
            if (-not (Test-Path -LiteralPath $taskRestartState)) { throw 'Durability preparation snapshot missing.' }
            $taskClusterData = [IO.Path]::GetFullPath((Join-Path $taskRun 'data'))
            $taskCatalogIdentity = (& (Join-Path $taskPgBin 'psql.exe') -X -At -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres -d builderad_test_disposable -c "SELECT json_build_object('data',current_setting('data_directory'),'system',system_identifier::text) FROM pg_control_system()") | ConvertFrom-Json
            if ($LASTEXITCODE -ne 0 -or [IO.Path]::GetFullPath($taskCatalogIdentity.data) -ne $taskClusterData -or $taskCatalogIdentity.system -ne $env:CURRENT_INTENT_CLUSTER_SYSTEM_IDENTIFIER) { throw 'Owned restart identity mismatch.' }
            # Restart from the runner, after the first PHP process has exited, so
            # Windows server handles cannot keep a PHP-wrapper pipe open.
            $taskRestart = Start-Process -FilePath (Join-Path $taskPgBin 'pg_ctl.exe') -ArgumentList @('-D',$taskClusterData,'-l',(Join-Path $taskRun 'restart-postgres.log'),'-m','immediate','-t','30','-w','restart') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRun 'restart-ctl.log') -RedirectStandardError (Join-Path $taskRun 'restart-ctl-error.log')
            $taskRestart.WaitForExit()
            if ($taskRestart.ExitCode -ne 0) { throw 'Owned persistence restart failed.' }
            $env:CURRENT_INTENT_DURABILITY_STAGE = 'AfterRestart'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/CurrentIntentDurabilityProof.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'durability.log') -Append
        }
        'UI' {
            if (Get-NetTCPConnection -LocalPort 8017 -State Listen -ErrorAction SilentlyContinue) { throw 'UI port is not free.' }
            if (Test-Path -LiteralPath (Join-Path $taskRoot 'public/hot')) { throw 'An existing Vite hot file would bypass the isolated built preview.' }
            $env:SESSION_DRIVER = 'file'
            $env:APP_URL = 'http://127.0.0.1:8017'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/CurrentIntentUiFixture.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'ui.log')
            if ($LASTEXITCODE -ne 0) { throw 'Isolated UI fixture failed.' }
            $taskUiServer = Start-Process -FilePath 'C:\tools\php85\php.exe' -ArgumentList @('-S','127.0.0.1:8017','-t','.',(Join-Path $taskRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')) -WorkingDirectory (Join-Path $taskRoot 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskRun 'ui-server.log') -RedirectStandardError (Join-Path $taskRun 'ui-server-error.log')
            & 'C:\tools\node24\node.exe' tests/Support/current-intent-browser.mjs 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'ui-browser.log')
            if ($LASTEXITCODE -ne 0) { throw 'Current Intent real browser proof failed.' }
            # Current Intent browser/context is closed before the independent old fixture reset.
            $env:DTM_PROOF_RUN = Join-Path $taskRun 'dtm'
            New-Item -ItemType Directory -Path $env:DTM_PROOF_RUN | Out-Null
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/DeclaredTargetMarketsUiFixture.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'dtm-ui.log')
            if ($LASTEXITCODE -ne 0) { throw 'Existing DTM fixture failed.' }
            $taskCurrentFixturePath = Join-Path $taskRun 'ui-fixture.json'
            $taskCurrentFixture = Get-Content -LiteralPath $taskCurrentFixturePath -Raw | ConvertFrom-Json
            $taskDtmFixture = Get-Content -LiteralPath (Join-Path $env:DTM_PROOF_RUN 'ui-fixture.json') -Raw | ConvertFrom-Json
            $taskCurrentFixture.sessionFiles = @($taskCurrentFixture.sessionFiles) + @($taskDtmFixture.sessionFiles)
            $taskCurrentFixture | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $taskCurrentFixturePath
            # Assertions and synthetic fixture contract remain exact; only artifact destination
            # changes in an ignored executable copy to this chat's authorized writable root.
            $taskOldBrowser = Join-Path $taskRoot 'tests/Support/declared-target-markets-browser.mjs'
            $taskDtmBrowser = Join-Path $env:DTM_PROOF_RUN 'browser.mjs'
            $taskBrowserSource = [IO.File]::ReadAllText($taskOldBrowser)
            $taskOldArtifacts = 'C:/Users/stoma/.codex/visualizations/2026/10/04/01a1061d-77e6-7a21-98bc-fda1bd8dcabb'
            if (-not $taskBrowserSource.Contains($taskOldArtifacts)) { throw 'Existing DTM artifact destination changed unexpectedly.' }
            [IO.File]::WriteAllText($taskDtmBrowser, $taskBrowserSource.Replace($taskOldArtifacts,'C:/Users/stoma/.codex/visualizations/2026/10/04/01a1083d-67dd-77d2-9c01-92495dd1999c'))
            Get-FileHash -LiteralPath $taskOldBrowser | Format-List | Out-File (Join-Path $taskRun 'dtm-original-browser-hash.txt')
            & 'C:\tools\node24\node.exe' $taskDtmBrowser 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'dtm-browser.log')
        }
    }
    $taskExit = $LASTEXITCODE
    if ($Phase -eq 'Durability' -and $taskExit -eq 0) {
        # Regression for a real standalone Laravel exception that previously rendered with exit 0.
        $taskOriginalReference = $env:CURRENT_INTENT_DURABILITY_REFERENCE
        try {
            $env:CURRENT_INTENT_DURABILITY_REFERENCE = Join-Path $taskRun 'missing-negative-reference.json'
            & pwsh -NoProfile -File scripts/builderad.ps1 php tests/Support/CurrentIntentDurabilityProof.php 2>&1 | Tee-Object -FilePath (Join-Path $taskRun 'negative-proof.log')
            if ($LASTEXITCODE -eq 0) { throw 'A failed durability proof incorrectly returned success.' }
            Write-Output 'DURABILITY_FAILURE_EXIT_CLASSIFICATION=PASS'
        } finally {
            $env:CURRENT_INTENT_DURABILITY_REFERENCE = $taskOriginalReference
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
        $taskOldFixture = Join-Path $taskRun 'dtm/ui-fixture.json'
        if (Test-Path -LiteralPath $taskOldFixture) { Remove-Item -LiteralPath $taskOldFixture -Force }
    }
    if ($taskStarted) {
        # Final disposal only: durability proofs must perform their explicit
        # persistence restart before this point. Do not checkpoint data that is
        # about to be deleted (Windows shutdown fsync can exceed test timeouts).
        & (Join-Path $taskPgBin 'pg_ctl.exe') -D (Join-Path $taskRun 'data') -m immediate -t 30 -w stop
        if ($LASTEXITCODE -ne 0) { throw 'Task PostgreSQL stop failed; data was preserved.' }
        Write-Output 'TASK_POSTGRESQL_PROCESS=STOPPED'
        $taskDataPath = [IO.Path]::GetFullPath((Join-Path $taskRun 'data'))
        $taskAllowedRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'storage/framework/testing'))
        if (-not $taskDataPath.StartsWith($taskAllowedRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Task cleanup escaped workspace.' }
        Remove-Item -LiteralPath $taskDataPath -Recurse -Force
        Write-Output 'TASK_POSTGRESQL_DATA=CLEANED_LOGS_PRESERVED'
    }
    if ($taskEnvironmentFileCreated) {
        if ([IO.File]::ReadAllText($taskEnvironmentPath) -ne "APP_ENV=testing`n") { throw 'Task testing environment file changed; retained for review.' }
        Remove-Item -LiteralPath $taskEnvironmentPath -Force
    }
    Write-Output "TASK_RUN=$taskRun"
    Write-Output 'PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0'
}
exit $taskExit
