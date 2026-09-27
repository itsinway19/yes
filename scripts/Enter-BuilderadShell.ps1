$ErrorActionPreference = 'Stop'
$builderadRoot = Split-Path -Parent $PSScriptRoot
$builderadRunner = Join-Path $PSScriptRoot 'builderad.ps1'

& pwsh -NoProfile -File $builderadRunner preflight
if ($LASTEXITCODE -ne 0) { throw 'Builderad toolchain preflight failed; shell aliases were not installed.' }

$global:BuilderadPhpExe = if ($env:BUILDERAD_PHP) { $env:BUILDERAD_PHP } else { 'C:\tools\php85\php.exe' }
$global:BuilderadNodeExe = if ($env:BUILDERAD_NODE) { $env:BUILDERAD_NODE } else { 'C:\Users\stoma\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe' }
$global:BuilderadPsqlExe = if ($env:BUILDERAD_PSQL) { $env:BUILDERAD_PSQL } else { 'C:\Program Files\PostgreSQL\18\bin\psql.exe' }
$global:BuilderadComposerPhar = if ($env:BUILDERAD_COMPOSER_PHAR) { $env:BUILDERAD_COMPOSER_PHAR } else { 'C:\composer\composer.phar' }
$global:BuilderadNpmCli = if ($env:BUILDERAD_NPM_CLI) { $env:BUILDERAD_NPM_CLI } else { 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' }

$scratch = Join-Path $builderadRoot 'storage/framework/testing/toolchain-tmp'
New-Item -ItemType Directory -Force -Path $scratch | Out-Null
$env:TEMP = $scratch
$env:TMP = $scratch
$env:COMPOSER_CACHE_DIR = Join-Path $scratch 'composer-cache'
$env:npm_config_cache = Join-Path $scratch 'npm-cache'
$env:Path = ((Split-Path -Parent $global:BuilderadPhpExe), (Split-Path -Parent $global:BuilderadNodeExe), (Split-Path -Parent $global:BuilderadPsqlExe), $env:Path) -join ';'

function global:php { & $global:BuilderadPhpExe @args }
function global:node { & $global:BuilderadNodeExe @args }
function global:composer { & $global:BuilderadPhpExe $global:BuilderadComposerPhar @args }
function global:npm { & $global:BuilderadNodeExe $global:BuilderadNpmCli @args }
function global:psql { & $global:BuilderadPsqlExe @args }
