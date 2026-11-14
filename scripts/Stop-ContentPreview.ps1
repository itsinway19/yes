[CmdletBinding()]
param()

# Stop verified Laravel and only a Redis instance started and still owned by the
# official launcher. Pre-existing/shared Redis and PostgreSQL remain untouched.
& (Join-Path $PSScriptRoot 'Start-StablePreview.ps1') -Stop
exit $LASTEXITCODE
