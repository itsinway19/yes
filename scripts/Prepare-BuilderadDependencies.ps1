param(
    [ValidateSet('Seed', 'Online')]
    [string] $Mode = 'Seed',
    [string] $SeedRoot,
    [switch] $VerifyOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = Split-Path -Parent $PSScriptRoot
$runner = Join-Path $PSScriptRoot 'builderad.ps1'

& pwsh -NoProfile -File $runner preflight | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Toolchain preflight failed before dependency preparation.' }

function Assert-LockMatch([string] $source, [string] $name) {
    $sourceLock = Join-Path $source $name
    $targetLock = Join-Path $root $name
    if (-not (Test-Path -LiteralPath $sourceLock -PathType Leaf) -or
        (Get-FileHash -LiteralPath $sourceLock -Algorithm SHA256).Hash -ne
        (Get-FileHash -LiteralPath $targetLock -Algorithm SHA256).Hash) {
        throw "$name lock mismatch; seed dependencies cannot be reused."
    }
}

function Assert-Installed([string] $location) {
    $composerLock = Get-Content -LiteralPath (Join-Path $root 'composer.lock') -Raw | ConvertFrom-Json
    $composerInstalledPath = Join-Path $location 'vendor/composer/installed.json'
    if (-not (Test-Path -LiteralPath $composerInstalledPath -PathType Leaf)) {
        throw 'Composer installed inventory is missing.'
    }
    $composerInstalled = Get-Content -LiteralPath $composerInstalledPath -Raw | ConvertFrom-Json
    $composerVersions = @{}
    foreach ($package in $composerInstalled.packages) { $composerVersions[$package.name] = $package.version }
    $composerLockedNames = @($composerLock.packages.name) + @($composerLock.'packages-dev'.name)
    foreach ($name in $composerVersions.Keys) {
        if ($name -notin $composerLockedNames) {
            throw "Composer installed inventory contains an unlocked package: $name"
        }
    }
    foreach ($package in @($composerLock.packages) + @($composerLock.'packages-dev')) {
        if (-not $composerVersions.ContainsKey($package.name) -or $composerVersions[$package.name] -ne $package.version) {
            throw "Composer installed inventory differs from lock: $($package.name)"
        }
    }

    $npmLock = Get-Content -LiteralPath (Join-Path $root 'package-lock.json') -Raw | ConvertFrom-Json -AsHashtable
    $npmInstalledPath = Join-Path $location 'node_modules/.package-lock.json'
    if (-not (Test-Path -LiteralPath $npmInstalledPath -PathType Leaf)) {
        throw 'npm installed inventory is missing.'
    }
    $npmInstalled = Get-Content -LiteralPath $npmInstalledPath -Raw | ConvertFrom-Json -AsHashtable
    foreach ($name in $npmInstalled.packages.Keys) {
        if (-not $npmLock.packages.ContainsKey($name) -or
            $npmLock.packages[$name].version -ne $npmInstalled.packages[$name].version) {
            throw "npm installed inventory differs from lock: $name"
        }
    }
    $direct = @($npmLock.packages[''].dependencies.Keys) + @($npmLock.packages[''].devDependencies.Keys)
    foreach ($name in $direct) {
        $path = "node_modules/$name"
        if (-not $npmInstalled.packages.ContainsKey($path)) {
            throw "Direct npm dependency is missing: $name"
        }
    }
}

if ($Mode -eq 'Online') {
    if ($VerifyOnly) { throw '-VerifyOnly applies only to Seed mode.' }
    & pwsh -NoProfile -File $runner composer install --no-interaction --prefer-dist --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'Composer online installation failed; use a verified local seed when the network is restricted.' }
    & pwsh -NoProfile -File $runner npm ci --no-audit --no-fund
    if ($LASTEXITCODE -ne 0) { throw 'npm online installation failed; use a verified local seed when the network is restricted.' }
    Assert-Installed $root
    Write-Output 'Locked dependencies installed and verified.'
    exit 0
}

if (-not $SeedRoot) { throw 'Seed mode requires -SeedRoot.' }
$seed = (Resolve-Path -LiteralPath $SeedRoot).Path
if ($seed -eq (Resolve-Path -LiteralPath $root).Path) { throw 'Seed root must be a separate checkout.' }
Assert-LockMatch $seed 'composer.lock'
Assert-LockMatch $seed 'package-lock.json'
Assert-Installed $seed

if (-not $VerifyOnly) {
    foreach ($directory in 'vendor', 'node_modules') {
        $destination = Join-Path $root $directory
        if (-not (Test-Path -LiteralPath $destination)) {
            Copy-Item -LiteralPath (Join-Path $seed $directory) -Destination $destination -Recurse
        }
    }
    Assert-Installed $root
}

Write-Output 'Locked dependencies verified against identical Composer and npm locks.'
