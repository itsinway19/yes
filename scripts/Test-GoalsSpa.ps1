param([ValidateSet('Focused','Affected','Browser','Closure','Foundation','Acceptance')][string]$Phase='Browser', [string]$ReuseRun='', [ValidateSet('all','closure','retention','restoration','foundation','workspaces','context','purpose','sources','account','auth','appearance','admin-ui','businesses','public-platform','market-knowledge','data-licenses','content')][string]$BrowserSelection='all', [string]$ClusterDataPath='', [string]$TestFilter='', [switch]$CreateBrowserFixture, [ValidateSet('default','reverse')][string]$TestOrder='default', [switch]$KeepCluster)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$proofRoot=Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $proofRoot
. (Join-Path $PSScriptRoot 'GoalsSpaLifecycle.ps1')
# Frozen dependency identity is a test precondition: matching lock files alone do not qualify a copied vendor.
# Fail before starting services; do not repair dependencies or alter lock files inside the proof runner.
$proofComposerLock=Get-Content -LiteralPath (Join-Path $proofRoot 'composer.lock') -Raw|ConvertFrom-Json -AsHashtable
$proofComposerInstalledPath=Join-Path $proofRoot 'vendor/composer/installed.json'
if(-not(Test-Path -LiteralPath $proofComposerInstalledPath -PathType Leaf)){throw 'Run Composer install from the existing lock before starting a proof'}
$proofComposerInstalled=Get-Content -LiteralPath $proofComposerInstalledPath -Raw|ConvertFrom-Json -AsHashtable
$proofPackageRows={param($packages) foreach($package in $packages){
 $source=$package['source'];$dist=$package['dist'];$reference=if($null -ne $source){$source['reference']}elseif($null -ne $dist){$dist['reference']}else{''}
 '{0}|{1}|{2}' -f $package['name'],$package['version'],$reference
}}
$proofExpectedPackages=@(& $proofPackageRows (@($proofComposerLock['packages'])+@($proofComposerLock['packages-dev'])))
$proofInstalledPackages=@(& $proofPackageRows $proofComposerInstalled['packages'])
if($proofExpectedPackages.Count -ne $proofInstalledPackages.Count -or @(Compare-Object $proofExpectedPackages $proofInstalledPackages -CaseSensitive).Count){throw 'Installed Composer packages do not match composer.lock; run frozen Composer install before tests'}
$proofTestingRoot=Join-Path $proofRoot 'storage/framework/testing'
$proofOwner=[guid]::NewGuid().ToString()
$proofRun=Join-Path $proofTestingRoot ('goals-spa-'+$proofOwner)
if(($ReuseRun -or $ClusterDataPath) -and -not $KeepCluster){throw 'ReuseRun and ClusterDataPath require explicit -KeepCluster'}
if($ReuseRun){
 $proofRun=[IO.Path]::GetFullPath($ReuseRun)
 if([IO.Path]::GetDirectoryName($proofRun) -ne $proofTestingRoot -or [IO.Path]::GetFileName($proofRun) -notmatch '^goals-spa-[0-9a-f-]{36}$'){
  throw 'Reuse requires a recorded task-owned run'
 }
 Assert-ProofPlainPath (Join-Path $proofRun 'proof-token.txt')
 Assert-ProofPlainPath (Join-Path $proofRun 'cluster-path.txt')
 if(-not (Test-Path -LiteralPath (Join-Path $proofRun 'proof-token.txt') -PathType Leaf)){throw 'Reuse requires an isolation token'}
}
$proofDataPath=if($ClusterDataPath){[IO.Path]::GetFullPath($ClusterDataPath)}else{Join-Path $proofRun 'data'}
if($ClusterDataPath){
 if(-not [IO.Path]::IsPathFullyQualified($ClusterDataPath)){throw 'ClusterDataPath must be absolute'}
 if(-not $ReuseRun -and (Test-Path -LiteralPath $proofDataPath)){throw 'New external proof cluster must not exist'}
}
Assert-ProofPlainPath $proofRun
Assert-ProofPlainPath $proofDataPath
if($ReuseRun -and ([IO.File]::ReadAllText((Join-Path $proofRun 'cluster-path.txt')) -ne $proofDataPath -or
  -not (Test-Path -LiteralPath (Join-Path $proofDataPath 'PG_VERSION') -PathType Leaf))){throw 'Reuse requires the existing recorded cluster path'}
