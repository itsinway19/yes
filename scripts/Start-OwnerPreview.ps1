[CmdletBinding()]
param([switch] $NoOpenBrowser)

# This retired entry point must not start the old 8027 / 5434 environment.
Write-Error 'Start-OwnerPreview.ps1 is retired for this worktree. Use scripts/Start-StablePreview.ps1 for localhost:8038 and builderad_dev:5432; use -Status or -Stop to manage it. No legacy service was started or stopped.'
exit 1
