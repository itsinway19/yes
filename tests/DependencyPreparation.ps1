$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$script = Join-Path $root 'scripts/Prepare-BuilderadDependencies.ps1'
$temporary = Join-Path $root 'storage/framework/testing/dependency-fixture'

try {
    New-Item -ItemType Directory -Force -Path (Join-Path $temporary 'vendor/composer'), (Join-Path $temporary 'node_modules') | Out-Null
    Copy-Item -LiteralPath (Join-Path $root 'composer.lock') -Destination $temporary
    Copy-Item -LiteralPath (Join-Path $root 'package-lock.json') -Destination $temporary
    Copy-Item -LiteralPath (Join-Path $root 'vendor/composer/installed.json') -Destination (Join-Path $temporary 'vendor/composer')
    Copy-Item -LiteralPath (Join-Path $root 'node_modules/.package-lock.json') -Destination (Join-Path $temporary 'node_modules')

    $valid = & pwsh -NoProfile -File $script -Mode Seed -SeedRoot $temporary -VerifyOnly 2>&1
    if ($LASTEXITCODE -ne 0 -or ($valid -join "`n") -notmatch 'Locked dependencies verified') {
        throw "Matching seed was rejected: $($valid -join ' ')"
    }

    Set-Content -LiteralPath (Join-Path $temporary 'package-lock.json') -Value '{"name":"wrong-seed"}'
    $invalid = & pwsh -NoProfile -File $script -Mode Seed -SeedRoot $temporary -VerifyOnly 2>&1
    if ($LASTEXITCODE -eq 0 -or ($invalid -join "`n") -notmatch 'lock mismatch') {
        throw 'A seed with a different package lock was not rejected.'
    }

    Write-Output 'Dependency preparation verifies matching seed and rejects lock mismatch.'
} finally {
    $resolvedRoot = [IO.Path]::GetFullPath($root)
    $resolvedTemporary = [IO.Path]::GetFullPath($temporary)
    if ($resolvedTemporary.StartsWith($resolvedRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $temporary)) {
        Remove-Item -LiteralPath $temporary -Recurse -Force
    }
}
