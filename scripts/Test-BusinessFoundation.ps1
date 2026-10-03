param([string] $Filter = 'BusinessFoundationTest|WorkspaceCreationTest|InternalAuthorityTest|ProviderCallbackIngressTest')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskPgBin = 'C:\Program Files\PostgreSQL\18\bin'
$taskRun = Join-Path $taskRoot ('storage/framework/testing/business-db-' + [guid]::NewGuid().ToString())
$taskToken = [guid]::NewGuid().ToString()
$taskStarted = $false
New-Item -ItemType Directory -Force -Path $taskRun | Out-Null
Set-Location -LiteralPath $taskRoot
function Invoke-TaskPsql([string[]] $TaskArguments) {
    & (Join-Path $taskPgBin 'psql.exe') -X -h 127.0.0.1 -p 5433 -U postgres @TaskArguments
    if ($LASTEXITCODE -ne 0) { throw 'Isolated database provisioning failed.' }
}
try {
    & (Join-Path $taskPgBin 'pg_isready.exe') -h 127.0.0.1 -p 5433
    if ($LASTEXITCODE -ne 2) { throw 'Port 5433 responds or is still shutting down; it will not be modified.' }
    & (Join-Path $taskPgBin 'initdb.exe') -D (Join-Path $taskRun 'data') -U postgres -A trust --encoding=UTF8 --locale=C
    if ($LASTEXITCODE -ne 0) { throw 'initdb failed.' }
    $taskStart = Start-Process -FilePath (Join-Path $taskPgBin 'pg_ctl.exe') -ArgumentList @('-D', (Join-Path $taskRun 'data'), '-l', (Join-Path $taskRun 'postgres.log'), '-o', '"-p 5433 -h 127.0.0.1"', '-w', 'start') -WindowStyle Hidden -PassThru
    $taskStart.WaitForExit()
    if ($taskStart.ExitCode -ne 0) { throw 'Task PostgreSQL failed to start.' }
    $taskStarted = $true
    Invoke-TaskPsql @('-d','postgres','-f','database/bootstrap/postgresql.sql')
    Invoke-TaskPsql @('-d','builderad_test','-f','database/bootstrap/test-authority-role.sql')
    Invoke-TaskPsql @('-d','postgres','-v',"isolation_token=$taskToken",'-f','database/bootstrap/disposable-test.sql')
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
    $taskRelativeResult = 'storage/framework/testing/' + (Split-Path -Leaf $taskRun) + '/results.xml'
    & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--filter=$Filter" "--log-junit=$taskRelativeResult" 2>&1 |
        Tee-Object -FilePath (Join-Path $taskRun 'tests.log')
    $taskExit = $LASTEXITCODE
} finally {
    if ($taskStarted) {
        & (Join-Path $taskPgBin 'pg_ctl.exe') -D (Join-Path $taskRun 'data') -m fast -t 120 -w stop
        if ($LASTEXITCODE -ne 0) { throw 'Task database stop failed; its data was preserved.' }
        Write-Output 'TASK_POSTGRESQL_PROCESS=STOPPED'
        $taskDataPath = [IO.Path]::GetFullPath((Join-Path $taskRun 'data'))
        $taskAllowedRoot = [IO.Path]::GetFullPath((Join-Path $taskRoot 'storage/framework/testing'))
        if (-not $taskDataPath.StartsWith($taskAllowedRoot + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Temporary database cleanup escaped workspace.'
        }
        Remove-Item -LiteralPath $taskDataPath -Recurse -Force
        Write-Output 'TASK_POSTGRESQL_DATA=CLEANED_LOGS_PRESERVED'
    }
}
exit $taskExit
