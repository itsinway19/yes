param(
    [Parameter(Mandatory = $true)][string]$RunPath,
    [ValidateRange(1, 60)][int]$RefreshSeconds = 2,
    [switch]$Once
)
$ErrorActionPreference = 'Stop'
$progressRun = (Resolve-Path -LiteralPath $RunPath).Path
do {
    $rows = @(Get-Content -LiteralPath (Join-Path $progressRun 'events.log') -ErrorAction SilentlyContinue)
    $start = $rows | Where-Object { $_ -match '^Test Runner Execution Started \((\d+) tests?\)' } | Select-Object -Last 1
    $total = if ($start -match '\((\d+) tests?\)') { [int]$Matches[1] } else { 0 }
    $passed = @($rows | Where-Object { $_ -match '^Test Passed \(' }).Count
    $failures = @($rows | Where-Object { $_ -match '^Test (Failed|Errored) \(' } |
        ForEach-Object { $_ -replace '^Test (Failed|Errored) ', '' } | Select-Object -Unique)
    $skipped = @($rows | Where-Object { $_ -match '^Test (Skipped|Marked Incomplete) \(' }).Count
    $completed = @($rows | Where-Object { $_ -match '^Test (Finished|Skipped|Marked Incomplete) \(' } |
        ForEach-Object { $_ -replace '^Test (Finished|Skipped|Marked Incomplete) ', '' } | Select-Object -Unique).Count
    if (-not $Once) { Clear-Host }
    Write-Host ('Foundation | {0:yyyy-MM-dd HH:mm:ss}' -f (Get-Date)) -ForegroundColor Cyan
    if ($total -gt 0) {
        $percent = [math]::Min(100.0, [math]::Round(100.0 * $completed / $total, 1))
        Write-Progress -Activity 'Foundation: PHP tests' -Status "$completed / $total ($percent%)" -PercentComplete $percent
        Write-Host "PHP completed: $completed / $total ($percent%)"
    } else { Write-Host 'Waiting for the PHP test inventory...' }
    Write-Host "PASS: $passed | FAIL/ERROR: $($failures.Count) | SKIP/INCOMPLETE: $skipped"
    $rows | Where-Object { $_ -match '^Test Prepared \(' } | Select-Object -Last 1 | Write-Host
    $failures | Select-Object -Last 5 | ForEach-Object { Write-Host $_ -ForegroundColor Red }
    $startedAt = (Get-Item -LiteralPath (Join-Path $progressRun 'source-manifest.json') -ErrorAction SilentlyContinue).LastWriteTime
    $gate = Get-ChildItem -LiteralPath $progressRun -Filter 'gate-*.log' |
        Where-Object { -not $startedAt -or $_.LastWriteTime -ge $startedAt } |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($gate) {
        Write-Host "`nGate: $($gate.Name)" -ForegroundColor Yellow
        Get-Content -LiteralPath $gate.FullName -Tail 5
    }
    $completion = Get-Content -LiteralPath (Join-Path $progressRun 'foundation-console.log') -Tail 16 -ErrorAction SilentlyContinue |
        Where-Object { $_ -match '^GOALS_SPA_PROCESSES=STOPPED; EXIT=(\d+)' } | Select-Object -Last 1
    if ($completion -match 'EXIT=(\d+)') {
        $exitCode = [int]$Matches[1]
        Write-Progress -Activity 'Foundation: PHP tests' -Completed
        Write-Host "`nFoundation finished. Exit code: $exitCode (0 = PASS)." -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } else { 'Red' })
        break
    }
    Write-Host "`nPercentage covers PHP only; later gates have separate logs. Ctrl+C stops this viewer only."
    if (-not $Once) { Start-Sleep -Seconds $RefreshSeconds }
} while (-not $Once)
