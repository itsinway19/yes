param([ValidateSet('Focused','Affected','Browser','Closure','Foundation','Acceptance')][string]$Phase='Browser', [string]$ReuseRun='', [ValidateSet('all','closure','retention','restoration','foundation','workspaces','context','purpose','sources','account')][string]$BrowserSelection='all', [string]$ClusterDataPath='', [string]$TestFilter='', [switch]$CreateBrowserFixture)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$proofRoot=Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $proofRoot
$proofRun=Join-Path $proofRoot ('storage/framework/testing/goals-spa-'+[guid]::NewGuid())
if($ReuseRun){
 $proofRun=[IO.Path]::GetFullPath($ReuseRun)
 $proofPrefix=[IO.Path]::GetFullPath((Join-Path $proofRoot 'storage/framework/testing/goals-spa-'))
 if(-not $proofRun.StartsWith($proofPrefix) -or -not (Test-Path -LiteralPath (Join-Path $proofRun 'proof-token.txt'))){throw 'Reuse requires a recorded task-owned run'}
 $proofAttempt=@(Get-ChildItem -LiteralPath $proofRun -Filter 'browser-results-attempt-*.json').Count+1
 if(Test-Path -LiteralPath (Join-Path $proofRun 'browser-results.json')){Copy-Item -LiteralPath (Join-Path $proofRun 'browser-results.json') -Destination (Join-Path $proofRun "browser-results-attempt-$proofAttempt.json")}
 $proofArchive=[guid]::NewGuid().ToString()
 foreach($proofName in @('tests.xml','tests.log','source-manifest.json','source-unchanged.txt','browser.log')){
  $proofPrior=Join-Path $proofRun $proofName
  if(Test-Path -LiteralPath $proofPrior){Copy-Item -LiteralPath $proofPrior -Destination (Join-Path $proofRun ($proofArchive+'-'+$proofName))}
 }
}
New-Item -ItemType Directory -Force -Path $proofRun | Out-Null
$proofDataPath=if($ClusterDataPath){[IO.Path]::GetFullPath($ClusterDataPath)}else{Join-Path $proofRun 'data'}
if($ClusterDataPath){
 if(-not [IO.Path]::IsPathFullyQualified($ClusterDataPath)){throw 'ClusterDataPath must be absolute'}
 if(-not $ReuseRun -and (Test-Path -LiteralPath $proofDataPath)){throw 'New external proof cluster must not exist'}
 if($ReuseRun -and [IO.File]::ReadAllText((Join-Path $proofRun 'cluster-path.txt')) -ne $proofDataPath){throw 'External cluster must match its recorded task-owned path'}
}
[IO.File]::WriteAllText((Join-Path $proofRun 'cluster-path.txt'),$proofDataPath)
$proofPg='C:\Program Files\PostgreSQL\18\bin'
$proofProcesses=@()
$proofStarted=$false
$proofExit=1
$proofSourceBefore=$null
$proofEnvironmentPath=Join-Path $proofRoot '.env'
$proofEnvironmentCreated=$false
$proofEnvironmentContents="# Disposable proof only. Configuration is supplied explicitly by the guarded runner.`n"
function Get-ProofSource {
 $proofFiles=@(& git ls-files --cached --others --exclude-standard)
 $proofFiles+=@('public/build/manifest.json','public/build/goals-spa/manifest.json')|Where-Object{Test-Path -LiteralPath (Join-Path $proofRoot $_)}
 $proofMap=[ordered]@{}
 foreach($proofFile in ($proofFiles|Sort-Object -Unique)){
  $proofSourcePath=Join-Path $proofRoot $proofFile
  $proofMap[$proofFile]=if(Test-Path -LiteralPath $proofSourcePath -PathType Leaf){(Get-FileHash -LiteralPath $proofSourcePath -Algorithm SHA256).Hash}else{'<deleted>'}
 }
 return $proofMap|ConvertTo-Json -Depth 4
}
function Invoke-ProofPsql([string[]]$Arguments) {
 & (Join-Path $proofPg 'psql.exe') -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres @Arguments
 if($LASTEXITCODE -ne 0){throw 'Proof PostgreSQL operation failed'}
}
function Invoke-ProofPhp([string[]]$Arguments) {
 & pwsh -NoProfile -File scripts/builderad.ps1 php @Arguments
 if($LASTEXITCODE -ne 0){throw 'Proof PHP operation failed'}
}
try {
 foreach($proofPort in @(5433,6397,8136,8137,8138)) {
  if(Get-NetTCPConnection -LocalPort $proofPort -State Listen -ErrorAction SilentlyContinue){throw "Proof port $proofPort is occupied; no existing service contacted"}
 }
 if(-not (Test-Path -LiteralPath $proofEnvironmentPath)){
  [IO.File]::WriteAllText($proofEnvironmentPath,$proofEnvironmentContents)
  $proofEnvironmentCreated=$true
 }
 if(-not $ReuseRun){
  & (Join-Path $proofPg 'initdb.exe') -D $proofDataPath -U postgres -A trust --encoding=UTF8 --locale=C
  if($LASTEXITCODE -ne 0){throw 'initdb failed'}
 }
 $proofCtl=Start-Process -FilePath (Join-Path $proofPg 'pg_ctl.exe') -ArgumentList @('-D',$proofDataPath,'-l',(Join-Path $proofRun 'postgres.log'),'-o','"-p 5433 -h 127.0.0.1 -c max_wal_size=128MB"','-w','start') -WindowStyle Hidden -PassThru
 $proofCtl.WaitForExit();if($proofCtl.ExitCode -ne 0){throw 'PostgreSQL start failed'};$proofStarted=$true
 if(-not $ReuseRun){
  Invoke-ProofPsql @('-d','postgres','-f','database/bootstrap/postgresql.sql')
  Invoke-ProofPsql @('-d','builderad_test','-f','database/bootstrap/test-authority-role.sql')
  $proofToken=[guid]::NewGuid().ToString()
  Invoke-ProofPsql @('-d','postgres','-v',"isolation_token=$proofToken",'-f','database/bootstrap/disposable-test.sql')
  [IO.File]::WriteAllText((Join-Path $proofRun 'proof-token.txt'),$proofToken)
 }else{
  $proofToken=[IO.File]::ReadAllText((Join-Path $proofRun 'proof-token.txt'))
 }
 $proofRedisConfig=Join-Path $proofRun 'redis.conf'
 [IO.File]::WriteAllText($proofRedisConfig,"bind 127.0.0.1`nport 6397`nsave `"`"`nappendonly no`ndir $($proofRun.Replace('\','/'))`n")
 $proofRedis=Start-Process -FilePath 'C:\Program Files\Redis\redis-server.exe' -ArgumentList $proofRedisConfig -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $proofRun 'redis.log') -RedirectStandardError (Join-Path $proofRun 'redis-error.log')
 $proofProcesses+=,$proofRedis
 $proofProcesses|Select-Object Id,ProcessName|ConvertTo-Json|Set-Content (Join-Path $proofRun 'processes.json')
 $env:APP_ENV='testing';$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
 $env:DB_CONNECTION='pgsql';$env:DB_HOST='127.0.0.1';$env:DB_PORT='5433'
 $env:DB_DATABASE='builderad_test_disposable';$env:DB_OWNER_DATABASE='builderad_test_disposable'
 $env:DB_USERNAME='builderad_app';$env:DB_OWNER_USERNAME='builderad_owner';$env:DB_AUTHORITY_USERNAME='builderad_authority'
 $env:DB_PASSWORD='';$env:DB_OWNER_PASSWORD='';$env:DB_AUTHORITY_PASSWORD=''
 $env:BUILDERAD_TEST_ISOLATION_TOKEN=$proofToken
 $env:SESSION_DRIVER='redis';$env:SESSION_CONNECTION='default';$env:SESSION_LIFETIME='2';$env:SESSION_SECURE_COOKIE='false'
 $env:REDIS_PORT='6397';$env:REDIS_HOST='127.0.0.1';$env:REDIS_DB='0';$env:REDIS_CACHE_DB='1';$env:REDIS_PREFIX='goals-spa-'+$proofToken+':'
 $env:CACHE_STORE='array';$env:QUEUE_CONNECTION='sync';$env:MAIL_MAILER='array';$env:BCRYPT_ROUNDS='4'
 $env:APP_URL='http://localhost:8138';$env:APP_LOCALE='ar';$env:GOALS_SPA_RUN=$proofRun;$env:GOALS_SPA_PROOF='enabled';$env:GOALS_SPA_ENABLED='true'
 $env:LARAVEL_STORAGE_PATH=Join-Path $proofRun 'laravel'
 foreach($proofDir in @('framework/sessions','framework/views','framework/cache/data','logs','app/private')){New-Item -ItemType Directory -Force -Path (Join-Path $env:LARAVEL_STORAGE_PATH $proofDir)|Out-Null}
 Invoke-ProofPhp @('artisan','security:assert-disposable-test-db')
 Invoke-ProofPhp @('artisan','package:discover','--no-ansi') | Out-File (Join-Path $proofRun 'package-discovery.log')
 $proofSourceBefore=Get-ProofSource
 [IO.File]::WriteAllText((Join-Path $proofRun 'source-manifest.json'),$proofSourceBefore)
 if($Phase -eq 'Foundation'){
  $proofJunit='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/tests.xml'
  $proofEvents='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/events.log'
  & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--log-junit=$proofJunit" "--log-events-text=$proofEvents" 2>&1 | Tee-Object -FilePath (Join-Path $proofRun 'tests.log')
  if($LASTEXITCODE -ne 0){throw 'Full Foundation PHP suite failed; evidence retained'}
  foreach($proofGate in @(@('composer','validate','--strict'),@('composer','audit'),@('npm','audit','--audit-level=high'),@('npm','test','--','--test-concurrency=1'),@('npm','run','api:check'),@('php','vendor/bin/pint','--test'),@('npm','run','build'),@('npm','run','build:goals-spa'))){
   $proofAction=$proofGate[0];$proofArguments=$proofGate[1..($proofGate.Count-1)]
   $proofGateLogName=('gate-'+($proofGate -join '-')+'.log') -replace '[\\/:*?"<>|]', '-'
   $proofGateLog=Join-Path $proofRun $proofGateLogName
   if($proofAction -eq 'npm' -and $proofArguments[0] -eq 'test'){
    # -File cannot forward the literal npm `--` through PowerShell binding.
    # These are the same approved Node/npm paths validated by builderad.ps1.
    & 'C:\tools\node24\node.exe' 'C:\tools\node24\node_modules\npm\bin\npm-cli.js' @proofArguments 2>&1|Tee-Object -FilePath $proofGateLog
   }else{
    & pwsh -NoProfile -File scripts/builderad.ps1 $proofAction @proofArguments 2>&1|Tee-Object -FilePath $proofGateLog
   }
   if($LASTEXITCODE -ne 0){throw "Foundation gate failed: $($proofGate -join ' ')"}
  }
 }
 if($Phase -in @('Focused','Affected','Acceptance')) {
  $proofFilter=if($Phase -in @('Acceptance','Affected')){'GoalsSpaProofTest|AdvertiserGoalPreferencesTest|ProductionIdentityTest|IdentityRenewalTest|IdentityDocumentTest|SessionAuthorityTest|DatabaseAuthorityBoundaryTest|CoreDependencyBoundaryTest|ArchitectureSourceOptionalityTest'}else{'GoalsSpaProofTest'}
  if($TestFilter){$proofFilter=$TestFilter}
  $proofJunit='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/tests.xml'
  & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--filter=$proofFilter" "--log-junit=$proofJunit" 2>&1 | Tee-Object -FilePath (Join-Path $proofRun 'tests.log')
  if($LASTEXITCODE -ne 0){throw 'Focused/affected acceptance checks failed; evidence retained'}
 }
 if($Phase -notin @('Focused','Affected','Foundation')) {
  if(-not $ReuseRun -or $CreateBrowserFixture){
   Invoke-ProofPhp @('artisan','migrate','--database=pgsql_migrations','--force')
   Invoke-ProofPhp @('tests/Support/GoalsSpaFixture.php','setup')
  }
  $proofSourceBefore=Get-ProofSource
  [IO.File]::WriteAllText((Join-Path $proofRun 'source-manifest.json'),$proofSourceBefore)
  $env:GOALS_SPA_ONLY=$BrowserSelection
  foreach($proofPort in @(8136,8137)){
   $env:APP_ENV='local'
   $proofServer=Start-Process -FilePath 'C:\tools\php85\php.exe' -ArgumentList @('-S',"localhost:$proofPort",'-t','public','tests/Support/goals-spa-server.php') -WorkingDirectory $proofRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $proofRun "server-$proofPort.log") -RedirectStandardError (Join-Path $proofRun "server-$proofPort-error.log")
   $proofProcesses+=,$proofServer
  }
  $proofProcesses|Select-Object Id,ProcessName|ConvertTo-Json|Set-Content (Join-Path $proofRun 'processes.json')
  if($Phase -eq 'Closure'){$env:GOALS_SPA_CLOSURE='enabled'}
  $env:GOALS_SPA_ONLY=$BrowserSelection
  & 'C:\tools\node24\node.exe' tests/Support/goals-spa-browser.mjs 2>&1|Tee-Object -FilePath (Join-Path $proofRun 'browser.log')
  if($LASTEXITCODE -eq 2){Write-Output 'GOALS_SPA_ACCEPTANCE=BLOCKED; see findings/unverified in browser-results.json';throw 'Required condition remains blocked; no security contract changed'}
  if($LASTEXITCODE -ne 0){throw 'Browser proof failed; no acceptance claimed'}
 }
 $proofExit=0
} finally {
 if($null -ne $proofSourceBefore){[IO.File]::WriteAllText((Join-Path $proofRun 'source-unchanged.txt'),[string]($proofSourceBefore -eq (Get-ProofSource)))}
 foreach($proofProcess in $proofProcesses){if(-not $proofProcess.HasExited){Stop-Process -Id $proofProcess.Id -Force}}
 if($proofStarted){
  $proofData=[IO.Path]::GetFullPath($proofDataPath)
  if($proofData -ne [IO.File]::ReadAllText((Join-Path $proofRun 'cluster-path.txt'))){throw 'Cluster stop escaped its recorded owned path'}
  # This is process cleanup observation, not an application/SQL test timeout.
  & (Join-Path $proofPg 'pg_ctl.exe') -D $proofData -m fast -t 60 -w stop
  if($LASTEXITCODE -ne 0){throw 'Owned PostgreSQL stop is unconfirmed; do not report processes stopped'}
 }
 if($proofEnvironmentCreated -and [IO.File]::ReadAllText($proofEnvironmentPath) -eq $proofEnvironmentContents){Remove-Item -LiteralPath $proofEnvironmentPath}
 Write-Output "GOALS_SPA_EVIDENCE=$proofRun"
 Write-Output "GOALS_SPA_PROCESSES=STOPPED; EXIT=$proofExit"
}
exit $proofExit
