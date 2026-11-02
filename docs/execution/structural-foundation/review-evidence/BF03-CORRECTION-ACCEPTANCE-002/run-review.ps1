param([ValidateSet('Browser')][string]$Phase='Browser',[string]$ReuseRun='')
$ErrorActionPreference='Stop'
$reviewRoot=$PSScriptRoot
$workspaceRoot='C:\dev\builderad-worktrees\ba-commercial-expertise-001'
$canonicalPath=Join-Path $workspaceRoot 'scripts\Test-GoalsSpa.ps1'
$canonical=[IO.File]::ReadAllText($canonicalPath)
$rootLine='$proofRoot=Split-Path -Parent $PSScriptRoot'
$childLine="& 'C:\tools\node24\node.exe' `$proofBrowser 2>&1|Tee-Object -FilePath (Join-Path `$proofRun 'browser.log')"
if(([regex]::Matches($canonical,[regex]::Escape($rootLine))).Count -ne 1 -or ([regex]::Matches($canonical,[regex]::Escape($childLine))).Count -ne 1){throw 'Canonical runner hook mismatch; no test started'}
# Review-only child dispatch. All official frozen-dependency, port, database-role,
# fixture, source-receipt and finally-cleanup logic remains byte-identical.
$derived=$canonical.Replace($rootLine,"`$proofRoot='$workspaceRoot'").Replace($childLine,"& 'C:\tools\node24\node.exe' '$reviewRoot\acceptance-browser.mjs' 2>&1|Tee-Object -FilePath (Join-Path `$proofRun 'browser.log')")
[IO.File]::WriteAllText((Join-Path $reviewRoot 'derived-runner.ps1'),$derived)
@{canonical_sha256=(Get-FileHash -LiteralPath $canonicalPath).Hash;derived_sha256=(Get-FileHash -LiteralPath (Join-Path $reviewRoot 'derived-runner.ps1')).Hash;changes=@('Resolve canonical workspace root when executing review-owned copy','Dispatch one independent review browser probe instead of the existing browser selection');application_or_existing_test_edits=$false}|ConvertTo-Json -Depth 4|Set-Content -LiteralPath (Join-Path $reviewRoot 'runner-derivation.json')
$runnerArguments=@('-NoProfile','-File',(Join-Path $reviewRoot 'derived-runner.ps1'),'-Phase','Browser','-BrowserSelection','businesses')
if($ReuseRun){$runnerArguments+=@('-ReuseRun',$ReuseRun)}
& pwsh @runnerArguments 2>&1|Tee-Object -FilePath (Join-Path $reviewRoot 'runner-console.log')
exit $LASTEXITCODE
