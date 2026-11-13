# Exercise the real orchestration in a scratch copy. Only native service and
# PHP calls are replaced. No test hook is available in the production runner.
$ErrorActionPreference='Stop'
$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$scratch=Join-Path $root ('storage/framework/testing/runner-acceptance-'+[guid]::NewGuid())
$scripts=Join-Path $scratch 'scripts'
New-Item -ItemType Directory -Path $scripts,(Join-Path $scratch 'vendor/composer') | Out-Null
Copy-Item -LiteralPath (Join-Path $root 'scripts/Test-GoalsSpa.ps1'),(Join-Path $root 'scripts/GoalsSpaLifecycle.ps1') -Destination $scripts
'{"packages":[],"packages-dev":[]}' | Set-Content -LiteralPath (Join-Path $scratch 'composer.lock')
'{"packages":[]}' | Set-Content -LiteralPath (Join-Path $scratch 'vendor/composer/installed.json')
$runner=Join-Path $scripts 'Test-GoalsSpa.ps1'
@'
function Get-ProofFreeBytes($Path){if($env:RUNNER_CASE -eq 'disk'){return 100MB}; return 8GB}
function Get-NetTCPConnection { }
function git { }
function Initialize-ProofCluster($Pg,$Data){'18' | Set-Content -LiteralPath (Join-Path $Data 'PG_VERSION')}
function Start-ProofPostgres($Pg,$Data,$Log){
 'postgres evidence' | Set-Content -LiteralPath $Log
 if($env:RUNNER_CASE -eq 'pg-start'){throw 'injected PostgreSQL startup failure'}
}
function Get-ProofPostmaster {return [pscustomobject]@{Id=999999}}
function Assert-ProofPostgresTarget {if($env:RUNNER_CASE -eq 'pg-target'){throw 'injected PostgreSQL target mismatch'}}
function Assert-ProofPostgresAbsent($Pg,$Data){'absent' | Set-Content -LiteralPath (Join-Path $proofRun 'pg-stopped')}
function Stop-ProofPostgres($Pg,$Data,$Process){
 if($env:RUNNER_CASE -eq 'pg-stop'){throw 'injected PostgreSQL shutdown failure'}
 'stopped' | Set-Content -LiteralPath (Join-Path $proofRun 'pg-stopped')
}
function Invoke-ProofPsql { }
function Invoke-ProofPhp { }
function Start-ProofRedis($Run,$Processes){
 $fake=[pscustomobject]@{Id=999999;HasExited=$false;Run=$Run}
 $fake | Add-Member ScriptMethod Kill { 'stopped' | Set-Content -LiteralPath (Join-Path $this.Run 'redis-stopped'); $this.HasExited=$true }
 $fake | Add-Member ScriptMethod WaitForExit { return $this.HasExited }
 $Processes.Add($fake)
 'redis evidence' | Set-Content -LiteralPath (Join-Path $Run 'redis-6397.log')
 if($env:RUNNER_CASE -eq 'redis-start'){throw 'injected Redis readiness failure'}
 return 6397
}
function pwsh {
 foreach($argument in $args){
  if($argument -like '--log-junit=*'){'<testsuites />' | Set-Content -LiteralPath ($argument -replace '^--log-junit=')}
  if($argument -like '--log-events-text=*'){'Test Runner Execution Started (1 test)','Test Finished (probe)' | Set-Content -LiteralPath ($argument -replace '^--log-events-text=')}
 }
 if($env:RUNNER_CASE -eq 'junction'){
  New-Item -ItemType Junction -Path (Join-Path $proofDataPath 'external') -Target (Join-Path $proofRoot 'protected') | Out-Null
 }
 $global:LASTEXITCODE=if($env:RUNNER_CASE -in @('php-fail','pg-stop')){1}else{0}
 'PHP evidence'
}
'@ | Add-Content -LiteralPath (Join-Path $scripts 'GoalsSpaLifecycle.ps1')

