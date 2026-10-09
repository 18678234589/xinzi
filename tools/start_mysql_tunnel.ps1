$ErrorActionPreference = 'Stop'
$activeTunnels = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
    Where-Object { $_.LocalPort -in @(13306, 13307) }
if ($activeTunnels) {
    $owners = @($activeTunnels | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($ownerId in $owners) {
        $ownerProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$ownerId"
        if ($ownerProcess.Name -ne 'ssh.exe' -or $ownerProcess.CommandLine -notmatch '58\.58\.97\.174' -or $ownerProcess.CommandLine -notmatch '5024') {
            throw "Local database port is held by a different process (PID $ownerId). Stop the old tunnel first."
        }
    }
    if (@($activeTunnels | Select-Object -ExpandProperty LocalPort -Unique).Count -eq 2) { return }
    throw 'An incomplete SSH tunnel already exists; restart it with both database forwards.'
}
$keyPath = Join-Path $env:USERPROFILE '.ssh/id_ed25519_tfdev'
if (-not (Test-Path -LiteralPath $keyPath)) { throw 'The deployment SSH key is missing.' }
$sshArguments = @(
    '-i', ('"' + $keyPath + '"'), '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=yes', '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3', '-o', 'ExitOnForwardFailure=yes', '-N',
    '-L', '127.0.0.1:13306:10.99.0.4:3306',
    '-L', '127.0.0.1:13307:172.17.0.1:13307', '-p', '5024', 'root@58.58.97.174'
)
Start-Process -FilePath 'C:/Windows/System32/OpenSSH/ssh.exe' -ArgumentList $sshArguments -WindowStyle Hidden
for ($attempt = 0; $attempt -lt 25; $attempt++) {
    $ports = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalPort -in @(13306, 13307) } | Select-Object -ExpandProperty LocalPort -Unique)
    if ($ports.Count -eq 2) { return }
    Start-Sleep -Milliseconds 200
}
throw 'The SSH database tunnel did not start.'
