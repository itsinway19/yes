[CmdletBinding()]
param()

# Compatibility entry point. Stop only Laravel; shared PostgreSQL/Redis remain up.
& (Join-Path $PSScriptRoot 'Start-StablePreview.ps1') -Stop
exit $LASTEXITCODE
