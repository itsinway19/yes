[CmdletBinding()]
param()

# Compatibility entry point. The stable launcher uses the existing shared
# PostgreSQL and Redis services and never initializes data or applies migrations.
& (Join-Path $PSScriptRoot 'Start-StablePreview.ps1')
exit $LASTEXITCODE
