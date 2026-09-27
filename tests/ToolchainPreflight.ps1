$ErrorActionPreference = 'Stop'
$runner = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts/builderad.ps1'
$php85 = 'C:\tools\php85\php.exe'
$node24 = 'C:\Users\stoma\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'

function Invoke-Preflight([string]$php, [string]$node) {
    $env:BUILDERAD_PHP = $php
    $env:BUILDERAD_NODE = $node
    $output = & pwsh -NoProfile -File $runner preflight 2>&1
    return @{ ExitCode = $LASTEXITCODE; Output = ($output -join "`n") }
}

try {
    $valid = Invoke-Preflight $php85 $node24
    if ($valid.ExitCode -ne 0 -or $valid.Output -notmatch 'PHP 8\.5\.' -or $valid.Output -notmatch 'Node 24\.' -or $valid.Output -notmatch 'PostgreSQL 18\.') {
        throw "Approved toolchain did not pass: $($valid.Output)"
    }

    $oldPhp = Invoke-Preflight 'C:\xampp\php\php.exe' $node24
    if ($oldPhp.ExitCode -eq 0 -or $oldPhp.Output -notmatch 'PHP version') {
        throw 'PHP 8.3 was not rejected.'
    }

    $oldNode = Invoke-Preflight $php85 'C:\Program Files\nodejs\node.exe'
    if ($oldNode.ExitCode -eq 0 -or $oldNode.Output -notmatch 'Node version') {
        throw 'Node 22 was not rejected.'
    }

    $env:BUILDERAD_PHP = $php85
    $env:BUILDERAD_NODE = $node24
    $env:DB_DATABASE = 'builderad'
    $wrongDatabase = & pwsh -NoProfile -File $runner test --filter=ExampleTest 2>&1
    if ($LASTEXITCODE -eq 0 -or ($wrongDatabase -join "`n") -notmatch 'disposable test database') {
        throw 'A test run targeting the application database was not rejected.'
    }
    Remove-Item Env:DB_DATABASE

    $enter = Join-Path (Split-Path -Parent $runner) 'Enter-BuilderadShell.ps1'
    $fresh = & pwsh -NoProfile -Command ". '$enter'; php -r 'echo PHP_VERSION;'; node -p 'process.versions.node'; npm --version" 2>&1
    if ($LASTEXITCODE -ne 0 -or ($fresh -join "`n") -notmatch '8\.5\.' -or ($fresh -join "`n") -notmatch '24\.19\.') {
        throw "A fresh PowerShell session did not use the approved toolchain: $($fresh -join ' ')"
    }

    Write-Output 'Toolchain preflight accepts approved versions, rejects wrong versions/database and initializes a fresh PowerShell session.'
} finally {
    Remove-Item Env:BUILDERAD_PHP, Env:BUILDERAD_NODE, Env:DB_DATABASE -ErrorAction SilentlyContinue
}