function Assert-Runner($Condition,[string]$Message){
 if(-not $Condition){throw "$Message (evidence: $scratch)"}
}
$protected=Join-Path $scratch 'protected'
New-Item -ItemType Directory -Path $protected | Out-Null
'protected' | Set-Content -LiteralPath (Join-Path $protected 'sentinel')
foreach($case in @('success','php-fail','redis-start','pg-start','pg-target','disk','pg-stop','junction','keep','external')){
 $env:RUNNER_CASE=$case
 $extra=@()
 if($case -in @('keep','external')){$extra+='-KeepCluster'}
 if($case -eq 'external'){$extra+=@('-ClusterDataPath',(Join-Path $scratch 'external-data'))}
 $output=@(& pwsh -NoProfile -File $runner -Phase Focused @extra)
 $code=$LASTEXITCODE
 $line=$output | Where-Object {$_ -like 'GOALS_SPA_EVIDENCE=*'} | Select-Object -Last 1
 Assert-Runner ($output -contains "GOALS_SPA_FINISHED=EXIT=$code") "$case missing terminal marker"
 if($case -eq 'disk'){
  Assert-Runner ($code -ne 0 -and -not $line -and ($output -match 'available 100 MiB; required 2,048 MiB')) 'Disk preflight did not reject before initialization'
  continue
 }
 Assert-Runner $line "$case missing evidence directory"
 $run=$line -replace '^GOALS_SPA_EVIDENCE='
 $data=if($case -eq 'external'){Join-Path $scratch 'external-data'}else{Join-Path $run 'data'}
 $retain=$case -in @('pg-stop','junction','keep','external')
 Assert-Runner ((Test-Path -LiteralPath $data) -eq $retain) "$case incorrect cluster retention"
 Assert-Runner (($code -eq 0) -eq ($case -in @('success','keep','external'))) "$case incorrect exit code"
 Assert-Runner ((Test-Path -LiteralPath (Join-Path $run 'pg-stopped')) -eq ($case -ne 'pg-stop')) "$case incorrect PostgreSQL shutdown"
 Assert-Runner ((Test-Path -LiteralPath (Join-Path $run 'redis-stopped')) -eq ($case -notin @('pg-start','pg-target'))) "$case incorrect Redis shutdown"
 if($case -notin @('pg-start','pg-target','redis-start')){
  foreach($name in @('tests.xml','events.log','tests.log','lifecycle.log','postgres.log','redis-6397.log')){
   Assert-Runner (Test-Path -LiteralPath (Join-Path $run $name)) "$case missing $name"
  }
 }
 if($case -eq 'pg-stop'){
  Assert-Runner ($output -contains 'GOALS_SPA_ERROR=Focused/affected acceptance checks failed; evidence retained') 'PHP failure was masked by shutdown failure'
  Assert-Runner (-not ($output -match '^GOALS_SPA_PROCESSES=STOPPED')) 'Reported stopped despite shutdown failure'
  $output | Set-Content -LiteralPath (Join-Path $run 'foundation-console.log')
  $watch=@(& pwsh -NoProfile -File (Join-Path $root 'scripts/Watch-Foundation.ps1') -LogPath (Join-Path $run 'foundation-console.log') -Once)
  Assert-Runner ($watch -match 'Foundation: FAIL') 'Watcher did not consume the terminal marker'
 }
 if($case -eq 'keep'){$keepRun=$run}
 if($case -eq 'external'){$externalRun=$run}
 Write-Output "PASS $case"
}
$env:RUNNER_CASE='success'
$reuse=@(& pwsh -NoProfile -File $runner -Phase Focused -ReuseRun $keepRun -KeepCluster)
Assert-Runner ($LASTEXITCODE -eq 0 -and (Test-Path -LiteralPath (Join-Path $keepRun 'data'))) 'Reuse did not retain its recorded cluster'
Assert-Runner (@(Get-ChildItem -LiteralPath $keepRun -Directory -Filter 'previous-*').Count -eq 1) 'Reuse did not archive earlier evidence'
$rejected=@(& pwsh -NoProfile -File $runner -Phase Focused -ReuseRun $externalRun -KeepCluster -ClusterDataPath $protected 2>&1)
Assert-Runner ($LASTEXITCODE -ne 0 -and (Get-Content -LiteralPath (Join-Path $protected 'sentinel')) -eq 'protected') 'External path was not protected'
. (Join-Path $root 'scripts/GoalsSpaLifecycle.ps1')
try {
 Remove-ProofCluster $externalRun $protected (Join-Path $scratch 'storage/framework/testing') 'unowned'
 throw 'External path cleanup unexpectedly succeeded'
} catch {
 Assert-Runner ($_.Exception.Message -like 'Cluster is outside*') 'External cleanup did not fail its ownership check'
}
Write-Output "PASS disk, reuse, external ownership, watcher; evidence $scratch"
