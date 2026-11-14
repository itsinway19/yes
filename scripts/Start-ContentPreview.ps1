[CmdletBinding()]
param([switch] $Status, [switch] $Stop)

# Compatibility entry point for the approved 8038 / builderad_dev preview.
# The official launcher never initializes PostgreSQL or applies migrations.
& (Join-Path $PSScriptRoot 'Start-StablePreview.ps1') @PSBoundParameters
exit $LASTEXITCODE
