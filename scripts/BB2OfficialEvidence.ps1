param([Parameter(Mandatory)][ValidateNotNullOrEmpty()][string[]]$Urls, [string]$Output = 'storage/framework/testing/bb2-official-evidence.json')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$target = [IO.Path]::GetFullPath((Join-Path $taskRoot $Output))
if (-not $target.StartsWith($taskRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Output must stay in this worktree.' }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
$records = @()
if (Test-Path -LiteralPath $target) { $records = @(Get-Content -Raw -LiteralPath $target | ConvertFrom-Json) }
foreach ($url in $Urls) {
    $uri = [uri]$url
    if ($uri.Scheme -ne 'https' -or $uri.Host -notin @('developers.google.com','developers.facebook.com','developers.snap.com','business-api.tiktok.com','docs.x.com','developer.x.com','ads.tiktok.com')) { throw 'Official documentation hosts only.' }
    $record = [ordered]@{ url=$url; accessed_at=[DateTimeOffset]::UtcNow.ToString('o'); status='UNAVAILABLE'; title=$null; last_updated_if_present=$null; response_sha256=$null; text=$null; links=@(); error=$null }
    try {
        $response = Invoke-WebRequest -Uri $url -TimeoutSec 25
        $html = $response.Content
        $title = [regex]::Match($html, '(?is)<title[^>]*>(.*?)</title>').Groups[1].Value
        $article = [regex]::Match($html, '(?is)<article\b[^>]*>(.*?)</article>').Groups[1].Value
        if (-not $article) { $article = $html }
        $article = $article -replace '(?is)<(script|style)\b[^>]*>.*?</\1>', ' '
        $plain = [Net.WebUtility]::HtmlDecode(($article -replace '<[^>]+>', ' ' -replace '\s+', ' ')).Trim()
        $record.status = 'RETRIEVED'
        $record.title = [Net.WebUtility]::HtmlDecode($title)
        if (-not $record.title) { $record.title = [regex]::Match($html, '(?m)^# ([^\r\n]+)').Groups[1].Value }
        $record.last_updated_if_present = [regex]::Match(($html -replace '<[^>]+>',' '), '(?i)Last updated\s+\d{4}-\d{2}-\d{2}(?: UTC)?|Updated\s*:\s*[A-Za-z]+\s+\d{1,2},\s+\d{4}').Value
        $record.response_sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($html))).ToLowerInvariant()
        $record.text = $plain
        $record.links = @([regex]::Matches($html, '(?is)<a\b[^>]*href=["'']([^"'']+)["''][^>]*>(.*?)</a>') | ForEach-Object {
            [pscustomobject]@{href=$_.Groups[1].Value; label=[Net.WebUtility]::HtmlDecode(($_.Groups[2].Value -replace '<[^>]+>',' ' -replace '\s+',' ')).Trim()}
        } | Where-Object label | Sort-Object href -Unique)
        Write-Output "$url | $($response.StatusCode) | $($record.title) | $($plain.Length) characters"
    } catch { $record.error = $_.Exception.Message; Write-Output "$url | UNAVAILABLE | $($record.error)" }
    $records += [pscustomobject]$record
    ConvertTo-Json -Depth 8 -InputObject @($records) | Set-Content -LiteralPath $target -Encoding utf8
}
