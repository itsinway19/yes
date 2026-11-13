function Assert-ProofPlainPath([string]$Path) {
 for($item=$Path; $item; $item=[IO.Path]::GetDirectoryName($item)){
  $entry=Get-Item -LiteralPath $item -Force -ErrorAction SilentlyContinue
  if($null -ne $entry -and ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)){
   throw "Reparse point is forbidden in proof path: $item"
  }
 }
}

function Get-ProofFreeBytes([string]$Path) {
 $volume=[IO.DriveInfo]::new([IO.Path]::GetPathRoot($Path))
 return $volume.AvailableFreeSpace
}

function Assert-ProofDiskSpace([string]$Path) {
 # Retained clusters measured up to 224 MiB after stop. A full checkpoint logged
 # over 71k files. Reserve space for file allocation, transient WAL, logs and build.
 $minimum=2GB
 $available=Get-ProofFreeBytes $Path
 $message='Proof disk {0}: available {1:N0} MiB; required {2:N0} MiB' -f [IO.Path]::GetPathRoot($Path),($available/1MB),($minimum/1MB)
 if($available -lt $minimum){throw $message}
 Write-Output $message
}

function Test-ProofPortOccupied([int]$Port) {
 $listeners=@(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
 if($listeners.Count -eq 0){return $false}
 if(@($listeners|Where-Object{$_.OwningProcess -ne 0}).Count -gt 0){return $true}
 # Windows can retain owner-zero phantom LISTEN rows after an owned server
 # exits. Never ignore a row that still accepts a loopback connection.
 foreach($address in @('127.0.0.1','::1')){
  $client=[Net.Sockets.TcpClient]::new()
  try {
   if($client.ConnectAsync($address,$Port).Wait(250)){return $true}
  }catch [System.Exception]{}finally{$client.Dispose()}
 }
 return $false
}

function Remove-ProofCluster([string]$Run,[string]$Data,[string]$TestingRoot,[string]$Owner) {
 if([IO.Path]::GetDirectoryName($Run) -ne $TestingRoot -or
    [IO.Path]::GetFileName($Run) -notmatch '^goals-spa-[0-9a-f-]{36}$' -or
    $Data -ne (Join-Path $Run 'data')){
  throw "Cluster is outside the run's owned data directory: $Data"
 }
 Assert-ProofPlainPath $Data
 $receipt=Join-Path $Run 'cluster-owner.txt'
 Assert-ProofPlainPath $receipt
 if([IO.File]::ReadAllText($receipt) -cne $Owner){throw "Cluster ownership receipt does not match: $Data"}
 $bytes=Get-ProofPlainTreeBytes $Data
 [IO.Directory]::Delete($Data,$true)
 return $bytes
}

function Get-ProofPlainTreeBytes([string]$Path) {
 Assert-ProofPlainPath $Path
 # Check every entry without following a reparse point before removing anything.
 $pending=[Collections.Generic.Stack[string]]::new()
 $pending.Push($Path)
 $bytes=0L
 while($pending.Count){
  $directory=$pending.Pop()
  foreach($entry in [IO.Directory]::EnumerateFileSystemEntries($directory)){
   $attributes=[IO.File]::GetAttributes($entry)
   if($attributes -band [IO.FileAttributes]::ReparsePoint){throw "Reparse point in cluster: $entry"}
   if($attributes -band [IO.FileAttributes]::Directory){$pending.Push($entry)}
   else{$bytes+=[IO.FileInfo]::new($entry).Length}
  }
 }
 return $bytes
}

function Get-ProofPostmaster([string]$Data,[datetime]$StartedAt) {
 Assert-ProofPlainPath (Join-Path $Data 'postmaster.pid')
 $info=[IO.File]::ReadAllLines((Join-Path $Data 'postmaster.pid'))
 if($info.Count -lt 4 -or [IO.Path]::GetFullPath($info[1]) -ne $Data -or $info[3] -ne '5433'){
  throw "PostgreSQL PID file does not identify this cluster: $Data"
 }
 $process=Get-Process -Id ([int]$info[0]) -ErrorAction Stop
 $recorded=[DateTimeOffset]::FromUnixTimeSeconds([long]$info[2]).UtcDateTime
 if($process.ProcessName -ne 'postgres' -or $recorded -lt $StartedAt.AddSeconds(-2) -or
    [math]::Abs(($process.StartTime.ToUniversalTime()-$recorded).TotalSeconds) -gt 5){
  throw "PostgreSQL PID does not identify a process created by this run: $Data"
 }
 $command=(Get-CimInstance Win32_Process -Filter "ProcessId=$($process.Id)").CommandLine
 $quotedData=[regex]::Escape($Data.Replace('/','\'))
 if(-not $command -or $command.Replace('/','\') -notmatch "(?:^|\s)-D\s+(?:`"$quotedData`"|$quotedData)(?:\s|$)"){
  throw "PostgreSQL command line does not identify this cluster: $Data"
 }
 # Keep a handle to the specific process so PID reuse cannot prove shutdown.
 $null=$process.Handle
 return $process
}

function Initialize-ProofCluster([string]$Pg,[string]$Data) {
 & (Join-Path $Pg 'initdb.exe') -D $Data -U postgres -A trust --encoding=UTF8 --locale=C | Out-Host
 if($LASTEXITCODE -ne 0){throw 'initdb failed'}
}

function Invoke-ProofPsql([string[]]$Arguments) {
 & (Join-Path $proofPg 'psql.exe') -X -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 5433 -U postgres @Arguments
 if($LASTEXITCODE -ne 0){throw 'Proof PostgreSQL operation failed'}
}

function Assert-ProofPostgresTarget([string]$Data) {
 # Confirm the actual TCP target before any bootstrap SQL. Windows socket
 # listings can omit a newly started postmaster even after pg_ctl reports ready.
 $directory=@(Invoke-ProofPsql @('-d','postgres','-A','-t','-c','SHOW data_directory'))
 if($directory.Count -ne 1 -or [IO.Path]::GetFullPath($directory[0]) -ne $Data){
  throw "PostgreSQL on 5433 does not report this run's data directory: $Data"
 }
}

function Invoke-ProofPhp([string[]]$Arguments) {
 & pwsh -NoProfile -File scripts/builderad.ps1 php @Arguments
 if($LASTEXITCODE -ne 0){throw 'Proof PHP operation failed'}
}

function Start-ProofPostgres([string]$Pg,[string]$Data,[string]$Log) {
 $ctl=Start-Process -FilePath (Join-Path $Pg 'pg_ctl.exe') -ArgumentList @('-D',('"' + $Data + '"'),'-l',('"' + $Log + '"'),'-o','"-p 5433 -h 127.0.0.1 -c max_wal_size=128MB"','-w','start') -WindowStyle Hidden -PassThru
 $ctl.WaitForExit()
 if($ctl.ExitCode -ne 0){throw 'PostgreSQL start failed; see postgres.log'}
}

function Stop-ProofPostgres([string]$Pg,[string]$Data,$Process) {
 if(-not $Process.HasExited){
  $pidFile=Join-Path $Data 'postmaster.pid'
  Assert-ProofPlainPath $pidFile
  if([IO.File]::ReadAllLines($pidFile)[0] -ne [string]$Process.Id){throw 'PostgreSQL PID file changed before stop'}
  & (Join-Path $Pg 'pg_ctl.exe') -D $Data -m fast -t 360 -w stop | Out-Host
  if($LASTEXITCODE -ne 0){throw "Graceful PostgreSQL shutdown failed: $Data"}
 }
 if(-not $Process.WaitForExit(5000) -or
    (Test-Path -LiteralPath (Join-Path $Data 'postmaster.pid'))){
  throw "Graceful PostgreSQL shutdown is unconfirmed: $Data"
 }
 & (Join-Path $Pg 'pg_ctl.exe') -D $Data status | Out-Host
 if($LASTEXITCODE -ne 3){throw "PostgreSQL still reports an active or unknown cluster: $Data"}
}

function Assert-ProofPostgresAbsent([string]$Pg,[string]$Data) {
 & (Join-Path $Pg 'pg_ctl.exe') -D $Data status | Out-Host
 if($LASTEXITCODE -ne 3 -or (Test-Path -LiteralPath (Join-Path $Data 'postmaster.pid'))){
  throw "PostgreSQL absence after failed start is unconfirmed: $Data"
 }
 foreach($candidate in Get-CimInstance Win32_Process -Filter "Name='postgres.exe'"){
  if($candidate.CommandLine -and $candidate.CommandLine.Replace('/','\').Contains($Data.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase)){
   throw "PostgreSQL process $($candidate.ProcessId) still names the failed cluster: $Data"
  }
 }
}

function Start-ProofRedis([string]$Run,[Collections.Generic.List[object]]$Processes) {
 $redisHome='C:\Program Files\Redis'
 foreach($port in (@(6397)+@(16397..16404))){
  # Windows can refuse a bind even when no LISTEN socket is reported.
  $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$port)
  try{
   $listener.ExclusiveAddressUse=$true
   $listener.Start()
  }catch{
   $message="Redis port ${port}: $($_.Exception.GetBaseException().Message)"
   Add-Content -LiteralPath (Join-Path $Run 'redis-startup.log') -Value $message
   Write-Warning $message
   continue
  }finally{$listener.Stop()}
  $config=Join-Path $Run 'redis.conf'
  [IO.File]::WriteAllText($config,"bind 127.0.0.1`nport $port`nsave `"`"`nappendonly no`ndir `"$($Run.Replace('\','/'))`"`n")
  $stdout=Join-Path $Run "redis-$port.log"
  $process=Start-Process -FilePath (Join-Path $redisHome 'redis-server.exe') -ArgumentList ('"'+$config+'"') -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError (Join-Path $Run "redis-$port-error.log")
  $Processes.Add($process)
  $deadline=[datetime]::UtcNow.AddSeconds(8)
  do {
   if($process.HasExited){break}
   if(Test-ProofRedisPing $port $process.Id){
    Add-Content -LiteralPath (Join-Path $Run 'redis-startup.log') -Value "Redis PONG and server PID verified on $port (PID $($process.Id))"
    return $port
   }
   Start-Sleep -Milliseconds 100
  }while([datetime]::UtcNow -lt $deadline)
  if($process.HasExited -and (Select-String -LiteralPath $stdout -Pattern 'listening socket.*bind:' -Quiet)){
   Write-Warning "Redis could not bind port $port; see $stdout"
   continue
  }
  throw "Redis readiness failed on port $port (PONG and server PID required); see $stdout and redis-$port-error.log"
 }
 throw "No isolated Redis port could bind; see $Run/redis-startup.log and Redis logs"
}

function Test-ProofRedisPing([int]$Port,[int]$ProcessId) {
 $client=[Net.Sockets.TcpClient]::new()
 try{
  if(-not $client.ConnectAsync('127.0.0.1',$Port).Wait(500)){return $false}
  $client.ReceiveTimeout=500
  $client.SendTimeout=500
  $stream=$client.GetStream()
  # Redis supplies its own PID over the same connection as PONG. Socket
  # enumeration is unreliable on this Windows host during process startup.
  $payload=[Text.Encoding]::ASCII.GetBytes("PING`r`nINFO server`r`n")
  $stream.Write($payload,0,$payload.Length)
  $reader=[IO.StreamReader]::new($stream,[Text.Encoding]::ASCII)
  if($reader.ReadLine() -cne '+PONG'){return $false}
  if($reader.ReadLine() -notmatch '^\$(\d{1,5})$'){return $false}
  $count=[int]$Matches[1]
  if($count -gt 65536){return $false}
  $body=[char[]]::new($count)
  return $reader.ReadBlock($body,0,$count) -eq $count -and
   [string]::new($body) -match "(?m)^process_id:$ProcessId\r?$"
 }catch [System.Exception]{
  return $false
 }finally{$client.Dispose()}
}