$proofProcesses=[Collections.Generic.List[object]]::new()
$proofPostmaster=$null
$proofStartAttempted=$false
$proofOwnData=$false
$proofLock=$null
$proofRunReady=$false
$proofFailures=[Collections.Generic.List[string]]::new()
$proofPg='C:\Program Files\PostgreSQL\18\bin'
$proofExit=1
$proofPrimaryError=$null
$proofGateName=$null
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
try {
 Assert-ProofDiskSpace $proofDataPath
 if([IO.Path]::GetPathRoot($proofRun) -ne [IO.Path]::GetPathRoot($proofDataPath)){Assert-ProofDiskSpace $proofRun}
 if(-not $ReuseRun){New-Item -ItemType Directory -Path $proofRun | Out-Null}
 if($ReuseRun){
  $null=Get-ProofPlainTreeBytes $proofRun
  if($ClusterDataPath){$null=Get-ProofPlainTreeBytes $proofDataPath}
 }
 $proofLockPath=Join-Path $proofRun 'runner.lock'
 Assert-ProofPlainPath $proofLockPath
 $proofLock=[IO.File]::Open($proofLockPath,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
 $proofRunReady=$true
 if($ReuseRun){
  $archive=Join-Path $proofRun ('previous-'+$proofOwner)
  New-Item -ItemType Directory -Path $archive | Out-Null
  Get-ChildItem -LiteralPath $proofRun -File | Where-Object {
   $_.Extension -in @('.log','.xml') -or $_.Name -in @('source-manifest.json','source-unchanged.txt','browser-results.json')
  } | ForEach-Object {
   Assert-ProofPlainPath $_.FullName
   Copy-Item -LiteralPath $_.FullName -Destination $archive
  }
 }else{
  [IO.File]::WriteAllText((Join-Path $proofRun 'cluster-path.txt'),$proofDataPath)
  [IO.File]::WriteAllText((Join-Path $proofRun 'cluster-owner.txt'),$proofOwner)
 }
 'GOALS_SPA_RUNNING' | Set-Content -LiteralPath (Join-Path $proofRun 'lifecycle.log')
 if('Foundation' -eq $Phase){Write-Output "GOALS_SPA_FOUNDATION_RUN=$proofRun"}
 foreach($proofPort in @(5433,8136,8137,8138)) {
  if(Test-ProofPortOccupied $proofPort){throw "Proof port $proofPort is occupied; no existing service contacted"}
 }
 if(-not (Test-Path -LiteralPath $proofEnvironmentPath)){
  [IO.File]::WriteAllText($proofEnvironmentPath,$proofEnvironmentContents)
  $proofEnvironmentCreated=$true
 }
 if(-not $ReuseRun){
  New-Item -ItemType Directory -Path $proofDataPath | Out-Null
  $proofOwnData=$true
  Initialize-ProofCluster $proofPg $proofDataPath
 }
 if(Test-Path -LiteralPath (Join-Path $proofDataPath 'postmaster.pid')){throw 'Cluster already has a postmaster PID file'}
 $proofStartedAt=[datetime]::UtcNow
 $proofStartAttempted=$true
 Start-ProofPostgres $proofPg $proofDataPath (Join-Path $proofRun 'postgres.log')
 $proofPostmaster=Get-ProofPostmaster $proofDataPath $proofStartedAt
 Assert-ProofPostgresTarget $proofDataPath
 if(-not $ReuseRun){
  Invoke-ProofPsql @('-d','postgres','-f','database/bootstrap/postgresql.sql')
  Invoke-ProofPsql @('-d','builderad_test','-f','database/bootstrap/test-authority-role.sql')
  $proofToken=[guid]::NewGuid().ToString()
  Invoke-ProofPsql @('-d','postgres','-v',"isolation_token=$proofToken",'-f','database/bootstrap/disposable-test.sql')
  [IO.File]::WriteAllText((Join-Path $proofRun 'proof-token.txt'),$proofToken)
 }else{
  $proofToken=[IO.File]::ReadAllText((Join-Path $proofRun 'proof-token.txt'))
 }
 $proofRedisPort=Start-ProofRedis $proofRun $proofProcesses
 $proofProcesses|Select-Object Id,ProcessName|ConvertTo-Json|Set-Content (Join-Path $proofRun 'processes.json')
 $env:APP_ENV='testing';$env:APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
 $env:DB_CONNECTION='pgsql';$env:DB_HOST='127.0.0.1';$env:DB_PORT='5433'
 $env:DB_DATABASE='builderad_test_disposable';$env:DB_OWNER_DATABASE='builderad_test_disposable'
 $env:DB_USERNAME='builderad_app';$env:DB_OWNER_USERNAME='builderad_owner';$env:DB_AUTHORITY_USERNAME='builderad_authority'
 $env:DB_PASSWORD='';$env:DB_OWNER_PASSWORD='';$env:DB_AUTHORITY_PASSWORD=''
 $env:BUILDERAD_TEST_ISOLATION_TOKEN=$proofToken
 $env:SESSION_DRIVER='redis';$env:SESSION_CONNECTION='default';$env:SESSION_LIFETIME='2';$env:SESSION_SECURE_COOKIE='false'
 $env:REDIS_PORT="$proofRedisPort";$env:REDIS_HOST='127.0.0.1';$env:REDIS_DB='0';$env:REDIS_CACHE_DB='1';$env:REDIS_PREFIX='goals-spa-'+$proofToken+':'
 $env:REDIS_URL='(null)';$env:DB_URL='(null)'
 # Laravel resolves these variables relative to the application root on Windows.
 $proofRelativeRun=Join-Path 'storage/framework/testing' (Split-Path -Leaf $proofRun)
 $env:APP_CONFIG_CACHE=Join-Path $proofRelativeRun "$proofOwner-config.php"
 $env:APP_PACKAGES_CACHE=Join-Path $proofRelativeRun "$proofOwner-packages.php"
 $env:APP_SERVICES_CACHE=Join-Path $proofRelativeRun "$proofOwner-services.php"
 # The isolated Redis has no credential; an inherited developer password would make Predis send AUTH.
 $env:REDIS_PASSWORD='';$env:REDIS_USERNAME=''
 $env:CACHE_STORE='array';$env:QUEUE_CONNECTION='sync';$env:MAIL_MAILER='array';$env:BCRYPT_ROUNDS='4'
 $env:APP_URL=if($BrowserSelection -eq 'appearance'){'http://localhost:8136'}else{'http://localhost:8138'}
 $env:APP_LOCALE='ar';$env:GOALS_SPA_RUN=$proofRun;$env:GOALS_SPA_PROOF='enabled'
 $env:LARAVEL_STORAGE_PATH=Join-Path $proofRun 'laravel'
 foreach($proofDir in @('framework/sessions','framework/views','framework/cache/data','framework/testing','logs','app/private')){New-Item -ItemType Directory -Force -Path (Join-Path $env:LARAVEL_STORAGE_PATH $proofDir)|Out-Null}
 Invoke-ProofPhp @('artisan','security:assert-disposable-test-db')
 Invoke-ProofPhp @('artisan','package:discover','--no-ansi') | Out-File (Join-Path $proofRun 'package-discovery.log')
 $proofSourceBefore=Get-ProofSource
 [IO.File]::WriteAllText((Join-Path $proofRun 'source-manifest.json'),$proofSourceBefore)
 if($Phase -eq 'Foundation'){
  Write-Output 'GOALS_SPA_FOUNDATION_TOTAL=10'
  Write-Output 'GOALS_SPA_GATE_START=PHP suite'
  $proofGateName='PHP suite'
  $proofJunit='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/tests.xml'
  $proofEvents='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/events.log'
  & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--log-junit=$proofJunit" "--log-events-text=$proofEvents" 2>&1 | Tee-Object -FilePath (Join-Path $proofRun 'tests.log')
  if($LASTEXITCODE -ne 0){throw 'Full Foundation PHP suite failed; evidence retained'}
  Write-Output 'GOALS_SPA_GATE_PASS=PHP suite'
  $proofGateName=$null
  foreach($proofGate in @(@('npm','run','inertia:check'),@('composer','validate','--strict'),@('composer','audit'),@('npm','audit','--audit-level=high'),@('npm','test'),@('npm','run','api:check'),@('php','vendor/bin/pint','--test'),@('npm','run','build'))){
   Write-Output "GOALS_SPA_GATE_START=$($proofGate -join ' ')"
   $proofGateName=$proofGate -join ' '
   $proofAction=$proofGate[0];$proofArguments=$proofGate[1..($proofGate.Count-1)]
   $proofGateLogName=('gate-'+($proofGate -join '-')+'.log') -replace '[\\/:*?"<>|]', '-'
   $proofGateLog=Join-Path $proofRun $proofGateLogName
   if($proofAction -eq 'npm' -and $proofArguments[0] -eq 'test'){
    # Node runner flags must precede the test files; npm appends arguments after them.
    # Keep package.json as the single selection owner, using the validated Node path.
    $proofTestCommand=(Get-Content -LiteralPath (Join-Path $proofRoot 'package.json') -Raw|ConvertFrom-Json).scripts.test
    if($proofTestCommand -notmatch '^node --test (tests/\S+\.test\.mjs ?)+$'){throw 'Unsupported canonical JavaScript test command'}
    $proofTestArguments=@($proofTestCommand -split ' '|Select-Object -Skip 1)
    & 'C:\tools\node24\node.exe' --test-concurrency=1 @proofTestArguments 2>&1|Tee-Object -FilePath $proofGateLog
   }else{
    & pwsh -NoProfile -File scripts/builderad.ps1 $proofAction @proofArguments 2>&1|Tee-Object -FilePath $proofGateLog
   }
   if($LASTEXITCODE -ne 0){throw "Foundation gate failed: $($proofGate -join ' ')"}
   Write-Output "GOALS_SPA_GATE_PASS=$($proofGate -join ' ')"
   $proofGateName=$null
  }
  Write-Output 'GOALS_SPA_GATE_START=production asset guard'
  $proofGateName='production asset guard'
  & 'C:\tools\node24\node.exe' scripts/runtime-retirement.mjs --assets 2>&1|Tee-Object -FilePath (Join-Path $proofRun 'gate-runtime-assets.log')
  if($LASTEXITCODE -ne 0){throw 'Foundation production asset guard failed'}
  Write-Output 'GOALS_SPA_GATE_PASS=production asset guard'
  $proofGateName=$null
 }
 if($Phase -in @('Focused','Affected','Acceptance')) {
  $proofFilter=if($Phase -in @('Acceptance','Affected')){'WorkspaceCommercialRetirementTest|BusinessFoundationContractTest|BusinessHistoryTest|ProductionIdentityTest|IdentityRenewalTest|IdentityDocumentTest|SessionAuthorityTest|DatabaseAuthorityBoundaryTest|CoreDependencyBoundaryTest|ArchitectureSourceOptionalityTest'}else{'WorkspaceCommercialRetirementTest'}
  if($TestFilter){$proofFilter=$TestFilter}
  $proofJunit='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/tests.xml'
  $proofEvents='storage/framework/testing/'+(Split-Path -Leaf $proofRun)+'/events.log'
  Write-Output "GOALS_SPA_TEST_ORDER=$TestOrder"
  & pwsh -NoProfile -File scripts/builderad.ps1 test --compact --display-warnings "--filter=$proofFilter" "--order-by=$TestOrder" "--log-junit=$proofJunit" "--log-events-text=$proofEvents" 2>&1 | Tee-Object -FilePath (Join-Path $proofRun 'tests.log')
  if($LASTEXITCODE -ne 0){throw 'Focused/affected acceptance checks failed; evidence retained'}
 }
 if($Phase -notin @('Focused','Affected','Foundation')) {
  $env:GOALS_SPA_ONLY=$BrowserSelection
  if(-not $ReuseRun -or $CreateBrowserFixture){
   Invoke-ProofPhp @('artisan','migrate','--database=pgsql_migrations','--force')
   $proofFixtureOutput=Invoke-ProofPhp @('tests/Support/GoalsSpaFixture.php','setup')
   $proofFixtureOutput | Set-Content (Join-Path $proofRun 'fixture-setup.log')
   # Laravel's standalone exception renderer can print a failure with exit code zero.
   # Require a successful structured setup receipt before contacting the browser.
   $proofFixtureReceipt=($proofFixtureOutput -join "`n") | ConvertFrom-Json -ErrorAction Stop
   if($proofFixtureReceipt.database.db -ne 'builderad_test_disposable' -or $proofFixtureReceipt.database.role -ne 'builderad_owner' -or $proofFixtureReceipt.database.port -ne 5433){throw 'Invalid isolated fixture setup receipt'}
   Write-Output ($proofFixtureOutput -join "`n")
  }
  $proofSourceBefore=Get-ProofSource
  [IO.File]::WriteAllText((Join-Path $proofRun 'source-manifest.json'),$proofSourceBefore)
  $env:GOALS_SPA_ONLY=$BrowserSelection
  if($BrowserSelection -eq 'businesses' -and $TestFilter){$env:GOALS_SPA_CASE_FILTER=$TestFilter}
  foreach($proofPort in @(8136,8137)){
   $env:APP_ENV='local'
   $proofServer=Start-Process -FilePath 'C:\tools\php85\php.exe' -ArgumentList @('-S',"localhost:$proofPort",'-t','public','tests/Support/goals-spa-server.php') -WorkingDirectory $proofRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $proofRun "server-$proofPort.log") -RedirectStandardError (Join-Path $proofRun "server-$proofPort-error.log")
   $proofProcesses.Add($proofServer)
  }
  $proofProcesses|Select-Object Id,ProcessName|ConvertTo-Json|Set-Content (Join-Path $proofRun 'processes.json')
  if($Phase -eq 'Closure'){$env:GOALS_SPA_CLOSURE='enabled'}
  $env:GOALS_SPA_ONLY=$BrowserSelection
  $proofBrowser = if($BrowserSelection -eq 'data-licenses'){'tests/Support/data-license-browser.mjs'}elseif($BrowserSelection -eq 'market-knowledge'){'tests/Support/market-knowledge-browser.mjs'}elseif($BrowserSelection -eq 'public-platform'){'tests/Support/public-platform-browser.mjs'}elseif($BrowserSelection -eq 'businesses'){'tests/Support/business-surface-browser.mjs'}elseif($BrowserSelection -eq 'content'){'tests/Support/content-browser.mjs'}elseif($BrowserSelection -eq 'admin-ui'){'tests/Support/admin-ui-browser.mjs'}elseif($BrowserSelection -eq 'appearance'){'tests/Support/appearance-browser.mjs'}else{'tests/Support/goals-spa-browser.mjs'}
  & 'C:\tools\node24\node.exe' $proofBrowser 2>&1|Tee-Object -FilePath (Join-Path $proofRun 'browser.log')
  if($LASTEXITCODE -eq 2){Write-Output 'GOALS_SPA_ACCEPTANCE=BLOCKED; see findings/unverified in browser-results.json';throw 'Required condition remains blocked; no security contract changed'}
  if($LASTEXITCODE -ne 0){throw 'Browser proof failed; no acceptance claimed'}
 }
 $proofExit=0
} catch {
 $proofPrimaryError=$_.Exception.Message
 if($proofGateName){Write-Output "GOALS_SPA_GATE_FAIL=$proofGateName"}
 Write-Output "GOALS_SPA_ERROR=$proofPrimaryError"
} finally {
 $proofProcessesStopped=$true
 foreach($proofProcess in $proofProcesses){
  try {
   if(-not $proofProcess.HasExited){$proofProcess.Kill()}
   if(-not $proofProcess.WaitForExit(5000)){throw "Owned test process $($proofProcess.Id) did not exit"}
  } catch {
   $proofProcessesStopped=$false
   $proofFailures.Add($_.Exception.Message)
  }
 }
 if($proofStartAttempted){
  try {
   Assert-ProofPlainPath $proofDataPath
   if($proofDataPath -ne [IO.File]::ReadAllText((Join-Path $proofRun 'cluster-path.txt'))){throw 'Recorded cluster path changed'}
   if($null -eq $proofPostmaster -and (Test-Path -LiteralPath (Join-Path $proofDataPath 'postmaster.pid'))){
    $proofPostmaster=Get-ProofPostmaster $proofDataPath $proofStartedAt
   }
   if($null -ne $proofPostmaster){
    Stop-ProofPostgres $proofPg $proofDataPath $proofPostmaster
   }else{
    Assert-ProofPostgresAbsent $proofPg $proofDataPath
   }
  } catch {
   $proofProcessesStopped=$false
   $proofFailures.Add($_.Exception.Message)
  }
 }
 $proofCleanup='NOT_CREATED'
 $proofClusterBytes=$null
 if($proofOwnData -or ($proofRunReady -and $ReuseRun)){
  $proofCleanup='RETAINED'
  if(-not $proofProcessesStopped){$proofCleanup='FAILED'}
  elseif(-not $KeepCluster){
   try {
    $proofClusterBytes=Remove-ProofCluster $proofRun $proofDataPath $proofTestingRoot $proofOwner
    $proofCleanup='DELETED'
   } catch {
    $proofCleanup='FAILED'
    $proofFailures.Add($_.Exception.Message)
   }
  }
 }
 try {
  if($proofEnvironmentCreated -and [IO.File]::ReadAllText($proofEnvironmentPath) -eq $proofEnvironmentContents){Remove-Item -LiteralPath $proofEnvironmentPath}
 } catch {$proofFailures.Add($_.Exception.Message)}
 try {
  if($null -ne $proofSourceBefore){[IO.File]::WriteAllText((Join-Path $proofRun 'source-unchanged.txt'),[string]($proofSourceBefore -eq (Get-ProofSource)))}
 } catch {$proofFailures.Add($_.Exception.Message)}
 $proofResult=@("GOALS_SPA_TEST_EXIT=$proofExit","GOALS_SPA_CLEANUP=$proofCleanup; DATA=$proofDataPath")
 if($null -ne $proofClusterBytes){$proofResult+=@("GOALS_SPA_CLUSTER_BYTES=$proofClusterBytes")}
 $proofResult+=@($proofFailures | ForEach-Object {"GOALS_SPA_LIFECYCLE_ERROR=$_"})
 if($proofExit -eq 0 -and $proofFailures.Count){$proofExit=2}
 if($proofRunReady){
  try {
   @("GOALS_SPA_ERROR=$proofPrimaryError")+$proofResult+@("GOALS_SPA_FINISHED=EXIT=$proofExit") | Set-Content -LiteralPath (Join-Path $proofRun 'lifecycle.log')
  } catch {$proofFailures.Add($_.Exception.Message); Write-Output "GOALS_SPA_LIFECYCLE_ERROR=$($_.Exception.Message)"}
  finally {$proofLock.Dispose()}
  Write-Output "GOALS_SPA_EVIDENCE=$proofRun"
 }
 Write-Output $proofResult
 if($proofExit -eq 0 -and $proofFailures.Count){$proofExit=2}
 if($proofProcessesStopped){Write-Output "GOALS_SPA_PROCESSES=STOPPED; EXIT=$proofExit"}
 Write-Output "GOALS_SPA_FINISHED=EXIT=$proofExit"
}
exit $proofExit
