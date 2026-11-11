param(
 [Parameter(Mandatory=$true)][string]$LogPath,
 [switch]$Once,
 [ValidateRange(1,60)][int]$IntervalSeconds=3
)
$ErrorActionPreference='Stop'
$resolvedLog=[IO.Path]::GetFullPath($LogPath)
do {
 $lines=if(Test-Path -LiteralPath $resolvedLog){@(Get-Content -LiteralPath $resolvedLog)}else{@()}
 $passed=@($lines|Where-Object{$_ -like 'GOALS_SPA_GATE_PASS=*'}|ForEach-Object{$_ -replace '^GOALS_SPA_GATE_PASS=',''}|Select-Object -Unique)
 $failed=@($lines|Where-Object{$_ -like 'GOALS_SPA_GATE_FAIL=*'}|ForEach-Object{$_ -replace '^GOALS_SPA_GATE_FAIL=',''}|Select-Object -Unique)
 $started=@($lines|Where-Object{$_ -like 'GOALS_SPA_GATE_START=*'}|ForEach-Object{$_ -replace '^GOALS_SPA_GATE_START=',''})
 $totalLine=@($lines|Where-Object{$_ -like 'GOALS_SPA_FOUNDATION_TOTAL=*'}|Select-Object -Last 1)
 $total=if($totalLine.Count){[int]($totalLine[0] -replace '^GOALS_SPA_FOUNDATION_TOTAL=','')}else{10}
 $terminal=@($lines|Where-Object{$_ -match '^GOALS_SPA_PROCESSES=STOPPED; EXIT=\d+$'}|Select-Object -Last 1)
 $status=if($failed.Count -or ($terminal.Count -and $terminal[0] -notmatch 'EXIT=0$')){'FAIL'}elseif($terminal.Count){'PASS'}else{'RUNNING'}
 if($status -eq 'FAIL' -and -not $failed.Count){$failed=@(if($started.Count -and $started[-1] -notin $passed){$started[-1]}else{'runner or process cleanup'})}
 $percent=[math]::Floor(100*$passed.Count/$total)
 if(-not $Once){Clear-Host}
 Write-Host "Foundation: $status | completed gates: $($passed.Count)/$total ($percent%)"
 Write-Host 'Percentage counts completed gates, not elapsed time. PHP has its own test progress below.'
 Write-Host "Log: $resolvedLog"
 foreach($gate in $passed){Write-Host "[PASS] $gate" -ForegroundColor Green}
 foreach($gate in $failed){Write-Host "[FAIL] $gate" -ForegroundColor Red}
 if($status -eq 'RUNNING'){
  $current=if($started.Count){$started[-1]}else{'isolated environment preparation'}
  Write-Host "[RUNNING] $current" -ForegroundColor Cyan
 }
 $runLine=@($lines|Where-Object{$_ -like 'GOALS_SPA_FOUNDATION_RUN=*'}|Select-Object -Last 1)
 if($runLine.Count -and $started.Count -and $started[-1] -eq 'PHP suite'){
  $events=Join-Path ($runLine[0] -replace '^GOALS_SPA_FOUNDATION_RUN=','') 'events.log'
  if(Test-Path -LiteralPath $events){
   $eventLines=@(Get-Content -LiteralPath $events)
   $suite=@($eventLines|Where-Object{$_ -match '^Test Suite Loaded \((\d+) tests?\)'}|Select-Object -First 1)
   $finished=@($eventLines|Where-Object{$_ -like 'Test Finished (*'}).Count
   $failures=@($eventLines|Where-Object{$_ -match '^Test (Failed|Errored) \('}).Count
   if($suite.Count -and $suite[0] -match '\((\d+) tests?\)'){
    $tests=[int]$Matches[1];$testPercent=if($tests){[math]::Floor(100*$finished/$tests)}else{0}
    Write-Host "PHP tests: $finished/$tests ($testPercent%) | failed/error events: $failures"
   }
   Write-Host 'Latest PHP event:'; $eventLines|Select-Object -Last 1|Write-Host
  }
 }
 if($Once -or $terminal.Count){break}
 Start-Sleep -Seconds $IntervalSeconds
}while($true)
