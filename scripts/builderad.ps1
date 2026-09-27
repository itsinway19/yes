param(
    [Parameter(Position = 0)]
    [ValidateSet('preflight', 'php', 'composer', 'npm', 'psql', 'test', 'build')]
    [string] $Action = 'preflight',
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PassThrough = @()
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $root

function Resolve-Tool([string] $override, [string] $fallback, [string] $label) {
    $path = if ($override) { $override } else { $fallback }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "$label executable is unavailable: $path"
    }
    return (Resolve-Path -LiteralPath $path).Path
}

function Assert-DisposableTestDatabase([string] $php) {
    $env:APP_ENV = 'testing'
    & $php artisan security:assert-disposable-test-db
    if ($LASTEXITCODE -ne 0) {
        throw 'The disposable test database check failed; no migration or test was started.'
    }
}

$composerContract = Get-Content -LiteralPath (Join-Path $root 'composer.json') -Raw | ConvertFrom-Json
$nodeContract = Get-Content -LiteralPath (Join-Path $root 'package.json') -Raw | ConvertFrom-Json
$postgresContract = Get-Content -LiteralPath (Join-Path $root 'database/bootstrap/postgresql.sql') -Raw

$phpRule = $composerContract.require.php
if ($phpRule -notmatch '^>=(\d+\.\d+\.\d+) <(\d+\.\d+\.\d+)$') {
    throw "Unsupported repository PHP constraint: $phpRule"
}
$phpMinimum = [version] $Matches[1]
$phpMaximum = [version] $Matches[2]
$nodeRule = $nodeContract.engines.node
if ($nodeRule -notmatch '^>=(\d+) <(\d+)$') {
    throw "Unsupported repository Node constraint: $nodeRule"
}
$nodeMinimum = [int] $Matches[1]
$nodeMaximum = [int] $Matches[2]
if ($postgresContract -notmatch "current_setting\('server_version_num'\)::integer >= (\d+)") {
    throw 'Repository PostgreSQL minimum is missing.'
}
$postgresMinimum = [int] $Matches[1]
if ($postgresContract -notmatch "current_setting\('server_version_num'\)::integer < (\d+)") {
    throw 'Repository PostgreSQL maximum is missing.'
}
$postgresMaximum = [int] $Matches[1]

$php = Resolve-Tool $env:BUILDERAD_PHP 'C:\tools\php85\php.exe' 'PHP'
$node = Resolve-Tool $env:BUILDERAD_NODE 'C:\Users\stoma\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' 'Node'
$psql = Resolve-Tool $env:BUILDERAD_PSQL 'C:\Program Files\PostgreSQL\18\bin\psql.exe' 'PostgreSQL'
$composer = Resolve-Tool $env:BUILDERAD_COMPOSER_PHAR 'C:\composer\composer.phar' 'Composer'
$npm = Resolve-Tool $env:BUILDERAD_NPM_CLI 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' 'npm'
$opensslConfig = Join-Path (Split-Path -Parent $php) 'extras/ssl/openssl.cnf'
if (-not (Test-Path -LiteralPath $opensslConfig -PathType Leaf)) {
    throw "Approved PHP OpenSSL configuration is unavailable: $opensslConfig"
}
$env:OPENSSL_CONF = $opensslConfig

$phpVersion = [version] (& $php -r 'echo PHP_VERSION;')
if ($LASTEXITCODE -ne 0 -or $phpVersion -lt $phpMinimum -or $phpVersion -ge $phpMaximum) {
    throw "PHP version $phpVersion is outside repository requirement $phpRule."
}
$nodeVersionText = & $node -p 'process.versions.node'
$nodeVersion = [version] $nodeVersionText
if ($LASTEXITCODE -ne 0 -or $nodeVersion.Major -lt $nodeMinimum -or $nodeVersion.Major -ge $nodeMaximum) {
    throw "Node version $nodeVersion is outside repository requirement $nodeRule."
}
$psqlVersionText = & $psql --version
if ($LASTEXITCODE -ne 0 -or $psqlVersionText -notmatch '(\d+)\.(\d+)') {
    throw 'PostgreSQL client version could not be read.'
}
$postgresVersionNumber = ([int] $Matches[1] * 10000) + ([int] $Matches[2] * 100)
if ($postgresVersionNumber -lt $postgresMinimum -or $postgresVersionNumber -ge $postgresMaximum) {
    throw "PostgreSQL version $($Matches[0]) is outside the repository bootstrap requirement."
}

$scratch = Join-Path $root 'storage/framework/testing/toolchain-tmp'
New-Item -ItemType Directory -Force -Path $scratch | Out-Null
$env:TEMP = $scratch
$env:TMP = $scratch
$env:COMPOSER_CACHE_DIR = Join-Path $scratch 'composer-cache'
$env:npm_config_cache = Join-Path $scratch 'npm-cache'
$env:Path = ((Split-Path -Parent $php), (Split-Path -Parent $node), (Split-Path -Parent $psql), $env:Path) -join ';'

if ($Action -eq 'preflight') {
    $npmVersion = & $node $npm --version
    $composerVersion = & $php $composer --version --no-ansi
    if ($LASTEXITCODE -ne 0 -or [int] (($npmVersion -split '\.')[0]) -lt 9 -or $composerVersion -notmatch 'Composer version 2\.') {
        throw 'Composer or npm cannot execute with the approved runtimes.'
    }
    Write-Output "PHP $phpVersion | $php"
    Write-Output "Node $nodeVersion | $node"
    Write-Output "PostgreSQL $($psqlVersionText -replace '^.*?([0-9]+\.[0-9]+).*$', '$1') | $psql"
    Write-Output "Composer $($composerVersion -replace '^.*?([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') | $composer"
    Write-Output "npm $npmVersion via approved Node | $npm"
    exit 0
}

switch ($Action) {
    'php' {
        if ($PassThrough.Count -gt 1 -and $PassThrough[0] -eq 'artisan' -and $PassThrough[1] -like 'migrate*') {
            Assert-DisposableTestDatabase $php
        }
        & $php @PassThrough
    }
    'composer' { & $php $composer @PassThrough }
    'npm' { & $node $npm @PassThrough }
    'psql' { & $psql @PassThrough }
    'test' {
        Assert-DisposableTestDatabase $php
        & $php artisan test @PassThrough
    }
    'build' { & $node $npm run build @PassThrough }
}
exit $LASTEXITCODE
